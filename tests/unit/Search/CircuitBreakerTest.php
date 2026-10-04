<?php
/**
 * CircuitBreaker tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Search\CircuitBreaker;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Transient-backed breaker.
 *
 * @covers \Meilisearch\WordPress\Search\CircuitBreaker
 */
final class CircuitBreakerTest extends TestCase {

	/**
	 * Transient name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = array();

	/**
	 * Stubs get_transient() over the in-memory store.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->store = array();
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return $this->store[ $name ] ?? false;
			}
		);
	}

	/**
	 * Closed until tripped; trip stores the transient for 60 s.
	 */
	public function test_trip_opens_the_breaker_for_ttl(): void {
		Functions\expect( 'set_transient' )->once()->with( CircuitBreaker::TRANSIENT, 1, 60 )->andReturnUsing(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		$breaker = new CircuitBreaker();
		self::assertFalse( $breaker->is_open() );

		$breaker->trip();

		self::assertTrue( $breaker->is_open() );
	}

	/**
	 * An expired transient closes it again.
	 */
	public function test_expired_transient_closes_the_breaker(): void {
		$this->store[ CircuitBreaker::TRANSIENT ] = 1;
		$breaker                                  = new CircuitBreaker();
		self::assertTrue( $breaker->is_open() );

		unset( $this->store[ CircuitBreaker::TRANSIENT ] );

		self::assertFalse( $breaker->is_open() );
	}
}
