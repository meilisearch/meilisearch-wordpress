<?php
/**
 * Pins the behaviour of the WordPress/WooCommerce doubles.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Support;

use Meilisearch\WordPress\Tests\Unit\TestCase;

final class WpDoublesTest extends TestCase {

	public function test_wp_post_copies_fields_and_keeps_defaults(): void {
		$post = new \WP_Post(
			[
				'ID'        => 12,
				'post_type' => 'page',
			]
		);

		$this->assertSame( 12, $post->ID );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '', $post->post_password );
	}

	public function test_wp_query_vars_and_flags(): void {
		$query = new \WP_Query(
			[
				's'     => 'shoes',
				'paged' => null,
			]
		);
		$query->set( 'posts_per_page', 5 );
		$query->main_query = true;

		$this->assertSame( 'shoes', $query->get( 's' ) );
		$this->assertSame( 1, $query->get( 'paged', 1 ) );
		$this->assertSame( 5, $query->get( 'posts_per_page' ) );
		$this->assertSame( '', $query->get( 'missing' ) );
		$this->assertTrue( $query->is_main_query() );
		$this->assertFalse( $query->is_search() );
		$this->assertNull( $query->tax_query );
	}

	public function test_wp_tax_query_normalizes_like_core(): void {
		$tax_query = new \WP_Tax_Query(
			[
				'relation' => 'or',
				[
					'taxonomy' => 'category',
					'terms'    => 3,
				],
				[
					[
						'taxonomy' => 'post_tag',
						'field'    => 'slug',
						'terms'    => [ 'a', 'b' ],
						'operator' => 'NOT IN',
					],
				],
			]
		);

		$this->assertSame( 'OR', $tax_query->relation );
		$this->assertSame(
			[
				'relation' => 'OR',
				[
					'taxonomy'         => 'category',
					'terms'            => [ 3 ],
					'field'            => 'term_id',
					'operator'         => 'IN',
					'include_children' => true,
				],
				[
					[
						'taxonomy'         => 'post_tag',
						'terms'            => [ 'a', 'b' ],
						'field'            => 'slug',
						'operator'         => 'NOT IN',
						'include_children' => true,
					],
					'relation' => 'AND',
				],
			],
			$tax_query->queries
		);
	}

	public function test_wp_error(): void {
		$error = new \WP_Error( 'http_request_failed', 'cURL error 28', [ 'status' => 0 ] );

		$this->assertTrue( $error->has_errors() );
		$this->assertSame( 'http_request_failed', $error->get_error_code() );
		$this->assertSame( 'cURL error 28', $error->get_error_message() );
		$this->assertSame( [ 'status' => 0 ], $error->get_error_data() );
		$this->assertFalse( ( new \WP_Error() )->has_errors() );
	}

	public function test_wc_product_doubles(): void {
		$product   = new \WC_Product(
			[
				'id'           => 7,
				'stock_status' => 'outofstock',
				'meta'         => [ '_color' => 'red' ],
			]
		);
		$variable  = new \WC_Product_Variable(
			[
				'variation_prices' => [
					'min' => '10',
					'max' => '20',
				],
			]
		);
		$variation = new \WC_Product_Variation( [ 'attributes' => [ 'pa_color' => 'red' ] ] );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 4 );
		$attribute->set_name( 'pa_color' );
		$attribute->set_options( [ 11, 12 ] );

		$this->assertSame( 7, $product->get_id() );
		$this->assertSame( 'simple', $product->get_type() );
		$this->assertFalse( $product->is_in_stock() );
		$this->assertSame( 'red', $product->get_meta( '_color' ) );
		$this->assertSame( 'variable', $variable->get_type() );
		$this->assertSame( '20', $variable->get_variation_price( 'max' ) );
		$this->assertSame( [ 'attribute_pa_color' => 'red' ], $variation->get_variation_attributes() );
		$this->assertSame( [ 'pa_color' => 'red' ], $variation->get_variation_attributes( false ) );
		$this->assertTrue( $attribute->is_taxonomy() );
		$this->assertSame( [ 11, 12 ], $attribute->get_options() );
	}
}
