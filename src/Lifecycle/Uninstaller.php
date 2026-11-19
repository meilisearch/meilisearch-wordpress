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
		$unreachable = array();
		self::delete_remote_data( $plans, Client::WRITE_TIMEOUT, $unreachable );
	}

	/**
	 * Removes the queued actions, options and transients of the current site, then its remote data (opt-in).
	 * Used when a site of a network is deleted (SiteCleaner), while its options still exist.
	 *
	 * @param float              $timeout     Maximum seconds per remote call.
	 * @param array<string,true> $unreachable Hosts that already failed at the transport level, skipped; hosts
	 *                                        failing now are added, so a caller cleaning several sites in one
	 *                                        request waits for an unreachable host only once.
	 * @return list<string> The remote objects that could not be deleted, each with the error code; empty when
	 *                      everything was deleted or the site did not opt in.
	 */
	public static function clean_current_site( float $timeout = Client::WRITE_TIMEOUT, array &$unreachable = array() ): array {
		$plan = self::clean_site();
		if ( null === $plan ) {
			return array();
		}

		return self::delete_remote_data( array( $plan ), $timeout, $unreachable );
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
	 * call to that host is skipped. Error messages are never kept: they may echo a key, and the host may be gone.
	 *
	 * @param list<array{host: string, key: string, uids: list<string>, key_uid: string}> $plans       Captured per site.
	 * @param float                                                                       $timeout     Maximum seconds per call.
	 * @param array<string,true>                                                          $unreachable Hosts to skip; failing hosts are added.
	 * @return list<string> What could not be deleted ("index <uid>" or "search key <uid>"), each with the error code.
	 */
	private static function delete_remote_data( array $plans, float $timeout, array &$unreachable ): array {
		$failures   = array();
		$user_agent = 'Meilisearch-WordPress/uninstall WordPress/' . get_bloginfo( 'version' );

		foreach ( $plans as $plan ) {
			$client = new Client( new CappedTransport( new WpTransport(), $timeout ), $plan['host'], $plan['key'], $user_agent );
			$calls  = array();
			foreach ( $plan['uids'] as $uid ) {
				$calls[ 'index ' . $uid ] = static fn() => $client->delete_index( $uid );
			}
			if ( '' !== $plan['key_uid'] ) {
				$calls[ 'search key ' . $plan['key_uid'] ] = static fn() => $client->delete_key( $plan['key_uid'] );
			}

			foreach ( $calls as $target => $call ) {
				if ( isset( $unreachable[ $plan['host'] ] ) ) {
					$failures[] = $target . ' (transport_error)';
					continue;
				}
				try {
					$call();
				} catch ( ApiError $error ) {
					// Best effort (spec § 11.5). A key that is already gone is not a failure.
					if ( 404 === $error->http_status ) {
						continue;
					}
					$failures[] = $target . ' (' . $error->error_code . ')';
					if ( 'transport_error' === $error->error_code ) {
						$unreachable[ $plan['host'] ] = true;
					}
				}
			}
		}//end foreach

		return $failures;
	}
}
