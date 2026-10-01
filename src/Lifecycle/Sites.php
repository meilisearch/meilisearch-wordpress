<?php
/**
 * Per-site iteration and Action Scheduler cleanup shared by deactivation and uninstall.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Lifecycle;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Sync\Queue;

/**
 * Per-site iteration and Action Scheduler cleanup shared by deactivation and uninstall.
 */
final class Sites {

	public const BATCH = 100;

	/**
	 * Runs $callback once for every site (all networks), switched to that site. Single site: runs it once.
	 *
	 * @param callable $callback Called with no argument while switched to each site.
	 */
	public static function for_each( callable $callback ): void {
		if ( ! is_multisite() ) {
			$callback();
			return;
		}

		$offset = 0;
		$count  = 0;
		do {
			$ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => self::BATCH,
					'offset'                 => $offset,
					'orderby'                => 'id',
					'order'                  => 'ASC',
					'update_site_meta_cache' => false,
				)
			);
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try {
					$callback();
				} finally {
					restore_current_blog();
				}
			}
			$offset += self::BATCH;
			$count   = count( $ids );
		} while ( self::BATCH === $count );
	}

	/**
	 * Cancels the plugin's pending actions on the current site.
	 */
	public static function unschedule_actions(): void {
		if ( ! class_exists( 'ActionScheduler', false ) || ! \ActionScheduler::is_initialized() || ! self::action_tables_exist() ) {
			return;
		}
		as_unschedule_all_actions( '', array(), Queue::GROUP );
	}

	/**
	 * Whether the Action Scheduler actions table exists on the current site.
	 *
	 * @return bool
	 */
	private static function action_tables_exist(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, run once per site on deactivation/uninstall.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
