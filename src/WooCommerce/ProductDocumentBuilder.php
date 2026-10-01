<?php
/**
 * Product document builder (spec § 8.2).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Indexing\ContentDocumentBuilder;
use Meilisearch\WordPress\Indexing\DocumentBuilder;
use Meilisearch\WordPress\Settings\Options;

/**
 * Builds `{prefix}_products` documents: content core fields + product fields.
 *
 * Prices are display prices (`woocommerce_tax_display_shop`) computed for the store base
 * location. Sync runs in Action Scheduler / WP-CLI where `WC()->customer` is null, and
 * WooCommerce then uses the base location only for some tax settings (otherwise no tax at
 * all), so the base location is forced for the duration of the price calculation through
 * a scoped `woocommerce_get_tax_location` filter that is removed before returning.
 */
final class ProductDocumentBuilder implements DocumentBuilder {

	public const MAX_VARIATIONS = AttributeCollector::MAX_VARIATIONS;

	/**
	 * Constructor.
	 *
	 * @param ContentDocumentBuilder $content    Core field builder.
	 * @param CategoryHierarchy      $categories Category hierarchy builder.
	 * @param AttributeCollector     $attributes Attribute collector.
	 * @param Options                $options    Plugin options.
	 */
	public function __construct(
		private readonly ContentDocumentBuilder $content,
		private readonly CategoryHierarchy $categories,
		private readonly AttributeCollector $attributes,
		private readonly Options $options
	) {}

	/**
	 * Builds the document, or null when the post is not a supported product or cannot be encoded.
	 *
	 * @param \WP_Post $post Product post.
	 * @return array<string, mixed>|null
	 */
	public function build( \WP_Post $post ): ?array {
		if ( 'product' !== $post->post_type ) {
			return null;
		}
		$product = wc_get_product( (int) $post->ID );
		if ( ! $product instanceof \WC_Product || ! ProductRule::supports( $product ) ) {
			return null;
		}

		$document = array_merge( $this->content->core_fields( $post ), $this->product_fields( $product ) );

		/** This filter is documented in src/Indexing/ContentDocumentBuilder.php */
		$document = apply_filters( 'meilisearch_document', $document, $post, 'products' );

		if ( ! is_array( $document ) || false === wp_json_encode( $document ) ) {
			return null;
		}
		return $document;
	}

	/**
	 * Product-specific fields (everything except the content core fields).
	 *
	 * @param \WC_Product $product Parent product.
	 * @return array<string, mixed>
	 */
	public function product_fields( \WC_Product $product ): array {
		$settings = $this->options->woocommerce();

		add_filter( 'woocommerce_get_tax_location', array( $this, 'base_tax_location' ), PHP_INT_MAX, 0 );
		try {
			$fields = $this->scalar_fields( $product );
			if ( $product instanceof \WC_Product_Variable ) {
				$fields = array_merge( $fields, $this->variable_fields( $product, $settings['variation_skus'] ) );
			}
		} finally {
			remove_filter( 'woocommerce_get_tax_location', array( $this, 'base_tax_location' ), PHP_INT_MAX );
		}

		$categories                    = $this->categories->build( array_map( 'intval', (array) $product->get_category_ids() ) );
		$fields['tax_product_cat']     = $categories['names'];
		$fields['tax_product_cat_ids'] = $categories['ids'];
		if ( array() !== $categories['categories'] ) {
			$fields['categories'] = $categories['categories'];
		}

		return array_merge(
			$fields,
			$this->tag_fields( $product ),
			$this->attributes->collect( $product, $settings['attributes'], $settings['custom_attributes'] )
		);
	}

	/**
	 * Store base location in the shape WC_Tax::get_tax_location() returns: country, state, postcode, city.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string}
	 */
	public function base_tax_location(): array {
		$countries = WC()->countries;
		return array(
			(string) $countries->get_base_country(),
			(string) $countries->get_base_state(),
			(string) $countries->get_base_postcode(),
			(string) $countries->get_base_city(),
		);
	}

	/**
	 * Fields shared by every supported product type.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	private function scalar_fields( \WC_Product $product ): array {
		$quantity = $product->get_stock_quantity();

		return array(
			'sku'            => (string) $product->get_sku(),
			'product_type'   => (string) $product->get_type(),
			'featured'       => (bool) $product->is_featured(),
			'price'          => $this->display_price( $product, $product->get_price() ),
			'regular_price'  => $this->display_price( $product, $product->get_regular_price() ),
			'sale_price'     => $this->display_price( $product, $product->get_sale_price() ),
			'on_sale'        => (bool) $product->is_on_sale(),
			'in_stock'       => (bool) $product->is_in_stock(),
			'stock_status'   => (string) $product->get_stock_status(),
			'stock_quantity' => is_numeric( $quantity ) ? (int) $quantity : null,
			'rating_average' => (float) $product->get_average_rating(),
			'rating_count'   => (int) $product->get_rating_count(),
			'total_sales'    => (int) $product->get_total_sales(),
		);
	}

	/**
	 * Variable products: price range, compact variations and optional variation SKUs.
	 *
	 * @param \WC_Product_Variable $product        Variable product.
	 * @param bool                 $variation_skus Whether to index variation SKUs.
	 * @return array<string, mixed>
	 */
	private function variable_fields( \WC_Product_Variable $product, bool $variation_skus ): array {
		$min    = self::money( $product->get_variation_price( 'min', true ) );
		$fields = array(
			'price'         => $min,
			'price_min'     => $min,
			'price_max'     => self::money( $product->get_variation_price( 'max', true ) ),
			'regular_price' => self::money( $product->get_variation_regular_price( 'min', true ) ),
			'sale_price'    => $product->is_on_sale() ? self::money( $product->get_variation_sale_price( 'min', true ) ) : null,
		);

		$entries = array();
		$skus    = array();
		foreach ( $this->attributes->variations( $product ) as $variation ) {
			// 'view' falls back to the parent SKU; 'edit' is the variation's own.
			$own_sku   = (string) $variation->get_sku( 'edit' );
			$entries[] = array(
				'id'         => (int) $variation->get_id(),
				'sku'        => (string) $variation->get_sku(),
				'price'      => $this->display_price( $variation, $variation->get_price() ),
				'attributes' => $this->attributes->variation_attributes( $variation ),
				'in_stock'   => (bool) $variation->is_in_stock(),
			);
			if ( '' !== $own_sku ) {
				$skus[] = $own_sku;
			}
		}
		$fields['variations'] = $entries;
		if ( $variation_skus ) {
			$fields['variation_skus'] = array_values( array_unique( $skus ) );
		}
		return $fields;
	}

	/**
	 * Product tag names and IDs.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{tax_product_tag: list<string>, tax_product_tag_ids: list<int>}
	 */
	private function tag_fields( \WC_Product $product ): array {
		$names = array();
		$ids   = array();
		foreach ( (array) $product->get_tag_ids() as $tag_id ) {
			$term = get_term( (int) $tag_id, 'product_tag' );
			if ( $term instanceof \WP_Term ) {
				$ids[]   = (int) $term->term_id;
				$names[] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		return array(
			'tax_product_tag'     => array_values( array_unique( $names ) ),
			'tax_product_tag_ids' => array_values( array_unique( $ids ) ),
		);
	}

	/**
	 * Display price (tax display setting applied) or null when the raw price is empty.
	 *
	 * @param \WC_Product $product Product the price belongs to (tax class).
	 * @param mixed       $raw     Raw stored price.
	 */
	private function display_price( \WC_Product $product, mixed $raw ): ?float {
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		return (float) wc_get_price_to_display( $product, array( 'price' => (float) $raw ) );
	}

	/**
	 * Float or null for an already-display-ready amount.
	 *
	 * @param mixed $value Amount.
	 */
	private static function money( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}
}
