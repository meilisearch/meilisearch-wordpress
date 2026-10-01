<?php
/**
 * Uninstall routine (spec § 11.5).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Lifecycle;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\WpTransport;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;

/**
 * Uninstall (spec § 11.5). Called by uninstall.php; the main plugin file is not loaded then.
 */
final class Uninstaller {

	private const OPTION_NAMES = array(
		Options::CONNECTION,
		Options::ADMIN_KEY,
		Options::CONTENT,
		Options::WOOCOMMERCE,
		Options::SEARCH,
		Options::STATE,
		Options::LOG,
	);

	private const LOGICALS = array( 'content', 'products' );

	/**
	 * Cleans every site, then the network-level options.
	 */
	public static function run(): void {
		Sites::for_each( array( self::class, 'clean_current_site' ) );
		if ( is_multisite() ) {
			self::clean_networks();
		}
	}

	/**
	 * Removes the remote data (opt-in), the options, the transients and the queued actions of the current site.
	 */
	public static function clean_current_site(): void {
		global $wpdb;

		self::delete_remote_data( new Options() );

		foreach ( self::OPTION_NAMES as $name ) {
			delete_option( $name );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall sweep.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'meilisearch_' ) . '%',
				$wpdb->esc_like( '_transient_meilisearch_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_meilisearch_' ) . '%'
			)
		);
		foreach ( $names as $name ) {
			delete_option( (string) $name );
		}

		Sites::unschedule_actions();
	}

	/**
	 * Deletes network options and site transients with the plugin prefix on every network.
	 */
	private static function clean_networks(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall sweep.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT site_id, meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s",
				$wpdb->esc_like( 'meilisearch_' ) . '%',
				$wpdb->esc_like( '_site_transient_meilisearch_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_meilisearch_' ) . '%'
			)
		);
		foreach ( $rows as $row ) {
			delete_network_option( (int) $row->site_id, (string) $row->meta_key );
		}
	}

	/**
	 * Opt-in: delete this site's indexes and the plugin-created search key. Errors are never reported:
	 * they may echo a key, and the host may be gone.
	 *
	 * @param Options $options Site options.
	 */
	private static function delete_remote_data( Options $options ): void {
		if ( ! $options->delete_on_uninstall() || ! $options->is_configured() ) {
			return;
		}

		$client = new Client( new WpTransport(), $options->host(), $options->admin_key(), 'Meilisearch-WordPress/uninstall WordPress/' . get_bloginfo( 'version' ) );
		$names  = new IndexNames( $options );

		foreach ( self::LOGICALS as $logical ) {
			$uid = $names->uid( $logical );
			try {
				$client->delete_index( $uid );
			} catch ( \Throwable $error ) {
				// Best effort: the host may be gone (spec § 11.5).
				unset( $error );
			}
		}

		$key_uid = $options->search_key_uid();
		if ( '' !== $key_uid && ! (bool) $options->state( 'search_key_manual', false ) ) {
			try {
				$client->delete_key( $key_uid );
			} catch ( \Throwable $error ) {
				// Best effort.
				unset( $error );
			}
		}
	}
}
