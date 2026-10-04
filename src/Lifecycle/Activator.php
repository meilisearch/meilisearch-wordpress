<?php
/**
 * Activation (spec § 11.1).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Lifecycle;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Settings\Options;

/**
 * Activation (spec § 11.1): seed default options, no remote calls.
 */
final class Activator {

	/**
	 * Seeds the default options of the current site; no remote calls.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		// A network activation seeds only the current site as well: iterating every site here does not
		// scale. SiteSeeder seeds new sites on wp_initialize_site and existing ones on their first admin load.
		unset( $network_wide );
		( new Options() )->seed_defaults();
	}
}
