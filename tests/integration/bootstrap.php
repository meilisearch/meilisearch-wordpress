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
		}
		require_once $meilisearch_root . '/meilisearch.php';
	}
);

// Create the WooCommerce tables and roles once WordPress is loaded.
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = null;
			wp_roles();
		}
	}
);

// Actions run only through TestCase::run_actions().
tests_add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );

require $meilisearch_wp_tests . '/includes/bootstrap.php';
