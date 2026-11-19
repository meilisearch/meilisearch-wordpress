<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Api\WpTransport;
use Meilisearch\WordPress\Indexing\ContentDocumentBuilder;
use Meilisearch\WordPress\Lifecycle\Deactivator;
use Meilisearch\WordPress\Lifecycle\Sites;
use Meilisearch\WordPress\Lifecycle\Uninstaller;
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

	/**
	 * Search key uids created by connected_site(), deleted in tear_down.
	 *
	 * @var list<string>
	 */
	private array $key_uids = array();

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
		foreach ( $this->key_uids as $key_uid ) {
			$this->meili( 'DELETE', '/keys/' . rawurlencode( $key_uid ) );
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

	public function test_for_each_flushes_the_runtime_cache_after_each_batch(): void {
		wp_cache_set( 'probe', 'x', 'meilisearch_test' );

		Sites::for_each( static function (): void {} );

		self::assertFalse( wp_cache_get( 'probe', 'meilisearch_test' ) );
	}

	public function test_network_deactivation_queries_only_the_current_network(): void {
		$seen   = array();
		$filter = static function ( $pre, $query ) use ( &$seen ) {
			$seen[] = $query->query_vars['network_id'] ?? null;
			return $pre;
		};
		add_filter( 'sites_pre_query', $filter, 10, 2 );
		try {
			Deactivator::deactivate( true );
		} finally {
			remove_filter( 'sites_pre_query', $filter, 10 );
		}

		self::assertNotSame( array(), $seen );
		self::assertSame( array( get_current_network_id() ), array_unique( $seen ) );
	}

	public function test_uninstall_contacts_an_unreachable_host_only_once_across_sites(): void {
		$site_id  = self::factory()->blog->create();
		$host     = self::test_host();
		$saved    = array();
		$settings = array_merge( (array) get_option( Options::CONNECTION, array() ), array( 'delete_on_uninstall' => true ) );
		foreach ( array( Options::CONNECTION, Options::ADMIN_KEY, Options::CONTENT ) as $name ) {
			$saved[ $name ] = get_option( $name );
		}
		update_option( Options::CONNECTION, $settings );
		switch_to_blog( $site_id );
		try {
			update_option( Options::CONNECTION, array_merge( $settings, array( 'prefix' => $settings['prefix'] . '_two' ) ) );
			update_option( Options::ADMIN_KEY, self::test_key() );
		} finally {
			restore_current_blog();
		}

		$attempts = 0;
		$filter   = static function ( $pre, $args, $url ) use ( &$attempts, $host ) {
			unset( $args );
			if ( str_starts_with( (string) $url, $host ) ) {
				++$attempts;
				return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			Uninstaller::run();
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
			foreach ( $saved as $name => $value ) {
				if ( false !== $value ) {
					update_option( $name, $value );
				}
			}
		}

		self::assertSame( 1, $attempts );
	}

	/**
	 * Creates a site connected on its own prefix (content index and plugin-created search key in Meilisearch).
	 *
	 * @return array{id: int, uid: string, key_uid: string}
	 */
	private function connected_site( bool $delete_on_uninstall, string $suffix ): array {
		$site_id              = (int) self::factory()->blog->create();
		$connection           = array_merge(
			(array) get_option( Options::CONNECTION, array() ),
			array( 'delete_on_uninstall' => $delete_on_uninstall )
		);
		$connection['prefix'] = $connection['prefix'] . '_' . $suffix;

		switch_to_blog( $site_id );
		try {
			$this->ensure_action_scheduler_tables();
			update_option( Options::CONNECTION, $connection );
			update_option( Options::ADMIN_KEY, self::test_key() );
			update_option( Options::CONTENT, array_merge( Options::defaults()[ Options::CONTENT ], array( 'post_types' => array( 'post' ) ) ) );

			$options  = new Options();
			$names    = new IndexNames( $options );
			$clients  = new ClientFactory( $options, new WpTransport() );
			$settings = new SettingsBuilder( array( 'content' => new ContentSchema( $options ) ) );
			( new IndexManager( $clients, $names, $settings, $options ) )->connect();
			$site = array(
				'id'      => $site_id,
				'uid'     => $names->uid( 'content' ),
				'key_uid' => $options->search_key_uid(),
			);
		} finally {
			restore_current_blog();
		}
		self::assertNotSame( '', $site['key_uid'] );
		$this->key_uids[] = $site['key_uid'];

		return $site;
	}

	private function delete_site( int $site_id ): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_blog( $site_id, true );
		self::assertNull( get_site( $site_id ), 'The site itself must be deleted.' );
	}

	private function key_exists( string $key_uid ): bool {
		try {
			$this->service( 'clients', ClientFactory::class )->client()->get_key( $key_uid );
		} catch ( \Meilisearch\WordPress\Api\ApiError $error ) {
			self::assertSame( 404, $error->http_status );
			return false;
		}

		return true;
	}

	public function test_deleting_an_opted_in_site_removes_its_indexes_and_search_key(): void {
		$site   = $this->connected_site( true, 'gone' );
		$client = $this->service( 'clients', ClientFactory::class )->client();
		self::assertTrue( $client->index_exists( $site['uid'] ) );

		$this->delete_site( $site['id'] );
		$this->wait_for_tasks();

		self::assertFalse( $client->index_exists( $site['uid'] ) );
		self::assertFalse( $this->key_exists( $site['key_uid'] ) );
	}

	public function test_deleting_a_site_without_the_opt_in_keeps_its_indexes_and_search_key(): void {
		$site   = $this->connected_site( false, 'kept' );
		$client = $this->service( 'clients', ClientFactory::class )->client();

		$this->delete_site( $site['id'] );
		$this->wait_for_tasks();

		self::assertTrue( $client->index_exists( $site['uid'] ) );
		self::assertTrue( $this->key_exists( $site['key_uid'] ) );
	}

	public function test_site_deletion_survives_an_unreachable_host_with_a_short_timeout(): void {
		$first  = $this->connected_site( true, 'one' );
		$second = $this->connected_site( true, 'two' );
		$host   = self::test_host();

		$timeouts = array();
		$filter   = static function ( $pre, $args, $url ) use ( &$timeouts, $host ) {
			if ( str_starts_with( (string) $url, $host ) ) {
				$timeouts[] = (float) $args['timeout'];
				return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			$this->delete_site( $first['id'] );
			$this->delete_site( $second['id'] );
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}

		self::assertCount( 1, $timeouts, 'An unreachable host is contacted once per request, across deleted sites.' );
		self::assertLessThanOrEqual( 5.0, $timeouts[0] );
		$messages = implode( "\n", array_column( ( new ErrorLog() )->all(), 'message' ) );
		self::assertStringContainsString( $first['uid'], $messages );
		self::assertStringContainsString( $second['uid'], $messages );
		self::assertStringNotContainsString( self::test_key(), $messages );
	}
}
