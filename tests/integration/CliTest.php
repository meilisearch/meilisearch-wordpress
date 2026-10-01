<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Ops\Cli;
use Meilisearch\WordPress\Ops\SiteHealth;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Sync\SyncJob;
use Meilisearch\WordPress\Tests\Integration\Support\CliExit;
use Meilisearch\WordPress\Tests\Integration\Support\ShimProgressBar;
use WP_CLI;

final class CliTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once __DIR__ . '/Support/wp-cli-shim.php';
	}

	public function set_up(): void {
		parent::set_up();
		WP_CLI::reset();
	}

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

	/**
	 * A fresh command object with a fresh (non-memoized) SiteHealth.
	 */
	private function cli(): Cli {
		$health = new SiteHealth(
			$this->service( 'clients', ClientFactory::class ),
			$this->service( 'index_manager', IndexManager::class ),
			$this->service( 'names', IndexNames::class ),
			$this->service( 'reindexer', Reindexer::class ),
			$this->service( 'queue', Queue::class ),
			$this->service( 'options', Options::class )
		);

		return new Cli(
			$this->service( 'reindexer', Reindexer::class ),
			$this->service( 'sync_job', SyncJob::class ),
			$this->service( 'clients', ClientFactory::class ),
			$this->service( 'names', IndexNames::class ),
			$health,
			$this->service( 'indexability', Indexability::class ),
			$this->service( 'queue', Queue::class )
		);
	}

	private function hits( string $query ): int {
		$uid    = $this->service( 'names', IndexNames::class )->uid( 'content' );
		$result = $this->service( 'clients', ClientFactory::class )->client()->search(
			$uid,
			array(
				'q'     => $query,
				'limit' => 50,
			)
		);

		return count( (array) ( $result['hits'] ?? array() ) );
	}

	private function documents(): int {
		$uid   = $this->service( 'names', IndexNames::class )->uid( 'content' );
		$stats = $this->service( 'clients', ClientFactory::class )->client()->index_stats( $uid );

		return (int) ( $stats['numberOfDocuments'] ?? 0 );
	}

	/**
	 * @return list<string>
	 */
	private function messages( string $type ): array {
		$messages = array();
		foreach ( WP_CLI::$calls as $call ) {
			if ( $type === $call[0] ) {
				$messages[] = $call[1];
			}
		}

		return $messages;
	}

	private function expect_exit( callable $command, int $code ): void {
		try {
			$command();
			self::fail( 'Expected the command to exit.' );
		} catch ( CliExit $exit ) {
			self::assertSame( $code, $exit->getCode() );
		}
	}

	public function test_reindex_indexes_published_posts_and_reports_progress(): void {
		self::factory()->post->create_many(
			3,
			array(
				'post_status' => 'publish',
				'post_title'  => 'Clitestalpha entry',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Clitestalpha draft',
			)
		);

		$this->cli()->reindex(
			array(),
			array(
				'index'      => 'content',
				'batch-size' => '2',
			)
		);

		self::assertSame( 3, $this->hits( 'Clitestalpha' ) );
		$bar = WP_CLI::$bars[0] ?? null;
		self::assertInstanceOf( ShimProgressBar::class, $bar );
		self::assertTrue( $bar->finished );
		self::assertSame( $this->service( 'reindexer', Reindexer::class )->count_indexable( 'content' ), $bar->ticks );
		self::assertStringContainsString( 'Reindexed', $this->messages( 'success' )[0] ?? '' );
	}

	public function test_reindex_rejects_an_unknown_index(): void {
		$this->expect_exit( fn () => $this->cli()->reindex( array(), array( 'index' => 'orders' ) ), 1 );

		self::assertStringContainsString( 'Unknown or inactive index', $this->messages( 'error' )[0] ?? '' );
	}

	public function test_reindex_rejects_a_non_positive_batch_size(): void {
		$this->expect_exit( fn () => $this->cli()->reindex( array(), array( 'batch-size' => '0' ) ), 1 );

		self::assertStringContainsString( '--batch-size', $this->messages( 'error' )[0] ?? '' );
	}

	public function test_sync_reconciles_posts_immediately(): void {
		$this->cli()->reindex( array(), array( 'index' => 'content' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Clitestbravo',
			)
		);

		$this->cli()->sync( array( (string) $post_id ), array() );
		$this->wait_for_tasks();
		self::assertSame( 1, $this->hits( 'Clitestbravo' ) );
		self::assertStringContainsString( '1 upserted', (string) end( WP_CLI::$calls )[1] );

		wp_trash_post( $post_id );
		$this->cli()->sync( array( (string) $post_id ), array() );
		$this->wait_for_tasks();
		self::assertSame( 0, $this->hits( 'Clitestbravo' ) );
		self::assertStringContainsString( '1 deleted', (string) end( WP_CLI::$calls )[1] );
	}

	public function test_sync_rejects_an_invalid_id(): void {
		$this->expect_exit( fn () => $this->cli()->sync( array( '12abc' ), array() ), 1 );

		self::assertStringContainsString( 'not a valid post ID', $this->messages( 'error' )[0] ?? '' );
	}

	public function test_clear_asks_for_confirmation_then_deletes_documents(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Clitestcharlie',
			)
		);
		$this->cli()->reindex( array(), array( 'index' => 'content' ) );

		WP_CLI::$confirm = false;
		$this->expect_exit( fn () => $this->cli()->clear( array(), array( 'index' => 'content' ) ), 0 );
		self::assertSame( 1, $this->hits( 'Clitestcharlie' ) );

		$this->cli()->clear(
			array(),
			array(
				'index' => 'content',
				'yes'   => true,
			)
		);
		self::assertSame( 0, $this->documents() );
	}

	public function test_status_lists_every_active_index(): void {
		$this->cli()->reindex( array(), array( 'index' => 'content' ) );

		$this->cli()->status( array(), array( 'format' => 'json' ) );

		$names = $this->service( 'names', IndexNames::class );
		$table = WP_CLI::$items[0];
		self::assertSame( 'json', $table['format'] );
		self::assertSame( array( 'index', 'uid', 'documents', 'indexable', 'status', 'phase', 'sent', 'total', 'deleted' ), $table['fields'] );
		self::assertContains( $table['items'][0]['status'], array( 'done', 'idle' ) );
		self::assertSame( $names->active_logicals(), array_column( $table['items'], 'index' ) );
		self::assertSame( $names->uid( 'content' ), $table['items'][0]['uid'] );
		self::assertMatchesRegularExpression( '/^\d+$/', $table['items'][0]['documents'] );
		self::assertNotEmpty( array_filter( $this->messages( 'log' ), static fn ( string $m ): bool => str_starts_with( $m, 'Queue:' ) ) );
	}

	public function test_check_passes_without_critical_results_on_a_healthy_site(): void {
		$this->service( 'index_manager', IndexManager::class )->connect();
		$this->cli()->reindex( array(), array() );

		$this->cli()->check( array(), array() );

		self::assertSame( array(), $this->messages( 'error' ) );
		self::assertSame( array( 'test', 'status', 'label' ), WP_CLI::$items[0]['fields'] );
	}

	public function test_check_exits_with_status_1_when_meilisearch_is_unreachable(): void {
		if ( defined( 'MEILISEARCH_HOST' ) ) {
			self::markTestSkipped( 'MEILISEARCH_HOST overrides the host option.' );
		}
		update_option( Options::CONNECTION, array_merge( (array) get_option( Options::CONNECTION, array() ), array( 'host' => 'http://127.0.0.1:1' ) ) );

		$this->expect_exit( fn () => $this->cli()->check( array(), array() ), 1 );

		self::assertStringContainsString( 'critical', $this->messages( 'error' )[0] ?? '' );
	}

	public function test_status_never_prints_the_admin_key_from_a_failed_run(): void {
		$options = $this->service( 'options', Options::class );
		$options->set_reindex_state(
			'content',
			array(
				'run'    => 'abc',
				'phase'  => 'upsert',
				'sent'   => 1,
				'total'  => 2,
				'status' => 'failed',
				'error'  => 'API key `' . self::test_key() . '` not found',
			)
		);

		$this->cli()->status( array(), array( 'format' => 'json' ) );

		self::assertStringNotContainsString( self::test_key(), (string) wp_json_encode( WP_CLI::$items ) );
		self::assertStringContainsString( 'failed', (string) WP_CLI::$items[0]['items'][0]['status'] );
	}
}
