<?php
/**
 * Tests for Sites.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Lifecycle;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Lifecycle\Sites;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Per-site iteration and its runtime cache flush.
 */
final class SitesTest extends TestCase {

	/**
	 * Stubs a network of $count sites; get_sites() pages through them.
	 *
	 * @param int $count Number of sites.
	 */
	private function stub_network( int $count ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->alias(
			static fn ( array $query ): array => array_slice( range( 1, $count ), (int) $query['offset'], (int) $query['number'] )
		);
		Functions\when( 'switch_to_blog' )->justReturn( true );
		Functions\when( 'restore_current_blog' )->justReturn( true );
	}

	/**
	 * An object cache without runtime flushing is left alone: core's wp_cache_flush_runtime() would only
	 * report _doing_it_wrong, and wp_cache_flush() would wipe a shared persistent cache.
	 */
	public function test_for_each_does_not_flush_a_cache_without_runtime_flush_support(): void {
		$this->stub_network( 3 );
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\expect( 'wp_cache_flush_runtime' )->never();
		Functions\expect( 'wp_cache_flush' )->never();

		$visits = 0;
		Sites::for_each(
			static function () use ( &$visits ): void {
				++$visits;
			}
		);

		$this->assertSame( 3, $visits );
	}

	/**
	 * A cache that supports it is flushed once per batch of sites.
	 */
	public function test_for_each_flushes_the_runtime_cache_once_per_batch(): void {
		$this->stub_network( Sites::BATCH + 50 );
		Functions\when( 'wp_cache_supports' )->alias( static fn ( string $feature ): bool => 'flush_runtime' === $feature );
		Functions\expect( 'wp_cache_flush_runtime' )->twice()->andReturn( true );
		Functions\expect( 'wp_cache_flush' )->never();

		$visits = 0;
		Sites::for_each(
			static function () use ( &$visits ): void {
				++$visits;
			}
		);

		$this->assertSame( Sites::BATCH + 50, $visits );
	}

	/**
	 * Single site: the callback runs once and no cache is touched.
	 */
	public function test_for_each_runs_once_on_a_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'wp_cache_flush_runtime' )->never();

		$visits = 0;
		Sites::for_each(
			static function () use ( &$visits ): void {
				++$visits;
			}
		);

		$this->assertSame( 1, $visits );
	}
}
