<?php
/**
 * Site Health tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Ops\SiteHealth;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\Queue;

final class SiteHealthTest extends TestCase {

	/**
	 * @template T of object
	 * @param class-string<T> $type
	 * @return T
	 */
	private function service( string $id, string $type ): object {
		$plugin = Plugin::instance();
		self::assertNotNull( $plugin );
		$service = $plugin->get( $id );
		self::assertInstanceOf( $type, $service );

		return $service;
	}

	private function fresh_health(): SiteHealth {
		return new SiteHealth(
			$this->service( 'clients', ClientFactory::class ),
			$this->service( 'index_manager', IndexManager::class ),
			$this->service( 'names', IndexNames::class ),
			$this->service( 'reindexer', Reindexer::class ),
			$this->service( 'queue', Queue::class ),
			$this->service( 'options', Options::class )
		);
	}

	/**
	 * @return array<string, string> test id => status
	 */
	private function statuses(): array {
		return array_column( $this->fresh_health()->run_all(), 'status', 'test' );
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	private function update_connection( array $changes ): void {
		update_option( Options::CONNECTION, array_merge( (array) get_option( Options::CONNECTION, array() ), $changes ) );
	}

	private function reindex_content(): void {
		$this->service( 'reindexer', Reindexer::class )->run_sync( 'content', 50, static function (): void {} );
		$this->wait_for_tasks();
	}

	public function test_connected_and_reindexed_site_is_healthy(): void {
		$this->service( 'index_manager', IndexManager::class )->connect();
		self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		$this->reindex_content();

		$statuses = $this->statuses();

		self::assertSame( 'good', $statuses[ SiteHealth::TEST_CONNECTION ] );
		self::assertSame( 'good', $statuses[ SiteHealth::TEST_DOCUMENTS ] );
		self::assertSame( 'good', $statuses[ SiteHealth::TEST_SETTINGS ] );
		self::assertSame( 'good', $statuses[ SiteHealth::TEST_SEARCH_KEY ] );
	}

	public function test_unsynced_posts_are_reported_as_drift(): void {
		self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		$this->reindex_content();
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		self::assertSame( 'recommended', $this->statuses()[ SiteHealth::TEST_DOCUMENTS ] );
	}

	public function test_unreachable_host_is_critical_and_remote_checks_are_skipped(): void {
		if ( defined( 'MEILISEARCH_HOST' ) ) {
			self::markTestSkipped( 'MEILISEARCH_HOST overrides the host option.' );
		}
		$this->update_connection( array( 'host' => 'http://127.0.0.1:1' ) );

		$results  = $this->fresh_health()->run_all();
		$statuses = array_column( $results, 'status', 'test' );

		self::assertSame( 'critical', $statuses[ SiteHealth::TEST_CONNECTION ] );
		self::assertSame( 'recommended', $statuses[ SiteHealth::TEST_DOCUMENTS ] );
		self::assertSame( 'recommended', $statuses[ SiteHealth::TEST_SEARCH_KEY ] );
	}

	public function test_unconfigured_site_runs_only_local_checks(): void {
		if ( defined( 'MEILISEARCH_ADMIN_KEY' ) ) {
			self::markTestSkipped( 'MEILISEARCH_ADMIN_KEY overrides the admin key option.' );
		}
		update_option( Options::ADMIN_KEY, '' );

		$statuses = $this->statuses();

		self::assertSame( array( SiteHealth::TEST_CONNECTION, SiteHealth::TEST_QUEUE, SiteHealth::TEST_REINDEX ), array_keys( $statuses ) );
		self::assertSame( 'recommended', $statuses[ SiteHealth::TEST_CONNECTION ] );
	}

	public function test_site_status_tests_filter_registers_callable_direct_tests(): void {
		$tests = apply_filters(
			'site_status_tests',
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);

		self::assertArrayHasKey( SiteHealth::TEST_CONNECTION, $tests['direct'] );
		self::assertIsCallable( $tests['direct'][ SiteHealth::TEST_CONNECTION ]['test'] );

		$result = call_user_func( $tests['direct'][ SiteHealth::TEST_CONNECTION ]['test'] );
		self::assertSame( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ), array_keys( $result ) );
	}
}
