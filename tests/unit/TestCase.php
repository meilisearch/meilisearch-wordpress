<?php
/**
 * Base class for unit tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Wraps Brain Monkey and provides an in-memory option table.
 */
abstract class TestCase extends PolyfillTestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Option table backing the stubs installed by stub_options().
	 *
	 * @var array<string, mixed>
	 */
	protected array $option_store = [];

	/**
	 * Autoload flag passed to add_option()/update_option() for each option they wrote.
	 *
	 * @var array<string, mixed>
	 */
	protected array $option_autoload = [];

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		// Pure helpers with identical PHP equivalents on PHP 8.1+.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$this->option_store    = [];
		$this->option_autoload = [];
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Backs get_option(), add_option(), update_option() and delete_option() with $this->option_store.
	 *
	 * @param array<string, mixed> $initial Option name => value.
	 */
	protected function stub_options( array $initial = [] ): void {
		$this->option_store    = $initial;
		$this->option_autoload = [];

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				return array_key_exists( $name, $this->option_store ) ? $this->option_store[ $name ] : $default_value;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value = '', $deprecated = '', $autoload = null ): bool {
				if ( array_key_exists( $name, $this->option_store ) ) {
					return false;
				}
				$this->option_store[ $name ]    = $value;
				$this->option_autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				$this->option_store[ $name ] = $value;
				if ( null !== $autoload ) {
					$this->option_autoload[ $name ] = $autoload;
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				$existed = array_key_exists( $name, $this->option_store );
				unset( $this->option_store[ $name ] );
				return $existed;
			}
		);
	}
}
