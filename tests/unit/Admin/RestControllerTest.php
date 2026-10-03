<?php
/**
 * Tests for RestController.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\RestController;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Indexing\ContentSchema;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\Support\SyncFixtures;
use Meilisearch\WordPress\Tests\Unit\TestCase;

require_once dirname( __DIR__ ) . '/Support/rest-doubles.php';

/**
 * Route registration, permissions, validation and callbacks.
 */
final class RestControllerTest extends TestCase {

	use SyncFixtures;

	/**
	 * Fake HTTP transport.
	 *
	 * @var FakeTransport
	 */
	private FakeTransport $transport;

	/**
	 * Options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Controller under test.
	 *
	 * @var RestController
	 */
	private RestController $controller;

	/**
	 * Route => register_rest_route() args.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $routes = array();

	/**
	 * Result of current_user_can().
	 *
	 * @var bool
	 */
	private bool $can = true;

	/**
	 * Wires the controller over real collaborators.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_wordpress_state();
		$this->transport  = new FakeTransport();
		$this->options    = new Options();
		$this->routes     = array();
		$this->can        = true;
		$clients          = $this->make_clients( $this->options, $this->transport );
		$indexes          = new IndexManager( $clients, new IndexNames( $this->options ), new SettingsBuilder( array( 'content' => new ContentSchema( $this->options ) ) ), $this->options );
		$reindexer        = $this->make_reindexer( $this->options, $clients, new Queue(), new ErrorLog(), array( 'content' => self::builder( fn () => null ) ) );
		$this->controller = new RestController( $indexes, $reindexer, $this->options );

		Functions\when( 'current_user_can' )->alias( fn () => $this->can );
		Functions\when( 'register_rest_route' )->alias(
			function ( $route_namespace, $route, $args ): bool {
				$this->routes[ $route_namespace . $route ] = $args;
				return true;
			}
		);
	}

	/**
	 * Routes are registered on rest_api_init and the script on admin_enqueue_scripts.
	 */
	public function test_register_attaches_hooks(): void {
		Actions\expectAdded( 'rest_api_init' )->once()->with( array( $this->controller, 'register_routes' ), 10, 0 );
		Actions\expectAdded( 'admin_enqueue_scripts' )->once()->with( array( $this->controller, 'enqueue_assets' ), 10, 1 );

		$this->controller->register();
		$this->addToAssertionCount( 2 );
	}

	/**
	 * Every route requires manage_options.
	 */
	public function test_every_route_checks_manage_options(): void {
		$this->controller->register_routes();

		$this->assertSame(
			array( 'meilisearch/v1/connection/test', 'meilisearch/v1/reindex', 'meilisearch/v1/reindex/status' ),
			array_keys( $this->routes )
		);
		foreach ( $this->routes as $args ) {
			$this->can = true;
			$this->assertTrue( call_user_func( $args['permission_callback'] ) );
			$this->can = false;
			$this->assertFalse( call_user_func( $args['permission_callback'] ) );
		}
		$this->assertSame( 'POST', $this->routes['meilisearch/v1/reindex']['methods'] );
		$this->assertSame( 'GET', $this->routes['meilisearch/v1/reindex/status']['methods'] );
	}

	/**
	 * The index argument is required, sanitized and validated against the active logical indexes.
	 */
	public function test_index_argument_is_validated(): void {
		$this->controller->register_routes();
		$arg = $this->routes['meilisearch/v1/reindex']['args']['index'];

		$this->assertTrue( $arg['required'] );
		$this->assertSame( 'sanitize_key', $arg['sanitize_callback'] );
		$this->assertTrue( call_user_func( $arg['validate_callback'], 'content' ) );
		$error = call_user_func( $arg['validate_callback'], 'products' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertInstanceOf( \WP_Error::class, call_user_func( $arg['validate_callback'], array( 'content' ) ) );
	}

	/**
	 * A second reindex while one is active returns 409 with the current state.
	 */
	public function test_reindex_while_running_returns_409_with_state(): void {
		$state = array(
			'run'        => 'r1',
			'phase'      => 'upsert',
			'last_id'    => 200,
			'sent'       => 200,
			'deleted'    => 0,
			'total'      => 1000,
			'task_uids'  => array( 1 ),
			'started_at' => time(),
			'updated_at' => time(),
			'status'     => 'running',
			'error'      => '',
		);
		$this->options->set_reindex_state( 'content', $state );
		$request = new \WP_REST_Request( 'POST', '/meilisearch/v1/reindex' );
		$request->set_param( 'index', 'content' );

		$response = $this->controller->start_reindex( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'meilisearch_already_running', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		$this->assertSame( $state, $response->get_error_data()['state'] );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Reindexing without a connection is a 400.
	 */
	public function test_reindex_requires_connection(): void {
		$this->option_store[ Options::ADMIN_KEY ] = '';
		$request                                  = new \WP_REST_Request( 'POST', '/meilisearch/v1/reindex' );
		$request->set_param( 'index', 'content' );

		$response = $this->controller->start_reindex( $request );

		$this->assertSame( 'meilisearch_not_configured', $response->get_error_code() );
	}

	/**
	 * The status route returns both logical indexes.
	 */
	public function test_status_returns_both_indexes(): void {
		$state = array(
			'run'        => 'r1',
			'phase'      => 'sweep',
			'sent'       => 40,
			'total'      => 40,
			'deleted'    => 3,
			'status'     => 'running',
			'updated_at' => time(),
		);
		$this->options->set_reindex_state( 'content', $state );

		$response = $this->controller->reindex_status();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'content'   => $state,
				'products'  => null,
				'abandoned' => array(
					'content'  => false,
					'products' => false,
				),
			),
			$response->get_data()
		);
	}

	/**
	 * A running run untouched past STALE_AFTER with no pending batch is reported as abandoned.
	 */
	public function test_status_flags_abandoned_run(): void {
		$this->options->set_reindex_state(
			'content',
			array(
				'run'        => 'r1',
				'phase'      => 'upsert',
				'sent'       => 5,
				'total'      => 40,
				'deleted'    => 0,
				'started_at' => time() - 10000,
				'updated_at' => time() - 10000,
				'status'     => 'running',
			)
		);
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );

		$data = $this->controller->reindex_status()->get_data();

		$this->assertTrue( $data['abandoned']['content'] );
		$this->assertFalse( $data['abandoned']['products'] );
	}

	/**
	 * A successful connection test returns the engine version.
	 */
	public function test_connection_test_returns_version(): void {
		$this->transport->queue(
			self::json_response(
				200,
				array(
					'pkgVersion' => '1.53.1',
					'commitSha'  => 'abc',
					'commitDate' => '2026-09-01',
				)
			)
		);

		$response = $this->controller->test_connection();

		$this->assertSame(
			array(
				'ok'      => true,
				'version' => '1.53.1',
			),
			$response->get_data()
		);
	}

	/**
	 * A Meilisearch error is returned with its message.
	 */
	public function test_connection_test_returns_meilisearch_error(): void {
		// An invalid key fails /version and the index listing that tells it apart from an index-scoped key.
		$this->transport->queue( new ApiError( 'The provided API key is invalid.', 'invalid_api_key', 403 ) )
			->queue( new ApiError( 'The provided API key is invalid.', 'invalid_api_key', 403 ) );

		$response = $this->controller->test_connection();

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'The provided API key is invalid.', $response->get_error_message() );
		$this->assertSame( 'invalid_api_key', $response->get_error_data()['meilisearch_code'] );
	}

	/**
	 * An engine older than the minimum is reported as such.
	 */
	public function test_connection_test_rejects_old_engine(): void {
		$this->transport->queue( self::json_response( 200, array( 'pkgVersion' => '1.33.2' ) ) );

		$response = $this->controller->test_connection();

		$this->assertSame( 'meilisearch_unsupported_version', $response->get_error_code() );
		$this->assertStringContainsString( IndexManager::MIN_VERSION, $response->get_error_message() );
	}

	/**
	 * The admin script is only enqueued on the plugin screen, with the REST nonce.
	 */
	public function test_script_is_enqueued_only_on_plugin_screen(): void {
		Functions\when( 'plugins_url' )->alias( fn ( $path ) => 'https://example.test/wp-content/plugins/meilisearch/' . $path );
		Functions\when( 'rest_url' )->alias( fn ( $path ) => 'https://example.test/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-123' );
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with( 'meilisearch-admin', 'https://example.test/wp-content/plugins/meilisearch/assets/js/admin.js', array(), MEILISEARCH_VERSION, array( 'in_footer' => true ) );
		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'meilisearch-admin',
				'meilisearchAdmin',
				\Mockery::on( fn ( $data ) => 'nonce-123' === $data['nonce'] && 'https://example.test/wp-json/meilisearch/v1/' === $data['restUrl'] && isset( $data['i18n']['upsert'], $data['i18n']['sweep'] ) )
			);

		$this->controller->enqueue_assets( 'edit.php' );
		$this->controller->enqueue_assets( 'toplevel_page_meilisearch' );
		$this->addToAssertionCount( 2 );
	}
}
