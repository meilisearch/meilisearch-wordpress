<?php
/**
 * Product attribute fields (spec § 8.2).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Indexing\FieldName;

/**
 * Collects global (`pa_*`) attribute term names across a product and its variations,
 * plus custom (per-product) attribute values.
 *
 * Field names are computed only by field_for_taxonomy() / field_for_custom(), which
 * ProductSchema and ProductQueryTranslator reuse, so indexing, settings and filtering
 * always agree (a name that normalizes to nothing is skipped everywhere).
 */
final class AttributeCollector {

	/**
	 * Maximum number of variations read per variable product (also used by ProductDocumentBuilder).
	 */
	public const MAX_VARIATIONS = 100;

	/**
	 * Field name for a global attribute taxonomy: `pa_color` → `attr_color`.
	 *
	 * @param string $taxonomy Attribute taxonomy name (with or without the `pa_` prefix).
	 */
	public static function field_for_taxonomy( string $taxonomy ): ?string {
		$slug  = 0 === strpos( $taxonomy, 'pa_' ) ? substr( $taxonomy, 3 ) : $taxonomy;
		$field = FieldName::normalize( $slug );
		return null === $field ? null : 'attr_' . $field;
	}

	/**
	 * Field name for a custom attribute: `Fabric Type` → `custom_attr_fabric_type`.
	 *
	 * Uses sanitize_title() first because WooCommerce keys custom attributes by
	 * sanitize_title( name ) on both the parent and its variations.
	 *
	 * @param string $name Custom attribute name as entered by the merchant.
	 */
	public static function field_for_custom( string $name ): ?string {
		$field = FieldName::normalize( sanitize_title( $name ) );
		return null === $field ? null : 'custom_attr_' . $field;
	}

	/**
	 * Collects attribute fields for a parent product.
	 *
	 * @param \WC_Product   $product        Parent product.
	 * @param string[]|null $allowed        `pa_*` taxonomy names to index; null = all.
	 * @param bool          $include_custom Whether custom attributes are collected.
	 * @return array<string, list<string>> field => names
	 */
	public function collect( \WC_Product $product, ?array $allowed, bool $include_custom ): array {
		$fields = array();

		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute ) {
				$this->add_parent_attribute( $fields, $product, $attribute, $allowed, $include_custom );
			}
		}

		if ( $product instanceof \WC_Product_Variable ) {
			foreach ( $this->variations( $product ) as $variation ) {
				foreach ( (array) $variation->get_attributes() as $key => $value ) {
					$this->add_variation_value( $fields, (string) $key, (string) $value, $allowed, $include_custom );
				}
			}
		}

		foreach ( $fields as $field => $values ) {
			$fields[ $field ] = array_values( array_unique( array_filter( $values, static fn( string $v ): bool => '' !== $v ) ) );
		}
		ksort( $fields );

		return $fields;
	}

	/**
	 * Adds a parent product attribute's values (term names or custom options) to the fields.
	 *
	 * @param array<string, list<string>> $fields         Fields being built.
	 * @param \WC_Product                 $product        Parent product.
	 * @param \WC_Product_Attribute       $attribute      Attribute object.
	 * @param string[]|null               $allowed        `pa_*` taxonomy names to index; null = all.
	 * @param bool                        $include_custom Whether custom attributes are collected.
	 */
	private function add_parent_attribute( array &$fields, \WC_Product $product, \WC_Product_Attribute $attribute, ?array $allowed, bool $include_custom ): void {
		if ( $attribute->is_taxonomy() ) {
			$taxonomy = (string) $attribute->get_name();
			$field    = self::field_for_taxonomy( $taxonomy );
			if ( null === $field || ! $this->is_allowed( $taxonomy, $allowed ) ) {
				return;
			}
			foreach ( wc_get_product_terms( (int) $product->get_id(), $taxonomy, array( 'fields' => 'names' ) ) as $name ) {
				$fields[ $field ][] = self::decode( (string) $name );
			}
			return;
		}
		if ( ! $include_custom ) {
			return;
		}
		$field = self::field_for_custom( (string) $attribute->get_name() );
		if ( null === $field ) {
			return;
		}
		foreach ( (array) $attribute->get_options() as $option ) {
			$fields[ $field ][] = self::decode( (string) $option );
		}
	}

	/**
	 * Adds one variation attribute value to the fields (term slugs are mapped to term names).
	 *
	 * @param array<string, list<string>> $fields         Fields being built.
	 * @param string                      $key            Variation attribute key (`pa_x` or sanitized custom name).
	 * @param string                      $value          Term slug or raw custom value; empty means "Any".
	 * @param string[]|null               $allowed        `pa_*` taxonomy names to index; null = all.
	 * @param bool                        $include_custom Whether custom attributes are collected.
	 */
	private function add_variation_value( array &$fields, string $key, string $value, ?array $allowed, bool $include_custom ): void {
		if ( '' === $value ) {
			// "Any ..." - the parent's terms already cover it.
			return;
		}
		if ( 0 === strpos( $key, 'pa_' ) ) {
			$field = self::field_for_taxonomy( $key );
			if ( null === $field || ! $this->is_allowed( $key, $allowed ) ) {
				return;
			}
			$term = get_term_by( 'slug', $value, $key );
			if ( $term instanceof \WP_Term ) {
				$fields[ $field ][] = self::decode( (string) $term->name );
			}
			return;
		}
		if ( ! $include_custom ) {
			return;
		}
		$field = self::field_for_custom( $key );
		if ( null !== $field ) {
			$fields[ $field ][] = self::decode( $value );
		}
	}

	/**
	 * Display names of a variation's attributes: attribute slug (without `pa_`) => display name.
	 * Taxonomy values (term slugs) are mapped to term names; "Any" (empty) values are omitted.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array<string, string>
	 */
	public function variation_attributes( \WC_Product_Variation $variation ): array {
		$result = array();
		foreach ( (array) $variation->get_attributes() as $key => $value ) {
			$key   = (string) $key;
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			if ( 0 === strpos( $key, 'pa_' ) ) {
				$term = get_term_by( 'slug', $value, $key );

				$result[ substr( $key, 3 ) ] = $term instanceof \WP_Term ? self::decode( (string) $term->name ) : $value;
				continue;
			}
			$result[ $key ] = self::decode( $value );
		}
		return $result;
	}

	/**
	 * Published child variations, capped at MAX_VARIATIONS (children beyond the cap are ignored).
	 * Reads children directly (not get_available_variations(), which is heavy and
	 * drops hidden / out-of-stock variations).
	 *
	 * @param \WC_Product_Variable $product Variable product.
	 * @return list<\WC_Product_Variation>
	 */
	public function variations( \WC_Product_Variable $product ): array {
		$variations = array();
		foreach ( array_slice( (array) $product->get_children(), 0, self::MAX_VARIATIONS ) as $child_id ) {
			$variation = wc_get_product( (int) $child_id );
			if ( $variation instanceof \WC_Product_Variation && 'publish' === $variation->get_status() ) {
				$variations[] = $variation;
			}
		}
		return $variations;
	}

	/**
	 * Whether a taxonomy is in the allow list (null = all).
	 *
	 * @param string        $taxonomy Attribute taxonomy.
	 * @param string[]|null $allowed  Allow list.
	 */
	private function is_allowed( string $taxonomy, ?array $allowed ): bool {
		return null === $allowed || in_array( $taxonomy, $allowed, true );
	}

	/**
	 * Decodes HTML entities WordPress stores in term names (`&amp;` → `&`).
	 *
	 * @param string $value Raw value.
	 */
	private static function decode( string $value ): string {
		return trim( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
	}
}
