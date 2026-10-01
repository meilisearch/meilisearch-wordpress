<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ChangeCollector;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Meilisearch\WordPress\WooCommerce\ProductRule;
use Meilisearch\WordPress\WooCommerce\ProductSync;

final class ProductSyncTest extends TestCase {

	private ChangeCollector $collector;

	private ProductSync $sync;

	protected function set_up(): void {
		parent::set_up();
		$this->stub_options();
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				$types = array(
					10 => 'product',
					11 => 'product_variation',
					20 => 'post',
				);
				if ( ! isset( $types[ (int) $id ] ) ) {
					return null;
				}
				return new \WP_Post(
					array(
						'ID'          => (int) $id,
						'post_type'   => $types[ (int) $id ],
						'post_parent' => 11 === (int) $id ? 10 : 0,
					)
				);
			}
		);
		Functions\when( 'get_post_type' )->alias(
			static function ( $id ) {
				$post = get_post( $id );
				return $post instanceof \WP_Post ? $post->post_type : false;
			}
		);
		Functions\when( 'wp_get_post_parent_id' )->alias(
			static function ( $id ) {
				$post = get_post( $id );
				return $post instanceof \WP_Post ? (int) $post->post_parent : false;
			}
		);

		$options         = new Options();
		$this->collector = new ChangeCollector( new Indexability( $options, new ProductRule() ), new Queue(), $options );
		$this->sync      = new ProductSync( $this->collector, $options );
	}

	private function pending_products(): array {
		return $this->collector->pending()['products'] ?? array();
	}

	private function variation(): \WC_Product_Variation {
		return new \WC_Product_Variation(
			array(
				'id'        => 11,
				'parent_id' => 10,
			)
		);
	}

	public function test_register_attaches_every_product_hook(): void {
		$this->sync->register();

		$expected = array(
			'woocommerce_new_product'                     => 'on_product',
			'woocommerce_update_product'                  => 'on_product',
			'woocommerce_new_product_variation'           => 'on_variation',
			'woocommerce_update_product_variation'        => 'on_variation',
			'woocommerce_before_delete_product_variation' => 'on_variation',
			'woocommerce_trash_product_variation'         => 'on_variation',
			'woocommerce_product_set_stock'               => 'on_product_object',
			'woocommerce_variation_set_stock'             => 'on_product_object',
			'woocommerce_product_set_stock_status'        => 'on_stock_status',
			'woocommerce_variation_set_stock_status'      => 'on_stock_status',
			'woocommerce_attribute_added'                 => 'flag_products_reindex',
			'woocommerce_attribute_updated'               => 'flag_products_reindex',
			'woocommerce_attribute_deleted'               => 'flag_products_reindex',
			'update_option_woocommerce_hide_out_of_stock_items' => 'on_store_option_updated',
			'add_option_woocommerce_hide_out_of_stock_items' => 'flag_products_reindex',
			'update_option_woocommerce_tax_display_shop'  => 'on_store_option_updated',
		);
		foreach ( $expected as $hook => $method ) {
			self::assertSame( 10, has_action( $hook, array( $this->sync, $method ) ), $hook );
		}
		self::assertSame( 20, has_action( 'wp_update_comment_count', array( $this->sync, 'on_comment_count' ) ) );
		self::assertFalse( has_action( 'edited_term' ), 'Product term edits belong to TermJob.' );
	}

	public function test_product_change_collects_the_product(): void {
		$this->sync->on_product( 10 );

		self::assertSame( array( 10 ), $this->pending_products() );
	}

	public function test_variation_change_collects_the_parent_from_the_object(): void {
		$this->sync->on_variation( 11, $this->variation() );

		self::assertSame( array( 10 ), $this->pending_products() );
	}

	public function test_variation_delete_or_trash_collects_the_parent_from_the_post(): void {
		$this->sync->on_variation( 11 );

		self::assertSame( array( 10 ), $this->pending_products() );
	}

	public function test_stock_hooks_collect_the_parent(): void {
		$this->sync->on_product_object( $this->variation() );
		$this->sync->on_stock_status( 11, 'outofstock', $this->variation() );
		$this->sync->on_product_object( 'not a product' );

		self::assertSame( array( 10 ), $this->pending_products() );
	}

	public function test_comment_count_collects_products_only(): void {
		$this->sync->on_comment_count( 20 );
		self::assertSame( array(), array_filter( $this->collector->pending() ) );

		$this->sync->on_comment_count( 10 );
		self::assertSame( array( 10 ), $this->pending_products() );
	}

	public function test_unresolvable_ids_are_ignored(): void {
		$this->sync->on_variation( 999 );
		$this->sync->on_product( 0 );

		self::assertSame( array(), array_filter( $this->collector->pending() ) );
	}

	public function test_parent_id_resolution(): void {
		self::assertSame( 10, $this->sync->parent_id( 11, $this->variation() ) );
		self::assertSame( 10, $this->sync->parent_id( 10, new \WC_Product_Simple( array( 'id' => 10 ) ) ) );
		self::assertSame( 10, $this->sync->parent_id( 11 ) );
		self::assertSame( 10, $this->sync->parent_id( 10 ) );
	}

	public function test_store_option_change_flags_products_reindex(): void {
		$this->sync->on_store_option_updated( 'no', 'yes' );

		self::assertTrue( ( new Options() )->needs_reindex( 'products' ) );
	}

	public function test_unchanged_store_option_does_not_flag(): void {
		$this->sync->on_store_option_updated( 'yes', 'yes' );

		self::assertFalse( ( new Options() )->needs_reindex( 'products' ) );
	}
}
