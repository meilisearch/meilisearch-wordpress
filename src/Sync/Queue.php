<?php
/**
 * Thin wrapper over the Action Scheduler functions used by the plugin.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules, cancels and counts the plugin's background actions (group "meilisearch").
 */
final class Queue {

	public const GROUP         = 'meilisearch';
	public const SYNC_POSTS    = 'meilisearch_sync_posts';
	public const SYNC_TERM     = 'meilisearch_sync_term';
	public const REINDEX_BATCH = 'meilisearch_reindex_batch';
	public const COUNT_CAP     = 1000;

	/**
	 * Schedules a single action. The payload is passed to the handler as its only argument,
	 * so every handler must be registered with accepted_args = 1.
	 *
	 * @param string              $hook    Action hook.
	 * @param array<string,mixed> $payload Handler payload.
	 * @param int                 $delay   Seconds from now.
	 * @return int Action ID, 0 when Action Scheduler could not store the action.
	 */
	public function schedule( string $hook, array $payload, int $delay = 0 ): int {
		return (int) as_schedule_single_action( time() + max( 0, $delay ), $hook, array( $payload ), self::GROUP );
	}

	/**
	 * Cancels every pending action of the plugin's group.
	 */
	public function cancel_all(): void {
		as_unschedule_all_actions( '', array(), self::GROUP );
	}

	/**
	 * Counts the plugin's actions in a status, capped at COUNT_CAP.
	 *
	 * @param string $status 'pending' or 'failed'.
	 * @return int
	 * @throws \InvalidArgumentException For any other status.
	 */
	public function count( string $status ): int {
		if ( ! in_array( $status, array( 'pending', 'failed' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported action status.' );
		}
		$ids = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => $status,
				'per_page' => self::COUNT_CAP,
				'orderby'  => 'none',
			),
			'ids'
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}
}
