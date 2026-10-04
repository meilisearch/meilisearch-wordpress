<?php
/**
 * Uninstall routine (spec § 11.5).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Lifecycle;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Admin\Notices;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\WpTransport;
use Meilisearch\WordPress\Search\CircuitBreaker;
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
	 * Cleans every site locally first (one pass, so a killed request leaves little behind), then the network-level
	 * options, then the remote data of the sites that opted in.
	 */
	public static function run(): void {
		$plans = array();
		Sites::for_each(
			static function () use ( &$plans ): void {
				$plan = self::clean_site();
				if ( null !== $plan ) {
					$plans[] = $plan;
				}
			}
		);
		if ( is_multisite() ) {
			self::clean_networks();
		}
		// Notice dismissals are per user, shared by every site.
		delete_metadata( 'user', 0, Notices::DISMISSED_META, '', true );
		self::delete_remote_data( $plans );
	}

	/**
	 * Removes the queued actions, options and transients of the current site, then its remote data (opt-in).
	 */
	public static function clean_current_site(): void {
		$plan = self::clean_site();
		if ( null !== $plan ) {
			self::delete_remote_data( array( $plan ) );
		}
	}

	/**
	 * Cleans the current site locally.
	 *
	 * @return array{host: string, key: string, uids: list<string>, key_uid: string}|null What the remote cleanup
	 *         needs, captured before the options are gone; null when the site did not opt in.
	 */
	private static function clean_site(): ?array {
		global $wpdb;

		$options = new Options();
		$plan    = null;
		if ( $options->delete_on_uninstall() && $options->is_configured() ) {
			$names = new IndexNames( $options );
			$plan  = array(
				'host'    => $options->host(),
				'key'     => $options->admin_key(),
				'uids'    => array_map( array( $names, 'uid' ), self::LOGICALS ),
				'key_uid' => (bool) $options->state( 'search_key_manual', false ) ? '' : $options->search_key_uid(),
			);
		}

		Sites::unschedule_actions();

		foreach ( self::OPTION_NAMES as $name ) {
			delete_option( $name );
		}
		delete_transient( CircuitBreaker::TRANSIENT );

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

		return $plan;
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
	 * Deletes the indexes and plugin-created search keys of the sites that opted in. Only this site's own index
	 * uids and the stored key uid are used. After a transport failure (timeout, refused connection) every further
	 * call to that host is skipped. Errors are never reported: they may echo a key, and the host may be gone.
	 *
	 * @param list<array{host: string, key: string, uids: list<string>, key_uid: string}> $plans Captured per site.
	 */
	private static function delete_remote_data( array $plans ): void {
		$unreachable = array();
		$user_agent  = 'Meilisearch-WordPress/uninstall WordPress/' . get_bloginfo( 'version' );

		foreach ( $plans as $plan ) {
			$client = new Client( new WpTransport(), $plan['host'], $plan['key'], $user_agent );
			$calls  = array();
			foreach ( $plan['uids'] as $uid ) {
				$calls[] = static fn() => $client->delete_index( $uid );
			}
			if ( '' !== $plan['key_uid'] ) {
				$calls[] = static fn() => $client->delete_key( $plan['key_uid'] );
			}

			foreach ( $calls as $call ) {
				if ( isset( $unreachable[ $plan['host'] ] ) ) {
					break;
				}
				try {
					$call();
				} catch ( ApiError $error ) {
					// Best effort (spec § 11.5).
					if ( 'transport_error' === $error->error_code ) {
						$unreachable[ $plan['host'] ] = true;
					}
				}
			}
		}//end foreach
	}
}
