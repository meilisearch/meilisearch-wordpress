<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Ops\Cli;
use Meilisearch\WordPress\Ops\SiteHealth;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Search\CircuitBreaker;
use Meilisearch\WordPress\Search\Interceptor;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Sync\SyncJob;
use Meilisearch\WordPress\Tests\Integration\Support\CliExit;
use Meilisearch\WordPress\Tests\Integration\Support\ShimProgressBar;
use WP_CLI;

final class CliTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once __DIR__ . '/Support/wp-cli-shim.php';
	}

	public function set_up(): void {
		parent::set_up();
		WP_CLI::reset();
	}

	/**
	 * @template T of object
	 * @param class-string<T> $type
	 * @return T
	 */
	private function service( string $id, string $type ): object {
		$plugin = Plugin::instance();
		self::assertNotNull( $plugin );
		$service = $plugin->get( $id );
		self::assertInstanceOf( $type, $service );

		return $service;
	}

	/**
	 * A fresh command object with a fresh (non-memoized) SiteHealth.
	 */
	private function cli(): Cli {
		$health = new SiteHealth(
			$this->service( 'clients', ClientFactory::class ),
			$this->service( 'index_manager', IndexManager::class ),
			$this->service( 'names', IndexNames::class ),
			$this->service( 'reindexer', Reindexer::class ),
			$this->service( 'queue', Queue::class ),
			$this->service( 'options', Options::class ),
			$this->service( 'indexability', Indexability::class )
		);

		return new Cli(
			$this->service( 'reindexer', Reindexer::class ),
			$this->service( 'sync_job', SyncJob::class ),
			$this->service( 'clients', ClientFactory::class ),
			$this->service( 'names', IndexNames::class ),
			$health,
			$this->service( 'indexability', Indexability::class ),
			$this->service( 'queue', Queue::class ),
			$this->service( 'options', Options::class ),
			$this->service( 'index_manager', IndexManager::class )
		);
	}

	private function hits( string $query ): int {
		$uid    = $this->service( 'names', IndexNames::class )->uid( 'content' );
		$result = $this->service( 'clients', ClientFactory::class )->client()->search(
			$uid,
			array(
				'q'     => $query,
				'limit' => 50,
			)
		);

		return count( (array) ( $result['hits'] ?? array() ) );
	}

	private function documents(): int {
		$uid   = $this->service( 'names', IndexNames::class )->uid( 'content' );
		$stats = $this->service( 'clients', ClientFactory::class )->client()->index_stats( $uid );

		return (int) ( $stats['numberOfDocuments'] ?? 0 );
	}

	/**
	 * @return list<string>
	 */
	private function messages( string $type ): array {
		$messages = array();
		foreach ( WP_CLI::$calls as $call ) {
			if ( $type === $call[0] ) {
				$messages[] = $call[1];
			}
		}

		return $messages;
	}

	private function expect_exit( callable $command, int $code ): void {
		try {
			$command();
			self::fail( 'Expected the command to exit.' );
		} catch ( CliExit $exit ) {
			self::assertSame( $code, $exit->getCode() );
		}
	}

	public function test_failed_connect_forgets_the_last_successful_connection(): void {
		if ( defined( 'MEILISEARCH_ADMIN_KEY' ) ) {
			self::markTestSkipped( 'The admin key is defined as a constant.' );
		}
		$options = $this->service( 'options', Options::class );
		$options->set_state(
			'last_connect',
			array(
				'version' => '1.0.0',
				'time'    => 1,
			)
		);
		$original = (string) get_option( Options::ADMIN_KEY, '' );
		update_option( Options::ADMIN_KEY, 'not-a-valid-key' );

		try {
			$this->expect_exit( fn() => $this->cli()->connect( array(), array() ), 1 );
		} finally {
			update_option( Options::ADMIN_KEY, $original );
		}

		self::assertStringContainsString( 'Could not connect to Meilisearch', implode( "\n", $this->messages( 'error' ) ) );
		self::assertNull( $options->state( 'last_connect' ), 'A rejected attempt must not leave "Connected" on the Connection screen.' );
	}

	public function test_connect_creates_indexes_and_the_search_key_and_records_last_connect(): void {
		$options = $this->service( 'options', Options::class );
		self::assertSame( '', $options->search_key() );

		$this->cli()->connect( array(), array() );

		$success = implode( "\n", $this->messages( 'success' ) );
		self::assertMatchesRegularExpression( '/Connected to Meilisearch \d+\.\d+/', $success );
		self::assertStringContainsString( 'search-only key', $success );
		self::assertNotSame( '', $options->search_key() );
		self::assertTrue( $options->search_key_is_verified() );
		$last = $options->state( 'last_connect' );
		self::assertIsArray( $last );
		self::assertNotSame( '', (string) ( $last['version'] ?? '' ) );
		self::assertSame( $options->connection_fingerprint( $this->service( 'names', IndexNames::class )->prefix() ), $last['fingerprint'] ?? null, 'The Connection screen shows "Connected" only for the credentials that were tested.' );
		$uid = $this->service( 'names', IndexNames::class )->uid( 'content' );
		self::assertSame( $uid, $this->meili( 'GET', '/indexes/' . $uid )['uid'] ?? null );

		$this->meili( 'DELETE', '/keys/' . (string) get_option( 'meilisearch_connection' )['search_key_uid'] );
	}

	public function test_connect_twice_keeps_the_key(): void {
		$this->cli()->connect( array(), array() );
		$first = $this->service( 'options', Options::class )->search_key();
		WP_CLI::reset();

		$this->cli()->connect( array(), array() );

		self::assertSame( $first, $this->service( 'options', Options::class )->search_key() );
		self::assertStringContainsString( 'Kept', implode( "\n", $this->messages( 'success' ) ) );
		$this->meili( 'DELETE', '/keys/' . (string) get_option( 'meilisearch_connection' )['search_key_uid'] );
	}

	/**
	 * Sites whose admin key cannot manage keys provide a search-only key themselves: connect verifies it,
	 * as saving the Connection screen does, so autocomplete can serve it.
	 */
	public function test_connect_verifies_a_manually_provided_search_key(): void {
		$uid                      = $this->service( 'names', IndexNames::class )->uid( 'content' );
		$key                      = $this->meili(
			'POST',
			'/keys',
			array(
				'actions'   => array( 'search' ),
				'indexes'   => array( $uid ),
				'expiresAt' => null,
			)
		);
		$connection               = get_option( 'meilisearch_connection' );
		$connection['search_key'] = (string) $key['key'];
		update_option( 'meilisearch_connection', $connection, false );
		$this->keep_connection_as_is();

		$this->cli()->connect( array(), array() );

		self::assertTrue( $this->service( 'options', Options::class )->search_key_is_verified() );
		self::assertStringContainsString( 'search-only key you provided is verified', implode( "\n", $this->messages( 'success' ) ) );
		$this->meili( 'DELETE', '/keys/' . (string) $key['uid'] );
	}

	/**
	 * A connection that already ran with this host, key and prefix and whose search key is managed by hand:
	 * connect keeps the key instead of creating one (the test admin key could create keys).
	 */
	private function keep_connection_as_is(): void {
		$options = $this->service( 'options', Options::class );
		$options->set_state( 'search_key_manual', true );
		$options->set_state( 'fingerprint', md5( $options->host() . '|' . $options->admin_key() . '|' . $this->service( 'names', IndexNames::class )->prefix() ) );
	}

	public function test_connect_refuses_to_serve_the_admin_key_as_a_manual_search_key(): void {
		$connection               = get_option( 'meilisearch_connection' );
		$connection['search_key'] = self::test_key();
		update_option( 'meilisearch_connection', $connection, false );
		$this->keep_connection_as_is();

		$this->cli()->connect( array(), array() );

		self::assertFalse( $this->service( 'options', Options::class )->search_key_is_verified() );
		self::assertStringContainsString( 'not a search-only key', implode( "\n", $this->messages( 'warning' ) ) );
	}

	/**
	 * An admin key scoped to this site's indexes (Meilisearch refuses the global `version` action on such
	 * keys) still connects: the version is reported as not readable.
	 */
	public function test_connect_works_with_an_index_scoped_admin_key(): void {
		$prefix = $this->service( 'names', IndexNames::class )->prefix();
		$scoped = $this->meili(
			'POST',
			'/keys',
			array(
				'actions'   => array( 'search', 'documents.*', 'indexes.*', 'settings.*', 'tasks.*', 'stats.*' ),
				'indexes'   => array( $prefix . '_*' ),
				'expiresAt' => null,
			)
		);
		update_option( 'meilisearch_admin_key', (string) $scoped['key'], false );

		$this->cli()->connect( array(), array() );

		self::assertStringContainsString( 'version not readable with this key', implode( "\n", $this->messages( 'success' ) ) );
		$uid = $this->service( 'names', IndexNames::class )->uid( 'content' );
		self::assertSame( $uid, $this->meili( 'GET', '/indexes/' . $uid )['uid'] ?? null );
		$this->meili( 'DELETE', '/keys/' . (string) $scoped['uid'] );
	}

	public function test_connect_exits_1_with_a_redacted_message_when_meilisearch_is_unreachable(): void {
		$connection         = get_option( 'meilisearch_connection' );
		$connection['host'] = 'http://127.0.0.1:9';
		update_option( 'meilisearch_connection', $connection, false );

		$this->expect_exit( fn () => $this->cli()->connect( array(), array() ), 1 );

		$error = $this->messages( 'error' )[0] ?? '';
		self::assertStringContainsString( 'Could not connect to Meilisearch', $error );
		self::assertStringNotContainsString( self::test_key(), $error );
	}

	public function test_reindex_indexes_published_posts_and_reports_progress(): void {
		self::factory()->post->create_many(
			3,
			array(
				'post_status' => 'publish',
				'post_title'  => 'Clitestalpha entry',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Clitestalpha draft',
			)
		);

		$this->cli()->reindex(
			array(),
			array(
				'index'      => 'content',
				'batch-size' => '2',
			)
		);

		self::assertSame( 3, $this->hits( 'Clitestalpha' ) );
		$bar = WP_CLI::$bars[0] ?? null;
		self::assertInstanceOf( ShimProgressBar::class, $bar );
		self::assertTrue( $bar->finished );
		self::assertSame( $this->service( 'reindexer', Reindexer::class )->count_indexable( 'content' ), $bar->ticks );
		self::assertStringContainsString( 'Reindexed', $this->messages( 'success' )[0] ?? '' );
	}

	public function test_reindex_with_vetoed_posts_completes_the_bar_and_status_shows_the_expected_count(): void {
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		$vetoed = self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		add_filter(
			'meilisearch_should_index_post',
			static fn ( $index, $post ) => in_array( (int) $post->ID, $vetoed, true ) ? false : $index,
			10,
			2
		);
		$candidates = $this->service( 'reindexer', Reindexer::class )->count_indexable( 'content' );

		$this->cli()->reindex( array(), array( 'index' => 'content' ) );

		$bar = WP_CLI::$bars[0] ?? null;
		self::assertInstanceOf( ShimProgressBar::class, $bar );
		self::assertSame( $candidates, $bar->ticks, 'Every candidate post was scanned, so the bar reaches its total.' );
		self::assertStringContainsString( ( $candidates - 2 ) . ' document(s) sent', $this->messages( 'success' )[0] ?? '' );

		WP_CLI::reset();
		$this->cli()->status( array(), array( 'format' => 'json' ) );
		$row = array_column( WP_CLI::$items[0]['items'], null, 'index' )['content'];
		self::assertSame( (string) ( $candidates - 2 ), $row['indexable'] );
		self::assertSame( (string) ( $candidates - 2 ), $row['documents'] );
	}

	/**
	 * Runs `wp meilisearch reindex --index=content` with these extra arguments and counts the
	 * add-documents requests sent to the content index.
	 *
	 * @param array<string, string> $assoc_args Extra arguments.
	 */
	private function reindex_requests( array $assoc_args ): int {
		$uid      = $this->service( 'names', IndexNames::class )->uid( 'content' );
		$requests = 0;
		$count    = static function ( $response, $context, $transport, $args, $url ) use ( $uid, &$requests ): void {
			if ( 'POST' === ( $args['method'] ?? '' ) && str_contains( (string) $url, '/indexes/' . $uid . '/documents?' ) ) {
				++$requests;
			}
		};
		add_action( 'http_api_debug', $count, 10, 5 );
		try {
			$this->cli()->reindex( array(), array( 'index' => 'content' ) + $assoc_args );
		} finally {
			remove_action( 'http_api_debug', $count, 10 );
		}
		return $requests;
	}

	public function test_reindex_batch_size_defaults_to_the_filter_and_the_flag_overrides_it(): void {
		$posts = $this->service( 'reindexer', Reindexer::class )->count_indexable( 'content' ) + 3;
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		add_filter( 'meilisearch_reindex_batch_size', static fn (): int => 1 );

		self::assertSame( $posts, $this->reindex_requests( array() ), 'Without --batch-size, the filter sets the batch size.' );
		self::assertSame( 1, $this->reindex_requests( array( 'batch-size' => (string) $posts ) ), '--batch-size overrides the filter.' );
	}

	public function test_reindex_rejects_an_unknown_index(): void {
		$this->expect_exit( fn () => $this->cli()->reindex( array(), array( 'index' => 'orders' ) ), 1 );

		self::assertStringContainsString( 'Unknown or inactive index', $this->messages( 'error' )[0] ?? '' );
	}

	public function test_reindex_rejects_a_non_positive_batch_size(): void {
		$this->expect_exit( fn () => $this->cli()->reindex( array(), array( 'batch-size' => '0' ) ), 1 );

		self::assertStringContainsString( '--batch-size', $this->messages( 'error' )[0] ?? '' );
	}

	public function test_sync_reconciles_posts_immediately(): void {
		$this->cli()->reindex( array(), array( 'index' => 'content' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Clitestbravo',
			)
		);

		$this->cli()->sync( array( (string) $post_id ), array() );
		$this->wait_for_tasks();
		self::assertSame( 1, $this->hits( 'Clitestbravo' ) );
		self::assertStringContainsString( '1 upserted', (string) end( WP_CLI::$calls )[1] );

		wp_trash_post( $post_id );
		$this->cli()->sync( array( (string) $post_id ), array() );
		$this->wait_for_tasks();
		self::assertSame( 0, $this->hits( 'Clitestbravo' ) );
		self::assertStringContainsString( '1 deleted', (string) end( WP_CLI::$calls )[1] );
	}

	public function test_sync_rejects_an_invalid_id(): void {
		$this->expect_exit( fn () => $this->cli()->sync( array( '12abc' ), array() ), 1 );

		self::assertStringContainsString( 'not a valid post ID', $this->messages( 'error' )[0] ?? '' );
	}

	public function test_clear_asks_for_confirmation_then_deletes_documents(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Clitestcharlie',
			)
		);
		$this->cli()->reindex( array(), array( 'index' => 'content' ) );

		WP_CLI::$confirm = false;
		$this->expect_exit( fn () => $this->cli()->clear( array(), array( 'index' => 'content' ) ), 0 );
		self::assertSame( 1, $this->hits( 'Clitestcharlie' ) );

		$this->cli()->clear(
			array(),
			array(
				'index' => 'content',
				'yes'   => true,
			)
		);
		self::assertSame( 0, $this->documents() );
	}

	public function test_clear_hands_search_back_to_the_database_until_the_next_reindex(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Clitestfoxtrot',
			)
		);
		update_option( Options::SEARCH, array_merge( Options::defaults()[ Options::SEARCH ], array( 'replace' => true ) ) );
		delete_transient( CircuitBreaker::TRANSIENT );
		$options = $this->service( 'options', Options::class );
		// Fill every active index: with WooCommerce a plain ?s= search spans content and products, and is only intercepted once both are populated.
		$this->cli()->reindex( array(), array() );

		$this->go_to( home_url( '/?s=Clitestfoxtrot' ) );
		self::assertTrue( $GLOBALS['wp_query']->get( Interceptor::QUERY_FLAG ), 'Precondition: Meilisearch answers.' );
		self::assertCount( 1, $GLOBALS['wp_query']->posts );

		$this->cli()->clear(
			array(),
			array(
				'index' => 'content',
				'yes'   => true,
			)
		);
		self::assertSame( 0, $this->documents() );
		self::assertFalse( $options->is_populated( 'content' ) );
		self::assertTrue( $options->needs_reindex( 'content' ), 'The admin is told the index needs a reindex.' );

		$this->go_to( home_url( '/?s=Clitestfoxtrot' ) );
		self::assertNotTrue( $GLOBALS['wp_query']->get( Interceptor::QUERY_FLAG ), 'The emptied index does not answer.' );
		self::assertCount( 1, $GLOBALS['wp_query']->posts, 'The database finds the post.' );

		$this->cli()->reindex( array(), array( 'index' => 'content' ) );
		$this->go_to( home_url( '/?s=Clitestfoxtrot' ) );
		self::assertTrue( $GLOBALS['wp_query']->get( Interceptor::QUERY_FLAG ), 'A completed reindex hands search back to Meilisearch.' );
		self::assertCount( 1, $GLOBALS['wp_query']->posts );
		self::assertFalse( $options->needs_reindex( 'content' ) );
	}

	public function test_clear_is_refused_while_a_reindex_runs(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Clitestgolf',
			)
		);
		$this->cli()->reindex( array(), array( 'index' => 'content' ) );
		$this->service( 'reindexer', Reindexer::class )->start( 'content' );
		WP_CLI::reset();

		$this->expect_exit(
			fn () => $this->cli()->clear(
				array(),
				array(
					'index' => 'content',
					'yes'   => true,
				)
			),
			1
		);

		self::assertStringContainsString( 'is running', $this->messages( 'error' )[0] ?? '' );
		self::assertSame( 1, $this->hits( 'Clitestgolf' ) );
		self::assertTrue( $this->service( 'options', Options::class )->is_populated( 'content' ) );
	}

	public function test_status_lists_every_active_index(): void {
		$this->cli()->reindex( array(), array( 'index' => 'content' ) );
		WP_CLI::reset();

		$this->cli()->status( array(), array( 'format' => 'json' ) );

		$names = $this->service( 'names', IndexNames::class );
		$table = WP_CLI::$items[0];
		self::assertSame( 'json', $table['format'] );
		self::assertSame( array( 'index', 'uid', 'documents', 'indexable', 'status', 'phase', 'sent', 'total', 'deleted', 'queue_pending', 'queue_failed' ), $table['fields'] );
		self::assertContains( $table['items'][0]['status'], array( 'done', 'idle' ) );
		self::assertSame( $names->active_logicals(), array_column( $table['items'], 'index' ) );
		self::assertSame( $names->uid( 'content' ), $table['items'][0]['uid'] );
		self::assertMatchesRegularExpression( '/^\d+$/', $table['items'][0]['documents'] );
		self::assertSame( array( 'queue_pending', 'queue_failed' ), array_slice( $table['fields'], -2 ) );
		self::assertMatchesRegularExpression( '/^\d+$/', $table['items'][0]['queue_pending'] );
		// Machine-readable formats: format_items() output is the only thing on stdout.
		self::assertSame( array(), WP_CLI::$calls );
		self::assertNotNull( json_decode( (string) wp_json_encode( $table['items'] ), true ) );
	}

	public function test_status_table_keeps_the_banner_and_the_queue_line(): void {
		$this->cli()->status( array(), array() );

		self::assertSame( 'table', WP_CLI::$items[0]['format'] );
		self::assertCount( 9, WP_CLI::$items[0]['fields'] );
		self::assertStringStartsWith( 'Meilisearch ', $this->messages( 'log' )[0] ?? '' );
		self::assertNotEmpty( array_filter( $this->messages( 'log' ), static fn ( string $m ): bool => str_starts_with( $m, 'Queue:' ) ) );
	}

	/**
	 * Makes every document request fail with a 400 whose message echoes the admin key.
	 */
	private function fail_documents_requests(): \Closure {
		$filter = function ( $pre, array $args, string $url ) {
			if ( ! str_contains( $url, '/documents' ) ) {
				return $pre;
			}

			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					array(
						'message' => 'API key `' . self::test_key() . '` not found',
						'code'    => 'invalid_api_key',
						'type'    => 'auth',
						'link'    => 'https://docs.meilisearch.com',
					)
				),
				'response' => array(
					'code'    => 400,
					'message' => 'Bad Request',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		return fn () => remove_filter( 'pre_http_request', $filter, 10 );
	}

	private function assert_clean_failure(): void {
		$errors = $this->messages( 'error' );
		self::assertCount( 1, $errors );
		self::assertStringNotContainsString( self::test_key(), implode( "\n", $errors ) );
		self::assertDoesNotMatchRegularExpression( '/Stack trace|#0 |\.php:\d+/', implode( "\n", $errors ) );
	}

	public function test_reindex_failure_messages_for_each_branch(): void {
		$method = new \ReflectionMethod( Cli::class, 'reindex_failure' );
		$cli    = $this->cli();

		$running  = (string) $method->invoke( $cli, 'idx', new \RuntimeException( 'already_running' ) );
		$replaced = (string) $method->invoke( $cli, 'idx', new \RuntimeException( 'superseded' ) );
		$generic  = (string) $method->invoke( $cli, 'idx', new \RuntimeException( 'boom ' . self::test_key() ) );

		self::assertStringContainsString( 'already running', $running );
		self::assertStringContainsString( 'superseded', $replaced );
		self::assertStringContainsString( 'boom', $generic );
		self::assertStringNotContainsString( self::test_key(), $generic );
	}

	public function test_reindex_exits_1_when_a_run_is_already_active(): void {
		$this->service( 'options', Options::class )->set_reindex_state(
			'content',
			array(
				'run'        => 'abc',
				'phase'      => 'upsert',
				'last_id'    => 0,
				'sent'       => 0,
				'deleted'    => 0,
				'total'      => 1,
				'task_uids'  => array(),
				'started_at' => time(),
				'updated_at' => time(),
				'status'     => 'running',
				'error'      => '',
			)
		);

		$this->expect_exit( fn () => $this->cli()->reindex( array(), array( 'index' => 'content' ) ), 1 );

		self::assertStringContainsString( 'already running', $this->messages( 'error' )[0] ?? '' );
		$this->assert_clean_failure();
	}

	public function test_reindex_exits_1_without_printing_the_key_when_meilisearch_rejects_documents(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$restore = $this->fail_documents_requests();

		try {
			$this->expect_exit( fn () => $this->cli()->reindex( array(), array( 'index' => 'content' ) ), 1 );
		} finally {
			$restore();
		}

		self::assertStringContainsString( 'failed', $this->messages( 'error' )[0] ?? '' );
		$this->assert_clean_failure();
	}

	public function test_clear_exits_1_without_printing_the_key_on_an_api_error(): void {
		$restore = $this->fail_documents_requests();

		try {
			$this->expect_exit(
				fn () => $this->cli()->clear(
					array(),
					array(
						'index' => 'content',
						'yes'   => true,
					)
				),
				1
			);
		} finally {
			$restore();
		}

		self::assertStringContainsString( 'Could not clear', $this->messages( 'error' )[0] ?? '' );
		$this->assert_clean_failure();
	}

	public function test_sync_exits_1_without_printing_the_key_on_an_api_error(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$restore = $this->fail_documents_requests();

		try {
			$this->expect_exit( fn () => $this->cli()->sync( array( (string) $post_id ), array() ), 1 );
		} finally {
			$restore();
		}

		self::assertStringContainsString( 'Sync of', $this->messages( 'error' )[0] ?? '' );
		$this->assert_clean_failure();
	}

	public function test_check_passes_without_critical_results_on_a_healthy_site(): void {
		$this->service( 'index_manager', IndexManager::class )->connect();
		$this->cli()->reindex( array(), array() );

		$this->cli()->check( array(), array() );

		self::assertSame( array(), $this->messages( 'error' ) );
		self::assertSame( array( 'test', 'status', 'label' ), WP_CLI::$items[0]['fields'] );
	}

	public function test_check_exits_with_status_1_when_meilisearch_is_unreachable(): void {
		if ( defined( 'MEILISEARCH_HOST' ) ) {
			self::markTestSkipped( 'MEILISEARCH_HOST overrides the host option.' );
		}
		update_option( Options::CONNECTION, array_merge( (array) get_option( Options::CONNECTION, array() ), array( 'host' => 'http://127.0.0.1:1' ) ) );

		$this->expect_exit( fn () => $this->cli()->check( array(), array() ), 1 );

		self::assertStringContainsString( 'critical', $this->messages( 'error' )[0] ?? '' );
	}

	public function test_status_never_prints_the_admin_key_from_a_failed_run(): void {
		$options = $this->service( 'options', Options::class );
		$options->set_reindex_state(
			'content',
			array(
				'run'    => 'abc',
				'phase'  => 'upsert',
				'sent'   => 1,
				'total'  => 2,
				'status' => 'failed',
				'error'  => 'API key `' . self::test_key() . '` not found',
			)
		);

		$this->cli()->status( array(), array( 'format' => 'json' ) );

		self::assertStringNotContainsString( self::test_key(), (string) wp_json_encode( WP_CLI::$items ) );
		self::assertStringContainsString( 'failed', (string) WP_CLI::$items[0]['items'][0]['status'] );
	}
}
