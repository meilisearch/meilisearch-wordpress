<?php
/**
 * Products index settings (spec § 8.3).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Indexing\ContentSchema;
use Meilisearch\WordPress\Indexing\Schema;
use Meilisearch\WordPress\Settings\Options;

/**
 * Required filterable / sortable fields and initial searchable order for `{prefix}_products`.
 * `id` is filterable and sortable for the reindex orphan sweep (Task 13).
 *
 * `custom_attr_*` fields are indexed but neither filterable nor searchable in 1.0: their
 * names are free text per product, so they cannot be enumerated when settings are computed
 * (and every filterableAttributes change makes Meilisearch re-index the whole index);
 * WooCommerce layered navigation only filters by global `pa_*` attributes anyway.
 */
final class ProductSchema implements Schema {

	/**
	 * Version of the product document shape, recorded by each completed products reindex (build_record()).
	 * 2: `price_max` on every document. Search uses a field only once a reindex has written it everywhere.
	 */
	public const DOCUMENT_VERSION = 2;

	/**
	 * Constructor.
	 *
	 * @param Options $options Plugin options.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Filterable attributes.
	 *
	 * @return list<string>
	 */
	public function filterable(): array {
		return array_values(
			array_unique(
				array_merge(
					array( 'id', 'tax_product_cat_ids', 'tax_product_tag_ids' ),
					$this->attribute_fields(),
					array( 'price', 'price_max', 'in_stock', 'stock_status', 'on_sale', 'featured', 'product_type', 'rating_average' )
				)
			)
		);
	}

	/**
	 * Sortable attributes.
	 *
	 * @return list<string>
	 */
	public function sortable(): array {
		return array( 'id', 'price', 'price_max', 'total_sales', 'rating_average', 'date', 'title' );
	}

	/**
	 * Initial searchable attributes, in ranking order.
	 *
	 * @return list<string>
	 */
	public function searchable(): array {
		return array_values(
			array_unique(
				array_merge(
					array( 'title', 'sku', 'variation_skus' ),
					$this->attribute_fields(),
					array( 'tax_product_cat', 'tax_product_tag', 'excerpt', 'content' )
				)
			)
		);
	}

	/**
	 * Indexed product fields WooCommerce does not publish (lifetime sales, exact stock, author ID).
	 */
	public const PRIVATE_FIELDS = array( 'total_sales', 'stock_quantity', 'author_id' );

	/**
	 * Product fields shown on the product page or in the catalogue, besides those the plugin searches,
	 * filters or sorts on.
	 */
	public const PUBLIC_FIELDS = array( 'sku', 'product_type', 'featured', 'price', 'regular_price', 'sale_price', 'price_min', 'price_max', 'on_sale', 'in_stock', 'stock_status', 'rating_average', 'rating_count', 'categories', 'variations', 'variation_skus', 'tax_product_cat', 'tax_product_cat_ids', 'tax_product_tag', 'tax_product_tag_ids' );

	/**
	 * Public attributes: the content core public fields, the product public fields and every field
	 * the plugin searches, filters or sorts on, except PRIVATE_FIELDS.
	 *
	 * @return list<string>
	 */
	public function displayed(): array {
		$fields = array_merge( ContentSchema::PUBLIC_FIELDS, self::PUBLIC_FIELDS, $this->searchable(), $this->filterable(), $this->sortable() );
		return array_values( array_diff( array_unique( $fields ), self::PRIVATE_FIELDS ) );
	}

	/**
	 * What a products reindex starting now writes into every document. The Reindexer records it when
	 * such a run completes (Options state `products_build`); ProductQueryTranslator only relies on
	 * fields the last completed run wrote.
	 *
	 * @return array{version: int, attributes: list<string>}
	 */
	public function build_record(): array {
		return array(
			'version'    => self::DOCUMENT_VERSION,
			'attributes' => $this->attribute_fields(),
		);
	}

	/**
	 * `attr_*` fields of the indexed global attributes (configured list, or every attribute taxonomy when null).
	 *
	 * @return list<string>
	 */
	public function attribute_fields(): array {
		$taxonomies = $this->options->woocommerce()['attributes'];
		if ( null === $taxonomies ) {
			$taxonomies = wc_get_attribute_taxonomy_names();
		}

		$fields = array();
		foreach ( (array) $taxonomies as $taxonomy ) {
			$field = AttributeCollector::field_for_taxonomy( (string) $taxonomy );
			if ( null !== $field ) {
				$fields[] = $field;
			}
		}
		return array_values( array_unique( $fields ) );
	}
}
