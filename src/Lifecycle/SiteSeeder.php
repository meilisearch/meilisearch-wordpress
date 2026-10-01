<?php
/**
 * Seeds default options on new sites and on sites that predate a network activation.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Lifecycle;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\Options;

/**
 * Seeds default options on new sites and, lazily, on sites that existed before a network activation.
 */
final class SiteSeeder implements Registrable {

	/**
	 * Registers the site-creation and lazy admin seeding hooks.
	 */
	public function register(): void {
		add_action( 'wp_initialize_site', array( $this, 'seed_new_site' ), 200, 1 );
		add_action( 'admin_init', array( $this, 'seed_current_site' ), 1, 0 );
	}

	/**
	 * Seeds a freshly created site when the plugin is network-active.
	 *
	 * @param \WP_Site $site New site.
	 */
	public function seed_new_site( \WP_Site $site ): void {
		if ( ! self::is_network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			( new Options() )->seed_defaults();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Seeds the current site once, when its content option is missing.
	 */
	public function seed_current_site(): void {
		// meilisearch_content is autoloaded: this check costs no query once the site is seeded.
		if ( false !== get_option( Options::CONTENT, false ) ) {
			return;
		}
		( new Options() )->seed_defaults();
	}

	/**
	 * Whether the plugin is network-activated.
	 *
	 * @return bool
	 */
	private static function is_network_active(): bool {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( MEILISEARCH_FILE ) );
	}
}
