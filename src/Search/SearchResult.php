<?php
/**
 * Search result.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Hit IDs in ranking order, totals for pagination and the _formatted map for highlighting.
 */
final class SearchResult {

	/**
	 * Constructor.
	 *
	 * @param int[]                            $ids         Post IDs in ranking order.
	 * @param int                              $total_hits  Hits reachable through pagination (found_posts).
	 * @param int                              $total_pages Pages that have hits (max_num_pages).
	 * @param array<int, array<string, mixed>> $formatted   Post ID => hit._formatted.
	 */
	public function __construct(
		public readonly array $ids,
		public readonly int $total_hits,
		public readonly int $total_pages,
		public readonly array $formatted
	) {}
}
