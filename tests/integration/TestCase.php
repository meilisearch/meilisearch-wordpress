<?php
/**
 * Base class for integration tests (real WordPress, real Meilisearch).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use ActionScheduler;
use Meilisearch\WordPress\Plugin;
use ActionScheduler_Store;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Index prefix of the current test: test_ + 6 lowercase alphanumerics.
	 *
	 * @var string
	 */
	protected string $prefix = '';

	/**
	 * "{hook}: {message}" for each action that failed during the last run_actions().
	 *
	 * @var list<string>
	 */
	protected array $action_failures = [];

	/**
	 * Plugin instance bootstrapped with WordPress, while a test runs on a rebooted one.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $bootstrapped_plugin = null;

	public function set_up(): void {
		parent::set_up();

		$this->prefix          = 'test_' . strtolower( wp_generate_password( 6, false, false ) );
		$this->action_failures = [];

		// Safety net for crashed runs: drop actions a previous run committed for real.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], 'meilisearch' );
		}

		// Option names and keys from the catalog (Settings\Options arrives in Task 5).
		update_option(
			'meilisearch_connection',
			[
				'host'                => self::test_host(),
				'prefix'              => $this->prefix,
				'search_key'          => '',
				'search_key_uid'      => '',
				'delete_on_uninstall' => false,
			],
			false
		);
		update_option( 'meilisearch_admin_key', self::test_key(), false );
		update_option(
			'meilisearch_content',
			[
				'post_types' => [ 'post', 'page' ],
				'taxonomies' => [ 'post' => [ 'category', 'post_tag' ] ],
				'meta_keys'  => [],
			]
		);
	}

	public function tear_down(): void {
		if ( '' !== $this->prefix ) {
			$connection = get_option( 'meilisearch_connection', [] );
			$key_uid    = is_array( $connection ) && ! empty( $connection['search_key_uid'] ) ? (string) $connection['search_key_uid'] : '';
			if ( '' !== $key_uid ) {
				$this->meili( 'DELETE', '/keys/' . rawurlencode( $key_uid ) );
			}
			$indexes = $this->meili( 'GET', '/indexes?limit=1000' );
			foreach ( $indexes['results'] ?? [] as $index ) {
				$uid = (string) ( $index['uid'] ?? '' );
				if ( str_starts_with( $uid, $this->prefix . '_' ) ) {
					$this->meili( 'DELETE', '/indexes/' . rawurlencode( $uid ) );
				}
			}
		}
		$this->settle_plugin();
		parent::tear_down();
	}

	/**
	 * Boots a fresh Plugin (e.g. after options changed which services exist), remembering the
	 * bootstrapped instance so tear_down() can put it back next to the hooks WP_UnitTestCase restores.
	 */
	protected function reboot_plugin(): void {
		if ( null === $this->bootstrapped_plugin ) {
			$this->bootstrapped_plugin = Plugin::instance();
		}
		Plugin::reset();
		Plugin::boot();
	}

	/**
	 * Schedules whatever the collectors still hold while the test transaction is open, so nothing is
	 * left for the `shutdown` flush, which would run after the rollback and commit actions for real.
	 * Then restores the bootstrapped Plugin instance (parent::tear_down() restores its hooks).
	 */
	private function settle_plugin(): void {
		$this->flush_collector( Plugin::instance() );
		if ( null !== $this->bootstrapped_plugin ) {
			$this->flush_collector( $this->bootstrapped_plugin );
			// Test-only seam: Plugin has no setter, and its hooks are the bootstrapped instance's.
			( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, $this->bootstrapped_plugin );
			$this->bootstrapped_plugin = null;
		}
	}

	private function flush_collector( ?Plugin $plugin ): void {
		if ( null !== $plugin && isset( $plugin->services()['collector'] ) ) {
			$plugin->get( 'collector' )->flush();
		}
	}

	protected static function test_host(): string {
		$host = getenv( 'MEILISEARCH_TEST_HOST' );
		return ( false === $host || '' === $host ) ? 'http://127.0.0.1:7700' : rtrim( $host, '/' );
	}

	protected static function test_key(): string {
		$key = getenv( 'MEILISEARCH_TEST_KEY' );
		return ( false === $key || '' === $key ) ? 'masterKey' : $key;
	}

	/**
	 * Executes every pending action of group "meilisearch", whatever its scheduled date.
	 *
	 * @param int $max_rounds Maximum passes (handlers may schedule follow-up actions).
	 * @return int Number of actions executed.
	 */
	protected function run_actions( int $max_rounds = 20 ): int {
		$this->action_failures = [];
		$listener              = function ( $action_id, $exception ): void {
			$action                  = ActionScheduler::store()->fetch_action( (string) $action_id );
			$this->action_failures[] = $action->get_hook() . ': ' . $exception->getMessage();
		};
		add_action( 'action_scheduler_failed_execution', $listener, 10, 2 );

		$executed = 0;
		for ( $round = 0; $round < $max_rounds; $round++ ) {
			$ids = as_get_scheduled_actions(
				[
					'group'    => 'meilisearch',
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
					'orderby'  => 'date',
					'order'    => 'ASC',
				],
				'ids'
			);
			if ( [] === $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				ActionScheduler::runner()->process_action( (int) $id, 'meilisearch-tests' );
				++$executed;
			}
		}

		remove_action( 'action_scheduler_failed_execution', $listener, 10 );
		return $executed;
	}

	/**
	 * Waits until Meilisearch has no enqueued or processing task (30 s max).
	 */
	protected function wait_for_tasks(): void {
		$deadline = microtime( true ) + 30.0;
		do {
			$pending = $this->meili( 'GET', '/tasks?statuses=enqueued,processing&limit=1' );
			if ( empty( $pending['results'] ) ) {
				return;
			}
			usleep( 100000 );
		} while ( microtime( true ) < $deadline );

		$this->fail( 'Meilisearch tasks were still pending after 30 seconds.' );
	}

	/**
	 * Sends a raw request to the test Meilisearch.
	 *
	 * @param string                        $method HTTP method.
	 * @param string                        $path   Path with query string, starting with '/'.
	 * @param array<int|string, mixed>|null $body   JSON body.
	 * @return array<int|string, mixed> Decoded body, array() when empty, whatever the status.
	 */
	protected function meili( string $method, string $path, ?array $body = null ): array {
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . self::test_key(),
				'Content-Type'  => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::test_host() . $path, $args );
		if ( is_wp_error( $response ) ) {
			$this->fail( 'Meilisearch request failed: ' . $response->get_error_message() );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Returns up to 1000 documents of an index.
	 *
	 * @param string $uid Index uid.
	 * @return list<array<string, mixed>>
	 */
	protected function index_documents( string $uid ): array {
		return $this->meili( 'GET', '/indexes/' . rawurlencode( $uid ) . '/documents?limit=1000' )['results'] ?? [];
	}
}
