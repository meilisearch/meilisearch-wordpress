<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Meilisearch\WordPress\WooCommerce\Compatibility;

final class CompatibilityTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		FeaturesUtil::$declared = array();
	}

	public function test_register_hooks_declare_on_before_woocommerce_init(): void {
		$compat = new Compatibility();
		$compat->register();

		self::assertSame( 10, has_action( 'before_woocommerce_init', array( $compat, 'declare' ) ) );
	}

	public function test_declare_declares_hpos_and_blocks_compatibility(): void {
		( new Compatibility() )->declare();

		self::assertSame(
			array(
				array( 'custom_order_tables', MEILISEARCH_FILE, true ),
				array( 'cart_checkout_blocks', MEILISEARCH_FILE, true ),
			),
			FeaturesUtil::$declared
		);
	}
}
