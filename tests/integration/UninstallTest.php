<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Lifecycle\Uninstaller;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\Queue;

final class UninstallTest extends TestCase {

	private const OPTIONS = array(
		Options::CONNECTION,
		Options::ADMIN_KEY,
		Options::CONTENT,
		Options::WOOCOMMERCE,
		Options::SEARCH,
		Options::STATE,
		Options::LOG,
	);

	/**
	 * Option values before the test, restored before the harness tear-down runs.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = array();

	/**
	 * Search key uids created by install_data(), deleted in tear_down (an uninstall may have dropped the option holding them).
	 *
	 * @var list<string>
	 */
	private array $key_uids = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( self::OPTIONS as $name ) {
			$this->saved[ $name ] = get_option( $name );
		}
	}

	public function tear_down(): void {
		$this->restore_options();
		foreach ( $this->key_uids as $key_uid ) {
			$this->meili( 'DELETE', '/keys/' . rawurlencode( $key_uid ) );
		}
		parent::tear_down();
	}

	/**
	 * Puts the harness connection back so wait_for_tasks() and the harness cleanup keep working.
	 */
	private function restore_options(): void {
		foreach ( $this->saved as $name => $value ) {
			if ( false !== $value ) {
				update_option( $name, $value );
			}
		}
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
	 * Connects (creates indexes + search key) and leaves local data behind.
	 *
	 * @return array{client: Client, uid: string, key_uid: string}
	 */
	private function install_data( bool $delete_on_uninstall ): array {
		$this->service( 'index_manager', IndexManager::class )->connect();
		$options = $this->service( 'options', Options::class );
		update_option( Options::CONNECTION, array_merge( (array) get_option( Options::CONNECTION, array() ), array( 'delete_on_uninstall' => $delete_on_uninstall ) ) );
		set_transient( 'meilisearch_uninstall_probe', 'x', 600 );
		add_option( 'meilisearch_legacy_flag', 'x' );
		as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			Queue::SYNC_POSTS,
			array(
				array(
					'index'   => 'content',
					'ids'     => array( 1 ),
					'attempt' => 1,
				),
			),
			Queue::GROUP
		);

		$client = $this->service( 'clients', ClientFactory::class )->client();
		$uid    = $this->service( 'names', IndexNames::class )->uid( 'content' );
		self::assertTrue( $client->index_exists( $uid ) );
		self::assertNotSame( '', $options->search_key_uid() );
		$this->key_uids[] = $options->search_key_uid();

		return array(
			'client'  => $client,
			'uid'     => $uid,
			'key_uid' => $options->search_key_uid(),
		);
	}

	private function assert_local_data_removed(): void {
		foreach ( array_merge( self::OPTIONS, array( 'meilisearch_legacy_flag' ) ) as $name ) {
			self::assertFalse( get_option( $name ), $name );
		}
		self::assertFalse( get_transient( 'meilisearch_uninstall_probe' ) );
		self::assertFalse( as_has_scheduled_action( Queue::SYNC_POSTS, null, Queue::GROUP ) );
	}

	public function test_uninstall_with_opt_in_removes_local_and_remote_data(): void {
		$data = $this->install_data( true );

		Uninstaller::run();

		$this->assert_local_data_removed();
		$this->restore_options();
		$this->wait_for_tasks();
		self::assertFalse( $data['client']->index_exists( $data['uid'] ) );
		try {
			$data['client']->get_key( $data['key_uid'] );
			self::fail( 'The plugin search key should have been deleted.' );
		} catch ( ApiError $error ) {
			self::assertSame( 404, $error->http_status );
		}
	}

	public function test_uninstall_without_opt_in_keeps_remote_data(): void {
		$data = $this->install_data( false );

		Uninstaller::run();

		$this->assert_local_data_removed();
		$this->restore_options();
		$this->wait_for_tasks();
		self::assertTrue( $data['client']->index_exists( $data['uid'] ) );
		self::assertSame( $data['key_uid'], (string) $data['client']->get_key( $data['key_uid'] )['uid'] );
		$data['client']->delete_key( $data['key_uid'] );
	}

	public function test_uninstall_never_deletes_a_manually_pasted_search_key(): void {
		$data = $this->install_data( true );
		$this->service( 'options', Options::class )->set_state( 'search_key_manual', true );

		Uninstaller::run();

		$this->restore_options();
		$this->wait_for_tasks();
		self::assertFalse( $data['client']->index_exists( $data['uid'] ) );
		self::assertSame( $data['key_uid'], (string) $data['client']->get_key( $data['key_uid'] )['uid'] );
		$data['client']->delete_key( $data['key_uid'] );
	}

	public function test_uninstall_with_unreachable_host_still_removes_local_data(): void {
		$this->install_data( true );
		$connection         = (array) get_option( Options::CONNECTION, array() );
		$connection['host'] = 'http://127.0.0.1:1';
		update_option( Options::CONNECTION, $connection );

		Uninstaller::run();

		$this->assert_local_data_removed();
	}

	public function test_uninstall_leaves_other_indexes_of_a_shared_instance_alone(): void {
		$data  = $this->install_data( true );
		$other = $this->prefix . '_foreign_content';
		$data['client']->create_index( $other );
		$this->wait_for_tasks();

		Uninstaller::run();

		$this->restore_options();
		$this->wait_for_tasks();
		self::assertTrue( $data['client']->index_exists( $other ) );
	}

	public function test_uninstall_php_runs_the_uninstaller(): void {
		$data = $this->install_data( false );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( MEILISEARCH_FILE ) );
		}

		require dirname( MEILISEARCH_FILE ) . '/uninstall.php';

		$this->assert_local_data_removed();
		$this->restore_options();
		$data['client']->delete_key( $data['key_uid'] );
	}
}
