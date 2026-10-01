<?php
/**
 * Integration test bootstrap: loads WordPress through wp-phpunit, then WooCommerce (optional) and the plugin.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

$meilisearch_root = dirname( __DIR__, 2 );

// Loads the PHPUnit Polyfills and sets WP_PHPUNIT__DIR.
require_once $meilisearch_root . '/vendor/autoload.php';

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- wp-phpunit reads the config path from the environment.
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$meilisearch_wp_tests = (string) getenv( 'WP_PHPUNIT__DIR' );

require_once $meilisearch_wp_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $meilisearch_root ): void {
		if ( '1' === getenv( 'MEILISEARCH_TEST_WC' ) ) {
			require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
			if ( ! class_exists( 'WC_Unit_Tests_Bootstrap' ) ) {
				// Marker WooCommerce's own suite defines: wc_get_product_visibility_term_ids() then skips its static cache,
				// which _delete_all_data() (tear_down_after_class deletes all terms) would leave holding stale term IDs.
				class WC_Unit_Tests_Bootstrap {}
			}
		}
		require_once $meilisearch_root . '/meilisearch.php';
	}
);

// Create the WooCommerce tables and roles once WordPress is loaded.
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( 'WC_Install' ) ) {
			// The test install has no theme; like Storefront or block themes, declare WooCommerce support before
			// WC_Install::install() registers the product post type (it then has an archive, so WooCommerce runs
			// its real product query for product searches).
			add_theme_support( 'woocommerce' );
			WC_Install::install();
			$GLOBALS['wp_roles'] = null;
			wp_roles();
		}
	}
);

// Actions run only through TestCase::run_actions().
tests_add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );

require $meilisearch_wp_tests . '/includes/bootstrap.php';
