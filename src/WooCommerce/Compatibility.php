<?php
/**
 * WooCommerce feature compatibility declarations (spec § 8.1).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Meilisearch\WordPress\Registrable;

/**
 * Declares HPOS and cart/checkout blocks compatibility. The plugin never reads or writes orders.
 */
final class Compatibility implements Registrable {

	/**
	 * WooCommerce features this plugin is compatible with.
	 */
	public const FEATURES = array( 'custom_order_tables', 'cart_checkout_blocks' );

	/**
	 * Hooks the declaration; `before_woocommerce_init` fires on `init` priority 0, after our `plugins_loaded` boot.
	 */
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare' ) );
	}

	/**
	 * Declares compatibility for every feature in FEATURES.
	 */
	public function declare(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}
		foreach ( self::FEATURES as $feature ) {
			FeaturesUtil::declare_compatibility( $feature, MEILISEARCH_FILE, true );
		}
	}
}
