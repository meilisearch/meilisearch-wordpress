<?php
/**
 * Product category hierarchy fields (spec § 8.2).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Builds `categories.lvl0..lvl2`, ancestor-inclusive category IDs and names.
 *
 * Paths deeper than three levels are truncated to their first three levels in
 * `categories.lvl*` (a future hierarchical facet shows at most three levels);
 * `ids` and `names` still contain every level.
 */
final class CategoryHierarchy {

	public const TAXONOMY  = 'product_cat';
	public const MAX_LEVEL = 3;
	public const SEPARATOR = ' > ';

	/**
	 * Builds the hierarchy fields for a product's assigned category IDs.
	 *
	 * @param int[] $term_ids Assigned product_cat term IDs.
	 * @return array{categories: array<string, list<string>>, ids: list<int>, names: list<string>}
	 */
	public function build( array $term_ids ): array {
		$levels = array();
		$ids    = array();
		$names  = array();

		foreach ( $term_ids as $term_id ) {
			$path = $this->path( (int) $term_id );
			if ( array() === $path ) {
				continue;
			}
			$labels = array();
			foreach ( $path as $depth => $node ) {
				$ids[]    = $node['id'];
				$names[]  = $node['name'];
				$labels[] = $node['name'];
				if ( $depth < self::MAX_LEVEL ) {
					$levels[ 'lvl' . $depth ][] = implode( self::SEPARATOR, $labels );
				}
			}
		}

		$categories = array();
		foreach ( $levels as $level => $values ) {
			$categories[ $level ] = array_values( array_unique( $values ) );
		}
		ksort( $categories );

		return array(
			'categories' => $categories,
			'ids'        => array_values( array_unique( $ids ) ),
			'names'      => array_values( array_unique( $names ) ),
		);
	}

	/**
	 * Root → leaf path of a term; empty when the term (or an ancestor) cannot be loaded.
	 *
	 * @param int $term_id Leaf term ID.
	 * @return list<array{id: int, name: string}>
	 */
	private function path( int $term_id ): array {
		$chain   = array_reverse( array_map( 'intval', (array) get_ancestors( $term_id, self::TAXONOMY, 'taxonomy' ) ) );
		$chain[] = $term_id;

		$path = array();
		foreach ( $chain as $id ) {
			$term = get_term( $id, self::TAXONOMY );
			if ( ! $term instanceof \WP_Term ) {
				return array();
			}
			$path[] = array(
				'id'   => (int) $term->term_id,
				'name' => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
			);
		}
		return $path;
	}
}
