<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\ContentDocumentBuilder;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Meilisearch\WordPress\WooCommerce\AttributeCollector;
use Meilisearch\WordPress\WooCommerce\CategoryHierarchy;
use Meilisearch\WordPress\WooCommerce\ProductDocumentBuilder;

final class ProductDocumentBuilderTest extends TestCase {

	/** @var array<int, \WC_Product> */
	private array $products = array();

	/** @var list<bool> whether the base-location filter was attached during each price computation */
	private array $filter_seen = array();

	protected function set_up(): void {
		parent::set_up();
		$this->products    = array();
		$this->filter_seen = array();
		$this->stub_options( array( Options::WOOCOMMERCE => array( 'variation_skus' => false ) ) );

		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				return $this->products[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'wc_get_price_to_display' )->alias(
			function ( $product, $args = array() ) {
				$this->filter_seen[] = false !== has_filter( 'woocommerce_get_tax_location' );
				return round( (float) $args['price'] * 1.2, 2 ); // Simulates 20 % tax display.
			}
		);
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_term' )->alias(
			static function ( $id, $taxonomy = '' ) {
				$names = array(
					'product_cat' => array( 15 => 'Shirts' ),
					'product_tag' => array(
						40 => 'Summer',
						41 => 'Linen &amp; Co',
					),
				);
				if ( ! isset( $names[ $taxonomy ][ $id ] ) ) {
					return null;
				}
				return new \WP_Term(
					array(
						'term_id'  => $id,
						'name'     => $names[ $taxonomy ][ $id ],
						'taxonomy' => $taxonomy,
					)
				);
			}
		);
		Functions\when( 'wc_get_product_terms' )->justReturn( array() );
		Functions\when( 'get_term_by' )->alias(
			static function ( $field, $value, $taxonomy = '' ) {
				if ( 'pa_color' !== $taxonomy ) {
					return false;
				}
				return new \WP_Term(
					array(
						'slug' => $value,
						'name' => ucfirst( (string) $value ),
					)
				);
			}
		);
	}

	private function builder(): ProductDocumentBuilder {
		$options = new Options();
		return new ProductDocumentBuilder( new ContentDocumentBuilder( $options ), new CategoryHierarchy(), new AttributeCollector(), $options );
	}

	private function post( int $id, string $type ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'        => $id,
				'post_type' => $type,
			)
		);
	}

	private function simple_props( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 7,
				'sku'            => 'SHIRT-1',
				'featured'       => true,
				'price'          => '8',
				'regular_price'  => '10',
				'sale_price'     => '8',
				'on_sale'        => true,
				'stock_status'   => 'instock',
				'stock_quantity' => 12,
				'average_rating' => '4.50',
				'rating_count'   => 3,
				'total_sales'    => '21',
				'category_ids'   => array( 15 ),
				'tag_ids'        => array( 40, 41 ),
			),
			$overrides
		);
	}

	public function test_build_returns_null_for_non_products_and_missing_products(): void {
		self::assertNull( $this->builder()->build( $this->post( 1, 'post' ) ) );
		self::assertNull( $this->builder()->build( $this->post( 404, 'product' ) ) );
	}

	public function test_build_returns_null_for_unsupported_product_types(): void {
		$this->products[9] = new \WC_Product_Variation( array( 'id' => 9 ) );

		self::assertNull( $this->builder()->build( $this->post( 9, 'product' ) ) );
	}

	public function test_simple_product_fields(): void {
		$fields = $this->builder()->product_fields( new \WC_Product_Simple( $this->simple_props() ) );

		self::assertSame(
			array(
				'sku'                 => 'SHIRT-1',
				'product_type'        => 'simple',
				'featured'            => true,
				'price'               => 9.6,
				'regular_price'       => 12.0,
				'sale_price'          => 9.6,
				'on_sale'             => true,
				'in_stock'            => true,
				'stock_status'        => 'instock',
				'stock_quantity'      => 12,
				'rating_average'      => 4.5,
				'rating_count'        => 3,
				'total_sales'         => 21,
				'tax_product_cat'     => array( 'Shirts' ),
				'tax_product_cat_ids' => array( 15 ),
				'categories'          => array( 'lvl0' => array( 'Shirts' ) ),
				'tax_product_tag'     => array( 'Summer', 'Linen & Co' ),
				'tax_product_tag_ids' => array( 40, 41 ),
			),
			$fields
		);
	}

	public function test_empty_prices_unmanaged_stock_and_no_categories(): void {
		$fields = $this->builder()->product_fields(
			new \WC_Product_External(
				$this->simple_props(
					array(
						'price'          => '',
						'regular_price'  => '',
						'sale_price'     => '',
						'stock_quantity' => null,
						'category_ids'   => array(),
					)
				)
			)
		);

		self::assertSame( 'external', $fields['product_type'] );
		self::assertNull( $fields['price'] );
		self::assertNull( $fields['regular_price'] );
		self::assertNull( $fields['sale_price'] );
		self::assertNull( $fields['stock_quantity'] );
		self::assertSame( array(), $fields['tax_product_cat'] );
		self::assertArrayNotHasKey( 'categories', $fields );
		self::assertArrayNotHasKey( 'variations', $fields );
	}

	public function test_prices_are_computed_with_the_base_location_filter_which_is_removed_afterwards(): void {
		$this->builder()->product_fields( new \WC_Product_Simple( $this->simple_props() ) );

		self::assertNotEmpty( $this->filter_seen );
		self::assertNotContains( false, $this->filter_seen );
		self::assertFalse( has_filter( 'woocommerce_get_tax_location' ) );
	}

	public function test_base_tax_location_uses_store_base_address(): void {
		Functions\when( 'WC' )->justReturn(
			(object) array(
				'countries' => new class() {
					public function get_base_country(): string {
						return 'FR';
					}
					public function get_base_state(): string {
						return '';
					}
					public function get_base_postcode(): string {
						return '75001';
					}
					public function get_base_city(): string {
						return 'Paris';
					}
				},
			)
		);

		self::assertSame( array( 'FR', '', '75001', 'Paris' ), $this->builder()->base_tax_location() );
	}

	private function variation( int $id, string $sku, string $price, array $attributes, string $stock_status = 'instock', string $status = 'publish' ): void {
		$this->products[ $id ] = new \WC_Product_Variation(
			array(
				'id'           => $id,
				'status'       => $status,
				'sku'          => $sku,
				'parent_sku'   => 'PARENT-SKU',
				'price'        => $price,
				'attributes'   => $attributes,
				'stock_status' => $stock_status,
			)
		);
	}

	private function variable_product(): \WC_Product_Variable {
		$this->variation( 101, 'SHIRT-RED', '10', array( 'pa_color' => 'red' ) );
		$this->variation( 102, '', '20', array( 'pa_color' => 'blue' ), 'outofstock' );
		$this->variation( 103, 'SHIRT-OFF', '5', array( 'pa_color' => 'green' ), 'instock', 'private' );

		return new \WC_Product_Variable(
			$this->simple_props(
				array(
					'price'                    => '10',
					'regular_price'            => '',
					'sale_price'               => '',
					'on_sale'                  => false,
					'children'                 => array( 101, 102, 103 ),
					'variation_prices'         => array(
						'min' => '12',
						'max' => '24',
					),
					'variation_regular_prices' => array( 'min' => '12' ),
					'variation_sale_prices'    => array( 'min' => '12' ),
				)
			)
		);
	}

	public function test_variable_product_price_range_and_compact_variations(): void {
		$fields = $this->builder()->product_fields( $this->variable_product() );

		self::assertSame( 'variable', $fields['product_type'] );
		self::assertSame( 12.0, $fields['price'] );
		self::assertSame( 12.0, $fields['price_min'] );
		self::assertSame( 24.0, $fields['price_max'] );
		self::assertSame( 12.0, $fields['regular_price'] );
		self::assertNull( $fields['sale_price'] );
		self::assertSame(
			array(
				array(
					'id'         => 101,
					'sku'        => 'SHIRT-RED',
					'price'      => 12.0,
					'attributes' => array( 'color' => 'Red' ),
					'in_stock'   => true,
				),
				array(
					'id'         => 102,
					'sku'        => 'PARENT-SKU',
					'price'      => 24.0,
					'attributes' => array( 'color' => 'Blue' ),
					'in_stock'   => false,
				),
			),
			$fields['variations']
		);
		self::assertArrayNotHasKey( 'variation_skus', $fields );
	}

	public function test_variation_skus_are_own_skus_only_when_enabled(): void {
		$this->stub_options( array( Options::WOOCOMMERCE => array( 'variation_skus' => true ) ) );

		$fields = $this->builder()->product_fields( $this->variable_product() );

		self::assertSame( array( 'SHIRT-RED' ), $fields['variation_skus'] );
	}

	public function test_variable_product_without_variations_has_null_price(): void {
		$product = new \WC_Product_Variable(
			$this->simple_props(
				array(
					'on_sale'                  => false,
					'children'                 => array(),
					'variation_prices'         => array(
						'min' => false,
						'max' => false,
					),
					'variation_regular_prices' => array( 'min' => false ),
				)
			)
		);

		$fields = $this->builder()->product_fields( $product );

		self::assertNull( $fields['price'] );
		self::assertNull( $fields['price_min'] );
		self::assertNull( $fields['price_max'] );
		self::assertSame( array(), $fields['variations'] );
	}
}
