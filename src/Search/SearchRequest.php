<?php
/**
 * Translated search request.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

/**
 * What QueryTranslator decided to send: which logical indexes, the query string, the page and,
 * per logical index, the filter expressions (ANDed) and sort rules.
 */
final class SearchRequest {

	/**
	 * Constructor.
	 *
	 * @param string[]                                           $logicals      Indexes to query ('content', 'products'), content first.
	 * @param string                                             $q             Trimmed query, at most QueryTranslator::MAX_QUERY_LENGTH characters.
	 * @param int                                                $page          1-based page.
	 * @param int                                                $hits_per_page 1..QueryTranslator::MAX_HITS_PER_PAGE.
	 * @param array<string, string[]>                            $filters       Logical => filter expressions (ANDed).
	 * @param array<string, string[]>                            $sort          Logical => sort rules ("field:asc|desc").
	 * @param array{embedder: string, semanticRatio: float}|null $hybrid        Hybrid search parameters.
	 * @param bool                                               $highlight     Whether to request cropped/highlighted fields.
	 */
	public function __construct(
		public readonly array $logicals,
		public readonly string $q,
		public readonly int $page,
		public readonly int $hits_per_page,
		public readonly array $filters,
		public readonly array $sort,
		public readonly ?array $hybrid,
		public readonly bool $highlight
	) {}
}
