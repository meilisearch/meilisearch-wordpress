<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Ops\Cli;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Search\Highlighter;
use Meilisearch\WordPress\Search\ResultMapper;
use Meilisearch\WordPress\Settings\Options;

final class BootTest extends TestCase {

	private const SERVICE_IDS = array(
		'options',
		'names',
		'clients',
		'indexability',
		'settings_builder',
		'index_manager',
		'error_log',
		'queue',
		'collector',
		'reindexer',
		'sync_job',
		'term_job',
		'translator',
		'searcher',
		'result_mapper',
		'circuit_breaker',
		'interceptor',
		'highlighter',
		'admin_menu',
		'admin_notices',
		'admin_rest',
		'autocomplete',
		'site_health',
		'privacy',
		'site_seeder',
	);

	private const PRODUCT_HOOKS = array(
		'woocommerce_new_product',
		'woocommerce_update_product',
		'woocommerce_new_product_variation',
		'woocommerce_update_product_variation',
		'woocommerce_before_delete_product_variation',
		'woocommerce_trash_product_variation',
		'woocommerce_product_set_stock',
		'woocommerce_variation_set_stock',
		'woocommerce_product_set_stock_status',
		'woocommerce_variation_set_stock_status',
		'wp_update_comment_count',
	);

	private const ROUTES = array(
		'/meilisearch/v1/connection/test',
		'/meilisearch/v1/reindex',
		'/meilisearch/v1/reindex/status',
	);

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	private function plugin(): Plugin {
		$plugin = Plugin::instance();
		self::assertNotNull( $plugin );

		return $plugin;
	}

	/**
	 * Asserts that $hook has at least one callback owned by $owner ([ $owner, 'method' ] or a closure bound to it).
	 */
	private function assert_hooked_by( string $hook, object $owner ): void {
		global $wp_filter;
		$found = false;
		if ( isset( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && $function[0] === $owner ) {
						$found = true;
					}
					if ( $function instanceof \Closure && ( new \ReflectionFunction( $function ) )->getClosureThis() === $owner ) {
						$found = true;
					}
				}
			}
		}
		self::assertTrue( $found, sprintf( '%1$s has no callback from %2$s.', $hook, get_class( $owner ) ) );
	}

	public function test_every_unconditional_service_resolves(): void {
		$plugin = $this->plugin();
		foreach ( self::SERVICE_IDS as $id ) {
			self::assertIsObject( $plugin->get( $id ), $id );
		}

		$this->expectException( \OutOfBoundsException::class );
		$plugin->get( 'does_not_exist' );
	}

	/**
	 * @dataProvider hook_provider
	 */
	public function test_hook_is_attached_by_its_service( string $hook, string $service ): void {
		$this->assert_hooked_by( $hook, $this->plugin()->get( $service ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function hook_provider(): array {
		return array(
			'pre_get_posts'                        => array( 'pre_get_posts', 'interceptor' ),
			'posts_pre_query'                      => array( 'posts_pre_query', 'interceptor' ),
			'save_post'                            => array( 'save_post', 'collector' ),
			'transition_post_status'               => array( 'transition_post_status', 'collector' ),
			'before_delete_post'                   => array( 'before_delete_post', 'collector' ),
			'set_object_terms'                     => array( 'set_object_terms', 'collector' ),
			'added_post_meta'                      => array( 'added_post_meta', 'collector' ),
			'updated_post_meta'                    => array( 'updated_post_meta', 'collector' ),
			'deleted_post_meta'                    => array( 'deleted_post_meta', 'collector' ),
			'shutdown'                             => array( 'shutdown', 'collector' ),
			'edited_term'                          => array( 'edited_term', 'term_job' ),
			'delete_term'                          => array( 'delete_term', 'term_job' ),
			'meilisearch_sync_term'                => array( 'meilisearch_sync_term', 'term_job' ),
			'meilisearch_sync_posts'               => array( 'meilisearch_sync_posts', 'sync_job' ),
			'meilisearch_reindex_batch'            => array( 'meilisearch_reindex_batch', 'reindexer' ),
			'admin_menu'                           => array( 'admin_menu', 'admin_menu' ),
			'admin_init (menu)'                    => array( 'admin_init', 'admin_menu' ),
			'admin_enqueue_scripts'                => array( 'admin_enqueue_scripts', 'admin_menu' ),
			'admin_notices'                        => array( 'admin_notices', 'admin_notices' ),
			'admin_post_meilisearch_enable_search' => array( 'admin_post_meilisearch_enable_search', 'admin_notices' ),
			'rest_api_init'                        => array( 'rest_api_init', 'admin_rest' ),
			'wp_enqueue_scripts'                   => array( 'wp_enqueue_scripts', 'autocomplete' ),
			'site_status_tests'                    => array( 'site_status_tests', 'site_health' ),
			'admin_init (privacy)'                 => array( 'admin_init', 'privacy' ),
			'wp_initialize_site'                   => array( 'wp_initialize_site', 'site_seeder' ),
			'admin_init (seeder)'                  => array( 'admin_init', 'site_seeder' ),
		);
	}

	public function test_rest_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		foreach ( self::ROUTES as $route ) {
			self::assertArrayHasKey( $route, $routes, $route );
		}
	}

	public function test_excerpt_highlighting_is_hooked_only_when_enabled(): void {
		$plugin      = $this->plugin();
		$boot_filter = has_filter( 'get_the_excerpt', array( $plugin->get( 'highlighter' ), 'filter_excerpt' ) );
		self::assertSame( Options::defaults()[ Options::SEARCH ]['highlight'], false !== $boot_filter );

		update_option( Options::SEARCH, array_merge( Options::defaults()[ Options::SEARCH ], array( 'highlight' => true ) ) );
		$mapper  = $plugin->get( 'result_mapper' );
		$options = $plugin->get( 'options' );
		self::assertInstanceOf( ResultMapper::class, $mapper );
		self::assertInstanceOf( Options::class, $options );
		$highlighter = new Highlighter( $mapper, $options );
		$highlighter->register();

		self::assertSame( 20, has_filter( 'get_the_excerpt', array( $highlighter, 'filter_excerpt' ) ) );
		remove_filter( 'get_the_excerpt', array( $highlighter, 'filter_excerpt' ), 20 );
	}

	public function test_woocommerce_services_follow_the_boot_configuration(): void {
		$services = $this->plugin()->services();
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::assertArrayNotHasKey( 'wc_compat', $services );
			self::assertArrayNotHasKey( 'wc_product_sync', $services );
			return;
		}

		self::assertArrayHasKey( 'wc_compat', $services );
		$this->assert_hooked_by( 'before_woocommerce_init', $services['wc_compat'] );

		self::assertArrayHasKey( 'wc_product_sync', $services, 'The bootstrap enables product indexing before boot when MEILISEARCH_TEST_WC=1.' );
		foreach ( self::PRODUCT_HOOKS as $hook ) {
			$this->assert_hooked_by( $hook, $services['wc_product_sync'] );
		}
	}

	public function test_cli_command_is_registered(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::markTestSkipped( 'Run with MEILISEARCH_TEST_WP_CLI=1 to boot the plugin under the WP-CLI shim.' );
		}

		self::assertArrayHasKey( 'meilisearch', \WP_CLI::$commands );
		self::assertInstanceOf( Cli::class, \WP_CLI::$commands['meilisearch'][0] );
	}
}
