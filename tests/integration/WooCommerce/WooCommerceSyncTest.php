<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\WooCommerce;

use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Tests\Integration\TestCase;

require_once __DIR__ . '/CreatesProducts.php';

final class WooCommerceSyncTest extends TestCase {

	use CreatesProducts;

	public function set_up(): void {
		parent::set_up();
		$this->skip_without_woocommerce();
		$this->enable_products();
	}

	public function tear_down(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->remove_attributes();
		}
		parent::tear_down();
	}

	/**
	 * @return array{0: \WC_Product_Variable, 1: array{id: int, taxonomy: string, terms: array<string, int>}}
	 */
	private function shirt(): array {
		$color   = $this->create_attribute( 'color', array( 'Red', 'Blue' ) );
		$product = $this->create_variable_product(
			'Meili Sync Shirt',
			$color,
			array(
				'Red'  => array(
					'price' => '10',
					'sku'   => 'MSS-RED',
				),
				'Blue' => array(
					'price' => '20',
					'sku'   => 'MSS-BLUE',
				),
			)
		);
		return array( $product, $color );
	}

	public function test_variable_product_is_indexed_with_attribute_names_prices_and_variations(): void {
		list( $product ) = $this->shirt();

		$this->sync_products();
		$document = $this->product_document( $product->get_id() );

		$this->assertNotNull( $document );
		$this->assertEqualsCanonicalizing( array( 'Red', 'Blue' ), $document['attr_color'] );
		$this->assertSame( 10.0, $document['price_min'] );
		$this->assertSame( 20.0, $document['price_max'] );
		$this->assertCount( 2, $document['variations'] );
		$this->assertEqualsCanonicalizing( array( 'MSS-RED', 'MSS-BLUE' ), $document['variation_skus'] );
	}

	public function test_variation_change_resyncs_the_parent(): void {
		list( $product ) = $this->shirt();
		$this->sync_products();

		$red = wc_get_product( $product->get_children()[0] );
		$red->set_regular_price( '15' );
		$red->save();
		$this->sync_products();

		$this->assertSame( 15.0, $this->product_document( $product->get_id() )['price_min'] );
	}

	public function test_hidden_catalog_visibility_removes_the_product(): void {
		$product = $this->create_simple_product( 'Meili Visible Tee', '12' );
		$this->sync_products();
		$this->assertNotNull( $this->product_document( $product->get_id() ) );

		$product->set_catalog_visibility( 'hidden' );
		$product->save();
		$this->sync_products();

		$this->assertNull( $this->product_document( $product->get_id() ) );
	}

	public function test_out_of_stock_product_is_removed_when_the_store_hides_out_of_stock_items(): void {
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$this->assertTrue( Plugin::instance()->get( 'options' )->needs_reindex( 'products' ) );

		$product = $this->create_simple_product(
			'Meili Stock Tee',
			'12',
			array(
				'manage_stock'   => true,
				'stock_quantity' => 5,
			)
		);
		$this->sync_products();
		$this->assertNotNull( $this->product_document( $product->get_id() ) );

		wc_update_product_stock( $product, 0, 'set' );
		$this->sync_products();

		$this->assertNull( $this->product_document( $product->get_id() ) );
	}

	public function test_stock_change_updates_the_document(): void {
		$product = $this->create_simple_product(
			'Meili Quantity Tee',
			'12',
			array(
				'manage_stock'   => true,
				'stock_quantity' => 5,
			)
		);
		$this->sync_products();
		$this->assertSame( 5, $this->product_document( $product->get_id() )['stock_quantity'] );

		wc_update_product_stock( $product, 3, 'set' );
		$this->sync_products();

		$this->assertSame( 3, $this->product_document( $product->get_id() )['stock_quantity'] );
	}

	public function test_trashed_product_is_removed(): void {
		$product = $this->create_simple_product( 'Meili Trash Tee', '12' );
		$this->sync_products();
		$this->assertNotNull( $this->product_document( $product->get_id() ) );

		wp_trash_post( $product->get_id() );
		$this->sync_products();

		$this->assertNull( $this->product_document( $product->get_id() ) );
	}

	public function test_category_rename_resyncs_its_products(): void {
		$term    = wp_insert_term( 'Tees', 'product_cat' );
		$product = $this->create_simple_product( 'Meili Category Tee', '12', array( 'category_ids' => array( (int) $term['term_id'] ) ) );
		$this->sync_products();

		wp_update_term( (int) $term['term_id'], 'product_cat', array( 'name' => 'T-shirts' ) );
		$this->sync_products();

		$this->assertSame( array( 'T-shirts' ), $this->product_document( $product->get_id() )['tax_product_cat'] );
	}

	public function test_scheduled_sale_start_resyncs_the_product(): void {
		$product = $this->create_simple_product(
			'Meili Sale Tee',
			'20',
			array(
				'sale_price'        => '5',
				'date_on_sale_from' => time() + DAY_IN_SECONDS,
			)
		);
		$this->sync_products();
		$this->assertNull( $this->product_document( $product->get_id() )['sale_price'] );

		update_post_meta( $product->get_id(), '_sale_price_dates_from', time() - HOUR_IN_SECONDS );
		wc_scheduled_sales();
		$this->sync_products();

		$this->assertSame( 5.0, $this->product_document( $product->get_id() )['sale_price'] );
	}
}
