<?php
/**
 * Removes a deleted site's data, remote data included when the site opted in.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Lifecycle;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Sync\ErrorLog;

/**
 * When a site of a network is deleted, cleans it as an uninstall would: its indexes and plugin-created search
 * key are deleted from Meilisearch when its "Delete indexes and search key on uninstall" option is on.
 *
 * Runs only where the plugin is loaded during the deletion: always when it is network-active; otherwise only
 * when it is active on the site the deletion runs from (the main site in the network admin).
 */
final class SiteCleaner implements Registrable {

	/**
	 * Core drops the site's tables, options included, at priority 10 of wp_uninitialize_site.
	 */
	public const PRIORITY = 5;

	/**
	 * Maximum seconds per Meilisearch call: a site deletion must not hang on an unreachable host.
	 */
	public const TIMEOUT = 5.0;

	/**
	 * Hosts that failed at the transport level in this request, skipped for the next deleted sites.
	 *
	 * @var array<string, true>
	 */
	private array $unreachable = array();

	/**
	 * Registers the site-deletion hook.
	 */
	public function register(): void {
		add_action( 'wp_uninitialize_site', array( $this, 'clean_deleted_site' ), self::PRIORITY, 1 );
	}

	/**
	 * Cleans the site being deleted. Never throws: a Meilisearch failure must not stop the deletion, it is
	 * recorded in the error log of the network's main site instead.
	 *
	 * @param mixed $site Site being deleted (a WP_Site).
	 */
	public function clean_deleted_site( mixed $site ): void {
		if ( ! $site instanceof \WP_Site ) {
			return;
		}
		$site_id = (int) $site->blog_id;

		switch_to_blog( $site_id );
		try {
			$failures = Uninstaller::clean_current_site( self::TIMEOUT, $this->unreachable );
		} catch ( \Throwable $error ) {
			// The message is not kept: it may echo a key.
			$failures = array( 'cleanup (' . get_class( $error ) . ')' );
		} finally {
			restore_current_blog();
		}

		if ( array() === $failures ) {
			return;
		}
		// Not the current site: wpmu_delete_blog() runs the deletion switched to the site being deleted.
		switch_to_blog( get_main_site_id( (int) $site->network_id ) );
		try {
			( new ErrorLog() )->add(
				'site-deletion',
				sprintf(
					'Site %1$d was deleted, but its data could not be removed from Meilisearch: %2$s. Delete it in Meilisearch directly.',
					$site_id,
					implode( ', ', $failures )
				)
			);
		} finally {
			restore_current_blog();
		}
	}
}
