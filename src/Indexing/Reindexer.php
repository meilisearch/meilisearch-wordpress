<?php
/**
 * Full reindex in place: upsert every indexable post, then sweep out stale documents.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;

/**
 * Spec § 6.3. One run per logical index, against the live index (no temporary index, no swap:
 * index settings, including embedders and their API keys, are never read back or rewritten):
 *
 * 1. start(): ensure_index() (creates it with the required settings if missing), store the run state.
 * 2. Phase "upsert": keyset pagination on post ID (never OFFSET); indexable posts are sent with
 *    add-or-replace. The cursor advances to the last scanned row even when every row is vetoed.
 * 3. Phase "sweep": page through the index's document IDs (documents/fetch, id > last, sorted by id)
 *    and delete those whose post is missing or no longer indexable (orphans, missed unpublishes).
 * 4. Phase "finalizing": wait until no recorded task is enqueued/processing; a failed or canceled
 *    task fails the run, otherwise the run is done and the "needs reindex" flag is cleared.
 *
 * Recorded task UIDs are pruned once TASK_PRUNE_AT accumulate: finished ones are checked for failure
 * and dropped, so the stored state stays small. Real-time sync keeps writing to the same index;
 * both always write the current state of a post, so they never conflict.
 */
final class Reindexer implements Registrable {

	public const DEFAULT_BATCH    = 200;
	public const MAX_BATCH        = 1000;
	public const SWEEP_PAGE       = 1000;
	public const FINALIZE_POLL    = 10;
	public const STALE_AFTER      = 900;
	public const CLI_TASK_TIMEOUT = 3600;

	private const TASK_UID_CHUNK = 100;
	private const TASK_PRUNE_AT  = 20;

	/**
	 * Constructor.
	 *
	 * @param ClientFactory                  $clients      Client factory.
	 * @param IndexManager                   $indexes      Index manager.
	 * @param IndexNames                     $names        Index names.
	 * @param Indexability                   $indexability Indexability rule.
	 * @param array<string, DocumentBuilder> $builders     Logical index => builder.
	 * @param Queue                          $queue        Action Scheduler wrapper.
	 * @param Options                        $options      Options.
	 * @param ErrorLog                       $log          Error log.
	 */
	public function __construct(
		private readonly ClientFactory $clients,
		private readonly IndexManager $indexes,
		private readonly IndexNames $names,
		private readonly Indexability $indexability,
		private readonly array $builders,
		private readonly Queue $queue,
		private readonly Options $options,
		private readonly ErrorLog $log
	) {}

	/**
	 * Attaches the batch handler. accepted_args must be 1: the payload is the only argument.
	 */
	public function register(): void {
		add_action( Queue::REINDEX_BATCH, array( $this, 'handle_batch' ), 10, 1 );
	}

	/**
	 * Starts an Action Scheduler driven run.
	 *
	 * @param string $logical 'content' or 'products'.
	 * @return array<string, mixed> The new run state.
	 * @throws \RuntimeException 'already_running' while another run is active; ApiError on Meilisearch errors.
	 */
	public function start( string $logical ): array {
		$state = $this->begin( $logical );
		if ( 0 === $this->queue->schedule( Queue::REINDEX_BATCH, $this->payload( $logical, (string) $state['run'] ) ) ) {
			$state = $this->fail( $logical, $state, __( 'The first reindex batch could not be scheduled.', 'meilisearch' ) );
		}
		return $state;
	}

	/**
	 * Action Scheduler handler: runs one upsert batch, one sweep page or one finalization check, then chains the next step.
	 *
	 * @param array<string, mixed> $payload {logical: string, run: string}.
	 * @throws \RuntimeException When the next step cannot be scheduled.
	 * @throws \Throwable Any error, after marking the run failed, so Action Scheduler also records the failure.
	 */
	public function handle_batch( array $payload ): void {
		$logical = isset( $payload['logical'] ) && is_string( $payload['logical'] ) ? $payload['logical'] : '';
		$run     = isset( $payload['run'] ) && is_string( $payload['run'] ) ? $payload['run'] : '';
		$state   = '' === $logical ? null : $this->status( $logical );
		if ( null === $state || '' === $run || ( $state['run'] ?? '' ) !== $run || ! self::is_active( $state ) ) {
			// Stale action from a superseded or finished run.
			return;
		}
		if ( ! $this->clients->is_configured() ) {
			$this->fail( $logical, $state, __( 'Meilisearch is not configured.', 'meilisearch' ) );
			return;
		}

		try {
			$state = $this->step( $logical, $state, $this->batch_size() );
			if ( self::is_active( $state ) ) {
				$delay = 'finalizing' === $state['phase'] ? self::FINALIZE_POLL : 0;
				if ( 0 === $this->queue->schedule( Queue::REINDEX_BATCH, $this->payload( $logical, $run ), $delay ) ) {
					throw new \RuntimeException( 'The next reindex step could not be scheduled.' );
				}
			}
		} catch ( \Throwable $e ) {
			$this->fail( $logical, $this->status( $logical ) ?? $state, $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * WP-CLI path: the same phases, inline, with a progress callback during the upsert phase.
	 *
	 * @param string   $logical    'content' or 'products'.
	 * @param int      $batch_size Posts per batch (1..MAX_BATCH).
	 * @param callable $progress   Called as $progress( int $sent, int $total ).
	 * @throws \RuntimeException When the run is superseded or fails.
	 * @throws ApiError 'task_timeout' when Meilisearch does not finish within CLI_TASK_TIMEOUT.
	 * @throws \Throwable Any other error, after marking the run failed.
	 */
	public function run_sync( string $logical, int $batch_size, callable $progress ): void {
		$state      = $this->begin( $logical );
		$run        = (string) $state['run'];
		$batch_size = max( 1, min( self::MAX_BATCH, $batch_size ) );
		$deadline   = 0;

		try {
			$progress( 0, (int) $state['total'] );
			while ( self::is_active( $state ) ) {
				$current = $this->status( $logical );
				if ( null === $current || ( $current['run'] ?? '' ) !== $run ) {
					throw new \RuntimeException( 'superseded' );
				}
				if ( 'finalizing' === $state['phase'] ) {
					$deadline = 0 === $deadline ? time() + self::CLI_TASK_TIMEOUT : $deadline;
					if ( time() >= $deadline ) {
						throw new ApiError( 'Timed out waiting for Meilisearch to process the reindex.', 'task_timeout' );
					}
				}
				$was_upsert = 'upsert' === $state['phase'];
				$state      = $this->step( $logical, $state, $batch_size );
				if ( $was_upsert ) {
					$progress( (int) $state['sent'], (int) $state['total'] );
				} elseif ( self::is_active( $state ) && 'finalizing' === $state['phase'] ) {
					usleep( 500000 );
				}
			}
		} catch ( \Throwable $e ) {
			$current = $this->status( $logical );
			if ( null !== $current && ( $current['run'] ?? '' ) === $run && 'failed' !== ( $current['status'] ?? '' ) ) {
				$this->fail( $logical, $current, $e->getMessage() );
			}
			throw $e;
		}//end try

		if ( 'failed' === $state['status'] ) {
			throw new \RuntimeException( esc_html( (string) $state['error'] ) );
		}
	}

	/**
	 * Current run state of a logical index.
	 *
	 * @param string $logical 'content' or 'products'.
	 * @return array<string, mixed>|null
	 */
	public function status( string $logical ): ?array {
		return $this->options->reindex_state( $logical );
	}

	/**
	 * The IDs among $ids that must not be in the index: missing posts, wrong type, not public, vetoed.
	 * One SQL query narrows to published, password-less posts of the index's types; Indexability decides.
	 *
	 * @param string $logical 'content' or 'products'.
	 * @param int[]  $ids     Document IDs.
	 * @return list<int>
	 */
	public function non_indexable( string $logical, array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$types = $this->post_types( $logical );
		if ( array() === $types ) {
			return $ids;
		}

		global $wpdb;
		$id_placeholders   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$type_placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$id_placeholders}) AND post_type IN ({$type_placeholders}) AND post_status = 'publish' AND post_password = ''",
				array_merge( $ids, $types )
			)
		);
		// phpcs:enable
		$candidates = array_map( 'intval', (array) $rows );

		$keep = array();
		if ( array() !== $candidates ) {
			_prime_post_caches( $candidates, true, true );
			foreach ( $candidates as $id ) {
				$post = get_post( $id );
				if ( $post instanceof \WP_Post
					&& $logical === $this->indexability->index_for( $post )
					&& $this->indexability->is_indexable( $post ) ) {
					$keep[ $id ] = true;
				}
			}
		}

		return array_values( array_filter( $ids, static fn ( int $id ): bool => ! isset( $keep[ $id ] ) ) );
	}

	/**
	 * Indexable post IDs greater than $last_id, ascending, from at most $limit scanned rows.
	 *
	 * @param string $logical 'content' or 'products'.
	 * @param int    $last_id Cursor.
	 * @param int    $limit   Rows to scan.
	 * @return list<int>
	 */
	public function indexable_ids_after( string $logical, int $last_id, int $limit ): array {
		return $this->scan( $logical, $last_id, $limit )['ids'];
	}

	/**
	 * Number of candidate posts (published, no password, indexed type). An upper bound: the
	 * PHP-side rule (product visibility, meilisearch_should_index_post) is not applied here.
	 *
	 * @param string $logical 'content' or 'products'.
	 * @return int
	 */
	public function count_indexable( string $logical ): int {
		global $wpdb;
		$types = $this->post_types( $logical );
		if ( array() === $types ) {
			return 0;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish' AND post_password = ''",
				$types
			)
		);
		// phpcs:enable
		return (int) $count;
	}

	/**
	 * Validates, ensures the live index exists with the required settings and stores the initial run state.
	 *
	 * @param string $logical Logical index.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException For an inactive logical index.
	 * @throws \RuntimeException 'already_running'.
	 */
	private function begin( string $logical ): array {
		if ( ! isset( $this->builders[ $logical ] ) || ! in_array( $logical, $this->names->active_logicals(), true ) ) {
			throw new \InvalidArgumentException( 'unknown_index' );
		}
		$current = $this->status( $logical );
		if ( null !== $current && self::is_active( $current ) && ! $this->is_abandoned( $logical, $current ) ) {
			throw new \RuntimeException( 'already_running' );
		}

		$this->indexes->ensure_index( $logical );

		$state = array(
			'run'        => strtolower( gmdate( 'YmdHis' ) . wp_generate_password( 4, false ) ),
			'phase'      => 'upsert',
			'last_id'    => 0,
			'sent'       => 0,
			'deleted'    => 0,
			'total'      => $this->count_indexable( $logical ),
			'task_uids'  => array(),
			'started_at' => time(),
			'status'     => 'running',
			'error'      => '',
		);
		$this->options->set_reindex_state( $logical, $state );
		return $state;
	}

	/**
	 * Runs one unit of work for the current phase and stores the new state.
	 *
	 * @param string               $logical    Logical index.
	 * @param array<string, mixed> $state      Running state.
	 * @param int                  $batch_size Upsert batch size.
	 * @return array<string, mixed>
	 */
	private function step( string $logical, array $state, int $batch_size ): array {
		$client = $this->clients->client();
		switch ( $state['phase'] ?? '' ) {
			case 'upsert':
				$state = $this->upsert_batch( $client, $logical, $state, $batch_size );
				break;
			case 'sweep':
				$state = $this->sweep_page( $client, $logical, $state );
				break;
			default:
				return $this->finalize_step( $client, $logical, $state );
		}

		if ( count( $state['task_uids'] ) >= self::TASK_PRUNE_AT ) {
			$checked = $this->check_tasks( $client, $state['task_uids'] );
			if ( null !== $checked['failed'] ) {
				return $this->fail( $logical, $state, self::task_error( $checked['failed'] ) );
			}
			$state['task_uids'] = $checked['pending'];
		}
		$this->options->set_reindex_state( $logical, $state );
		return $state;
	}

	/**
	 * Upsert phase: sends one batch to the live index and advances the cursor.
	 *
	 * @param Client               $client     Client.
	 * @param string               $logical    Logical index.
	 * @param array<string, mixed> $state      Run state.
	 * @param int                  $batch_size Rows to scan.
	 * @return array<string, mixed> Updated state; phase becomes 'sweep' (cursor reset) after the last batch.
	 */
	private function upsert_batch( Client $client, string $logical, array $state, int $batch_size ): array {
		$scan      = $this->scan( $logical, (int) $state['last_id'], $batch_size );
		$documents = array();
		foreach ( $scan['ids'] as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$document = $this->build( $logical, $post );
				if ( null !== $document ) {
					$documents[] = $document;
				}
			}
		}

		if ( array() !== $documents ) {
			$state['task_uids'][] = $client->add_documents( $this->names->uid( $logical ), $documents )->uid;
		}
		$state['sent']    = (int) $state['sent'] + count( $documents );
		$state['last_id'] = $scan['last_scanned'];
		if ( $scan['exhausted'] ) {
			$state['phase']   = 'sweep';
			$state['last_id'] = 0;
		}
		return $state;
	}

	/**
	 * Sweep phase: reads one page of document IDs and deletes the stale ones.
	 *
	 * @param Client               $client  Client.
	 * @param string               $logical Logical index.
	 * @param array<string, mixed> $state   Run state.
	 * @return array<string, mixed> Updated state; phase becomes 'finalizing' after the last page.
	 */
	private function sweep_page( Client $client, string $logical, array $state ): array {
		$uid      = $this->names->uid( $logical );
		$response = $client->fetch_documents(
			$uid,
			array(
				'filter' => 'id > ' . (int) $state['last_id'],
				'sort'   => array( 'id:asc' ),
				'fields' => array( 'id' ),
				'limit'  => self::SWEEP_PAGE,
			)
		);
		$results  = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
		$ids      = array();
		foreach ( $results as $document ) {
			if ( is_array( $document ) && isset( $document['id'] ) && is_numeric( $document['id'] ) ) {
				$ids[] = (int) $document['id'];
			}
		}

		if ( array() !== $ids ) {
			$stale = $this->non_indexable( $logical, $ids );
			if ( array() !== $stale ) {
				$state['task_uids'][] = $client->delete_documents( $uid, $stale )->uid;
				$state['deleted']     = (int) $state['deleted'] + count( $stale );
			}
			$state['last_id'] = max( $ids );
		}
		if ( count( $results ) < self::SWEEP_PAGE ) {
			$state['phase'] = 'finalizing';
		}
		return $state;
	}

	/**
	 * Finalizing phase: done when no recorded task is pending; failed on the first failed/canceled task.
	 *
	 * @param Client               $client  Client.
	 * @param string               $logical Logical index.
	 * @param array<string, mixed> $state   Run state.
	 * @return array<string, mixed> Unchanged phase while tasks are still processing, otherwise the final state.
	 */
	private function finalize_step( Client $client, string $logical, array $state ): array {
		$checked = $this->check_tasks( $client, $state['task_uids'] );
		if ( null !== $checked['failed'] ) {
			return $this->fail( $logical, $state, self::task_error( $checked['failed'] ) );
		}
		$state['task_uids'] = $checked['pending'];
		if ( array() !== $checked['pending'] ) {
			$this->options->set_reindex_state( $logical, $state );
			return $state;
		}

		$state['status'] = 'done';
		$state['error']  = '';
		$this->options->set_reindex_state( $logical, $state );
		$this->options->flag_reindex( $logical, false );
		$this->options->set_state( 'first_reindex_done', true );
		return $state;
	}

	/**
	 * Splits recorded tasks into still-pending UIDs and the first failed/canceled finished task.
	 * Pending tasks are listed first; every other task has reached a final status, so the failure
	 * check over them cannot miss a task that fails later.
	 *
	 * @param Client $client Client.
	 * @param int[]  $uids   Task UIDs.
	 * @return array{pending: list<int>, failed: array<string, mixed>|null}
	 */
	private function check_tasks( Client $client, array $uids ): array {
		$uids    = array_values( array_unique( array_map( 'intval', $uids ) ) );
		$pending = array();
		foreach ( array_chunk( $uids, self::TASK_UID_CHUNK ) as $chunk ) {
			$response = $client->get_tasks(
				array(
					'uids'     => $chunk,
					'statuses' => array( 'enqueued', 'processing' ),
					'limit'    => self::TASK_UID_CHUNK,
				)
			);
			foreach ( (array) ( $response['results'] ?? array() ) as $task ) {
				if ( is_array( $task ) && isset( $task['uid'] ) ) {
					$pending[] = (int) $task['uid'];
				}
			}
		}

		$finished = array_values( array_diff( $uids, $pending ) );
		foreach ( array_chunk( $finished, self::TASK_UID_CHUNK ) as $chunk ) {
			$response = $client->get_tasks(
				array(
					'uids'     => $chunk,
					'statuses' => array( 'failed', 'canceled' ),
					'limit'    => 1,
				)
			);
			if ( isset( $response['results'][0] ) && is_array( $response['results'][0] ) ) {
				return array(
					'pending' => $pending,
					'failed'  => $response['results'][0],
				);
			}
		}

		sort( $pending );
		return array(
			'pending' => $pending,
			'failed'  => null,
		);
	}

	/**
	 * Marks the run failed and logs.
	 *
	 * @param string               $logical Logical index.
	 * @param array<string, mixed> $state   Run state.
	 * @param string               $message Reason.
	 * @return array<string, mixed>
	 */
	private function fail( string $logical, array $state, string $message ): array {
		$state['status'] = 'failed';
		$state['error']  = $message;
		$this->options->set_reindex_state( $logical, $state );
		$this->log->add( 'reindex', sprintf( 'Reindex of the %1$s index failed: %2$s', $logical, $message ) );
		return $state;
	}

	/**
	 * Keyset scan: candidate rows by SQL, then the authoritative Indexability rule in PHP.
	 *
	 * @param string $logical Logical index.
	 * @param int    $last_id Cursor.
	 * @param int    $limit   Rows to scan.
	 * @return array{ids: list<int>, last_scanned: int, exhausted: bool}
	 */
	private function scan( string $logical, int $last_id, int $limit ): array {
		global $wpdb;
		$types = $this->post_types( $logical );
		if ( array() === $types || $limit < 1 ) {
			return array(
				'ids'          => array(),
				'last_scanned' => $last_id,
				'exhausted'    => true,
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish' AND post_password = '' AND ID > %d ORDER BY ID ASC LIMIT %d",
				array_merge( $types, array( $last_id, $limit ) )
			)
		);
		// phpcs:enable
		$rows = array_map( 'intval', (array) $rows );
		if ( array() === $rows ) {
			return array(
				'ids'          => array(),
				'last_scanned' => $last_id,
				'exhausted'    => true,
			);
		}

		_prime_post_caches( $rows, true, true );
		$ids = array();
		foreach ( $rows as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post
				&& $logical === $this->indexability->index_for( $post )
				&& $this->indexability->is_indexable( $post ) ) {
				$ids[] = $id;
			}
		}

		return array(
			'ids'          => $ids,
			'last_scanned' => max( $rows ),
			'exhausted'    => count( $rows ) < $limit,
		);
	}

	/**
	 * Builds a document, or returns null (and logs) when it cannot be built or JSON-encoded.
	 *
	 * @param string   $logical Logical index.
	 * @param \WP_Post $post    Post.
	 * @return array<string, mixed>|null
	 */
	private function build( string $logical, \WP_Post $post ): ?array {
		try {
			$document = $this->builders[ $logical ]->build( $post );
		} catch ( \Throwable $e ) {
			$this->log->add( 'reindex', sprintf( 'Post %1$d could not be converted to a document: %2$s', (int) $post->ID, $e->getMessage() ) );
			return null;
		}
		if ( null === $document ) {
			$this->log->add( 'reindex', sprintf( 'Post %d could not be converted to a document and was skipped.', (int) $post->ID ) );
			return null;
		}
		// wp_json_encode() would silently re-encode invalid UTF-8; the raw encoder tells us the truth.
		if ( false === json_encode( $document ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$this->log->add( 'reindex', sprintf( 'Post %1$d was skipped: its document cannot be JSON-encoded (%2$s).', (int) $post->ID, json_last_error_msg() ) );
			return null;
		}
		return $document;
	}

	/**
	 * Post types stored in a logical index.
	 *
	 * @param string $logical Logical index.
	 * @return list<string>
	 */
	private function post_types( string $logical ): array {
		if ( 'content' === $logical ) {
			return $this->options->enabled_post_types();
		}
		if ( 'products' === $logical && $this->options->products_enabled() ) {
			return array( 'product' );
		}
		return array();
	}

	/**
	 * Batch size from the meilisearch_reindex_batch_size filter, clamped to 1..MAX_BATCH.
	 *
	 * @return int
	 */
	private function batch_size(): int {
		$size = (int) apply_filters( 'meilisearch_reindex_batch_size', self::DEFAULT_BATCH );
		return max( 1, min( self::MAX_BATCH, $size ) );
	}

	/**
	 * A run is abandoned when it is older than STALE_AFTER and no batch action of it is pending or running
	 * (e.g. the PHP process died mid-batch). A WP-CLI run older than that is also treated as abandoned;
	 * it then stops at its next batch with "superseded".
	 *
	 * @param string               $logical Logical index.
	 * @param array<string, mixed> $state   Run state.
	 * @return bool
	 */
	private function is_abandoned( string $logical, array $state ): bool {
		if ( time() - (int) ( $state['started_at'] ?? 0 ) <= self::STALE_AFTER ) {
			return false;
		}
		return ! as_has_scheduled_action( Queue::REINDEX_BATCH, array( $this->payload( $logical, (string) ( $state['run'] ?? '' ) ) ), Queue::GROUP );
	}

	/**
	 * Message of a failed task.
	 *
	 * @param array<string, mixed> $task Task.
	 * @return string
	 */
	private static function task_error( array $task ): string {
		return isset( $task['error']['message'] ) && is_string( $task['error']['message'] )
			? $task['error']['message']
			: sprintf( 'Meilisearch task %d did not succeed.', (int) ( $task['uid'] ?? 0 ) );
	}

	/**
	 * Batch action payload.
	 *
	 * @param string $logical Logical index.
	 * @param string $run     Run ID.
	 * @return array{logical: string, run: string}
	 */
	private function payload( string $logical, string $run ): array {
		return array(
			'logical' => $logical,
			'run'     => $run,
		);
	}

	/**
	 * Whether a run is still running.
	 *
	 * @param array<string, mixed> $state Run state.
	 * @return bool
	 */
	private static function is_active( array $state ): bool {
		return 'running' === ( $state['status'] ?? '' );
	}
}
