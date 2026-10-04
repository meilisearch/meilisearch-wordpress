<?php
/**
 * Smoke test for the integration harness.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Plugin;

final class PluginLoadsTest extends TestCase {

	public function test_plugin_is_booted(): void {
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
		$this->assertTrue( defined( 'MEILISEARCH_VERSION' ) );
	}

	public function test_action_scheduler_is_loaded(): void {
		$this->assertTrue( function_exists( 'as_schedule_single_action' ) );
		$this->assertTrue( \ActionScheduler::is_initialized() );
	}

	public function test_set_up_configures_a_unique_prefix(): void {
		$connection = get_option( 'meilisearch_connection' );

		$this->assertMatchesRegularExpression( '/^test_[a-z0-9]{6}$/', $this->prefix );
		$this->assertSame( $this->prefix, $connection['prefix'] );
		$this->assertSame( self::test_host(), $connection['host'] );
		$this->assertSame( self::test_key(), get_option( 'meilisearch_admin_key' ) );
		$this->assertSame( [ 'post', 'page' ], get_option( 'meilisearch_content' )['post_types'] );
	}

	public function test_meilisearch_is_reachable(): void {
		$this->assertSame( 'available', $this->meili( 'GET', '/health' )['status'] ?? null );
		$this->assertArrayHasKey( 'pkgVersion', $this->meili( 'GET', '/version' ) );
	}

	public function test_run_actions_executes_pending_meilisearch_actions(): void {
		$calls = 0;
		add_action(
			'meilisearch_test_noop',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'meilisearch_test_noop', [], 'meilisearch' );

		$this->assertSame( 1, $this->run_actions() );
		$this->assertSame( 1, $calls );
		$this->assertSame( [], $this->action_failures );
		$this->assertSame( 0, $this->run_actions() );
	}

	public function test_index_helpers_round_trip(): void {
		$uid = $this->prefix . '_content';
		$this->meili(
			'POST',
			'/indexes/' . $uid . '/documents?primaryKey=id',
			[
				[
					'id'    => 1,
					'title' => 'Hello',
				],
			]
		);
		$this->wait_for_tasks();

		$this->assertSame(
			[
				[
					'id'    => 1,
					'title' => 'Hello',
				],
			],
			$this->index_documents( $uid )
		);
	}
}
