<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Meilisearch\WordPress\WooCommerce\ProductRule;

final class ProductRuleTest extends TestCase {

	private function product_post( int $id = 7 ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'        => $id,
				'post_type' => 'product',
			)
		);
	}

	private function stub_product( ?\WC_Product $product, string $hide_out_of_stock = 'no' ): void {
		Functions\when( 'wc_get_product' )->justReturn( $product ?? false );
		$this->stub_options( array( 'woocommerce_hide_out_of_stock_items' => $hide_out_of_stock ) );
	}

	private function simple( string $visibility, string $stock_status = 'instock' ): \WC_Product_Simple {
		return new \WC_Product_Simple(
			array(
				'catalog_visibility' => $visibility,
				'stock_status'       => $stock_status,
			)
		);
	}

	public function test_non_product_posts_pass(): void {
		$post = new \WP_Post(
			array(
				'ID'        => 1,
				'post_type' => 'post',
			)
		);

		self::assertTrue( ( new ProductRule() )( $post ) );
	}

	public function test_missing_product_fails(): void {
		$this->stub_product( null );

		self::assertFalse( ( new ProductRule() )( $this->product_post() ) );
	}

	/**
	 * @dataProvider visibility_cases
	 */
	public function test_catalog_visibility( string $visibility, bool $expected ): void {
		$this->stub_product( $this->simple( $visibility ) );

		self::assertSame( $expected, ( new ProductRule() )( $this->product_post() ) );
	}

	public function visibility_cases(): array {
		return array(
			'visible' => array( 'visible', true ),
			'search'  => array( 'search', true ),
			'catalog' => array( 'catalog', false ),
			'hidden'  => array( 'hidden', false ),
		);
	}

	public function test_out_of_stock_is_excluded_only_when_hidden_by_store_setting(): void {
		$this->stub_product( $this->simple( 'visible', 'outofstock' ), 'yes' );
		self::assertFalse( ( new ProductRule() )( $this->product_post() ) );

		$this->stub_product( $this->simple( 'visible', 'onbackorder' ), 'yes' );
		self::assertTrue( ( new ProductRule() )( $this->product_post() ) );

		$this->stub_product( $this->simple( 'visible', 'outofstock' ), 'no' );
		self::assertTrue( ( new ProductRule() )( $this->product_post() ) );
	}

	public function test_supported_types(): void {
		self::assertTrue( ProductRule::supports( new \WC_Product_Simple() ) );
		self::assertTrue( ProductRule::supports( new \WC_Product_Variable() ) );
		self::assertTrue( ProductRule::supports( new \WC_Product_Grouped() ) );
		self::assertTrue( ProductRule::supports( new \WC_Product_External() ) );
		self::assertFalse( ProductRule::supports( new \WC_Product_Variation() ) );
		self::assertFalse( ProductRule::supports( new \WC_Product() ) );
	}

	public function test_unsupported_type_fails(): void {
		$this->stub_product( new \WC_Product_Variation() );

		self::assertFalse( ( new ProductRule() )( $this->product_post() ) );
	}
}
