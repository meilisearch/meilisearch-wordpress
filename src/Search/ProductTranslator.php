<?php
/**
 * Product-specific translation contract.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Translates the product-specific part of a WP_Query for the products index (implemented by
 * WooCommerce\ProductQueryTranslator, Task 22).
 *
 * QueryTranslator calls it whenever the products index is targeted (products-only or mixed) and
 * returns null when it returns null. QueryTranslator has already handled `s`, pagination, post
 * types and denylisted vars, refuses author / meta_query / date_query constraints on products,
 * translates product_cat / product_tag clauses itself (tax_{t}_ids), and skips top-level ANDed
 * product_visibility and pa_* clauses, which this translator owns (nested or OR-ed ones make
 * QueryTranslator return null). The returned filters are ANDed with QueryTranslator's; the
 * returned sort is used as-is (QueryTranslator never interprets orderby for products). On a mixed
 * search both sort lists must be identical, otherwise the query is not intercepted.
 */
interface ProductTranslator {

	/**
	 * Filters and sort rules for the products index.
	 *
	 * @param \WP_Query $query Query.
	 * @return array{filters: list<string>, sort: list<string>}|null null = cannot translate → do not intercept.
	 */
	public function constraints( \WP_Query $query ): ?array;
}
