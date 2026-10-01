<?php
/**
 * WooCommerce part of search translation (spec § 8.5).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Search\FilterBuilder;
use Meilisearch\WordPress\Search\ProductTranslator;
use Meilisearch\WordPress\Settings\Options;

/**
 * Translates the WooCommerce-specific part of a product search into products-index filters and
 * sort rules, or returns null ("do not intercept") when it cannot (contract: Search\ProductTranslator).
 *
 * QueryTranslator (Task 16) handles `s`, pagination, post types, denied vars, refuses author /
 * meta / date constraints on products, translates product_cat / product_tag clauses itself and
 * skips top-level ANDed product_visibility / pa_* clauses (nested or OR-ed ones make it return
 * null). This class consumes exactly what it skips:
 * - top-level clauses of an AND tax query whose taxonomy is `product_visibility` or `pa_*`
 *   (every other clause and every nested group is ignored here);
 * - query vars `orderby` / `order` (WooCommerce catalog ordering) and non-empty `post__in` (→ null);
 * - request parameters `filter_{attr}`, `query_type_{attr}`, `min_price`, `max_price`, read from
 *   `$_GET` only for WooCommerce's main product query (`wc_query` = `product_query`), exactly
 *   where WooCommerce itself applies them.
 */
final class ProductQueryTranslator implements ProductTranslator {

	public const VISIBILITY_TAXONOMY = 'product_visibility';

	/**
	 * Constructor.
	 *
	 * @param Options $options Plugin options.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Products-index constraints for the query.
	 *
	 * @param \WP_Query $query Query.
	 * @return array{filters: list<string>, sort: list<string>}|null
	 */
	public function constraints( \WP_Query $query ): ?array {
		$sort = $this->sort( $query );
		if ( null === $sort ) {
			return null;
		}
		if ( ! empty( $query->get( 'post__in' ) ) ) {
			return null;
		}

		$filterable = ( new ProductSchema( $this->options ) )->filterable();
		$filters    = array();
		$handled    = array();

		foreach ( $this->owned_clauses( $query ) as $clause ) {
			$taxonomy = (string) $clause['taxonomy'];
			if ( self::VISIBILITY_TAXONOMY === $taxonomy ) {
				$filter = $this->visibility_filter( $clause );
			} else {
				$filter    = $this->attribute_clause_filter( $clause, $filterable );
				$handled[] = $taxonomy;
			}
			if ( null === $filter ) {
				return null;
			}
			$filters[] = $filter;
		}

		if ( 'product_query' === $query->get( 'wc_query' ) ) {
			$layered = $this->layered_nav_filters( $filterable, $handled );
			if ( null === $layered ) {
				return null;
			}
			$filters   = array_merge( $filters, $layered );
			$filters[] = $this->price_filter();
		}

		return array(
			'filters' => array_values( array_filter( $filters, static fn( string $filter ): bool => '' !== $filter ) ),
			'sort'    => $sort,
		);
	}

	/**
	 * WooCommerce catalog ordering → sort rules; [] = relevance; null = unsupported ordering.
	 * "Default sorting" (menu_order) is answered by relevance. Without an explicit `order`, date
	 * and title sort descending as in WP_Query (WooCommerce always sets `order` itself), so a
	 * federated search sorts both indexes identically (Task 16 requires equal sort lists).
	 *
	 * @param \WP_Query $query Query.
	 * @return list<string>|null
	 */
	public function sort( \WP_Query $query ): ?array {
		$orderby = $query->get( 'orderby' );
		if ( is_array( $orderby ) ) {
			if ( array() !== $orderby ) {
				return null;
			}
			$orderby = '';
		}
		$orderby = strtolower( trim( (string) $orderby ) );
		$order   = strtoupper( trim( (string) $query->get( 'order' ) ) );

		// Raw request form (`price-desc`) when WooCommerce did not normalize the query.
		if ( 1 === preg_match( '/^(price|date|title)-(asc|desc)$/', $orderby, $matches ) ) {
			$orderby = $matches[1];
			$order   = strtoupper( $matches[2] );
		}

		switch ( $orderby ) {
			case '':
			case 'relevance':
			case 'none':
			case 'menu_order':
			case 'menu_order title':
				return array();
			case 'price':
				return array( 'DESC' === $order ? 'price:desc' : 'price:asc' );
			case 'popularity':
				return array( 'total_sales:desc' );
			case 'rating':
				return array( 'rating_average:desc' );
			case 'date':
			case 'date id':
				return array( 'ASC' === $order ? 'date:asc' : 'date:desc' );
			case 'title':
				return array( 'ASC' === $order ? 'title:asc' : 'title:desc' );
			default:
				return null;
		}//end switch
	}

	/**
	 * Top-level `product_visibility` / `pa_*` clauses of an AND tax query: the clauses
	 * QueryTranslator skips. Other clauses (product_cat, product_tag, …) and nested groups are
	 * QueryTranslator's; with a top-level OR relation QueryTranslator has already returned null
	 * when such a clause is present, so nothing is consumed here.
	 *
	 * @param \WP_Query $query Query.
	 * @return list<array<string, mixed>>
	 */
	private function owned_clauses( \WP_Query $query ): array {
		if ( ! $query->tax_query instanceof \WP_Tax_Query || 'OR' === strtoupper( (string) $query->tax_query->relation ) ) {
			return array();
		}
		$owned = array();
		foreach ( (array) $query->tax_query->queries as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) || ! array_key_exists( 'taxonomy', $clause ) ) {
				continue;
			}
			$taxonomy = (string) $clause['taxonomy'];
			if ( self::VISIBILITY_TAXONOMY === $taxonomy || 0 === strpos( $taxonomy, 'pa_' ) ) {
				$owned[] = $clause;
			}
		}
		return $owned;
	}

	/**
	 * Product visibility clause → filter; '' = drop (guaranteed by the index); null = untranslatable.
	 *
	 * `exclude-from-search` is never indexed (§ 5.2) so excluding it is a no-op. `outofstock`
	 * maps to `in_stock`, `featured` to `featured`, `rated-N` (WooCommerce's rating filter) to
	 * the rating range that rounds to N. `exclude-from-catalog` cannot be expressed: products
	 * with catalog visibility "search" carry it and are indexed.
	 *
	 * @param array<string, mixed> $clause Tax clause.
	 */
	private function visibility_filter( array $clause ): ?string {
		$terms = $this->resolve_terms( $clause, self::VISIBILITY_TAXONOMY );
		if ( null === $terms ) {
			return null;
		}
		$slugs    = array_values( array_unique( array_map( static fn( \WP_Term $term ): string => (string) $term->slug, $terms ) ) );
		$operator = strtoupper( (string) ( $clause['operator'] ?? 'IN' ) );

		if ( 'NOT IN' === $operator ) {
			$parts = array();
			foreach ( $slugs as $slug ) {
				if ( 'exclude-from-search' === $slug ) {
					continue;
				}
				if ( 'outofstock' === $slug ) {
					$parts[] = FilterBuilder::compare( 'in_stock', '=', true );
					continue;
				}
				if ( 'featured' === $slug ) {
					$parts[] = FilterBuilder::compare( 'featured', '=', false );
					continue;
				}
				return null;
			}
			return FilterBuilder::all( $parts );
		}

		if ( array( 'featured' ) === $slugs && ( 'IN' === $operator || 'AND' === $operator ) ) {
			return FilterBuilder::compare( 'featured', '=', true );
		}

		if ( 'IN' === $operator && array() !== $slugs ) {
			$ranges = array();
			foreach ( $slugs as $slug ) {
				if ( 1 !== preg_match( '/^rated-([1-5])$/', $slug, $matches ) ) {
					return null;
				}
				$ranges[] = self::rating_range( (int) $matches[1] );
			}
			return FilterBuilder::any( $ranges );
		}

		return null;
	}

	/**
	 * Average ratings that WooCommerce rounds to $stars (it assigns `rated-N` with N = round(average)).
	 *
	 * @param int $stars 1–5.
	 */
	private static function rating_range( int $stars ): string {
		$low = $stars - 0.5;
		if ( 5 === $stars ) {
			return FilterBuilder::compare( 'rating_average', '>=', $low );
		}
		return FilterBuilder::all(
			array(
				FilterBuilder::compare( 'rating_average', '>=', $low ),
				FilterBuilder::compare( 'rating_average', '<', $stars + 0.5 ),
			)
		);
	}

	/**
	 * Attribute (pa_*) tax clause → attr_* filter, or null when the attribute is not indexed / terms are unknown.
	 *
	 * @param array<string, mixed> $clause     Tax clause.
	 * @param string[]             $filterable Products filterable attributes.
	 */
	private function attribute_clause_filter( array $clause, array $filterable ): ?string {
		$taxonomy = (string) $clause['taxonomy'];
		$field    = AttributeCollector::field_for_taxonomy( $taxonomy );
		if ( null === $field || ! in_array( $field, $filterable, true ) ) {
			return null;
		}
		$operator = strtoupper( (string) ( $clause['operator'] ?? 'IN' ) );
		if ( 'EXISTS' === $operator ) {
			return FilterBuilder::exists( $field );
		}
		if ( 'NOT EXISTS' === $operator ) {
			return FilterBuilder::not_exists( $field );
		}

		$terms = $this->resolve_terms( $clause, $taxonomy );
		if ( null === $terms || array() === $terms ) {
			return null;
		}
		$names = array_values( array_unique( array_map( static fn( \WP_Term $term ): string => self::decode( (string) $term->name ), $terms ) ) );

		switch ( $operator ) {
			case 'IN':
				return FilterBuilder::in( $field, $names );
			case 'NOT IN':
				return FilterBuilder::not_in( $field, $names );
			case 'AND':
				return self::all_equal( $field, $names );
			default:
				return null;
		}
	}

	/**
	 * Layered navigation (`filter_{attr}` + `query_type_{attr}`) → attr_* filters.
	 * Attributes already present as tax clauses (lookup-table filtering disabled) are skipped.
	 *
	 * @param string[] $filterable Products filterable attributes.
	 * @param string[] $handled    Taxonomies already translated from tax clauses.
	 * @return list<string>|null
	 */
	private function layered_nav_filters( array $filterable, array $handled ): ?array {
		$filters = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only catalog filters, as in WC_Query.
		foreach ( $_GET as $key => $value ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, 'filter_' ) || ! is_string( $value ) ) {
				continue;
			}
			$attribute = wc_sanitize_taxonomy_name( substr( $key, 7 ) );
			$taxonomy  = wc_attribute_taxonomy_name( $attribute );
			if ( in_array( $taxonomy, $handled, true ) ) {
				continue;
			}
			$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', explode( ',', (string) wc_clean( wp_unslash( $value ) ) ) ) ) ) );
			if ( array() === $slugs || ! taxonomy_exists( $taxonomy ) ) {
				// WooCommerce ignores these filters too.
				continue;
			}
			$field = AttributeCollector::field_for_taxonomy( $taxonomy );
			if ( null === $field || ! in_array( $field, $filterable, true ) ) {
				return null;
			}
			$names = array();
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					return null;
				}
				$names[] = self::decode( (string) $term->name );
			}
			$names = array_values( array_unique( $names ) );

			$type = isset( $_GET[ 'query_type_' . $attribute ] ) ? (string) wc_clean( wp_unslash( $_GET[ 'query_type_' . $attribute ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! in_array( $type, array( 'and', 'or' ), true ) ) {
				$type = (string) apply_filters( 'woocommerce_layered_nav_default_query_type', 'and' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
			}
			$filters[] = 'or' === $type ? FilterBuilder::in( $field, $names ) : self::all_equal( $field, $names );
		}//end foreach
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $filters;
	}

	/**
	 * `min_price` / `max_price` → price range on the indexed display price ('' when absent).
	 */
	private function price_filter(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to float, as in WC_Query.
		$min = isset( $_GET['min_price'] ) ? (float) wc_clean( wp_unslash( $_GET['min_price'] ) ) : null;
		$max = isset( $_GET['max_price'] ) ? (float) wc_clean( wp_unslash( $_GET['max_price'] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( null !== $min && null !== $max ) {
			return FilterBuilder::between( 'price', $min, $max );
		}
		if ( null !== $min ) {
			return FilterBuilder::compare( 'price', '>=', $min );
		}
		if ( null !== $max ) {
			return FilterBuilder::compare( 'price', '<=', $max );
		}
		return '';
	}

	/**
	 * Resolves a clause's terms (any `field`); null when one of them does not exist.
	 *
	 * @param array<string, mixed> $clause   Tax clause.
	 * @param string               $taxonomy Taxonomy.
	 * @return list<\WP_Term>|null
	 */
	private function resolve_terms( array $clause, string $taxonomy ): ?array {
		$field = strtolower( (string) ( $clause['field'] ?? 'term_id' ) );
		$terms = array();
		foreach ( (array) ( $clause['terms'] ?? array() ) as $value ) {
			switch ( $field ) {
				case 'slug':
				case 'name':
					$term = get_term_by( $field, (string) $value, $taxonomy );
					break;
				case 'term_taxonomy_id':
					$term = get_term_by( 'term_taxonomy_id', (int) $value, $taxonomy );
					break;
				default:
					$term = get_term( (int) $value, $taxonomy );
			}
			if ( ! $term instanceof \WP_Term ) {
				return null;
			}
			$terms[] = $term;
		}
		return $terms;
	}

	/**
	 * `field = a AND field = b …` (every value required).
	 *
	 * @param string   $field  Field.
	 * @param string[] $values Values.
	 */
	private static function all_equal( string $field, array $values ): string {
		return FilterBuilder::all( array_map( static fn( string $value ): string => FilterBuilder::compare( $field, '=', $value ), $values ) );
	}

	/**
	 * Decodes HTML entities stored in term names.
	 *
	 * @param string $value Raw value.
	 */
	private static function decode( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
	}
}
