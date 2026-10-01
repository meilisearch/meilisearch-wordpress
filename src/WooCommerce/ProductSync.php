<?php
/**
 * WooCommerce sync hooks (spec § 8.4).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ChangeCollector;

/**
 * Feeds the ChangeCollector with the PARENT product ID for every WooCommerce change that
 * affects a product document, and flags a products reindex when a store-wide setting that
 * changes indexability or display prices is modified.
 *
 * Product trash/delete arrive through the core hooks of ChangeCollector (§ 6.1); edits of
 * product_cat, product_tag and pa_* terms go through TermJob (Task 12).
 */
final class ProductSync implements Registrable {

	/**
	 * Store options whose change affects which products are indexable or their display price.
	 */
	public const REINDEX_OPTIONS = array(
		'woocommerce_hide_out_of_stock_items',
		'woocommerce_tax_display_shop',
		'woocommerce_prices_include_tax',
		'woocommerce_calc_taxes',
		'woocommerce_tax_based_on',
		'woocommerce_default_country',
	);

	/**
	 * Constructor.
	 *
	 * @param ChangeCollector $collector Change collector.
	 * @param Options         $options   Plugin options.
	 */
	public function __construct(
		private readonly ChangeCollector $collector,
		private readonly Options $options
	) {}

	/**
	 * Attaches the hooks.
	 */
	public function register(): void {
		// Args: product ID, product.
		add_action( 'woocommerce_new_product', array( $this, 'on_product' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product' ), 10, 1 );
		// Args: variation ID, variation.
		add_action( 'woocommerce_new_product_variation', array( $this, 'on_variation' ), 10, 2 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_variation' ), 10, 2 );
		// Args: variation ID; fired while the variation post still exists, so its parent is resolvable.
		add_action( 'woocommerce_before_delete_product_variation', array( $this, 'on_variation' ), 10, 1 );
		add_action( 'woocommerce_trash_product_variation', array( $this, 'on_variation' ), 10, 1 );
		// Args: the product or variation object.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_product_object' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_product_object' ), 10, 1 );
		// Args: product or variation ID, new stock status, product object.
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		// Args: post ID, new count, old count; runs after WooCommerce recalculates ratings at priority 10.
		add_action( 'wp_update_comment_count', array( $this, 'on_comment_count' ), 20, 1 );
		// Global attribute added / renamed / deleted: attr_* fields and filterable settings change.
		add_action( 'woocommerce_attribute_added', array( $this, 'flag_products_reindex' ), 10, 0 );
		add_action( 'woocommerce_attribute_updated', array( $this, 'flag_products_reindex' ), 10, 0 );
		add_action( 'woocommerce_attribute_deleted', array( $this, 'flag_products_reindex' ), 10, 0 );

		foreach ( self::REINDEX_OPTIONS as $option ) {
			add_action( "update_option_{$option}", array( $this, 'on_store_option_updated' ), 10, 2 );
			add_action( "add_option_{$option}", array( $this, 'flag_products_reindex' ), 10, 0 );
		}
	}

	/**
	 * Product created / updated, or stock status changed by ID.
	 *
	 * @param int|string $product_id Product ID.
	 */
	public function on_product( $product_id ): void {
		$this->collect( $this->parent_id( (int) $product_id ) );
	}

	/**
	 * Variation created / updated / about to be deleted / trashed.
	 *
	 * @param int|string $variation_id Variation ID.
	 * @param mixed      $variation    Variation object when the hook passes one.
	 */
	public function on_variation( $variation_id, $variation = null ): void {
		$this->collect( $this->parent_id( (int) $variation_id, $variation ) );
	}

	/**
	 * Stock quantity changed (hook passes the product or variation object).
	 *
	 * @param mixed $product Product or variation.
	 */
	public function on_product_object( $product ): void {
		if ( $product instanceof \WC_Product ) {
			$this->collect( $this->parent_id( (int) $product->get_id(), $product ) );
		}
	}

	/**
	 * Stock status changed.
	 *
	 * @param int|string $product_id   Product or variation ID.
	 * @param mixed      $stock_status New stock status (unused).
	 * @param mixed      $product      Product or variation object.
	 */
	public function on_stock_status( $product_id, $stock_status = '', $product = null ): void {
		$this->collect( $this->parent_id( (int) $product_id, $product ) );
	}

	/**
	 * Approved comment count changed — for products this means the rating changed.
	 *
	 * @param int|string $post_id Post ID.
	 */
	public function on_comment_count( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'product' === get_post_type( $post_id ) ) {
			$this->collect( $post_id );
		}
	}

	/**
	 * A store option in REINDEX_OPTIONS changed value.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 */
	public function on_store_option_updated( $old_value, $value ): void {
		if ( $old_value !== $value ) {
			$this->flag_products_reindex();
		}
	}

	/**
	 * Marks the products index as needing a full reindex (admin notice + Status tab).
	 */
	public function flag_products_reindex(): void {
		$this->options->flag_reindex( 'products', true );
	}

	/**
	 * Resolves the parent product ID of a product or variation (0 when unknown).
	 * Only variations are mapped to their parent; other products are their own document.
	 *
	 * @param int   $id      Product or variation ID.
	 * @param mixed $product Product object when available.
	 */
	public function parent_id( int $id, $product = null ): int {
		if ( $product instanceof \WC_Product_Variation ) {
			$parent = (int) $product->get_parent_id();
			return $parent > 0 ? $parent : (int) wp_get_post_parent_id( $id );
		}
		if ( $product instanceof \WC_Product ) {
			return (int) $product->get_id();
		}
		if ( 'product_variation' === get_post_type( $id ) ) {
			return (int) wp_get_post_parent_id( $id );
		}
		return $id;
	}

	/**
	 * Adds a resolved ID to the collector.
	 *
	 * @param int $product_id Parent product ID.
	 */
	private function collect( int $product_id ): void {
		if ( $product_id > 0 ) {
			$this->collector->add( $product_id );
		}
	}
}
