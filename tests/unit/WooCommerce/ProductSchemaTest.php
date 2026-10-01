<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Meilisearch\WordPress\WooCommerce\ProductSchema;

final class ProductSchemaTest extends TestCase {

	private function schema( ?array $attributes, bool $custom = false ): ProductSchema {
		$this->stub_options(
			array(
				Options::WOOCOMMERCE => array(
					'attributes'        => $attributes,
					'custom_attributes' => $custom,
				),
			)
		);
		return new ProductSchema( new Options() );
	}

	public function test_configured_attributes_become_normalized_filterable_fields(): void {
		$schema = $this->schema( array( 'pa_color', 'pa_couleur-é', 'pa_日本' ) );

		self::assertSame(
			array( 'id', 'tax_product_cat_ids', 'tax_product_tag_ids', 'attr_color', 'attr_couleur', 'price', 'in_stock', 'stock_status', 'on_sale', 'featured', 'product_type', 'rating_average' ),
			$schema->filterable()
		);
	}

	public function test_null_attributes_means_every_attribute_taxonomy(): void {
		Functions\when( 'wc_get_attribute_taxonomy_names' )->justReturn( array( 'pa_color', 'pa_size' ) );

		self::assertSame( array( 'attr_color', 'attr_size' ), $this->schema( null )->attribute_fields() );
	}

	public function test_custom_attribute_fields_are_never_filterable_or_searchable(): void {
		$schema = $this->schema( array(), true );

		self::assertSame( array(), preg_grep( '/^custom_attr_/', array_merge( $schema->filterable(), $schema->searchable() ) ) );
	}

	public function test_id_is_filterable_and_sortable_for_the_orphan_sweep(): void {
		$schema = $this->schema( array() );

		self::assertContains( 'id', $schema->filterable() );
		self::assertContains( 'id', $schema->sortable() );
	}

	public function test_sortable_and_searchable_order(): void {
		$schema = $this->schema( array( 'pa_color' ) );

		self::assertSame( array( 'id', 'price', 'total_sales', 'rating_average', 'date', 'title' ), $schema->sortable() );
		self::assertSame(
			array( 'title', 'sku', 'variation_skus', 'attr_color', 'tax_product_cat', 'tax_product_tag', 'excerpt', 'content' ),
			$schema->searchable()
		);
	}
}
