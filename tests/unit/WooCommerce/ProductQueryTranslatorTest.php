<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Search\FilterBuilder;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Meilisearch\WordPress\WooCommerce\ProductQueryTranslator;

final class ProductQueryTranslatorTest extends TestCase {

	/**
	 * Taxonomy => list of [term_id, term_taxonomy_id, slug, name].
	 *
	 * @var array<string, list<array{0: int, 1: int, 2: string, 3: string}>>
	 */
	private const TERMS = array(
		'product_visibility' => array(
			array( 1, 1, 'exclude-from-search', 'exclude-from-search' ),
			array( 2, 2, 'exclude-from-catalog', 'exclude-from-catalog' ),
			array( 3, 3, 'featured', 'featured' ),
			array( 4, 4, 'outofstock', 'outofstock' ),
			array( 14, 14, 'rated-4', 'rated-4' ),
			array( 15, 15, 'rated-5', 'rated-5' ),
		),
		'pa_color'           => array(
			array( 21, 121, 'red', 'Red' ),
			array( 22, 122, 'blue', 'Blue' ),
			array( 23, 123, 'navy-blue', 'Navy &amp; Blue' ),
		),
		'pa_size'            => array(
			array( 31, 131, 'xl', 'XL' ),
		),
	);

	private array $saved_get = array();

	protected function set_up(): void {
		parent::set_up();
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET            = array();
		$this->stub_options( array( Options::WOOCOMMERCE => array( 'attributes' => array( 'pa_color' ) ) ) );

		$find = static function ( string $taxonomy, int $column, $value ): ?\WP_Term {
			foreach ( self::TERMS[ $taxonomy ] ?? array() as $row ) {
				if ( $row[ $column ] === $value ) {
					return new \WP_Term(
						array(
							'term_id'          => $row[0],
							'term_taxonomy_id' => $row[1],
							'slug'             => $row[2],
							'name'             => $row[3],
							'taxonomy'         => $taxonomy,
						)
					);
				}
			}
			return null;
		};
		Functions\when( 'get_term_by' )->alias(
			static function ( $field, $value, $taxonomy = '' ) use ( $find ) {
				$columns = array(
					'term_taxonomy_id' => 1,
					'slug'             => 2,
					'name'             => 3,
				);
				$value   = 'term_taxonomy_id' === $field ? (int) $value : (string) $value;
				return $find( (string) $taxonomy, $columns[ $field ], $value ) ?? false;
			}
		);
		Functions\when( 'get_term' )->alias(
			static function ( $id, $taxonomy = '' ) use ( $find ) {
				return $find( (string) $taxonomy, 0, (int) $id );
			}
		);
		Functions\when( 'taxonomy_exists' )->alias(
			static function ( $taxonomy ) {
				return isset( self::TERMS[ $taxonomy ] );
			}
		);
		Functions\when( 'wc_sanitize_taxonomy_name' )->alias(
			static function ( $name ) {
				return strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '', (string) $name ) );
			}
		);
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static function ( $name ) {
				return 'pa_' . $name;
			}
		);
		Functions\when( 'sanitize_title' )->alias(
			static function ( $title ) {
				return strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ), '-' ) );
			}
		);
		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : $value; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
		);
	}

	protected function tear_down(): void {
		$_GET = $this->saved_get;
		parent::tear_down();
	}

	private function query( array $vars = array(), array $clauses = array(), string $relation = 'AND' ): \WP_Query {
		$query            = new \WP_Query( $vars );
		$query->tax_query = new \WP_Tax_Query( array_merge( $clauses, array( 'relation' => $relation ) ) );
		return $query;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function clause( string $taxonomy, array $terms, string $operator = 'IN', string $field = 'term_taxonomy_id' ): array {
		return array(
			'taxonomy'         => $taxonomy,
			'terms'            => $terms,
			'field'            => $field,
			'operator'         => $operator,
			'include_children' => true,
		);
	}

	private function translator(): ProductQueryTranslator {
		return new ProductQueryTranslator( new Options() );
	}

	/**
	 * @dataProvider orderings
	 */
	public function test_catalog_ordering_maps_to_sort( $orderby, string $order, $sort, string $wc_query = '' ): void {
		$vars   = array(
			'orderby'  => $orderby,
			'order'    => $order,
			'wc_query' => $wc_query,
		);
		$result = $this->translator()->constraints( $this->query( $vars ) );

		self::assertSame( $sort, $result['sort'] ?? 'null' );
	}

	public function orderings(): array {
		$wc = 'product_query';
		return array(
			'default'          => array( '', '', array() ),
			'relevance'        => array( 'relevance', 'DESC', array() ),
			'menu order'       => array( 'menu_order title', 'ASC', array(), $wc ),
			'empty array'      => array( array(), '', array() ),
			'price asc'        => array( 'price', 'ASC', array( 'price:asc' ), $wc ),
			'price desc'       => array( 'price', 'DESC', array( 'price:desc' ), $wc ),
			'popularity'       => array( 'popularity', 'DESC', array( 'total_sales:desc' ), $wc ),
			'rating'           => array( 'rating', 'ASC', array( 'rating_average:desc' ), $wc ),
			'date (WC)'        => array( 'date ID', 'DESC', array( 'date:desc' ), $wc ),
			'date asc'         => array( 'date', 'ASC', array( 'date:asc' ) ),
			'title'            => array( 'title', 'ASC', array( 'title:asc' ) ),
			'title desc'       => array( 'title', 'DESC', array( 'title:desc' ) ),
			'title WP order'   => array( 'title', '', array( 'title:desc' ) ),
			'date WP order'    => array( 'date', '', array( 'date:desc' ) ),
			'no wc price'      => array( 'price', 'ASC', 'null' ),
			'no wc price-desc' => array( 'price-desc', '', 'null' ),
			'no wc popularity' => array( 'popularity', 'DESC', 'null' ),
			'no wc rating'     => array( 'rating', 'DESC', 'null' ),
			'no wc menu'       => array( 'menu_order title', 'ASC', 'null' ),
		);
	}

	/**
	 * @dataProvider unsupported_orderings
	 */
	public function test_unsupported_ordering_is_not_translated( $orderby ): void {
		self::assertNull( $this->translator()->constraints( $this->query( array( 'orderby' => $orderby ) ) ) );
	}

	public function unsupported_orderings(): array {
		return array(
			'rand'       => array( 'rand' ),
			'id'         => array( 'ID' ),
			'meta value' => array( 'meta_value_num' ),
			'array form' => array( array( 'price' => 'ASC' ) ),
			'modified'   => array( 'modified ID' ),
		);
	}

	public function test_non_empty_post_in_is_not_translated_but_woocommerce_empty_array_is(): void {
		self::assertNull( $this->translator()->constraints( $this->query( array( 'post__in' => array( 3, 4 ) ) ) ) );
		self::assertSame(
			array(
				'filters' => array(),
				'sort'    => array(),
			),
			$this->translator()->constraints( $this->query( array( 'post__in' => array() ) ) )
		);
	}

	public function test_woocommerce_search_visibility_clause_is_dropped(): void {
		$result = $this->translator()->constraints( $this->query( array(), array( $this->clause( 'product_visibility', array( 1 ), 'NOT IN' ) ) ) );

		self::assertSame( array(), $result['filters'] );
	}

	public function test_hidden_out_of_stock_clause_becomes_in_stock_filter(): void {
		$result = $this->translator()->constraints( $this->query( array(), array( $this->clause( 'product_visibility', array( 1, 4 ), 'NOT IN' ) ) ) );

		self::assertSame( array( FilterBuilder::all( array( FilterBuilder::compare( 'in_stock', '=', true ) ) ) ), $result['filters'] );
	}

	public function test_featured_clause_becomes_featured_filter(): void {
		$result = $this->translator()->constraints( $this->query( array(), array( $this->clause( 'product_visibility', array( 'featured' ), 'IN', 'name' ) ) ) );

		self::assertSame( array( FilterBuilder::compare( 'featured', '=', true ) ), $result['filters'] );
	}

	public function test_exclude_from_catalog_cannot_be_translated(): void {
		self::assertNull( $this->translator()->constraints( $this->query( array(), array( $this->clause( 'product_visibility', array( 2 ), 'NOT IN' ) ) ) ) );
	}

	public function test_rating_filter_becomes_rating_ranges(): void {
		$result = $this->translator()->constraints( $this->query( array(), array( $this->clause( 'product_visibility', array( 14, 15 ), 'IN' ) ) ) );

		$expected = FilterBuilder::any(
			array(
				FilterBuilder::all(
					array(
						FilterBuilder::compare( 'rating_average', '>=', 3.5 ),
						FilterBuilder::compare( 'rating_average', '<', 4.5 ),
					)
				),
				FilterBuilder::compare( 'rating_average', '>=', 4.5 ),
			)
		);
		self::assertSame( array( $expected ), $result['filters'] );
	}

	public function test_unknown_visibility_term_is_not_translated(): void {
		self::assertNull( $this->translator()->constraints( $this->query( array(), array( $this->clause( 'product_visibility', array( 999 ), 'NOT IN' ) ) ) ) );
	}

	public function test_other_top_level_clauses_are_left_to_the_query_translator(): void {
		$result = $this->translator()->constraints(
			$this->query(
				array(),
				array(
					$this->clause( 'product_cat', array( 5 ), 'IN', 'term_id' ),
					$this->clause( 'product_visibility', array( 1 ), 'NOT IN' ),
				)
			)
		);

		self::assertSame( array(), $result['filters'] );
	}

	public function test_nested_groups_and_or_relations_are_not_consumed(): void {
		$nested = array(
			'relation' => 'OR',
			$this->clause( 'pa_color', array( 'red' ), 'IN', 'slug' ),
			$this->clause( 'product_cat', array( 5 ), 'IN', 'term_id' ),
		);

		self::assertSame( array(), $this->translator()->constraints( $this->query( array(), array( $nested ) ) )['filters'] );
		self::assertSame(
			array(),
			$this->translator()->constraints( $this->query( array(), array( $this->clause( 'pa_color', array( 'red' ), 'IN', 'slug' ) ), 'OR' ) )['filters']
		);
	}

	public function test_attribute_tax_clause_maps_slugs_to_term_names(): void {
		$result = $this->translator()->constraints( $this->query( array(), array( $this->clause( 'pa_color', array( 'red', 'navy-blue' ), 'IN', 'slug' ) ) ) );

		self::assertSame( array( FilterBuilder::in( 'attr_color', array( 'Red', 'Navy & Blue' ) ) ), $result['filters'] );
	}

	public function test_attribute_tax_clause_with_and_operator_requires_every_term(): void {
		$result = $this->translator()->constraints( $this->query( array(), array( $this->clause( 'pa_color', array( 121, 122 ), 'AND' ) ) ) );

		$expected = FilterBuilder::all(
			array(
				FilterBuilder::compare( 'attr_color', '=', 'Red' ),
				FilterBuilder::compare( 'attr_color', '=', 'Blue' ),
			)
		);
		self::assertSame( array( $expected ), $result['filters'] );
	}

	public function test_attribute_that_is_not_indexed_is_not_translated(): void {
		self::assertNull( $this->translator()->constraints( $this->query( array(), array( $this->clause( 'pa_size', array( 31 ), 'IN', 'term_id' ) ) ) ) );
	}

	public function test_layered_nav_is_read_only_for_the_woocommerce_product_query(): void {
		$_GET = array(
			'filter_color'     => 'red,navy-blue',
			'query_type_color' => 'or',
		);

		$main  = $this->translator()->constraints( $this->query( array( 'wc_query' => 'product_query' ) ) );
		$other = $this->translator()->constraints( $this->query() );

		self::assertSame( array( FilterBuilder::in( 'attr_color', array( 'Red', 'Navy & Blue' ) ) ), $main['filters'] );
		self::assertSame( array(), $other['filters'] );
	}

	public function test_layered_nav_defaults_to_and(): void {
		$_GET = array( 'filter_color' => 'red,blue' );

		$result = $this->translator()->constraints( $this->query( array( 'wc_query' => 'product_query' ) ) );

		$expected = FilterBuilder::all(
			array(
				FilterBuilder::compare( 'attr_color', '=', 'Red' ),
				FilterBuilder::compare( 'attr_color', '=', 'Blue' ),
			)
		);
		self::assertSame( array( $expected ), $result['filters'] );
	}

	public function test_layered_nav_attribute_already_in_tax_query_is_not_duplicated(): void {
		$_GET = array( 'filter_color' => 'red' );

		$result = $this->translator()->constraints(
			$this->query( array( 'wc_query' => 'product_query' ), array( $this->clause( 'pa_color', array( 'red' ), 'AND', 'slug' ) ) )
		);

		self::assertCount( 1, $result['filters'] );
	}

	public function test_layered_nav_unknown_slug_is_not_translated(): void {
		$_GET = array( 'filter_color' => 'purple' );

		self::assertNull( $this->translator()->constraints( $this->query( array( 'wc_query' => 'product_query' ) ) ) );
	}

	public function test_layered_nav_on_unindexed_attribute_is_not_translated(): void {
		$_GET = array( 'filter_size' => 'xl' );

		self::assertNull( $this->translator()->constraints( $this->query( array( 'wc_query' => 'product_query' ) ) ) );
	}

	public function test_layered_nav_on_unknown_attribute_is_ignored_like_woocommerce(): void {
		$_GET = array(
			'filter_material' => 'wool',
			'filter_color'    => array( 'red' ),
		);

		$result = $this->translator()->constraints( $this->query( array( 'wc_query' => 'product_query' ) ) );

		self::assertSame( array(), $result['filters'] );
	}

	public function test_price_range(): void {
		$_GET   = array(
			'min_price' => '10',
			'max_price' => '50.5',
		);
		$result = $this->translator()->constraints( $this->query( array( 'wc_query' => 'product_query' ) ) );
		self::assertSame( array( FilterBuilder::between( 'price', 10.0, 50.5 ) ), $result['filters'] );

		$_GET   = array( 'min_price' => '10' );
		$result = $this->translator()->constraints( $this->query( array( 'wc_query' => 'product_query' ) ) );
		self::assertSame( array( FilterBuilder::compare( 'price', '>=', 10.0 ) ), $result['filters'] );

		$_GET   = array( 'max_price' => '20' );
		$result = $this->translator()->constraints( $this->query( array( 'wc_query' => 'product_query' ) ) );
		self::assertSame( array( FilterBuilder::compare( 'price', '<=', 20.0 ) ), $result['filters'] );
	}

	public function test_price_range_is_ignored_outside_the_woocommerce_product_query(): void {
		$_GET = array( 'min_price' => '10' );

		self::assertSame( array(), $this->translator()->constraints( $this->query() )['filters'] );
	}
}
