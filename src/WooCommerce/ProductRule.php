<?php
/**
 * Product part of the indexability rule (spec § 5.2 rule 4).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * A product is indexable only when it is a parent product (not a variation), its catalog visibility is
 * `visible` or `search`, and — when "Hide out of stock items" is on — it is in stock.
 * Passed to Indexability as its `$product_rule` callable.
 */
final class ProductRule {

	public const VISIBLE_IN_SEARCH = array( 'visible', 'search' );

	/**
	 * Applies the rule; non-product posts are not this rule's concern and pass.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function __invoke( \WP_Post $post ): bool {
		if ( 'product' !== $post->post_type ) {
			return true;
		}
		$product = wc_get_product( (int) $post->ID );
		if ( ! $product instanceof \WC_Product || ! self::supports( $product ) ) {
			return false;
		}
		if ( ! in_array( (string) $product->get_catalog_visibility(), self::VISIBLE_IN_SEARCH, true ) ) {
			return false;
		}
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $product->is_in_stock() ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a product gets its own document: every parent product, the four core types (simple,
	 * variable, grouped, external) and types added by extensions (subscriptions, bookings, bundles…),
	 * so searchable products never vanish from intercepted searches. Variations never do.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function supports( \WC_Product $product ): bool {
		return ! $product instanceof \WC_Product_Variation;
	}
}
