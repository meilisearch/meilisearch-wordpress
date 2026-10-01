<?php
/**
 * Tests for SettingsSync: Content and WooCommerce settings changes reach Meilisearch right away.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Schema;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Indexing\SettingsSync;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\Support\SyncFixtures;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class SettingsSyncTest extends TestCase {

	use SyncFixtures;

	private const HOST = 'http://meili.test';

	private FakeTransport $transport;

	protected function set_up(): void {
		parent::set_up();
		$this->stub_wordpress_state();
		$this->transport = new FakeTransport();
	}

	private function sync(): SettingsSync {
		$options = new Options();
		$names   = new IndexNames( $options );
		$clients = new ClientFactory( $options, $this->transport );
		$schema  = new class() implements Schema {
			public function filterable(): array {
				return array( 'post_type' );
			}
			public function sortable(): array {
				return array();
			}
			public function searchable(): array {
				return array( 'title' );
			}
		};

		return new SettingsSync( $clients, new IndexManager( $clients, $names, new SettingsBuilder( array( 'content' => $schema ) ), $options ), $names, $options, new ErrorLog() );
	}

	/**
	 * @return list<string> "METHOD path" of every request.
	 */
	private function calls(): array {
		return array_map(
			static fn ( array $request ): string => $request['method'] . ' ' . substr( $request['url'], strlen( self::HOST ) ),
			$this->transport->requests()
		);
	}

	/**
	 * Queues ensure_all() answers: the content index exists and its settings are complete.
	 */
	private function queue_content_up_to_date(): void {
		$this->transport
			->respond( 200, array( 'uid' => 'wp_test_content' ) )
			->respond(
				200,
				array(
					'searchableAttributes' => array( '*' ),
					'filterableAttributes' => array( 'post_type' ),
					'sortableAttributes'   => array(),
				)
			);
	}

	/**
	 * @param list<string> $post_types Post types.
	 * @return array<string, mixed>
	 */
	private static function content( array $post_types ): array {
		return array(
			'post_types' => $post_types,
			'taxonomies' => array(),
			'meta_keys'  => array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function woocommerce( bool $enabled ): array {
		return array(
			'enabled'           => $enabled,
			'attributes'        => null,
			'custom_attributes' => false,
			'variation_skus'    => true,
		);
	}

	public function test_register_hooks_the_option_updates(): void {
		$sync = $this->sync();
		$sync->register();

		self::assertSame( 10, has_action( 'update_option_' . Options::CONTENT, array( $sync, 'on_content_update' ) ) );
		self::assertSame( 10, has_action( 'update_option_' . Options::WOOCOMMERCE, array( $sync, 'on_woocommerce_update' ) ) );
	}

	public function test_removed_post_types_are_deleted_by_filter_then_settings_are_pushed(): void {
		$this->transport->respond( 202, array( 'taskUid' => 4 ) );
		$this->queue_content_up_to_date();

		$this->sync()->on_content_update( self::content( array( 'post', 'page' ) ), self::content( array( 'post' ) ) );

		self::assertSame(
			array( 'POST /indexes/wp_test_content/documents/delete', 'GET /indexes/wp_test_content', 'GET /indexes/wp_test_content/settings' ),
			$this->calls()
		);
		self::assertSame( array( 'filter' => 'post_type IN ["page"]' ), $this->transport->requests()[0]['body'] );
		self::assertSame( array(), ( new ErrorLog() )->all() );
	}

	public function test_content_change_without_removed_types_only_pushes_settings(): void {
		$this->queue_content_up_to_date();

		$this->sync()->on_content_update( self::content( array( 'post' ) ), self::content( array( 'post', 'page' ) ) );

		self::assertSame( array( 'GET /indexes/wp_test_content', 'GET /indexes/wp_test_content/settings' ), $this->calls() );
	}

	public function test_content_errors_are_logged_never_thrown(): void {
		$this->transport
			->fail( ApiError::transport( 'cURL error 28: timed out' ) )
			->fail( ApiError::transport( 'cURL error 28: timed out' ) );

		$this->sync()->on_content_update( self::content( array( 'post', 'page' ) ), self::content( array( 'post' ) ) );

		$messages = array_column( ( new ErrorLog() )->all(), 'message' );
		self::assertCount( 2, $messages );
		self::assertStringContainsString( 'timed out', $messages[0] );
	}

	public function test_missing_content_index_is_nothing_to_delete(): void {
		$this->transport->respond(
			404,
			array(
				'code'    => 'index_not_found',
				'message' => 'Index `wp_test_content` not found.',
			)
		);
		$this->queue_content_up_to_date();

		$this->sync()->on_content_update( self::content( array( 'page' ) ), self::content( array() ) );

		self::assertSame( array(), ( new ErrorLog() )->all() );
	}

	public function test_disabling_products_deletes_every_product_document_and_unpopulates(): void {
		( new Options() )->set_populated( 'products', true );
		$this->transport->respond( 202, array( 'taskUid' => 9 ) );

		$this->sync()->on_woocommerce_update( self::woocommerce( true ), self::woocommerce( false ) );

		self::assertSame( array( 'DELETE /indexes/wp_test_products/documents' ), $this->calls() );
		self::assertFalse( ( new Options() )->is_populated( 'products' ) );
	}

	/**
	 * @dataProvider unchanged_products
	 */
	public function test_other_woocommerce_changes_do_not_delete( bool $before, bool $after ): void {
		$this->sync()->on_woocommerce_update( self::woocommerce( $before ), self::woocommerce( $after ) );

		self::assertSame( array(), $this->calls() );
	}

	/**
	 * @return array<string, array{bool, bool}>
	 */
	public static function unchanged_products(): array {
		return array(
			'stays enabled'  => array( true, true ),
			'stays disabled' => array( false, false ),
			'enabled'        => array( false, true ),
		);
	}

	public function test_products_delete_errors_are_logged_never_thrown(): void {
		$this->transport->fail( ApiError::transport( 'cURL error 7: refused' ) );

		$this->sync()->on_woocommerce_update( self::woocommerce( true ), self::woocommerce( false ) );

		self::assertStringContainsString( 'refused', ( new ErrorLog() )->all()[0]['message'] );
	}

	public function test_nothing_is_sent_when_not_configured(): void {
		$this->option_store[ Options::ADMIN_KEY ] = '';
		( new Options() )->set_populated( 'products', true );

		$this->sync()->on_content_update( self::content( array( 'post', 'page' ) ), self::content( array( 'post' ) ) );
		$this->sync()->on_woocommerce_update( self::woocommerce( true ), self::woocommerce( false ) );

		self::assertSame( array(), $this->calls() );
		self::assertFalse( ( new Options() )->is_populated( 'products' ) );
	}

	public function test_unexpected_hook_values_are_ignored(): void {
		$this->sync()->on_woocommerce_update( 'garbage', null );
		$this->sync()->on_content_update( 'garbage', null );

		self::assertSame( array(), $this->calls() );
	}
}
