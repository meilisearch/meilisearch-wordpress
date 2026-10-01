<?php
/**
 * Status tab: index health, reindex controls, recent errors and failed Meilisearch tasks.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;

/**
 * Renders the Status tab (spec § 7.1) and handles the "Clear log" admin-post action.
 * The reindex and connection buttons are driven by assets/js/admin.js through RestController.
 */
final class StatusTab implements Tab, Registrable {

	public const CLEAR_LOG_ACTION = 'meilisearch_clear_log';

	/**
	 * Constructor.
	 *
	 * @param Options       $options   Options.
	 * @param IndexNames    $names     Index names.
	 * @param Reindexer     $reindexer Reindexer (counts and run state).
	 * @param ErrorLog      $log       Error log.
	 * @param ClientFactory $clients   Client factory.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly IndexNames $names,
		private readonly Reindexer $reindexer,
		private readonly ErrorLog $log,
		private readonly ClientFactory $clients
	) {}

	/**
	 * Attaches the admin-post handler for "Clear log".
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::CLEAR_LOG_ACTION, array( $this, 'handle_clear_log' ), 10, 0 );
	}

	/**
	 * Tab slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'status';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Status', 'meilisearch' );
	}

	/**
	 * Always visible.
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		return true;
	}

	/**
	 * The Status tab has no settings: its actions go through REST (reindex, connection test) and admin-post (clear log).
	 */
	public function register_settings(): void {
		// No settings on this tab.
	}

	/**
	 * Renders the tab body. Remote calls are skipped when the connection is not configured.
	 */
	public function render(): void {
		$client = null;
		if ( $this->options->is_configured() ) {
			try {
				$client = $this->clients->client();
			} catch ( \RuntimeException $e ) {
				$client = null;
			}
		}

		echo '<h2>' . esc_html__( 'Indexes', 'meilisearch' ) . '</h2>';
		if ( null === $client ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Connect to Meilisearch on the Connection tab before indexing.', 'meilisearch' ) . '</p></div>';
		}
		$this->render_indexes( $client );

		if ( null !== $client ) {
			echo '<p><button type="button" class="button" data-meilisearch-action="test-connection" data-meilisearch-target="meilisearch-connection-result">'
				. esc_html__( 'Test connection', 'meilisearch' )
				. '</button> <span id="meilisearch-connection-result" role="status"></span></p>';
		}
		echo '<div id="meilisearch-live" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>';

		$this->render_errors();
		$this->render_failed_tasks( $client );
	}

	/**
	 * Admin-post handler: clears the log and redirects back to the tab.
	 */
	public function handle_clear_log(): void {
		wp_safe_redirect( $this->process_clear_log() );
		exit;
	}

	/**
	 * Checks capability and nonce, clears the log and returns the redirect URL.
	 *
	 * @return string
	 */
	public function process_clear_log(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the Meilisearch log.', 'meilisearch' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CLEAR_LOG_ACTION );
		$this->log->clear();

		return add_query_arg(
			array(
				'page'                    => Menu::SLUG,
				'tab'                     => $this->slug(),
				'meilisearch-log-cleared' => '1',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Index table: indexable vs indexed counts, reindex button and progress per active logical index.
	 *
	 * @param Client|null $client Client, or null when not connected.
	 */
	private function render_indexes( ?Client $client ): void {
		echo '<table class="widefat striped meilisearch-status-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Index', 'meilisearch' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Indexable posts', 'meilisearch' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Documents in Meilisearch', 'meilisearch' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Full reindex', 'meilisearch' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->names->active_logicals() as $logical ) {
			$uid    = $this->names->uid( $logical );
			$state  = $this->reindexer->status( $logical );
			$active = null !== $state && 'running' === ( $state['status'] ?? '' );
			$total  = null !== $state ? (int) ( $state['total'] ?? 0 ) : 0;
			$sent   = null !== $state ? (int) ( $state['sent'] ?? 0 ) : 0;

			echo '<tr><th scope="row">' . esc_html( $this->logical_label( $logical ) ) . '<br><code>' . esc_html( $uid ) . '</code>';
			if ( $this->options->needs_reindex( $logical ) ) {
				echo ' <span class="meilisearch-badge">' . esc_html__( 'Reindex needed', 'meilisearch' ) . '</span>';
			}
			echo '</th>';
			echo '<td>' . esc_html( number_format_i18n( $this->reindexer->count_indexable( $logical ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->document_count( $client, $uid ) ) . '</td>';
			echo '<td><button type="button" class="button" data-meilisearch-action="reindex" data-meilisearch-index="' . esc_attr( $logical ) . '"';
			echo ( $active || null === $client ) ? ' disabled' : '';
			echo '>' . esc_html__( 'Reindex', 'meilisearch' ) . '</button>';
			echo '<div class="meilisearch-progress" data-meilisearch-progress="' . esc_attr( $logical ) . '">';
			$percent = 'upsert' === ( $state['phase'] ?? 'upsert' ) ? ( $total > 0 ? min( 100, round( $sent * 100 / $total ) ) : 0 ) : 100;
			printf( '<progress max="100" value="%d"', (int) $percent );
			echo $active ? '' : ' hidden';
			echo '></progress> <span class="meilisearch-progress-text">' . esc_html( $this->describe( $state ) ) . '</span></div></td></tr>';
		}//end foreach

		echo '</tbody></table>';
	}

	/**
	 * Document count of an index, or a short reason when it cannot be read.
	 *
	 * @param Client|null $client Client.
	 * @param string      $uid    Index UID.
	 * @return string
	 */
	private function document_count( ?Client $client, string $uid ): string {
		if ( null === $client ) {
			return __( 'Not connected', 'meilisearch' );
		}
		try {
			$stats = $client->index_stats( $uid );
		} catch ( \RuntimeException $e ) {
			if ( $e instanceof ApiError && 'index_not_found' === $e->error_code ) {
				return __( 'Index not created yet', 'meilisearch' );
			}
			return __( 'Unavailable', 'meilisearch' );
		}
		return number_format_i18n( (int) ( $stats['numberOfDocuments'] ?? 0 ) );
	}

	/**
	 * Human-readable run state: phase, posts sent / total and stale documents removed (mirrors describe() in admin.js).
	 *
	 * @param array<string, mixed>|null $state Run state.
	 * @return string
	 */
	private function describe( ?array $state ): string {
		if ( null === $state ) {
			return __( 'No reindex has run yet.', 'meilisearch' );
		}
		$sent    = number_format_i18n( (int) ( $state['sent'] ?? 0 ) );
		$total   = number_format_i18n( (int) ( $state['total'] ?? 0 ) );
		$deleted = number_format_i18n( (int) ( $state['deleted'] ?? 0 ) );
		switch ( $state['status'] ?? '' ) {
			case 'running':
				if ( 'sweep' === ( $state['phase'] ?? '' ) ) {
					/* translators: %s: number of stale documents removed so far. */
					return sprintf( __( 'Removing stale documents: %s removed so far.', 'meilisearch' ), $deleted );
				}
				if ( 'finalizing' === ( $state['phase'] ?? '' ) ) {
					return __( 'Finalizing: waiting for Meilisearch to process the changes.', 'meilisearch' );
				}
				/* translators: 1: posts sent so far, 2: total posts. */
				return sprintf( __( 'Indexing: %1$s of %2$s posts sent.', 'meilisearch' ), $sent, $total );
			case 'done':
				/* translators: 1: posts sent, 2: stale documents removed. */
				return sprintf( __( 'Last reindex completed: %1$s posts sent, %2$s stale documents removed.', 'meilisearch' ), $sent, $deleted );
			case 'failed':
				/* translators: %s: error message. */
				return sprintf( __( 'Last reindex failed: %s', 'meilisearch' ), (string) ( $state['error'] ?? '' ) );
		}
		return '';
	}

	/**
	 * Recent plugin errors plus the "Clear log" form.
	 */
	private function render_errors(): void {
		echo '<h2>' . esc_html__( 'Recent errors', 'meilisearch' ) . '</h2>';
		$entries = $this->log->all();
		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'No errors recorded.', 'meilisearch' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Time', 'meilisearch' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Context', 'meilisearch' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Message', 'meilisearch' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $entries, 0, 20 ) as $entry ) {
			echo '<tr><td>' . esc_html( (string) wp_date( 'Y-m-d H:i:s', $entry['time'] ) ) . '</td>';
			echo '<td>' . esc_html( $entry['context'] ) . '</td>';
			echo '<td>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CLEAR_LOG_ACTION ) . '">';
		wp_nonce_field( self::CLEAR_LOG_ACTION );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Clear log', 'meilisearch' ) . '</button></p></form>';
	}

	/**
	 * The 10 most recent failed Meilisearch tasks on this site's indexes.
	 *
	 * @param Client|null $client Client.
	 */
	private function render_failed_tasks( ?Client $client ): void {
		echo '<h2>' . esc_html__( 'Recent failed Meilisearch tasks', 'meilisearch' ) . '</h2>';
		if ( null === $client ) {
			echo '<p>' . esc_html__( 'Not connected.', 'meilisearch' ) . '</p>';
			return;
		}

		$uids = array_map( array( $this->names, 'uid' ), $this->names->active_logicals() );
		try {
			$response = $client->get_tasks(
				array(
					'statuses'  => array( 'failed' ),
					'indexUids' => $uids,
					'limit'     => 10,
				)
			);
		} catch ( \RuntimeException $e ) {
			echo '<p>' . esc_html__( 'Failed tasks could not be loaded.', 'meilisearch' ) . '</p>';
			return;
		}

		$tasks = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
		if ( array() === $tasks ) {
			echo '<p>' . esc_html__( 'No failed tasks.', 'meilisearch' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Task', 'meilisearch' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Index', 'meilisearch' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Type', 'meilisearch' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Error', 'meilisearch' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Enqueued at', 'meilisearch' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$error = isset( $task['error']['message'] ) && is_string( $task['error']['message'] ) ? $task['error']['message'] : '';
			echo '<tr><td>' . esc_html( (string) (int) ( $task['uid'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $task['indexUid'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $task['type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $error ) . '</td>';
			echo '<td>' . esc_html( (string) ( $task['enqueuedAt'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Label of a logical index.
	 *
	 * @param string $logical Logical index.
	 * @return string
	 */
	private function logical_label( string $logical ): string {
		return 'products' === $logical ? __( 'Products', 'meilisearch' ) : __( 'Content', 'meilisearch' );
	}
}
