<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Meilisearch\WordPress\WooCommerce\CategoryHierarchy;

final class CategoryHierarchyTest extends TestCase {

	/**
	 * Term id => [name, parent id].
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private const TREE = array(
		10 => array( 'Clothing', 0 ),
		11 => array( 'Shirts', 10 ),
		12 => array( 'Long sleeve', 11 ),
		13 => array( 'Linen', 12 ),
		20 => array( 'Sale', 0 ),
		30 => array( 'Tops &amp; Tees', 0 ),
	);

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'get_ancestors' )->alias(
			static function ( $id ) {
				$ancestors = array();
				$parent    = self::TREE[ $id ][1] ?? 0;
				while ( 0 !== $parent ) {
					$ancestors[] = $parent;
					$parent      = self::TREE[ $parent ][1];
				}
				return $ancestors;
			}
		);
		Functions\when( 'get_term' )->alias(
			static function ( $id, $taxonomy = '' ) {
				if ( 'product_cat' !== $taxonomy || ! isset( self::TREE[ $id ] ) ) {
					return null;
				}
				return new \WP_Term(
					array(
						'term_id'  => $id,
						'name'     => self::TREE[ $id ][0],
						'parent'   => self::TREE[ $id ][1],
						'taxonomy' => 'product_cat',
					)
				);
			}
		);
	}

	public function test_single_leaf_builds_root_to_leaf_levels(): void {
		$result = ( new CategoryHierarchy() )->build( array( 12 ) );

		self::assertSame(
			array(
				'lvl0' => array( 'Clothing' ),
				'lvl1' => array( 'Clothing > Shirts' ),
				'lvl2' => array( 'Clothing > Shirts > Long sleeve' ),
			),
			$result['categories']
		);
		self::assertSame( array( 10, 11, 12 ), $result['ids'] );
		self::assertSame( array( 'Clothing', 'Shirts', 'Long sleeve' ), $result['names'] );
	}

	public function test_multiple_roots_produce_arrays_per_level(): void {
		$result = ( new CategoryHierarchy() )->build( array( 11, 20 ) );

		self::assertSame(
			array(
				'lvl0' => array( 'Clothing', 'Sale' ),
				'lvl1' => array( 'Clothing > Shirts' ),
			),
			$result['categories']
		);
		self::assertSame( array( 10, 11, 20 ), $result['ids'] );
		self::assertSame( array( 'Clothing', 'Shirts', 'Sale' ), $result['names'] );
	}

	public function test_paths_deeper_than_three_levels_are_truncated_but_ids_and_names_keep_every_level(): void {
		$result = ( new CategoryHierarchy() )->build( array( 13 ) );

		self::assertSame( array( 'lvl0', 'lvl1', 'lvl2' ), array_keys( $result['categories'] ) );
		self::assertSame( array( 'Clothing > Shirts > Long sleeve' ), $result['categories']['lvl2'] );
		self::assertSame( array( 10, 11, 12, 13 ), $result['ids'] );
		self::assertSame( array( 'Clothing', 'Shirts', 'Long sleeve', 'Linen' ), $result['names'] );
	}

	public function test_overlapping_paths_are_deduplicated(): void {
		$result = ( new CategoryHierarchy() )->build( array( 11, 12 ) );

		self::assertSame( array( 'Clothing' ), $result['categories']['lvl0'] );
		self::assertSame( array( 'Clothing > Shirts' ), $result['categories']['lvl1'] );
		self::assertSame( array( 10, 11, 12 ), $result['ids'] );
	}

	public function test_names_are_entity_decoded(): void {
		$result = ( new CategoryHierarchy() )->build( array( 30 ) );

		self::assertSame( array( 'lvl0' => array( 'Tops & Tees' ) ), $result['categories'] );
		self::assertSame( array( 'Tops & Tees' ), $result['names'] );
	}

	public function test_unknown_terms_are_skipped(): void {
		$result = ( new CategoryHierarchy() )->build( array( 99, 20 ) );

		self::assertSame( array( 'lvl0' => array( 'Sale' ) ), $result['categories'] );
		self::assertSame( array( 20 ), $result['ids'] );
	}

	public function test_no_terms_gives_empty_fields(): void {
		self::assertSame(
			array(
				'categories' => array(),
				'ids'        => array(),
				'names'      => array(),
			),
			( new CategoryHierarchy() )->build( array() )
		);
	}
}
