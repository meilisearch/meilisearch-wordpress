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
 *   where WooCommerce itself applies them;
 * - query vars of WooCommerce's product filter blocks, `filter_stock_status` and one per public
 *   product taxonomy (`categories`, `tags`, `brands`, `filter_{taxonomy}`), which WooCommerce
 *   applies to that same main query through `posts_clauses` and never turns into tax clauses.
 */
final class ProductQueryTranslator implements ProductTranslator {

	public const VISIBILITY_TAXONOMY = 'product_visibility';

	/**
	 * Query var WooCommerce's product filter blocks use for a taxonomy, when it is not `filter_{taxonomy}`.
	 */
	private const BLOCK_TAXONOMY_PARAMS = array(
		'product_cat'   => 'categories',
		'product_tag'   => 'tags',
		'product_brand' => 'brands',
	);

	/**
	 * Indexed term-ID field of the taxonomies a product filter block parameter can be translated for.
	 * `tax_product_cat_ids` holds every ancestor, which is WooCommerce's "term or any descendant".
	 */
	private const BLOCK_TAXONOMY_FIELDS = array(
		'product_cat' => 'tax_product_cat_ids',
		'product_tag' => 'tax_product_tag_ids',
	);

	/**
	 * First product document version (ProductSchema::DOCUMENT_VERSION) with `price_max` on every product.
	 */
	private const PRICE_MAX_SINCE = 2;

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

		$filterable = $this->filterable();
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
			$price = $this->price_filter();
			if ( null === $price ) {
				return null;
			}
			$blocks = $query->is_main_query() ? $this->block_filters( $query, $filterable ) : array();
			if ( null === $blocks ) {
				return null;
			}
			$filters   = array_merge( $filters, $layered, $blocks );
			$filters[] = $price;
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

		// Catalog orderings (price, popularity, rating, menu_order) exist only inside WooCommerce's product query;
		// anywhere else WP_Query cannot parse them and MySQL orders by date, which only a fallback reproduces.
		$wc = 'product_query' === $query->get( 'wc_query' );

		switch ( $orderby ) {
			case '':
			case 'relevance':
			case 'none':
				return array();
			case 'menu_order':
			case 'menu_order title':
				return $wc ? array() : null;
			case 'price':
				// As WooCommerce: ascending by the lowest price, descending by the highest one.
				if ( ! $wc ) {
					return null;
				}
				if ( 'DESC' !== $order ) {
					return array( 'price:asc' );
				}
				return array( $this->has_price_max() ? 'price_max:desc' : 'price:desc' );
			case 'popularity':
				return $wc ? array( 'total_sales:desc' ) : null;
			case 'rating':
				return $wc ? array( 'rating_average:desc' ) : null;
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
	 * Products fields an attribute filter may use. The attribute list comes from the current
	 * WordPress state (WooCommerce tab, global attributes), while the index's filterableAttributes
	 * and its documents follow it only after a full reindex: a newly selected or newly created
	 * attribute is unknown to Meilisearch (`invalid_search_filter`, which opens the circuit
	 * breaker) or missing from existing documents (incomplete results). While a products reindex
	 * is pending, attribute fields are left out, so attribute filters fall back to MySQL.
	 *
	 * @return list<string>
	 */
	private function filterable(): array {
		$schema     = new ProductSchema( $this->options );
		$filterable = $schema->filterable();
		if ( ! $this->options->needs_reindex( 'products' ) ) {
			return $filterable;
		}
		return array_values( array_diff( $filterable, $schema->attribute_fields() ) );
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
				// Not an attribute filter, so WooCommerce's layered navigation ignores it too. A
				// product filter block parameter of that shape (`filter_stock_status`,
				// `filter_{taxonomy}`) is block_filters()'s.
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
	 * Product filter block parameters → filters; null when one cannot be reproduced.
	 *
	 * Mirrors WooCommerce's QueryClauses::add_query_clauses_for_main_query(), which reads the query
	 * vars (not `$_GET`) of the main product query: stock statuses are ORed, slugs of one taxonomy
	 * are ORed, taxonomies are ANDed, and a taxonomy none of whose slugs exists is dropped. Where
	 * WooCommerce matches nothing (no valid status, no existing term at all) or filters on a
	 * taxonomy that is not indexed, the answer is null: the database handles that request.
	 *
	 * @param \WP_Query $query      Main product query.
	 * @param string[]  $filterable Products filterable attributes.
	 * @return list<string>|null
	 */
	private function block_filters( \WP_Query $query, array $filterable ): ?array {
		$filters = array();

		$statuses = $this->block_values( $query, 'filter_stock_status' );
		if ( null === $statuses ) {
			return null;
		}
		if ( array() !== $statuses ) {
			$known = array_map( 'strval', array_keys( wc_get_product_stock_status_options() ) );
			$valid = array_values( array_unique( array_intersect( $statuses, $known ) ) );
			if ( array() === $valid || ! in_array( 'stock_status', $filterable, true ) ) {
				return null;
			}
			$filters[] = FilterBuilder::in( 'stock_status', $valid );
		}

		$chosen = array();
		foreach ( $this->block_taxonomy_params() as $taxonomy => $param ) {
			// WooCommerce chooses a taxonomy whenever its trimmed parameter is not empty, even when no
			// slug survives (`,`): it then looks up an empty slug list, which get_terms() answers with
			// every term of the taxonomy. Only an absent or blank parameter is skipped.
			$value = $query->get( $param );
			if ( ! is_string( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}
				return null;
			}
			$value = trim( $value );
			if ( empty( $value ) ) {
				continue;
			}
			$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', explode( ',', $value ) ) ) ) );
			$field = self::BLOCK_TAXONOMY_FIELDS[ $taxonomy ] ?? null;
			if ( array() === $slugs || null === $field || ! in_array( $field, $filterable, true ) ) {
				return null;
			}
			$chosen[ $taxonomy ] = $slugs;
		}//end foreach
		if ( array() === $chosen ) {
			return $filters;
		}

		// One lookup for every taxonomy, as WooCommerce does: a slug counts in each chosen taxonomy that has it.
		$terms = get_terms(
			array(
				'taxonomy'   => array_keys( $chosen ),
				'slug'       => array_merge( ...array_values( $chosen ) ),
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return null;
		}
		$ids = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term && isset( $chosen[ $term->taxonomy ] ) ) {
				$ids[ $term->taxonomy ][] = (int) $term->term_id;
			}
		}
		if ( array() === $ids ) {
			return null;
		}
		foreach ( $ids as $taxonomy => $term_ids ) {
			$filters[] = FilterBuilder::in( self::BLOCK_TAXONOMY_FIELDS[ $taxonomy ], array_values( array_unique( $term_ids ) ) );
		}
		return $filters;
	}

	/**
	 * Comma-separated values of the stock status filter block query var: [] when the parameter is
	 * absent or has no value (WooCommerce skips it), null when it is not a string.
	 *
	 * @param \WP_Query $query Query.
	 * @param string    $param Query var.
	 * @return list<string>|null
	 */
	private function block_values( \WP_Query $query, string $param ): ?array {
		$value = $query->get( $param );
		if ( empty( $value ) ) {
			return array();
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		return array_values( array_filter( explode( ',', trim( $value ) ) ) );
	}

	/**
	 * Taxonomy → query var of WooCommerce's taxonomy filter block: every public product taxonomy
	 * with an admin UI (WooCommerce's Params::get_taxonomy_params()).
	 *
	 * @return array<string, string>
	 */
	private function block_taxonomy_params(): array {
		$taxonomies = get_taxonomies(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		$params     = array();
		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( 'product', (array) $taxonomy->object_type, true ) ) {
				$name            = (string) $taxonomy->name;
				$params[ $name ] = self::BLOCK_TAXONOMY_PARAMS[ $name ] ?? 'filter_' . $name;
			}
		}
		return $params;
	}

	/**
	 * `min_price` / `max_price` → price filter on the indexed display prices ('' when absent).
	 * As WooCommerce (WC_Query::price_filter_post_clauses), a product matches when its price range
	 * overlaps the requested one: lowest price <= max_price and highest price >= min_price, which
	 * keeps a variable product priced 10 to 50 for 20–30. A bound that overflows a float (`1e999`)
	 * cannot be written as a filter: null, so the database answers that request as it would
	 * without the plugin.
	 *
	 * Until a products reindex has written `price_max` into every document (has_price_max()), the
	 * lowest price is compared with both bounds, as before: a `price_max` condition would drop the
	 * documents written without it.
	 */
	private function price_filter(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to float, as in WC_Query.
		$min = isset( $_GET['min_price'] ) ? (float) wc_clean( wp_unslash( $_GET['min_price'] ) ) : null;
		$max = isset( $_GET['max_price'] ) ? (float) wc_clean( wp_unslash( $_GET['max_price'] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ( null !== $min && ! is_finite( $min ) ) || ( null !== $max && ! is_finite( $max ) ) ) {
			return null;
		}
		$highest = $this->has_price_max() ? 'price_max' : 'price';
		if ( null !== $min && null !== $max ) {
			if ( 'price' === $highest ) {
				return FilterBuilder::between( 'price', $min, $max );
			}
			return FilterBuilder::all(
				array(
					FilterBuilder::compare( 'price', '<=', $max ),
					FilterBuilder::compare( $highest, '>=', $min ),
				)
			);
		}
		if ( null !== $min ) {
			return FilterBuilder::compare( $highest, '>=', $min );
		}
		if ( null !== $max ) {
			return FilterBuilder::compare( 'price', '<=', $max );
		}
		return '';
	}

	/**
	 * Whether every product document carries `price_max`: the last completed products reindex wrote
	 * documents of a version that has it (Reindexer records ProductSchema::build_record()). Documents
	 * synced one by one since then have it too; an index filled by an older version does not, and a
	 * reindex that is still running has not rewritten every document yet.
	 */
	private function has_price_max(): bool {
		$build = $this->options->state( 'products_build' );
		return is_array( $build ) && (int) ( $build['version'] ?? 0 ) >= self::PRICE_MAX_SINCE;
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
