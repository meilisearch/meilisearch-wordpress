<?php
/**
 * Action Scheduler handler that reconciles a batch of posts with Meilisearch.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\DocumentBuilder;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\IndexNames;

/**
 * Handles Queue::SYNC_POSTS {index, ids, attempt} (spec § 6.2).
 *
 * For each ID the current post is re-read: indexable posts are upserted, everything else
 * (missing, unpublished, password-protected, vetoed, unbuildable) is deleted. The job never
 * waits on Meilisearch tasks and writes to the live index only (a full reindex runs in place).
 * Failures are rescheduled with BACKOFF[attempt - 1] and, when attempt MAX_ATTEMPTS fails,
 * logged and rethrown so Action Scheduler marks the action failed.
 */
final class SyncJob implements Registrable {

	/**
	 * Delays in seconds before attempts 2..6.
	 */
	public const BACKOFF = array( 60, 300, 1800, 7200, 21600 );

	/**
	 * Attempts before the action is left failed.
	 */
	public const MAX_ATTEMPTS = 6;

	/**
	 * Constructor.
	 *
	 * @param ClientFactory                  $clients      Client factory.
	 * @param IndexNames                     $names        Index names.
	 * @param Indexability                   $indexability Indexability rule.
	 * @param array<string, DocumentBuilder> $builders     Logical index => builder.
	 * @param Queue                          $queue        Action Scheduler wrapper.
	 * @param ErrorLog                       $log          Error log.
	 */
	public function __construct(
		private readonly ClientFactory $clients,
		private readonly IndexNames $names,
		private readonly Indexability $indexability,
		private readonly array $builders,
		private readonly Queue $queue,
		private readonly ErrorLog $log
	) {}

	/**
	 * Attaches the Action Scheduler handler. accepted_args must be 1: the payload is the only argument.
	 */
	public function register(): void {
		add_action( Queue::SYNC_POSTS, array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * Runs one sync job.
	 *
	 * @param array<string, mixed> $payload {index: string, ids: list<int>, attempt: int}.
	 * @throws ApiError When the last attempt fails (Action Scheduler then marks the action failed).
	 */
	public function handle( array $payload ): void {
		$logical = isset( $payload['index'] ) && is_string( $payload['index'] ) ? $payload['index'] : '';
		$ids     = isset( $payload['ids'] ) && is_array( $payload['ids'] ) ? self::sanitize_ids( $payload['ids'] ) : array();
		$attempt = isset( $payload['attempt'] ) ? max( 1, (int) $payload['attempt'] ) : 1;

		if ( ! isset( $this->builders[ $logical ] ) || array() === $ids ) {
			$this->log->add( 'sync', sprintf( 'Ignored a sync job with an invalid payload (index "%s").', $logical ) );
			return;
		}

		// Clear the "job waiting" markers before reading any post, so changes made from now on enqueue a new job.
		ChangeCollector::clear_pending( $ids );

		if ( ! $this->clients->is_configured() ) {
			return;
			// Nothing to sync to.
		}

		try {
			$this->reconcile( $logical, $ids );
		} catch ( ApiError $e ) {
			if ( $attempt < self::MAX_ATTEMPTS ) {
				$action_id = $this->queue->schedule(
					Queue::SYNC_POSTS,
					array(
						'index'   => $logical,
						'ids'     => $ids,
						'attempt' => $attempt + 1,
					),
					self::BACKOFF[ $attempt - 1 ]
				);
				if ( $action_id > 0 ) {
					return;
				}
			}
			$this->log->add(
				'sync',
				sprintf( 'Syncing %1$d post(s) to the %2$s index failed after %3$d attempt(s): %4$s', count( $ids ), $logical, $attempt, $e->getMessage() )
			);
			throw $e;
		}//end try
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- ApiError comes from the client calls.
	/**
	 * Deletes the posts that are not indexable, then upserts the rest, in the live index.
	 * Meilisearch and transport errors propagate as ApiError.
	 *
	 * @param string $logical 'content' or 'products'.
	 * @param int[]  $ids     Post IDs.
	 * @return array{upserted: int, deleted: int, skipped: int}
	 * @throws \InvalidArgumentException For an unknown logical index.
	 * @throws ApiError When Meilisearch rejects a request or cannot be reached.
	 */
	public function reconcile( string $logical, array $ids ): array {
		if ( ! isset( $this->builders[ $logical ] ) ) {
			throw new \InvalidArgumentException( 'unknown_index' );
		}
		$ids = self::sanitize_ids( $ids );
		if ( array() === $ids ) {
			return array(
				'upserted' => 0,
				'deleted'  => 0,
				'skipped'  => 0,
			);
		}

		_prime_post_caches( $ids, true, true );

		$upserts = array();
		$deletes = array();
		$skipped = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post
				|| $logical !== $this->indexability->index_for( $post )
				|| ! $this->indexability->is_indexable( $post ) ) {
				$deletes[] = $id;
				continue;
			}
			$document = $this->build( $logical, $post );
			if ( null === $document ) {
				++$skipped;
				$deletes[] = $id;
				// A stale copy must not linger in the index.
				continue;
			}
			$upserts[] = $document;
		}

		$client = $this->clients->client();
		$uid    = $this->names->uid( $logical );
		// Deletes first: an upsert that keeps failing (e.g. a rejected payload) must not keep
		// unpublished or private content reachable in the index.
		if ( array() !== $deletes ) {
			$client->delete_documents( $uid, $deletes );
		}
		if ( array() !== $upserts ) {
			$client->add_documents( $uid, $upserts );
		}

		return array(
			'upserted' => count( $upserts ),
			'deleted'  => count( $deletes ),
			'skipped'  => $skipped,
		);
	}

	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

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
			$this->log->add( 'sync', sprintf( 'Post %1$d could not be converted to a document: %2$s', (int) $post->ID, $e->getMessage() ) );
			return null;
		}
		if ( null === $document ) {
			$this->log->add( 'sync', sprintf( 'Post %d could not be converted to a document and was skipped.', (int) $post->ID ) );
			return null;
		}
		// wp_json_encode() would silently re-encode invalid UTF-8; the raw encoder tells us the truth.
		if ( false === json_encode( $document ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$this->log->add( 'sync', sprintf( 'Post %1$d was skipped: its document cannot be JSON-encoded (%2$s).', (int) $post->ID, json_last_error_msg() ) );
			return null;
		}
		return $document;
	}

	/**
	 * Positive, unique integer IDs.
	 *
	 * @param array<mixed> $ids Raw IDs.
	 * @return list<int>
	 */
	private static function sanitize_ids( array $ids ): array {
		$clean = array();
		foreach ( $ids as $id ) {
			if ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$clean[ $id ] = $id;
				}
			}
		}
		return array_values( $clean );
	}
}
