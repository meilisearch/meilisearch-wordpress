<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\WooCommerce;

use Meilisearch\WordPress\Indexing\ContentDocumentBuilder;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Integration\TestCase;
use Meilisearch\WordPress\WooCommerce\AttributeCollector;
use Meilisearch\WordPress\WooCommerce\CategoryHierarchy;
use Meilisearch\WordPress\WooCommerce\ProductDocumentBuilder;

require_once __DIR__ . '/CreatesProducts.php';

final class ProductDocumentBuilderTest extends TestCase {

	use CreatesProducts;

	public function set_up(): void {
		parent::set_up();
		$this->skip_without_woocommerce();
	}

	public function tear_down(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->remove_attributes();
		}
		parent::tear_down();
	}

	private function builder(): ProductDocumentBuilder {
		$options = Plugin::instance()->get( 'options' );
		return new ProductDocumentBuilder( new ContentDocumentBuilder( $options ), new CategoryHierarchy(), new AttributeCollector(), $options );
	}

	public function test_variable_product_document(): void {
		update_option(
			Options::WOOCOMMERCE,
			array(
				'enabled'           => true,
				'attributes'        => null,
				'custom_attributes' => false,
				'variation_skus'    => true,
			)
		);
		$color   = $this->create_attribute( 'color', array( 'Red', 'Blue' ) );
		$product = $this->create_variable_product(
			'Meili Linen Shirt',
			$color,
			array(
				'Red'  => array(
					'price' => '10',
					'sku'   => 'MLS-RED',
				),
				'Blue' => array(
					'price' => '20',
					'sku'   => 'MLS-BLUE',
				),
			)
		);
		$parent  = wp_insert_term( 'Clothing', 'product_cat' );
		$child   = wp_insert_term( 'Shirts', 'product_cat', array( 'parent' => $parent['term_id'] ) );
		$product->set_category_ids( array( (int) $child['term_id'] ) );
		$product->save();

		$document = $this->builder()->build( get_post( $product->get_id() ) );

		$this->assertIsArray( $document );
		$this->assertSame( $product->get_id(), $document['id'] );
		$this->assertSame( 'product', $document['post_type'] );
		$this->assertSame( 'Meili Linen Shirt', $document['title'] );
		$this->assertSame( 'variable', $document['product_type'] );
		$this->assertSame( 10.0, $document['price'] );
		$this->assertSame( 10.0, $document['price_min'] );
		$this->assertSame( 20.0, $document['price_max'] );
		$this->assertTrue( $document['in_stock'] );
		$this->assertEqualsCanonicalizing( array( 'Red', 'Blue' ), $document['attr_color'] );
		$this->assertEqualsCanonicalizing( array( 'MLS-RED', 'MLS-BLUE' ), $document['variation_skus'] );
		$this->assertCount( 2, $document['variations'] );
		$this->assertEqualsCanonicalizing(
			array( array( 'color' => 'Red' ), array( 'color' => 'Blue' ) ),
			array_column( $document['variations'], 'attributes' )
		);
		$this->assertEqualsCanonicalizing( array( 'Clothing', 'Shirts' ), $document['tax_product_cat'] );
		$this->assertEqualsCanonicalizing( array( (int) $parent['term_id'], (int) $child['term_id'] ), $document['tax_product_cat_ids'] );
		$this->assertSame( array( 'Clothing > Shirts' ), $document['categories']['lvl1'] );
		$this->assertFalse( has_filter( 'woocommerce_get_tax_location' ) );
	}

	public function test_variation_post_is_not_built(): void {
		$color   = $this->create_attribute( 'color', array( 'Red' ) );
		$product = $this->create_variable_product(
			'Meili Variation Parent',
			$color,
			array(
				'Red' => array(
					'price' => '10',
					'sku'   => 'MVP-RED',
				),
			)
		);

		$variation_id = $product->get_children()[0];

		$this->assertNull( $this->builder()->build( get_post( $variation_id ) ) );
	}

	public function test_wired_sync_indexes_visible_products_only(): void {
		$this->enable_products();
		$visible = $this->create_simple_product( 'Meili Synced Mug', '15.50', array( 'sku' => 'MUG-1' ) );
		$hidden  = $this->create_simple_product( 'Meili Hidden Mug', '9', array( 'catalog_visibility' => 'hidden' ) );

		$this->sync_products();

		$document = $this->product_document( $visible->get_id() );
		$this->assertIsArray( $document );
		$this->assertSame( 'simple', $document['product_type'] );
		$this->assertSame( 15.5, $document['price'] );
		$this->assertSame( 'MUG-1', $document['sku'] );
		$this->assertNull( $this->product_document( $hidden->get_id() ) );
	}

	public function test_scheduled_sale_price_is_not_exposed(): void {
		$product = $this->create_simple_product(
			'Meili Scheduled Sale',
			'20',
			array(
				'sale_price'        => '5',
				'date_on_sale_from' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
			)
		);

		$document = $this->builder()->build( get_post( $product->get_id() ) );

		$this->assertIsArray( $document );
		$this->assertNull( $document['sale_price'] );
		$this->assertFalse( $document['on_sale'] );
		$this->assertSame( 20.0, $document['price'] );
	}

	public function test_enable_products_syncs_a_product_first(): void {
		$this->assert_product_syncs( 'Meili Sync One' );
	}

	public function test_enable_products_syncs_a_product_second(): void {
		$this->assert_product_syncs( 'Meili Sync Two' );
	}

	private function assert_product_syncs( string $name ): void {
		$this->enable_products();
		$product = $this->create_simple_product( $name, '12' );

		$this->sync_products();

		$this->assertIsArray( $this->product_document( $product->get_id() ) );
	}
}
