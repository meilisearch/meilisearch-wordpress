<?php
/**
 * WordPress test-suite configuration (loaded by wp-phpunit through WP_PHPUNIT__TESTS_CONFIG).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

if ( ! function_exists( 'meilisearch_tests_env' ) ) {
	/**
	 * Reads an environment variable with a default.
	 *
	 * @param string $name          Variable name.
	 * @param string $default_value Value when unset or empty.
	 */
	function meilisearch_tests_env( string $name, string $default_value ): string {
		$value = getenv( $name );
		return ( false === $value || '' === $value ) ? $default_value : $value;
	}
}

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/wordpress/' );
define( 'WP_PLUGIN_DIR', __DIR__ . '/plugins' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_HOST', meilisearch_tests_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_NAME', meilisearch_tests_env( 'WP_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', meilisearch_tests_env( 'WP_TESTS_DB_USER', 'wp' ) );
define( 'DB_PASSWORD', meilisearch_tests_env( 'WP_TESTS_DB_PASSWORD', 'wp' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Meilisearch Tests' );
define( 'WP_PHP_BINARY', PHP_BINARY );
