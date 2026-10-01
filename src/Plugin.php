<?php
/**
 * Composition root.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Builds every service by hand and registers the hooks of each Registrable service.
 */
final class Plugin {

	/**
	 * The booted instance; null before boot() and after reset().
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Services keyed by id, in construction order.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Use boot().
	 */
	private function __construct() {}

	/**
	 * Builds all services and registers their hooks. Calling it again does nothing.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}
		$plugin = new self();
		$plugin->build();
		self::$instance = $plugin;
		$plugin->register_services();
	}

	/**
	 * Returns the booted instance.
	 *
	 * @return Plugin|null Null before boot().
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Drops the instance so the next boot() builds a fresh one. Tests only: hooks stay attached.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Returns a service by id.
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws \OutOfBoundsException When no service has that id.
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			throw new \OutOfBoundsException( sprintf( 'Unknown Meilisearch service "%s".', $id ) );
		}
		return $this->services[ $id ];
	}

	/**
	 * Returns every service keyed by id.
	 *
	 * @return array<string, object>
	 */
	public function services(): array {
		return $this->services;
	}

	/**
	 * Stores a service under an id.
	 *
	 * @param string $id      Service id.
	 * @param object $service Service.
	 * @throws \LogicException When the id is already taken.
	 */
	private function add( string $id, object $service ): void {
		if ( isset( $this->services[ $id ] ) ) {
			throw new \LogicException( sprintf( 'Meilisearch service "%s" is already registered.', $id ) );
		}
		$this->services[ $id ] = $service;
	}

	/**
	 * Calls register() on every Registrable service, in construction order.
	 */
	private function register_services(): void {
		foreach ( $this->services as $service ) {
			if ( $service instanceof Registrable ) {
				$service->register();
			}
		}
	}

	/**
	 * Constructs every service.
	 */
	private function build(): void {
		// Task 5 — settings.
		$options = new Settings\Options();
		$names   = new Settings\IndexNames( $options );
		$this->add( 'options', $options );
		$this->add( 'names', $names );

		// Task 5 — API client factory (needs Options).
		$clients = new Api\ClientFactory( $options, new Api\WpTransport() );
		$this->add( 'clients', $clients );

		// Task 9 — error log (used by admin notices, sync and search).
		$error_log = new Sync\ErrorLog();
		$this->add( 'error_log', $error_log );

		// Tasks 6-7 (content), Task 20 (products) — indexing model.
		$content_docs = new Indexing\ContentDocumentBuilder( $options );
		$schemas      = array( 'content' => new Indexing\ContentSchema( $options ) );
		$builders     = array( 'content' => $content_docs );
		$product_rule = null;
		if ( $options->products_enabled() ) {
			$product_rule         = new WooCommerce\ProductRule();
			$schemas['products']  = new WooCommerce\ProductSchema( $options );
			$builders['products'] = new WooCommerce\ProductDocumentBuilder( $content_docs, new WooCommerce\CategoryHierarchy(), new WooCommerce\AttributeCollector(), $options );
		}
		$indexability = new Indexing\Indexability( $options, $product_rule );
		$settings     = new Indexing\SettingsBuilder( $schemas );
		$this->add( 'indexability', $indexability );
		$this->add( 'settings_builder', $settings );

		// Task 8 — index manager.
		$indexes = new Indexing\IndexManager( $clients, $names, $settings, $options );
		$this->add( 'index_manager', $indexes );

		// Tasks 11-13 — sync and reindex.
		$queue     = new Sync\Queue();
		$collector = new Sync\ChangeCollector( $indexability, $queue, $options );
		$sync_job  = new Sync\SyncJob( $clients, $names, $indexability, $builders, $queue, $error_log );
		$reindexer = new Indexing\Reindexer( $clients, $indexes, $names, $indexability, $builders, $queue, $options, $error_log );
		$this->add( 'queue', $queue );
		$this->add( 'collector', $collector );
		$this->add( 'reindexer', $reindexer );
		$this->add( 'sync_job', $sync_job );
		$this->add( 'term_job', new Sync\TermJob( $collector, $queue, $options ) );

		// Tasks 15-18, Task 22 — search.
		$product_translator = $options->products_enabled() ? new WooCommerce\ProductQueryTranslator( $options ) : null;
		$translator         = new Search\QueryTranslator( $options, $indexability, $product_translator );
		$searcher           = new Search\Searcher( $clients, $names );
		$mapper             = new Search\ResultMapper();
		$breaker            = new Search\CircuitBreaker();
		$this->add( 'translator', $translator );
		$this->add( 'searcher', $searcher );
		$this->add( 'result_mapper', $mapper );
		$this->add( 'circuit_breaker', $breaker );
		$this->add( 'interceptor', new Search\Interceptor( $translator, $searcher, $mapper, $breaker, $options, $error_log ) );
		$this->add( 'highlighter', new Search\Highlighter( $mapper, $options ) );

		// Tasks 9-10, 14, 21 — admin.
		$tabs = array(
			new Admin\ConnectionTab( $options, $indexes, $names ),
			new Admin\ContentTab( $options ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$tabs[] = new WooCommerce\WooCommerceTab( $options );
		}
		$tabs[] = new Admin\SearchTab( $options );
		$tabs[] = new Admin\StatusTab( $options, $names, $reindexer, $error_log, $clients );
		$this->add( 'admin_menu', new Admin\Menu( $tabs ) );
		$this->add( 'admin_notices', new Admin\Notices( $options, $error_log ) );
		$this->add( 'admin_rest', new Admin\RestController( $indexes, $reindexer, $options ) );

		// Task 19, Task 21 — WooCommerce hooks.
		if ( class_exists( 'WooCommerce' ) ) {
			$this->add( 'wc_compat', new WooCommerce\Compatibility() );
			if ( $options->products_enabled() ) {
				$this->add( 'wc_product_sync', new WooCommerce\ProductSync( $collector, $options ) );
			}
		}

		// Task 23 — frontend.
		$this->add( 'autocomplete', new Frontend\Autocomplete( $options, $names ) );

		// Task 24 — operations (Site Health + privacy text).
		$health = new Ops\SiteHealth( $clients, $indexes, $names, $reindexer, $queue, $options );
		$this->add( 'site_health', $health );
		$this->add( 'privacy', new Ops\Privacy() );

		// Task 26 — lifecycle.
		$this->add( 'site_seeder', new Lifecycle\SiteSeeder() );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'meilisearch', new Ops\Cli( $reindexer, $sync_job, $clients, $names, $health, $indexability, $queue ) );
		}
	}
}
