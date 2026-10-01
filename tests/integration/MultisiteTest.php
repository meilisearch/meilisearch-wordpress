<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Api\WpTransport;
use Meilisearch\WordPress\Indexing\ContentDocumentBuilder;
use Meilisearch\WordPress\Indexing\ContentSchema;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ChangeCollector;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;

/**
 * Runs only in the multisite suite: WP_MULTISITE=1 composer test:integration
 */
final class MultisiteTest extends TestCase {

	/**
	 * Index UIDs created on the second site, deleted in tear_down.
	 *
	 * @var list<string>
	 */
	private array $created = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Multisite only. Run with WP_MULTISITE=1.' );
		}
	}

	public function tear_down(): void {
		if ( array() !== $this->created ) {
			$client = $this->service( 'clients', ClientFactory::class )->client();
			foreach ( $this->created as $uid ) {
				$client->delete_index( $uid );
			}
			$this->wait_for_tasks();
		}
		parent::tear_down();
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
	 * Content-only services bound to the current (switched) site, mirroring Plugin::build().
	 *
	 * @return array{names: IndexNames, reindexer: Reindexer}
	 */
	private function site_services(): array {
		$options  = new Options();
		$names    = new IndexNames( $options );
		$clients  = new ClientFactory( $options, new WpTransport() );
		$settings = new SettingsBuilder( array( 'content' => new ContentSchema( $options ) ) );
		$indexes  = new IndexManager( $clients, $names, $settings, $options );

		return array(
			'names'     => $names,
			'reindexer' => new Reindexer( $clients, $indexes, $names, new Indexability( $options ), array( 'content' => new ContentDocumentBuilder( $options ) ), new Queue(), $options, new ErrorLog() ),
		);
	}

	/**
	 * Action Scheduler creates its tables per site on that site's own requests; create them for the test site.
	 */
	private function ensure_action_scheduler_tables(): void {
		( new \ActionScheduler_StoreSchema() )->register_tables( true );
		( new \ActionScheduler_LoggerSchema() )->register_tables( true );
	}

	private function hits( string $uid, string $query ): int {
		$result = $this->service( 'clients', ClientFactory::class )->client()->search( $uid, array( 'q' => $query ) );

		return count( (array) ( $result['hits'] ?? array() ) );
	}

	public function test_new_site_is_seeded_and_gets_independent_indexes(): void {
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( MEILISEARCH_FILE ) => time() ) );
		$main_options = $this->service( 'options', Options::class );
		$main_names   = $this->service( 'names', IndexNames::class );
		$main_prefix  = $main_names->prefix();
		$host         = $main_options->host();
		$admin_key    = $main_options->admin_key();
		$content      = array_merge( Options::defaults()[ Options::CONTENT ], array( 'post_types' => array( 'post' ) ) );
		update_option( Options::CONTENT, $content );

		$site_id = self::factory()->blog->create();

		self::assertNotFalse( get_blog_option( $site_id, Options::CONTENT ), 'wp_initialize_site must seed the new site.' );
		self::assertNotSame(
			IndexNames::default_prefix( network_home_url(), 1, true ),
			IndexNames::default_prefix( network_home_url(), $site_id, true )
		);

		switch_to_blog( $site_id );
		try {
			$this->ensure_action_scheduler_tables();
			update_option( Options::CONTENT, $content );
			update_option(
				Options::CONNECTION,
				array_merge(
					(array) get_option( Options::CONNECTION, array() ),
					array(
						'host'   => $host,
						'prefix' => $main_prefix . '_site2',
					)
				)
			);
			update_option( Options::ADMIN_KEY, $admin_key );

			$site = $this->site_services();
			self::assertSame( $main_prefix . '_site2', $site['names']->prefix() );
			$site_uid        = $site['names']->uid( 'content' );
			$this->created[] = $site_uid;

			self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Quokkasitetwo',
				)
			);
			// Drain what the main-site collector picked up while switched, into this site's queue tables.
			$this->service( 'collector', ChangeCollector::class )->flush();
			$site['reindexer']->run_sync( 'content', 50, static function (): void {} );
		} finally {
			restore_current_blog();
		}

		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Wombatmainsite',
			)
		);
		$this->service( 'reindexer', Reindexer::class )->run_sync( 'content', 50, static function (): void {} );
		$this->wait_for_tasks();

		$main_uid = $main_names->uid( 'content' );
		self::assertNotSame( $main_uid, $site_uid );
		self::assertSame( 1, $this->hits( $main_uid, 'Wombatmainsite' ) );
		self::assertSame( 0, $this->hits( $main_uid, 'Quokkasitetwo' ) );
		self::assertSame( 1, $this->hits( $site_uid, 'Quokkasitetwo' ) );
		self::assertSame( 0, $this->hits( $site_uid, 'Wombatmainsite' ) );
	}

	public function test_sites_for_each_visits_every_site_in_batches(): void {
		$ids = self::factory()->blog->create_many( 3 );

		$visited = array();
		\Meilisearch\WordPress\Lifecycle\Sites::for_each(
			static function () use ( &$visited ): void {
				$visited[] = get_current_blog_id();
			}
		);

		foreach ( $ids as $id ) {
			self::assertContains( (int) $id, $visited );
		}
		self::assertContains( get_main_site_id(), $visited );
		self::assertSame( get_main_site_id(), get_current_blog_id() );
	}
}
