<?php
/**
 * Products index settings (spec § 8.3).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

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
					array( 'price', 'in_stock', 'stock_status', 'on_sale', 'featured', 'product_type', 'rating_average' )
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
		return array( 'id', 'price', 'total_sales', 'rating_average', 'date', 'title' );
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
