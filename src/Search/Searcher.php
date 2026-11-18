<?php
/**
 * Executes search requests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Settings\IndexNames;

/**
 * One logical index → POST /indexes/{uid}/search; several → POST /multi-search, federated,
 * one query per index (spec § 9.4). Both use page/hitsPerPage (federation since Meilisearch
 * 1.34), so totalHits/totalPages count every match. The engine only serves the first
 * pagination.maxTotalHits of them, so the totals handed to WordPress are clamped to that limit.
 */
final class Searcher {

	/**
	 * Default of the index setting pagination.maxTotalHits, which the plugin never writes.
	 */
	public const DEFAULT_MAX_TOTAL_HITS = 1000;

	private const PRE_TAG  = '<mark>';
	private const POST_TAG = '</mark>';

	/**
	 * Constructor.
	 *
	 * @param ClientFactory $clients Client factory.
	 * @param IndexNames    $names   Index names.
	 */
	public function __construct(
		private ClientFactory $clients,
		private IndexNames $names
	) {}

	/**
	 * Runs a request.
	 *
	 * @param SearchRequest $request Request.
	 * @return SearchResult
	 * @throws ApiError On HTTP, engine or transport errors.
	 * @throws \RuntimeException When Meilisearch is not configured.
	 */
	public function execute( SearchRequest $request ): SearchResult {
		$client  = $this->clients->client();
		$timeout = (float) apply_filters( 'meilisearch_search_timeout', Client::SEARCH_TIMEOUT );

		if ( 1 === count( $request->logicals ) ) {
			$logical  = $request->logicals[0];
			$params   = array_merge(
				array(
					'q'           => $request->q,
					'page'        => $request->page,
					'hitsPerPage' => $request->hits_per_page,
				),
				$this->query_params( $request, $logical )
			);
			$response = $client->search( $this->names->uid( $logical ), $this->filter_params( $params, $request, $logical ), $timeout );
			return $this->parse( $response, $request );
		}

		$queries = array();
		foreach ( $request->logicals as $logical ) {
			$params = array_merge(
				array(
					'indexUid' => $this->names->uid( $logical ),
					'q'        => $request->q,
				),
				$this->query_params( $request, $logical )
			);
			$params = $this->filter_params( $params, $request, $logical );
			// Pagination is only allowed on the federation (invalid_multi_search_query_pagination).
			unset( $params['page'], $params['hitsPerPage'], $params['offset'], $params['limit'] );
			$queries[] = $params;
		}
		$federation = array(
			'page'        => $request->page,
			'hitsPerPage' => $request->hits_per_page,
		);
		return $this->parse( $client->multi_search( $queries, $federation, $timeout ), $request );
	}

	/**
	 * Per-index parameters shared by both request shapes.
	 *
	 * @param SearchRequest $request Request.
	 * @param string        $logical Logical index.
	 * @return array<string, mixed>
	 */
	private function query_params( SearchRequest $request, string $logical ): array {
		$params = array();
		$filter = FilterBuilder::all( $request->filters[ $logical ] ?? array() );
		if ( '' !== $filter ) {
			$params['filter'] = $filter;
		}
		$sort = $request->sort[ $logical ] ?? array();
		if ( array() !== $sort ) {
			$params['sort'] = array_values( $sort );
		}
		if ( null !== $request->hybrid ) {
			$params['hybrid'] = $request->hybrid;
		}
		// Only the ID and the cropped _formatted.content are read; Meilisearch returns _formatted for
		// cropped/highlighted attributes even when they are not retrieved.
		$params['attributesToRetrieve'] = array( 'id' );
		if ( ! $request->highlight ) {
			return $params;
		}
		$params['attributesToCrop']      = array( 'content:30' );
		$params['attributesToHighlight'] = array( 'content' );
		$params['highlightPreTag']       = self::PRE_TAG;
		$params['highlightPostTag']      = self::POST_TAG;
		return $params;
	}

	/**
	 * Applies the meilisearch_search_params filter.
	 *
	 * @param array<string, mixed> $params  Parameters.
	 * @param SearchRequest        $request Request.
	 * @param string               $logical Logical index.
	 * @return array<string, mixed>
	 */
	private function filter_params( array $params, SearchRequest $request, string $logical ): array {
		/**
		 * Filters the parameters of one Meilisearch search query.
		 *
		 * @param array<string, mixed> $params  Search parameters (one federated query or the single-index body).
		 * @param SearchRequest        $request Translated request.
		 * @param string               $logical 'content' | 'products'.
		 */
		return self::array_or( apply_filters( 'meilisearch_search_params', $params, $request, $logical ), $params );
	}

	/**
	 * A filtered value when it is still an array, the fallback otherwise.
	 *
	 * @param mixed                $value    Filtered value.
	 * @param array<string, mixed> $fallback Fallback.
	 * @return array<string, mixed>
	 */
	private static function array_or( mixed $value, array $fallback ): array {
		return is_array( $value ) ? $value : $fallback;
	}

	/**
	 * How many hits of this request the engine can serve across all its pages.
	 *
	 * An index never returns hits past its pagination.maxTotalHits. A federated search applies the
	 * limit of each index to that index's own matches, and its response does not say how the
	 * matches are split, so only the smallest limit is certain to be reachable.
	 *
	 * @param SearchRequest $request Request.
	 * @return int At least 1.
	 */
	private function max_total_hits( SearchRequest $request ): int {
		$smallest = PHP_INT_MAX;
		foreach ( $request->logicals as $logical ) {
			/**
			 * Filters the number of hits Meilisearch serves for one index: its pagination.maxTotalHits
			 * setting. Return the configured value when that setting was changed in Meilisearch.
			 *
			 * @param int           $limit   Meilisearch's default, 1000.
			 * @param string        $logical 'content' | 'products'.
			 * @param SearchRequest $request Translated request.
			 */
			$limit = apply_filters( 'meilisearch_max_total_hits', self::DEFAULT_MAX_TOTAL_HITS, $logical, $request );
			$limit = is_numeric( $limit ) ? (float) $limit : 0.0;
			if ( ! is_finite( $limit ) || $limit < 1 ) {
				$limit = (float) self::DEFAULT_MAX_TOTAL_HITS;
			}
			if ( $limit < PHP_INT_MAX ) {
				$smallest = min( $smallest, (int) $limit );
			}
		}
		return $smallest;
	}

	/**
	 * Reads IDs, totals and _formatted from a search or federated response.
	 *
	 * @param array<string, mixed> $response Response body.
	 * @param SearchRequest        $request  Request.
	 * @return SearchResult
	 * @throws ApiError Code 'invalid_response' when the body has no hits list (MySQL answers instead).
	 */
	private function parse( array $response, SearchRequest $request ): SearchResult {
		if ( ! isset( $response['hits'] ) || ! is_array( $response['hits'] ) ) {
			throw new ApiError( __( 'Meilisearch answered the search without a list of hits.', 'meilisearch' ), 'invalid_response' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is data; escaped where displayed.
		}
		$ids       = array();
		$formatted = array();
		$hits      = $response['hits'];
		foreach ( $hits as $hit ) {
			if ( ! is_array( $hit ) || ! isset( $hit['id'] ) || ! is_numeric( $hit['id'] ) ) {
				continue;
			}
			$id = (int) $hit['id'];
			if ( $id <= 0 ) {
				continue;
			}
			$ids[] = $id;
			if ( isset( $hit['_formatted'] ) && is_array( $hit['_formatted'] ) ) {
				$formatted[ $id ] = $hit['_formatted'];
			}
		}

		if ( isset( $response['totalHits'] ) && is_numeric( $response['totalHits'] ) ) {
			$total_hits = (int) $response['totalHits'];
		} else {
			$total_hits = count( $ids );
		}
		if ( isset( $response['totalPages'] ) && is_numeric( $response['totalPages'] ) ) {
			$total_pages = (int) $response['totalPages'];
		} else {
			$total_pages = (int) ceil( $total_hits / max( 1, $request->hits_per_page ) );
		}

		// found_posts / max_num_pages must not advertise pages the engine answers with no hits.
		$per_page = max( 1, $request->hits_per_page );
		$servable = $this->max_total_hits( $request );
		if ( array() === $hits && $request->page > 1 ) {
			// Nothing at this offset: whatever the limit is, the hits end before this page.
			$servable = min( $servable, ( $request->page - 1 ) * $per_page );
		}
		$total_hits  = min( $total_hits, $servable );
		$total_pages = min( $total_pages, (int) ceil( $total_hits / $per_page ) );

		return new SearchResult( $ids, $total_hits, $total_pages, $formatted );
	}
}
