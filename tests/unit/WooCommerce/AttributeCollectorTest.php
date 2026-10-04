<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Meilisearch\WordPress\WooCommerce\AttributeCollector;

final class AttributeCollectorTest extends TestCase {

	/**
	 * Taxonomy => slug => name.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const TERMS = array(
		'pa_color' => array(
			'red'       => 'Red',
			'blue'      => 'Blue',
			'navy-blue' => 'Navy Blue',
		),
		'pa_size'  => array(
			'xl' => 'XL',
		),
	);

	private array $products = array();

	protected function set_up(): void {
		parent::set_up();
		$this->products = array();
		Functions\when( 'sanitize_title' )->alias(
			static function ( $title ) {
				return strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ), '-' ) );
			}
		);
		Functions\when( 'get_term_by' )->alias(
			static function ( $field, $value, $taxonomy = '' ) {
				if ( 'slug' !== $field || ! isset( self::TERMS[ $taxonomy ][ $value ] ) ) {
					return false;
				}
				return new \WP_Term(
					array(
						'slug'     => $value,
						'name'     => self::TERMS[ $taxonomy ][ $value ],
						'taxonomy' => $taxonomy,
					)
				);
			}
		);
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				return $this->products[ (int) $id ] ?? false;
			}
		);
	}

	private function taxonomy_attribute( string $taxonomy, array $options = array() ): \WC_Product_Attribute {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 1 );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( $options );
		return $attribute;
	}

	private function custom_attribute( string $name, array $options ): \WC_Product_Attribute {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( $name );
		$attribute->set_options( $options );
		return $attribute;
	}

	private function stub_product_terms( array $by_taxonomy ): void {
		Functions\when( 'wc_get_product_terms' )->alias(
			static function ( $product_id, $taxonomy ) use ( $by_taxonomy ) {
				return $by_taxonomy[ $taxonomy ] ?? array();
			}
		);
	}

	private function variation( int $id, array $attributes, string $status = 'publish' ): \WC_Product_Variation {
		$variation             = new \WC_Product_Variation(
			array(
				'id'         => $id,
				'status'     => $status,
				'attributes' => $attributes,
			)
		);
		$this->products[ $id ] = $variation;
		return $variation;
	}

	public function test_field_for_taxonomy_strips_prefix_and_normalizes(): void {
		self::assertSame( 'attr_color', AttributeCollector::field_for_taxonomy( 'pa_color' ) );
		self::assertSame( 'attr_couleur', AttributeCollector::field_for_taxonomy( 'pa_couleur-é' ) );
		self::assertSame( 'attr_my_size', AttributeCollector::field_for_taxonomy( 'pa_my.size' ) );
		self::assertNull( AttributeCollector::field_for_taxonomy( 'pa_日本' ) );
	}

	public function test_field_for_custom_uses_sanitized_title(): void {
		self::assertSame( 'custom_attr_fabric_type', AttributeCollector::field_for_custom( 'Fabric Type' ) );
		self::assertNull( AttributeCollector::field_for_custom( '***' ) );
	}

	public function test_collects_allowed_taxonomy_terms_and_custom_values(): void {
		$this->stub_product_terms(
			array(
				'pa_color' => array( 'Red', 'Blue' ),
				'pa_size'  => array( 'XL' ),
			)
		);
		$product = new \WC_Product_Simple(
			array(
				'id'         => 5,
				'attributes' => array(
					'pa_color'    => $this->taxonomy_attribute( 'pa_color', array( 1, 2 ) ),
					'pa_size'     => $this->taxonomy_attribute( 'pa_size', array( 3 ) ),
					'fabric-type' => $this->custom_attribute( 'Fabric Type', array( 'Linen', 'Cotton &amp; Silk' ) ),
				),
			)
		);

		$fields = ( new AttributeCollector() )->collect( $product, array( 'pa_color' ), true );

		self::assertSame(
			array(
				'attr_color'              => array( 'Red', 'Blue' ),
				'custom_attr_fabric_type' => array( 'Linen', 'Cotton & Silk' ),
			),
			$fields
		);
	}

	public function test_null_allow_list_means_all_taxonomies_and_custom_can_be_disabled(): void {
		$this->stub_product_terms(
			array(
				'pa_color' => array( 'Red' ),
				'pa_size'  => array( 'XL' ),
			)
		);
		$product = new \WC_Product_Simple(
			array(
				'id'         => 5,
				'attributes' => array(
					'pa_color'    => $this->taxonomy_attribute( 'pa_color' ),
					'pa_size'     => $this->taxonomy_attribute( 'pa_size' ),
					'fabric-type' => $this->custom_attribute( 'Fabric Type', array( 'Linen' ) ),
				),
			)
		);

		$fields = ( new AttributeCollector() )->collect( $product, null, false );

		self::assertSame(
			array(
				'attr_color' => array( 'Red' ),
				'attr_size'  => array( 'XL' ),
			),
			$fields
		);
	}

	public function test_variable_product_unions_variation_term_names_not_slugs(): void {
		$this->stub_product_terms( array( 'pa_color' => array( 'Red' ) ) );
		$this->variation(
			101,
			array(
				'pa_color'    => 'navy-blue',
				'fabric-type' => 'Linen',
			)
		);
		$this->variation( 102, array( 'pa_color' => '' ) );
		$this->variation( 103, array( 'pa_color' => 'blue' ), 'private' );
		$this->variation( 104, array( 'pa_size' => 'xl' ) );
		$product = new \WC_Product_Variable(
			array(
				'id'         => 100,
				'children'   => array( 101, 102, 103, 104, 999 ),
				'attributes' => array(
					'pa_color'    => $this->taxonomy_attribute( 'pa_color' ),
					'fabric-type' => $this->custom_attribute( 'Fabric Type', array( 'Cotton' ) ),
				),
			)
		);

		$fields = ( new AttributeCollector() )->collect( $product, array( 'pa_color' ), true );

		self::assertSame(
			array(
				'attr_color'              => array( 'Red', 'Navy Blue' ),
				'custom_attr_fabric_type' => array( 'Cotton', 'Linen' ),
			),
			$fields
		);
	}

	public function test_non_taxonomy_attribute_objects_are_ignored(): void {
		$this->stub_product_terms( array() );
		$product = new \WC_Product_Simple(
			array(
				'id'         => 5,
				'attributes' => array( 'legacy' => 'not an attribute object' ),
			)
		);

		self::assertSame( array(), ( new AttributeCollector() )->collect( $product, null, true ) );
	}

	public function test_variation_attributes_maps_slugs_to_display_names(): void {
		$variation = $this->variation(
			201,
			array(
				'pa_color' => 'navy-blue',
				'size'     => 'Large',
				'pa_fit'   => '',
			)
		);

		self::assertSame(
			array(
				'color' => 'Navy Blue',
				'size'  => 'Large',
			),
			( new AttributeCollector() )->variation_attributes( $variation )
		);
	}

	public function test_variation_attributes_fall_back_to_slug_for_unknown_terms(): void {
		$variation = $this->variation( 202, array( 'pa_color' => 'unknown-slug' ) );

		self::assertSame( array( 'color' => 'unknown-slug' ), ( new AttributeCollector() )->variation_attributes( $variation ) );
	}

	public function test_variations_are_capped(): void {
		$children = array();
		for ( $id = 1000; $id < 1000 + AttributeCollector::MAX_VARIATIONS + 5; $id++ ) {
			$this->variation( $id, array() );
			$children[] = $id;
		}
		$product = new \WC_Product_Variable( array( 'children' => $children ) );

		self::assertCount( AttributeCollector::MAX_VARIATIONS, ( new AttributeCollector() )->variations( $product ) );
	}
}
