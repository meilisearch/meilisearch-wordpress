<?php
/**
 * Maps search results onto a WP_Query.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Turns hit IDs into WP_Post objects (ranking order kept), sets the pagination totals that
 * WordPress does not compute when posts_pre_query short-circuits, and keeps _formatted for
 * the Highlighter (spec § 9.5).
 */
final class ResultMapper {

	/**
	 * Post ID => _formatted of the intercepted queries since the last reset().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $formatted = array();

	/**
	 * Applies a result to a query.
	 *
	 * @param \WP_Query    $query  Query being answered.
	 * @param SearchResult $result Result.
	 * @return list<\WP_Post>
	 */
	public function apply( \WP_Query $query, SearchResult $result ): array {
		$this->formatted      = array_replace( $this->formatted, $result->formatted );
		$query->found_posts   = $result->total_hits;
		$query->max_num_pages = $result->total_pages;

		if ( array() === $result->ids ) {
			// Page past the end: no posts, totals kept, theme shows its normal template.
			return array();
		}

		_prime_post_caches( $result->ids );

		$posts = array();
		foreach ( $result->ids as $id ) {
			// Hits whose post no longer loads, or is no longer public (stale index entry until its
			// queued delete runs: spec § 5.2), are dropped; totals unchanged.
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status && '' === $post->post_password ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	/**
	 * The _formatted hit fields for a post of an intercepted result.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	public function formatted( int $post_id ): ?array {
		return $this->formatted[ $post_id ] ?? null;
	}

	/**
	 * Forgets formatted fields (start of each main query).
	 */
	public function reset(): void {
		$this->formatted = array();
	}
}
