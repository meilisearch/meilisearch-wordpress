<?php
/**
 * Settings changes reach Meilisearch without a reindex.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Integration\WooCommerce\CreatesProducts;

require_once __DIR__ . '/WooCommerce/CreatesProducts.php';

final class SettingsSyncTest extends TestCase {

	use CreatesProducts;

	/**
	 * @param string $logical Logical index.
	 * @return list<int> Document IDs.
	 */
	private function document_ids( string $logical ): array {
		$ids = array_map( static fn ( array $document ): int => (int) $document['id'], $this->index_documents( Plugin::instance()->get( 'names' )->uid( $logical ) ) );
		sort( $ids );
		return $ids;
	}

	public function test_removing_a_post_type_deletes_its_documents_right_away(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Kept post' ) );
		$page = self::factory()->post->create(
			array(
				'post_title' => 'Dropped page',
				'post_type'  => 'page',
			)
		);
		Plugin::instance()->get( 'index_manager' )->ensure_all();
		Plugin::instance()->get( 'reindexer' )->run_sync( 'content', 200, static function (): void {} );
		$this->wait_for_tasks();
		$this->assertSame( array( $post, $page ), $this->document_ids( 'content' ) );

		update_option( Options::CONTENT, array_merge( (array) get_option( Options::CONTENT ), array( 'post_types' => array( 'post' ) ) ) );
		$this->wait_for_tasks();

		$this->assertSame( array( $post ), $this->document_ids( 'content' ) );
		$this->assertSame( array(), Plugin::instance()->get( 'error_log' )->all() );
	}

	public function test_disabling_products_empties_the_products_index(): void {
		$this->skip_without_woocommerce();
		$this->enable_products();
		$this->create_simple_product( 'Meili Settings Mug', '12' );
		$this->sync_products();
		$uid = Plugin::instance()->get( 'names' )->uid( 'products' );
		$this->assertCount( 1, $this->index_documents( $uid ) );

		update_option( Options::WOOCOMMERCE, array_merge( (array) get_option( Options::WOOCOMMERCE ), array( 'enabled' => false ) ) );
		$this->wait_for_tasks();

		$this->assertSame( array(), $this->index_documents( $uid ) );
		$this->assertArrayHasKey( 'uid', $this->meili( 'GET', '/indexes/' . $uid ), 'The index and its settings are kept.' );
		$this->assertFalse( Plugin::instance()->get( 'options' )->is_populated( 'products' ) );
	}
}
