<?php
/**
 * Tests for the composition root.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit;

use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Registrable;
use ReflectionClass;
use ReflectionMethod;

final class PluginTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Plugin::reset();
		// The composition root reads options while building services.
		$this->stub_options();
	}

	protected function tear_down(): void {
		Plugin::reset();
		parent::tear_down();
	}

	public function test_instance_is_null_before_boot(): void {
		$this->assertNull( Plugin::instance() );
	}

	public function test_boot_is_idempotent(): void {
		Plugin::boot();
		$first = Plugin::instance();
		Plugin::boot();

		$this->assertInstanceOf( Plugin::class, $first );
		$this->assertSame( $first, Plugin::instance() );
	}

	public function test_reset_drops_the_instance(): void {
		Plugin::boot();
		Plugin::reset();

		$this->assertNull( Plugin::instance() );
	}

	public function test_get_unknown_id_throws(): void {
		Plugin::boot();

		$this->expectException( \OutOfBoundsException::class );
		$this->expectExceptionMessage( 'Unknown Meilisearch service "nope".' );

		Plugin::instance()->get( 'nope' );
	}

	public function test_registrable_services_are_registered_once(): void {
		$spy    = new class() implements Registrable {
			public int $calls = 0;

			public function register(): void {
				++$this->calls;
			}
		};
		$plain  = new \stdClass();
		$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();

		$this->call_private( $plugin, 'add', 'spy', $spy );
		$this->call_private( $plugin, 'add', 'plain', $plain );
		$this->call_private( $plugin, 'register_services' );

		$this->assertSame( 1, $spy->calls );
		$this->assertSame( $spy, $plugin->get( 'spy' ) );
		$this->assertSame(
			[
				'spy'   => $spy,
				'plain' => $plain,
			],
			$plugin->services()
		);
	}

	public function test_duplicate_service_id_throws(): void {
		$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$this->call_private( $plugin, 'add', 'one', new \stdClass() );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'Meilisearch service "one" is already registered.' );

		$this->call_private( $plugin, 'add', 'one', new \stdClass() );
	}

	/**
	 * Invokes a private method (PHP 8.1+ reflection needs no setAccessible()).
	 *
	 * @param object $target Object.
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call_private( object $target, string $method, ...$args ) {
		return ( new ReflectionMethod( $target, $method ) )->invoke( $target, ...$args );
	}
}
