<?php
/**
 * Deactivation (spec § 11.1).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation (spec § 11.1): cancel pending actions; options and indexes are kept.
 */
final class Deactivator {

	/**
	 * Cancels the plugin's pending actions, on every site for a network deactivation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			Sites::for_each( array( Sites::class, 'unschedule_actions' ), get_current_network_id() );
			return;
		}
		Sites::unschedule_actions();
	}
}
