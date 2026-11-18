<?php
/**
 * Site Health tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Ops;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Admin\Menu;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\Queue;

/**
 * Site Health tests (spec § 11.3). Also used by `wp meilisearch check`.
 *
 * @phpstan-type Result array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
 */
final class SiteHealth implements Registrable {

	/**
	 * Version marker when the admin key works but cannot read /version (index-scoped keys).
	 */
	public const VERSION_UNREADABLE = 'unreadable';


	public const DRIFT_THRESHOLD   = 0.02;
	public const BACKLOG_THRESHOLD = 500;

	public const TEST_CONNECTION = 'meilisearch_connection';
	public const TEST_DOCUMENTS  = 'meilisearch_documents';
	public const TEST_SETTINGS   = 'meilisearch_settings';
	public const TEST_SEARCH_KEY = 'meilisearch_search_key';
	public const TEST_QUEUE      = 'meilisearch_queue';
	public const TEST_REINDEX    = 'meilisearch_reindex';
	public const TEST_COVERAGE   = 'meilisearch_search_coverage';
	public const TEST_DISPLAYED  = 'meilisearch_displayed_attributes';
	public const TEST_SQL        = 'meilisearch_sql_filters';

	/**
	 * Memoized results for this request.
	 *
	 * @var list<Result>|null
	 */
	private ?array $results = null;

	/**
	 * Live settings read during this request: logical index => GET /settings response.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $live_settings = array();

	/**
	 * Wires the services.
	 *
	 * @param ClientFactory       $clients   Client factory.
	 * @param IndexManager        $indexes   Index manager.
	 * @param IndexNames          $names     Index names.
	 * @param Reindexer           $reindexer Reindexer.
	 * @param Queue               $queue     Sync queue.
	 * @param Options             $options   Options.
	 * @param Indexability        $indexability Indexability rule (which post types are indexed).
	 * @param SqlFilterAudit|null $sql_filters Finds other code on WP_Query's SQL filters.
	 */
	public function __construct(
		private readonly ClientFactory $clients,
		private readonly IndexManager $indexes,
		private readonly IndexNames $names,
		private readonly Reindexer $reindexer,
		private readonly Queue $queue,
		private readonly Options $options,
		private readonly Indexability $indexability,
		private readonly ?SqlFilterAudit $sql_filters = null
	) {}

	/**
	 * Registers the Site Health filter.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Adds the plugin's tests as direct tests.
	 *
	 * @param array<string, mixed> $tests Tests registered so far.
	 * @return array<string, mixed>
	 */
	public function add_tests( array $tests ): array {
		$direct = isset( $tests['direct'] ) && is_array( $tests['direct'] ) ? $tests['direct'] : array();
		foreach ( $this->test_labels() as $id => $label ) {
			$direct[ $id ] = array(
				'label' => $label,
				'test'  => fn (): array => $this->result( $id ),
			);
		}
		$tests['direct'] = $direct;

		return $tests;
	}

	/**
	 * Runs every applicable test once per instance.
	 *
	 * @return list<Result>
	 */
	public function run_all(): array {
		if ( null !== $this->results ) {
			return $this->results;
		}

		$connection_url = Menu::url( 'connection' );
		$status_url     = Menu::url( 'status' );
		$configured     = $this->options->is_configured();
		$version        = '';
		$error          = '';

		if ( $configured ) {
			try {
				$version = $this->indexes->server_version() ?? self::VERSION_UNREADABLE;
			} catch ( \Throwable $e ) {
				$error = $this->options->redact( $e->getMessage() );
			}
		}

		$results = array( self::evaluate_connection( $configured, $version, $error, $connection_url ) );

		if ( $configured ) {
			$usable    = '' === $error && ( self::VERSION_UNREADABLE === $version || version_compare( $version, IndexManager::MIN_VERSION, '>=' ) );
			$results[] = $this->checked( self::TEST_DOCUMENTS, $usable, fn (): array => self::evaluate_documents( $this->document_counts(), $status_url ) );
			$results[] = $this->checked( self::TEST_SETTINGS, $usable, fn (): array => self::evaluate_settings( $this->missing_settings(), $connection_url ) );
			$results[] = $this->checked( self::TEST_SEARCH_KEY, $usable, fn (): array => $this->search_key_result( $connection_url ) );
			$results[] = $this->checked( self::TEST_DISPLAYED, $usable, fn (): array => $this->displayed_result() );
			$results[] = self::evaluate_coverage( $this->options->search()['replace'], $this->indexability->unindexed_searchable_types(), Menu::url( 'content' ) );
			$results[] = $this->sql_filters_result();
		}

		$results[] = self::evaluate_queue( $this->queue->count( 'pending' ), $this->queue->count( 'failed' ), $status_url );
		$results[] = self::evaluate_reindex( $this->reindex_flags(), $this->failed_reindexes(), $status_url, $this->stalled_reindexes() );

		$this->results = $results;

		return $results;
	}

	/**
	 * Evaluates the connection and version.
	 *
	 * @param bool   $configured   Whether a host and admin key are set.
	 * @param string $version      Meilisearch version, '' when unknown.
	 * @param string $error        Error message of a failed request, '' when none.
	 * @param string $settings_url URL of the Connection tab.
	 * @return Result
	 */
	public static function evaluate_connection( bool $configured, string $version, string $error, string $settings_url ): array {
		if ( ! $configured ) {
			return self::make(
				self::TEST_CONNECTION,
				'recommended',
				__( 'Meilisearch is not connected yet', 'meilisearch' ),
				__( 'Enter the URL of your Meilisearch instance and an admin API key to start indexing your content. Until then, site search keeps using the WordPress database.', 'meilisearch' ),
				$settings_url
			);
		}

		if ( '' !== $error ) {
			return self::make(
				self::TEST_CONNECTION,
				'critical',
				__( 'Meilisearch cannot be reached', 'meilisearch' ),
				sprintf(
					/* translators: %s: error message returned by Meilisearch or by the HTTP request. */
					__( 'The last request to Meilisearch failed: %s. Search falls back to the WordPress database. Content changes are retried for several hours; if the connection is not back by then, the plugin asks for a full reindex.', 'meilisearch' ),
					$error
				),
				$settings_url
			);
		}

		if ( self::VERSION_UNREADABLE === $version ) {
			return self::make(
				self::TEST_CONNECTION,
				'good',
				__( 'Connected to Meilisearch', 'meilisearch' ),
				__( 'The plugin can reach your Meilisearch instance. The admin key is limited to some indexes, so it cannot read the Meilisearch version: make sure the server runs a supported version.', 'meilisearch' )
			);
		}

		if ( version_compare( $version, IndexManager::MIN_VERSION, '<' ) ) {
			return self::make(
				self::TEST_CONNECTION,
				'critical',
				__( 'Your Meilisearch version is not supported', 'meilisearch' ),
				sprintf(
					/* translators: 1: running Meilisearch version, 2: minimum supported Meilisearch version. */
					__( 'Meilisearch %1$s is running, but this plugin needs Meilisearch %2$s or later. Upgrade Meilisearch to keep search working.', 'meilisearch' ),
					'' === $version ? '?' : $version,
					IndexManager::MIN_VERSION
				),
				$settings_url
			);
		}

		return self::make(
			self::TEST_CONNECTION,
			'good',
			sprintf(
				/* translators: %s: Meilisearch version. */
				__( 'Connected to Meilisearch %s', 'meilisearch' ),
				esc_html( $version )
			),
			__( 'The plugin can reach your Meilisearch instance with the configured admin key.', 'meilisearch' )
		);
	}

	/**
	 * Compares document counts with what WordPress expects. 'indexable' is the SQL candidate count, an
	 * upper bound; 'excluded' is how many candidates the last completed reindex left out (hidden or
	 * out-of-stock products, posts vetoed by meilisearch_should_index_post). The index is in sync when
	 * it holds between indexable - excluded and indexable documents, within DRIFT_THRESHOLD.
	 *
	 * @param array<string, array{uid: string, documents: ?int, indexable: int, excluded?: int}> $counts Logical index => counts.
	 * @param string                                                                             $status_url URL of the Status tab.
	 * @return Result
	 */
	public static function evaluate_documents( array $counts, string $status_url ): array {
		$problems = array();
		foreach ( $counts as $count ) {
			if ( null === $count['documents'] ) {
				$problems[] = sprintf(
					/* translators: %s: index UID. */
					__( 'The index %s does not exist yet.', 'meilisearch' ),
					$count['uid']
				);
				continue;
			}
			// The nearest bound of [indexable - excluded, indexable]; the document count itself when inside.
			$lower    = max( 0, $count['indexable'] - max( 0, $count['excluded'] ?? 0 ) );
			$expected = max( $lower, min( $count['indexable'], $count['documents'] ) );
			if ( self::drift_ratio( $count['documents'], $expected ) > self::DRIFT_THRESHOLD ) {
				$problems[] = sprintf(
					/* translators: 1: index UID, 2: number of documents in Meilisearch, 3: number of searchable items in WordPress. */
					__( '%1$s holds %2$d documents but WordPress has %3$d searchable items.', 'meilisearch' ),
					$count['uid'],
					$count['documents'],
					$expected
				);
			}
		}//end foreach

		if ( array() === $problems ) {
			return self::make(
				self::TEST_DOCUMENTS,
				'good',
				__( 'Meilisearch has all your searchable content', 'meilisearch' ),
				__( 'Document counts in Meilisearch match the published content WordPress expects to find (within 2%).', 'meilisearch' )
			);
		}

		return self::make(
			self::TEST_DOCUMENTS,
			'recommended',
			__( 'Some content is missing from Meilisearch or out of date', 'meilisearch' ),
			implode( ' ', $problems ) . ' ' . __( 'Run a full reindex from the Status tab or with "wp meilisearch reindex".', 'meilisearch' ),
			$status_url
		);
	}

	/**
	 * Reports required index settings that are missing.
	 *
	 * @param array<string, list<string>> $missing Index UID => required attributes that are not configured.
	 * @param string                      $settings_url URL of the Connection tab.
	 * @return Result
	 */
	public static function evaluate_settings( array $missing, string $settings_url ): array {
		$lines = array();
		foreach ( $missing as $uid => $attributes ) {
			if ( array() !== $attributes ) {
				$lines[] = sprintf( '%1$s: %2$s.', $uid, implode( ', ', $attributes ) );
			}
		}

		if ( array() === $lines ) {
			return self::make(
				self::TEST_SETTINGS,
				'good',
				__( 'Meilisearch index settings are complete', 'meilisearch' ),
				__( 'Every attribute the plugin filters or sorts on is configured in Meilisearch.', 'meilisearch' )
			);
		}

		return self::make(
			self::TEST_SETTINGS,
			'recommended',
			__( 'Some required index settings are missing', 'meilisearch' ),
			__( 'Search filters and sorting need these attributes to be filterable or sortable:', 'meilisearch' ) . ' ' . implode( ' ', $lines ) . ' ' . __( 'Save the Connection tab to apply them again.', 'meilisearch' ),
			$settings_url
		);
	}

	/**
	 * Checks that the browser search key can only search this site's indexes.
	 *
	 * @param bool                      $autocomplete Whether autocomplete is enabled.
	 * @param string                    $key          Search key value (never printed).
	 * @param array<string, mixed>|null $details      GET /keys/{key} response, null when it could not be read.
	 * @param string[]                  $allowed_uids This site's index UIDs.
	 * @param string                    $error        Error message of the failed key lookup, '' when none.
	 * @param string                    $settings_url URL of the Connection tab.
	 * @param bool                      $unsafe       Whether IndexManager::inspect_search_key() found the key unsafe to serve.
	 * @return Result
	 */
	public static function evaluate_search_key( bool $autocomplete, string $key, ?array $details, array $allowed_uids, string $error, string $settings_url, bool $unsafe = false ): array {
		if ( '' === $key ) {
			if ( ! $autocomplete ) {
				return self::make(
					self::TEST_SEARCH_KEY,
					'good',
					__( 'No browser search key is in use', 'meilisearch' ),
					__( 'Autocomplete is disabled, so no Meilisearch key is sent to visitors\' browsers.', 'meilisearch' )
				);
			}

			return self::make(
				self::TEST_SEARCH_KEY,
				'recommended',
				__( 'Autocomplete has no search key', 'meilisearch' ),
				__( 'Autocomplete is enabled but no search key is stored, so the suggestions stay hidden. Save the Connection tab to create one, or paste a search-only key.', 'meilisearch' ),
				$settings_url
			);
		}

		if ( $unsafe && null === $details ) {
			return self::make(
				self::TEST_SEARCH_KEY,
				'critical',
				__( 'The browser search key is not a search-only key', 'meilisearch' ),
				sprintf(
					/* translators: %s: Meilisearch error code, or "none". */
					__( 'The stored search key is the admin or master key, is unknown to Meilisearch, or can call more than "search" (Meilisearch answer: %s). It is not sent to visitors. Save the Connection tab or paste a search-only key.', 'meilisearch' ),
					'' === $error ? __( 'none', 'meilisearch' ) : $error
				),
				$settings_url
			);
		}

		if ( null === $details ) {
			return self::make(
				self::TEST_SEARCH_KEY,
				'recommended',
				__( 'The browser search key could not be verified', 'meilisearch' ),
				sprintf(
					/* translators: %s: error message. */
					__( 'The admin key cannot read the details of the search key (%s). Check in Meilisearch that this key only allows the "search" action on this site\'s indexes, because every visitor can see it.', 'meilisearch' ),
					'' === $error ? __( 'unknown error', 'meilisearch' ) : $error
				),
				$settings_url
			);
		}

		$actions = array_values( array_map( 'strval', (array) ( $details['actions'] ?? array() ) ) );
		$indexes = array_values( array_map( 'strval', (array) ( $details['indexes'] ?? array() ) ) );

		if ( array( 'search' ) === $actions && array() !== $indexes && array() === array_diff( $indexes, $allowed_uids ) ) {
			return self::make(
				self::TEST_SEARCH_KEY,
				'good',
				__( 'The browser search key is restricted to search', 'meilisearch' ),
				sprintf(
					/* translators: %s: comma-separated index UIDs. */
					__( 'The key used by visitors\' browsers can only search %s.', 'meilisearch' ),
					implode( ', ', $indexes )
				)
			);
		}

		return self::make(
			self::TEST_SEARCH_KEY,
			'critical',
			__( 'The browser search key has too many permissions', 'meilisearch' ),
			sprintf(
				/* translators: 1: actions the key allows, 2: indexes the key allows, 3: this site's index UIDs. */
				__( 'Every visitor can read this key. It allows the actions [%1$s] on the indexes [%2$s], but it must allow only "search" on %3$s. Save the Connection tab to replace it with a restricted key.', 'meilisearch' ),
				implode( ', ', $actions ),
				implode( ', ', $indexes ),
				implode( ', ', $allowed_uids )
			),
			$settings_url
		);
	}

	/**
	 * Reports indexes that display every attribute while autocomplete hands visitors a key to search them.
	 * Indexes the plugin creates display only public fields; existing indexes are never changed.
	 *
	 * @param bool                        $autocomplete Whether autocomplete is enabled.
	 * @param array<string, list<string>> $exposed      Index UID => the attributes the plugin would display, for each index displaying ["*"].
	 * @return Result
	 */
	public static function evaluate_displayed( bool $autocomplete, array $exposed ): array {
		if ( ! $autocomplete || array() === $exposed ) {
			return self::make(
				self::TEST_DISPLAYED,
				'good',
				__( 'Visitors can only read public fields from Meilisearch', 'meilisearch' ),
				$autocomplete
					? __( 'The search key sent to visitors\' browsers only returns the public fields of your indexes.', 'meilisearch' )
					: __( 'Autocomplete is disabled, so no Meilisearch key is sent to visitors\' browsers.', 'meilisearch' )
			);
		}

		$lines = array();
		foreach ( $exposed as $uid => $fields ) {
			$lines[] = sprintf( '%1$s: %2$s.', $uid, implode( ', ', $fields ) );
		}

		return self::make(
			self::TEST_DISPLAYED,
			'recommended',
			__( 'Visitors can read every indexed field', 'meilisearch' ),
			sprintf(
				/* translators: %s: comma-separated index UIDs. */
				__( 'Autocomplete sends a search key to every visitor\'s browser, and %s still display every indexed field, so anyone can read data your site does not show, such as total sales, exact stock quantities, author IDs and custom field values. The plugin only lists public fields on the indexes it creates and never changes the settings of an existing index. Set the displayed attributes of each index in Meilisearch to the fields below, or delete the index and run a full reindex so that the plugin creates it again:', 'meilisearch' ),
				implode( ', ', array_keys( $exposed ) )
			) . ' ' . implode( ' ', $lines )
		);
	}

	/**
	 * Reports searchable post types that no index holds while "Replace site search" is on: searches
	 * that include them (the default theme search does) are never answered by Meilisearch.
	 *
	 * @param bool     $replace     Whether "Replace site search" is on.
	 * @param string[] $unindexed   Searchable post types that are not indexed.
	 * @param string   $content_url URL of the Content tab.
	 * @return Result
	 */
	public static function evaluate_coverage( bool $replace, array $unindexed, string $content_url ): array {
		if ( ! $replace || array() === $unindexed ) {
			return self::make(
				self::TEST_COVERAGE,
				'good',
				__( 'Meilisearch can answer your site searches', 'meilisearch' ),
				__( 'Every post type included in site searches is indexed, or Meilisearch search is turned off.', 'meilisearch' )
			);
		}

		return self::make(
			self::TEST_COVERAGE,
			'recommended',
			__( 'Some site searches are not answered by Meilisearch', 'meilisearch' ),
			sprintf(
				/* translators: %s: comma-separated post type names. */
				__( 'These post types are included in site searches but are not indexed: %s. Searches that include them, such as the default theme search, keep using the WordPress database. Index them in the Content tab (products in the WooCommerce tab).', 'meilisearch' ),
				implode( ', ', $unindexed )
			),
			$content_url
		);
	}

	/**
	 * Reports other code on WP_Query's SQL filters while "Replace site search" is on: searches answered by
	 * Meilisearch skip the SQL those callbacks build, so the constraints they add do not apply.
	 *
	 * @param bool     $replace    Whether "Replace site search" is on.
	 * @param string[] $callbacks  "hook: callback (owner)" for each foreign callback.
	 * @param string   $search_url URL of the Search tab.
	 * @return Result
	 */
	public static function evaluate_sql_filters( bool $replace, array $callbacks, string $search_url ): array {
		if ( ! $replace || array() === $callbacks ) {
			return self::make(
				self::TEST_SQL,
				'good',
				__( 'No other code changes the search queries Meilisearch answers', 'meilisearch' ),
				__( 'No plugin or theme adds SQL conditions to WordPress queries, or Meilisearch search is turned off.', 'meilisearch' )
			);
		}

		$listed = array_slice( array_values( $callbacks ), 0, SqlFilterAudit::MAX_LISTED );
		$more   = count( $callbacks ) - count( $listed );
		$list   = implode( '; ', $listed );
		if ( $more > 0 ) {
			$list .= '; ' . sprintf(
				/* translators: %d: number of other callbacks. */
				_n( '%d more', '%d more', $more, 'meilisearch' ),
				$more
			);
		}

		return self::make(
			self::TEST_SQL,
			'recommended',
			__( 'Other code adds SQL conditions that Meilisearch searches ignore', 'meilisearch' ),
			sprintf(
				/* translators: %s: semicolon-separated list of "filter: callback (plugin or theme)". */
				__( 'These callbacks change the SQL of WordPress queries: %s. Searches answered by Meilisearch do not run that SQL, so whatever these callbacks restrict (a language, members-only content, hidden items) does not apply to their results. If one of them must restrict search results, return false from the meilisearch_should_intercept filter for those searches so that WordPress answers them, or keep the posts out of the index with the meilisearch_should_index_post filter.', 'meilisearch' ),
				$list
			),
			$search_url
		);
	}

	/**
	 * Reports a backed-up or failing sync queue.
	 *
	 * @param int    $pending    Pending sync jobs.
	 * @param int    $failed     Failed sync jobs.
	 * @param string $status_url URL of the Status tab.
	 * @return Result
	 */
	public static function evaluate_queue( int $pending, int $failed, string $status_url ): array {
		$problems = array();
		if ( $failed > 0 ) {
			$problems[] = sprintf(
				/* translators: %d: number of failed sync jobs. */
				_n( '%d sync job failed after all retries.', '%d sync jobs failed after all retries.', $failed, 'meilisearch' ),
				$failed
			);
		}
		if ( $pending > self::BACKLOG_THRESHOLD ) {
			$problems[] = sprintf(
				/* translators: %d: number of pending sync jobs. */
				_n( '%d sync job is waiting to run.', '%d sync jobs are waiting to run.', $pending, 'meilisearch' ),
				$pending
			) . ' ' . __( 'Make sure WP-Cron (or a server cron calling wp-cron.php) runs regularly.', 'meilisearch' );
		}

		if ( array() === $problems ) {
			return self::make(
				self::TEST_QUEUE,
				'good',
				__( 'The Meilisearch sync queue is healthy', 'meilisearch' ),
				sprintf(
					/* translators: %d: number of pending sync jobs. */
					_n( '%d sync job is waiting and none failed.', '%d sync jobs are waiting and none failed.', $pending, 'meilisearch' ),
					$pending
				)
			);
		}

		return self::make(
			self::TEST_QUEUE,
			'recommended',
			$failed > 0 ? __( 'Some Meilisearch sync jobs failed', 'meilisearch' ) : __( 'The Meilisearch sync queue is backed up', 'meilisearch' ),
			implode( ' ', $problems ) . ' ' . __( 'Recent errors are listed in the Status tab.', 'meilisearch' ),
			$status_url
		);
	}

	/**
	 * Reports indexes that need a reindex or whose last run failed.
	 *
	 * @param array<string, bool>   $flags      Logical index => needs a reindex.
	 * @param array<string, string> $failed     Logical index => error of the last failed or abandoned reindex run.
	 * @param string                $status_url URL of the Status tab.
	 * @param string[]              $stalled    Logical indexes whose run stopped making progress.
	 * @return Result
	 */
	public static function evaluate_reindex( array $flags, array $failed, string $status_url, array $stalled = array() ): array {
		$problems = array();
		foreach ( $flags as $logical => $flag ) {
			if ( $flag ) {
				$problems[] = sprintf(
					/* translators: %s: index name (content or products). */
					__( 'The %s index needs a full reindex after a settings change.', 'meilisearch' ),
					$logical
				);
			}
		}
		foreach ( $failed as $logical => $message ) {
			$problems[] = sprintf(
				/* translators: 1: index name (content or products), 2: error message. */
				__( 'The last reindex of the %1$s index failed: %2$s', 'meilisearch' ),
				$logical,
				$message
			);
		}

		foreach ( $stalled as $logical ) {
			$problems[] = sprintf(
				/* translators: %s: index name (content or products). */
				__( 'The reindex of the %s index stopped making progress. Start it again from the Status tab.', 'meilisearch' ),
				$logical
			);
		}

		if ( array() === $problems ) {
			return self::make(
				self::TEST_REINDEX,
				'good',
				__( 'No Meilisearch reindex is needed', 'meilisearch' ),
				__( 'The indexes match the current plugin settings.', 'meilisearch' )
			);
		}

		return self::make(
			self::TEST_REINDEX,
			'recommended',
			__( 'A Meilisearch reindex is needed', 'meilisearch' ),
			implode( ' ', $problems ),
			$status_url
		);
	}

	/**
	 * Result of a check that could not run.
	 *
	 * @param string $test   Test id.
	 * @param string $reason Why the check could not run.
	 * @return Result
	 */
	public static function not_checked( string $test, string $reason ): array {
		$labels = self::labels();

		return self::make(
			$test,
			'recommended',
			sprintf(
				/* translators: %s: name of the Site Health check. */
				__( 'This check could not run: %s', 'meilisearch' ),
				$labels[ $test ] ?? $test
			),
			$reason
		);
	}

	/**
	 * Relative difference between indexed and indexable counts.
	 *
	 * @param int $documents Documents in Meilisearch.
	 * @param int $indexable Items WordPress would index.
	 * @return float
	 */
	public static function drift_ratio( int $documents, int $indexable ): float {
		return abs( $documents - $indexable ) / max( 1, $indexable );
	}

	/**
	 * Labels of every test.
	 *
	 * @return array<string, string> Test id => label.
	 */
	private static function labels(): array {
		return array(
			self::TEST_CONNECTION => __( 'Meilisearch connection', 'meilisearch' ),
			self::TEST_DOCUMENTS  => __( 'Meilisearch document counts', 'meilisearch' ),
			self::TEST_SETTINGS   => __( 'Meilisearch index settings', 'meilisearch' ),
			self::TEST_SEARCH_KEY => __( 'Meilisearch browser search key', 'meilisearch' ),
			self::TEST_QUEUE      => __( 'Meilisearch sync queue', 'meilisearch' ),
			self::TEST_REINDEX    => __( 'Meilisearch reindex status', 'meilisearch' ),
			self::TEST_COVERAGE   => __( 'Meilisearch search coverage', 'meilisearch' ),
			self::TEST_DISPLAYED  => __( 'Meilisearch fields visible to visitors', 'meilisearch' ),
			self::TEST_SQL        => __( 'Meilisearch and SQL query filters', 'meilisearch' ),
		);
	}

	/**
	 * Tests that apply to the current configuration (no remote call).
	 *
	 * @return array<string, string> Test id => label.
	 */
	private function test_labels(): array {
		$labels = self::labels();
		if ( $this->options->is_configured() ) {
			return $labels;
		}

		return array_intersect_key( $labels, array_flip( array( self::TEST_CONNECTION, self::TEST_QUEUE, self::TEST_REINDEX ) ) );
	}

	/**
	 * Returns the memoized result of one test.
	 *
	 * @param string $test Test id.
	 * @return Result
	 */
	private function result( string $test ): array {
		foreach ( $this->run_all() as $result ) {
			if ( $test === $result['test'] ) {
				return $result;
			}
		}

		return self::not_checked( $test, __( 'This check does not apply to the current configuration.', 'meilisearch' ) );
	}

	/**
	 * Runs a remote-dependent check, or reports that it could not run.
	 *
	 * @param string   $test   Test id.
	 * @param bool     $usable Whether Meilisearch is reachable and supported.
	 * @param callable $check  Check to run, returns a Result.
	 * @return Result Result of the check or of not_checked().
	 */
	private function checked( string $test, bool $usable, callable $check ): array {
		if ( ! $usable ) {
			return self::not_checked( $test, __( 'Meilisearch must be reachable and up to date for this check.', 'meilisearch' ) );
		}

		try {
			return $check();
		} catch ( \Throwable $e ) {
			return self::not_checked( $test, $this->options->redact( $e->getMessage() ) );
		}
	}

	/**
	 * Counts documents in Meilisearch and indexable items in WordPress.
	 *
	 * @return array<string, array{uid: string, documents: ?int, indexable: int, excluded: int}>
	 * @throws ApiError On errors other than a missing index.
	 */
	private function document_counts(): array {
		$client = $this->clients->client();
		$counts = array();
		foreach ( $this->names->active_logicals() as $logical ) {
			$uid       = $this->names->uid( $logical );
			$documents = null;
			try {
				$stats     = $client->index_stats( $uid );
				$documents = (int) ( $stats['numberOfDocuments'] ?? 0 );
			} catch ( ApiError $e ) {
				if ( 'index_not_found' !== $e->error_code ) {
					throw $e;
				}
			}
			$counts[ $logical ] = array(
				'uid'       => $uid,
				'documents' => $documents,
				'indexable' => $this->reindexer->count_indexable( $logical ),
				'excluded'  => $this->reindexer->excluded_count( $logical ),
			);
		}

		return $counts;
	}

	/**
	 * Lists required settings missing from each live index.
	 *
	 * @return array<string, list<string>>
	 * @throws ApiError On errors other than a missing index.
	 */
	private function missing_settings(): array {
		$missing = array();
		foreach ( $this->names->active_logicals() as $logical ) {
			try {
				$missing[ $this->names->uid( $logical ) ] = $this->indexes->drift( $logical, $this->settings_of( $logical ) );
			} catch ( ApiError $e ) {
				// A missing index is reported by the document-count test.
				if ( 'index_not_found' !== $e->error_code ) {
					throw $e;
				}
			}
		}

		return $missing;
	}

	/**
	 * Evaluates the callbacks other code attaches to WP_Query's SQL filters (no remote call).
	 *
	 * @return Result
	 */
	private function sql_filters_result(): array {
		$replace = $this->options->search()['replace'];
		return self::evaluate_sql_filters(
			$replace,
			$replace ? ( $this->sql_filters ?? new SqlFilterAudit() )->foreign_callbacks() : array(),
			Menu::url( 'search' )
		);
	}

	/**
	 * Evaluates what the browser search key can read; the indexes are only read when autocomplete is on.
	 *
	 * @return Result
	 */
	private function displayed_result(): array {
		$autocomplete = $this->options->search()['autocomplete'];
		return self::evaluate_displayed( $autocomplete, $autocomplete ? $this->fully_displayed() : array() );
	}

	/**
	 * Index UID => attributes the plugin displays, for each live index that displays every attribute.
	 *
	 * @return array<string, list<string>>
	 * @throws ApiError On errors other than a missing index.
	 */
	private function fully_displayed(): array {
		$exposed = array();
		foreach ( $this->names->active_logicals() as $logical ) {
			try {
				$settings = $this->settings_of( $logical );
			} catch ( ApiError $e ) {
				// A missing index is reported by the document-count test.
				if ( 'index_not_found' !== $e->error_code ) {
					throw $e;
				}
				continue;
			}
			if ( SettingsBuilder::displays_everything( $settings ) ) {
				$exposed[ $this->names->uid( $logical ) ] = $this->indexes->displayed_attributes( $logical );
			}
		}

		return $exposed;
	}

	/**
	 * Live settings of an index, read once per request.
	 *
	 * @param string $logical Logical index.
	 * @return array<string, mixed>
	 * @throws ApiError On Meilisearch errors.
	 */
	private function settings_of( string $logical ): array {
		if ( ! isset( $this->live_settings[ $logical ] ) ) {
			$this->live_settings[ $logical ] = $this->indexes->live_settings( $logical );
		}

		return $this->live_settings[ $logical ];
	}

	/**
	 * Evaluates the stored browser search key.
	 *
	 * @param string $connection_url URL of the Connection tab.
	 * @return Result
	 */
	private function search_key_result( string $connection_url ): array {
		$key     = $this->options->search_key();
		$details = null;
		$error   = '';
		$unsafe  = false;
		if ( '' !== $key ) {
			$inspection = $this->indexes->inspect_search_key( $key );
			$details    = $inspection['details'];
			$unsafe     = false === $inspection['verified'];
			if ( '' !== $inspection['error'] ) {
				$error = sprintf(
					/* translators: %s: Meilisearch error code. */
					__( 'Meilisearch refused the key lookup (%s)', 'meilisearch' ),
					$inspection['error']
				);
			}
		}

		return self::evaluate_search_key(
			$this->options->search()['autocomplete'],
			$key,
			$details,
			array( $this->names->uid( 'content' ), $this->names->uid( 'products' ) ),
			$error,
			$connection_url,
			$unsafe
		);
	}

	/**
	 * Logical index => needs a reindex.
	 *
	 * @return array<string, bool>
	 */
	private function reindex_flags(): array {
		$flags = array();
		foreach ( $this->names->active_logicals() as $logical ) {
			$flags[ $logical ] = $this->options->needs_reindex( $logical );
		}

		return $flags;
	}

	/**
	 * Logical index => error of the last failed or abandoned reindex run.
	 *
	 * @return array<string, string>
	 */
	private function failed_reindexes(): array {
		$failed = array();
		foreach ( $this->names->active_logicals() as $logical ) {
			$state = $this->reindexer->status( $logical );
			if ( is_array( $state ) && 'failed' === ( $state['status'] ?? '' ) ) {
				$failed[ $logical ] = $this->options->redact( (string) ( $state['error'] ?? '' ) );
			}
		}

		return $failed;
	}

	/**
	 * Logical indexes whose run is marked running but abandoned.
	 *
	 * @return string[]
	 */
	private function stalled_reindexes(): array {
		$stalled = array();
		foreach ( $this->names->active_logicals() as $logical ) {
			if ( $this->reindexer->is_stalled( $logical ) ) {
				$stalled[] = $logical;
			}
		}

		return $stalled;
	}

	/**
	 * Builds a Site Health result. The description is escaped here, once.
	 *
	 * @param string $test        Test id.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Result title.
	 * @param string $description Plain-text description.
	 * @param string $action_url  URL of the action link, '' for none.
	 * @return Result
	 */
	private static function make( string $test, string $status, string $label, string $description, string $action_url = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Search', 'meilisearch' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '' === $action_url ? '' : sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $action_url ),
				esc_html__( 'Open the Meilisearch settings', 'meilisearch' )
			),
			'test'        => $test,
		);
	}
}
