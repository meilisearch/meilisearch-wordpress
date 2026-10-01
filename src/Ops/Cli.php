<?php
/**
 * WP-CLI commands: wp meilisearch status|reindex|sync|clear|check.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Ops;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Sync\SyncJob;
use WP_CLI;

/**
 * Manages Meilisearch indexing from the command line.
 *
 * ## EXAMPLES
 *
 *     # Show the connection, document counts and the sync queue.
 *     $ wp meilisearch status
 *
 *     # Rebuild every index with a progress bar.
 *     $ wp meilisearch reindex
 */
final class Cli {

	/**
	 * Creates the command object.
	 *
	 * @param Reindexer     $reindexer    Reindexer.
	 * @param SyncJob       $sync         Sync job.
	 * @param ClientFactory $clients      Client factory.
	 * @param IndexNames    $names        Index names.
	 * @param SiteHealth    $health       Site Health tests.
	 * @param Indexability  $indexability Indexability rule.
	 * @param Queue         $queue        Action Scheduler wrapper.
	 * @param Options       $options      Options (redacts the configured keys from messages).
	 */
	public function __construct(
		private readonly Reindexer $reindexer,
		private readonly SyncJob $sync,
		private readonly ClientFactory $clients,
		private readonly IndexNames $names,
		private readonly SiteHealth $health,
		private readonly Indexability $indexability,
		private readonly Queue $queue,
		private readonly Options $options = new Options()
	) {}

	/**
	 * Shows the Meilisearch version, per-index document counts and the sync queue.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render the index table in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp meilisearch status
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI always passes both arrays.
		$this->require_configured();
		$client = $this->clients->client();
		try {
			$version = $client->version();
		} catch ( ApiError $error ) {
			$this->fail( sprintf( 'Meilisearch is unreachable: %s', $this->message( $error ) ) );
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$human  = 'table' === $format;
		if ( $human ) {
			WP_CLI::log( sprintf( 'Meilisearch %1$s, index prefix "%2$s".', (string) ( $version['pkgVersion'] ?? 'unknown' ), $this->names->prefix() ) );
		}

		$pending = $this->queue->count( 'pending' );
		$failed  = $this->queue->count( 'failed' );
		$rows    = array();
		foreach ( $this->names->active_logicals() as $logical ) {
			$uid = $this->names->uid( $logical );
			try {
				$stats     = $client->index_stats( $uid );
				$documents = (string) (int) ( $stats['numberOfDocuments'] ?? 0 );
			} catch ( ApiError $error ) {
				$documents = 'index_not_found' === $error->error_code ? 'missing' : 'error: ' . $this->message( $error );
			}
			$state  = $this->reindexer->status( $logical );
			$rows[] = array(
				'index'     => $logical,
				'uid'       => $uid,
				'documents' => $documents,
				'indexable' => (string) $this->reindexer->count_indexable( $logical ),
			) + $this->reindex_columns( $state );
			if ( ! $human ) {
				// Queue counts are group-wide; machine-readable formats carry them in the data, not in extra output lines.
				$rows[ count( $rows ) - 1 ] += array(
					'queue_pending' => (string) $pending,
					'queue_failed'  => (string) $failed,
				);
			}
		}//end foreach

		$fields = array( 'index', 'uid', 'documents', 'indexable', 'status', 'phase', 'sent', 'total', 'deleted' );
		if ( ! $human ) {
			$fields = array_merge( $fields, array( 'queue_pending', 'queue_failed' ) );
		}
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
		if ( $human ) {
			WP_CLI::log( sprintf( 'Queue: %1$d pending, %2$d failed actions in group "%3$s".', $pending, $failed, Queue::GROUP ) );
		}
	}

	/**
	 * Reindexes synchronously, in place: upserts every indexable post, deletes orphaned documents, then waits for Meilisearch.
	 *
	 * ## OPTIONS
	 *
	 * [--index=<index>]
	 * : Only rebuild this index. Defaults to every active index.
	 * ---
	 * options:
	 *   - content
	 *   - products
	 * ---
	 *
	 * [--batch-size=<n>]
	 * : Posts sent per request. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp meilisearch reindex
	 *     $ wp meilisearch reindex --index=products --batch-size=500
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function reindex( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI always passes both arrays.
		$this->require_configured();
		$logicals   = $this->logicals_from( $assoc_args );
		$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : Reindexer::DEFAULT_BATCH;
		if ( $batch_size < 1 ) {
			$this->fail( '--batch-size must be a positive integer.' );
		}

		foreach ( $logicals as $logical ) {
			$uid    = $this->names->uid( $logical );
			$total  = $this->reindexer->count_indexable( $logical );
			$bar    = \WP_CLI\Utils\make_progress_bar( sprintf( 'Reindexing %s', $uid ), max( 1, $total ) );
			$ticked = 0;
			try {
				$this->reindexer->run_sync(
					$logical,
					$batch_size,
					static function ( int $sent ) use ( $bar, &$ticked ): void {
						if ( $sent > $ticked ) {
							$bar->tick( $sent - $ticked );
							$ticked = $sent;
						}
					}
				);
			} catch ( \Throwable $error ) {
				$bar->finish();
				$this->fail( $this->reindex_failure( $uid, $error ) );
			}
			$bar->finish();

			$state   = $this->reindexer->status( $logical );
			$deleted = (int) ( $state['deleted'] ?? 0 );
			$status  = (string) ( $state['status'] ?? 'done' );
			WP_CLI::log( sprintf( 'Orphan sweep on %1$s: %2$d document(s) deleted.', $uid, $deleted ) );
			if ( 'failed' === $status ) {
				$this->fail( sprintf( 'Reindex of %1$s failed: %2$s', $uid, $this->message( (string) ( $state['error'] ?? '' ) ) ) );
			}
			WP_CLI::success( sprintf( 'Reindexed %1$s: %2$d document(s) sent, %3$d orphan(s) deleted, status %4$s.', $uid, $ticked, $deleted, $status ) );
		}//end foreach
	}

	/**
	 * Reconciles specific posts with Meilisearch now: indexable posts are upserted, others deleted.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more post IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp meilisearch sync 42 43
	 *
	 * @param string[]             $args       Post IDs.
	 * @param array<string, mixed> $assoc_args Associative arguments (none).
	 */
	public function sync( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI always passes both arrays.
		$this->require_configured();
		$active = $this->names->active_logicals();
		$groups = array();
		foreach ( $args as $raw ) {
			$id = (int) $raw;
			if ( $id < 1 || (string) $id !== (string) $raw ) {
				$this->fail( sprintf( '"%s" is not a valid post ID.', $raw ) );
			}
			$post    = get_post( $id );
			$logical = $post instanceof \WP_Post ? $this->indexability->index_for( $post ) : null;
			$targets = ( null !== $logical && in_array( $logical, $active, true ) ) ? array( $logical ) : $active;
			foreach ( $targets as $target ) {
				$groups[ $target ][] = $id;
			}
		}

		$totals = array(
			'upserted' => 0,
			'deleted'  => 0,
			'skipped'  => 0,
		);
		foreach ( $groups as $logical => $ids ) {
			try {
				$result = $this->sync->reconcile( $logical, array_values( array_unique( $ids ) ) );
			} catch ( \Throwable $error ) {
				$this->fail( sprintf( 'Sync of %1$s failed: %2$s', $this->names->uid( $logical ), $this->message( $error ) ) );
			}
			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += (int) $result[ $key ];
			}
		}

		WP_CLI::success( sprintf( 'Synced %1$d post(s): %2$d upserted, %3$d deleted, %4$d skipped.', count( $args ), $totals['upserted'], $totals['deleted'], $totals['skipped'] ) );
	}

	/**
	 * Deletes every document of an index (the index and its settings are kept).
	 *
	 * ## OPTIONS
	 *
	 * [--index=<index>]
	 * : Only clear this index. Defaults to every active index.
	 * ---
	 * options:
	 *   - content
	 *   - products
	 * ---
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp meilisearch clear --index=content --yes
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function clear( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI always passes both arrays.
		$this->require_configured();
		$uids = array_map( array( $this->names, 'uid' ), $this->logicals_from( $assoc_args ) );

		WP_CLI::confirm( sprintf( 'Delete all documents from %s?', implode( ', ', $uids ) ), $assoc_args );

		$client = $this->clients->client();
		foreach ( $uids as $uid ) {
			try {
				$client->delete_all_documents( $uid )->wait();
			} catch ( ApiError $error ) {
				$this->fail( sprintf( 'Could not clear %1$s: %2$s', $uid, $this->message( $error ) ) );
			}
			WP_CLI::success( sprintf( 'Cleared %s.', $uid ) );
		}
		WP_CLI::log( 'Run "wp meilisearch reindex" to fill the index again.' );
	}

	/**
	 * Runs the Site Health checks. Exits with status 1 when a check is critical.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp meilisearch check
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Associative arguments (none).
	 */
	public function check( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI always passes both arrays.
		$results = $this->health->run_all();
		$rows    = array();
		foreach ( $results as $result ) {
			$rows[] = array(
				'test'   => $result['test'],
				'status' => $result['status'],
				'label'  => $result['label'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'test', 'status', 'label' ) );

		$critical    = 0;
		$recommended = 0;
		foreach ( $results as $result ) {
			if ( 'good' === $result['status'] ) {
				continue;
			}
			WP_CLI::log( sprintf( '%1$s: %2$s', $result['test'], trim( html_entity_decode( wp_strip_all_tags( $result['description'] ), ENT_QUOTES ) ) ) );
			if ( 'critical' === $result['status'] ) {
				++$critical;
			} else {
				++$recommended;
			}
		}

		if ( $critical > 0 ) {
			$this->fail( sprintf( '%d critical check(s) failed.', $critical ) );
		}
		if ( $recommended > 0 ) {
			WP_CLI::warning( sprintf( '%d check(s) need attention.', $recommended ) );
			return;
		}
		WP_CLI::success( 'All Meilisearch checks passed.' );
	}

	/**
	 * Exits with an error unless a host and an admin key are configured.
	 */
	private function require_configured(): void {
		if ( ! $this->clients->is_configured() ) {
			$this->fail( 'Meilisearch is not configured. Set the host and admin key on the Meilisearch > Connection screen, or define MEILISEARCH_HOST and MEILISEARCH_ADMIN_KEY in wp-config.php.' );
		}
	}

	/**
	 * The logical indexes a command applies to: the --index one, or every active index.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return list<string>
	 */
	private function logicals_from( array $assoc_args ): array {
		$active = $this->names->active_logicals();
		if ( ! isset( $assoc_args['index'] ) ) {
			return $active;
		}
		$logical = (string) $assoc_args['index'];
		if ( ! in_array( $logical, $active, true ) ) {
			$this->fail( sprintf( 'Unknown or inactive index "%1$s". Active indexes: %2$s.', $logical, implode( ', ', $active ) ) );
		}

		return array( $logical );
	}

	/**
	 * Reindex columns of the status table (state shape: run, phase, sent, deleted, total, status, error, …).
	 *
	 * @param array<string, mixed>|null $state Stored run state.
	 * @return array{status: string, phase: string, sent: string, total: string, deleted: string}
	 */
	private function reindex_columns( ?array $state ): array {
		if ( null === $state ) {
			return array(
				'status'  => 'idle',
				'phase'   => '-',
				'sent'    => '-',
				'total'   => '-',
				'deleted' => '-',
			);
		}
		$status = (string) ( $state['status'] ?? 'unknown' );
		$error  = $this->message( (string) ( $state['error'] ?? '' ) );
		if ( 'failed' === $status && '' !== $error ) {
			$status .= ': ' . $error;
		}

		return array(
			'status'  => $status,
			'phase'   => (string) ( $state['phase'] ?? '-' ),
			'sent'    => (string) (int) ( $state['sent'] ?? 0 ),
			'total'   => (string) (int) ( $state['total'] ?? 0 ),
			'deleted' => (string) (int) ( $state['deleted'] ?? 0 ),
		);
	}

	/**
	 * A message that is safe to print: the configured keys are redacted (Meilisearch echoes them in
	 * some errors) and the "unsupported_version: " code prefix is dropped.
	 *
	 * @param \Throwable|string $error Exception or message.
	 */
	private function message( \Throwable|string $error ): string {
		$message = $this->options->redact( $error instanceof \Throwable ? $error->getMessage() : $error );
		$prefix  = 'unsupported_version: ';

		return str_starts_with( $message, $prefix ) ? substr( $message, strlen( $prefix ) ) : $message;
	}

	/**
	 * The error line of a failed reindex run.
	 *
	 * @param string     $uid   Index uid.
	 * @param \Throwable $error Error raised by the run.
	 */
	private function reindex_failure( string $uid, \Throwable $error ): string {
		$code = $error->getMessage();
		if ( 'already_running' === $code ) {
			return sprintf( 'A reindex of %s is already running. Check "wp meilisearch status" and try again when it has finished.', $uid );
		}
		if ( 'superseded' === $code ) {
			return sprintf( 'The reindex of %s was superseded by a newer run and stopped. Check "wp meilisearch status".', $uid );
		}

		return sprintf( 'Reindex of %1$s failed: %2$s', $uid, $this->message( $error ) );
	}

	/**
	 * Prints "Error: …" and exits with status 1.
	 *
	 * @param string $message Error message.
	 */
	private function fail( string $message ): never {
		WP_CLI::error( $message, false );
		WP_CLI::halt( 1 );
	}
}
