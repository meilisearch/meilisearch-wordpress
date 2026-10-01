<?php
/**
 * QueryTranslator tests (content index).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Search\QueryTranslator;
use Meilisearch\WordPress\Search\SearchRequest;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

// phpcs:disable WordPress.DB.SlowDBQuery, WordPress.WP.PostsPerPage -- Fixtures describe queries; nothing is executed.

/**
 * WP_Query → SearchRequest for the content index.
 *
 * @covers \Meilisearch\WordPress\Search\QueryTranslator
 */
final class QueryTranslatorTest extends TestCase {

	use SearchStubs;

	/**
	 * Installs option and taxonomy stubs.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_search_options();
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'       => 'post',
				'page'       => 'page',
				'attachment' => 'attachment',
			)
		);
		Functions\when( 'is_taxonomy_hierarchical' )->alias(
			static function ( $taxonomy ) {
				return 'category' === $taxonomy;
			}
		);
		Functions\when( 'is_object_in_taxonomy' )->alias(
			static function ( $type, $taxonomy ) {
				return 'post' === $type && in_array( $taxonomy, array( 'category', 'post_tag', 'genre' ), true );
			}
		);
		Functions\when( 'get_term_by' )->alias(
			static function ( $field, $value, $taxonomy, $output ) {
				$terms = array(
					'post_tag|slug|news'           => 7,
					'post_tag|name|News'           => 7,
					'post_tag|slug|tech'           => 8,
					'category|term_taxonomy_id|55' => 5,
				);
				$key   = $taxonomy . '|' . $field . '|' . $value;
				return 'ARRAY_A' === $output && isset( $terms[ $key ] ) ? array( 'term_id' => $terms[ $key ] ) : false;
			}
		);
	}

	/**
	 * Translator over the real Options/Indexability.
	 *
	 * @return QueryTranslator
	 */
	private function translator(): QueryTranslator {
		$options = new Options();
		return new QueryTranslator( $options, new Indexability( $options ) );
	}

	/**
	 * Translates and asserts a request was produced.
	 *
	 * @param \WP_Query $query Query.
	 * @return SearchRequest
	 */
	private function translate( \WP_Query $query ): SearchRequest {
		$request = $this->translator()->translate( $query );
		self::assertNotNull( $request );
		return $request;
	}

	/**
	 * A plain content search.
	 */
	public function test_basic_search(): void {
		$request = $this->translate(
			$this->make_query(
				array(
					's'         => 'hello world',
					'post_type' => 'post',
				)
			)
		);

		self::assertSame( array( 'content' ), $request->logicals );
		self::assertSame( 'hello world', $request->q );
		self::assertSame( 1, $request->page );
		self::assertSame( 10, $request->hits_per_page );
		self::assertSame( array( 'content' => array( 'post_type IN ["post"]' ) ), $request->filters );
		self::assertSame( array( 'content' => array() ), $request->sort );
		self::assertNull( $request->hybrid );
		self::assertFalse( $request->highlight );
	}

	/**
	 * Review Focus #2: whitespace-only (incl. non-breaking spaces), empty, non-string and
	 * invalid UTF-8 searches are not intercepted.
	 */
	public function test_whitespace_query_is_not_intercepted(): void {
		foreach ( array( "   \t\n ", "\u{00A0}\u{2003}", '', array( 'x' ), "caf\xC3" ) as $s ) {
			self::assertNull( $this->translator()->translate( $this->make_query( array( 's' => $s ) ) ) );
		}
	}

	/**
	 * WP_Query::parse_query() fills p, page_id, attachment_id, year, monthnum, day and w with 0
	 * when unset: that is not a restriction. A real value still is.
	 */
	public function test_zero_defaults_filled_by_parse_query_do_not_block_interception(): void {
		$defaults = array(
			's'             => 'shoes',
			'p'             => 0,
			'page_id'       => 0,
			'attachment_id' => 0,
			'year'          => 0,
			'monthnum'      => 0,
			'day'           => 0,
			'w'             => 0,
		);
		self::assertNotNull( $this->translator()->translate( $this->make_query( $defaults ) ) );
		self::assertNull( $this->translator()->translate( $this->make_query( array_merge( $defaults, array( 'p' => 5 ) ) ) ) );
		self::assertNull( $this->translator()->translate( $this->make_query( array_merge( $defaults, array( 'year' => 2024 ) ) ) ) );
	}

	/**
	 * Surrounding whitespace is trimmed from q.
	 */
	public function test_query_is_trimmed(): void {
		self::assertSame( 'shoes', $this->translate( $this->make_query( array( 's' => "  shoes\n" ) ) )->q );
	}

	/**
	 * Review Focus #2: a 5 000-character query is cut to 500 characters (not bytes).
	 */
	public function test_long_query_is_truncated(): void {
		$request = $this->translate( $this->make_query( array( 's' => str_repeat( 'é', 5000 ) ) ) );

		self::assertSame( QueryTranslator::MAX_QUERY_LENGTH, mb_strlen( $request->q ) );
		self::assertSame( str_repeat( 'é', 500 ), $request->q );
	}

	/**
	 * No post type on a search = every searchable type; the unindexed attachment type is ignored.
	 */
	public function test_any_post_type_uses_searchable_indexed_types(): void {
		foreach ( array( '', 'any' ) as $post_type ) {
			$request = $this->translate(
				$this->make_query(
					array(
						's'         => 'x',
						'post_type' => $post_type,
					)
				)
			);
			self::assertSame( array( 'post_type IN ["post", "page"]' ), $request->filters['content'] );
		}
	}

	/**
	 * A non-search opt-in query without post_type means "post", like WordPress.
	 */
	public function test_non_search_query_defaults_to_post(): void {
		$request = $this->translate( $this->make_query( array( 's' => 'x' ), false, false ) );

		self::assertSame( array( 'post_type IN ["post"]' ), $request->filters['content'] );
	}

	/**
	 * Any requested type without an index → not intercepted.
	 */
	public function test_unindexed_post_type_is_not_intercepted(): void {
		$translator = $this->translator();

		self::assertNull(
			$translator->translate(
				$this->make_query(
					array(
						's'         => 'x',
						'post_type' => array( 'post', 'book' ),
					)
				)
			)
		);
		self::assertNull(
			$translator->translate(
				$this->make_query(
					array(
						's'         => 'x',
						'post_type' => 'product',
					)
				)
			)
		);
	}

	/**
	 * Searchable types that are not indexed block interception of "any" searches.
	 */
	public function test_any_search_with_unindexed_searchable_type_is_not_intercepted(): void {
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post' => 'post',
				'book' => 'book',
			)
		);

		self::assertNull( $this->translator()->translate( $this->make_query( array( 's' => 'x' ) ) ) );
	}

	/**
	 * Pagination mapping.
	 *
	 * @dataProvider pagination_cases
	 *
	 * @param array<string, mixed> $vars          Query vars.
	 * @param int                  $page          Expected page.
	 * @param int                  $hits_per_page Expected hits per page.
	 */
	public function test_pagination( array $vars, int $page, int $hits_per_page ): void {
		$request = $this->translate( $this->make_query( array_merge( array( 's' => 'x' ), $vars ) ) );

		self::assertSame( $page, $request->page );
		self::assertSame( $hits_per_page, $request->hits_per_page );
	}

	/**
	 * Query vars => [page, hitsPerPage]. The posts_per_page option is '10'.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int, 2: int}>
	 */
	public static function pagination_cases(): array {
		return array(
			'defaults'                => array( array(), 1, 10 ),
			'paged and per page'      => array(
				array(
					'paged'          => 3,
					'posts_per_page' => 20,
				),
				3,
				20,
			),
			'paged zero'              => array( array( 'paged' => 0 ), 1, 10 ),
			'per page zero → option'  => array( array( 'posts_per_page' => 0 ), 1, 10 ),
			'all posts'               => array(
				array(
					'posts_per_page' => -1,
					'paged'          => 4,
				),
				1,
				1000,
			),
			'nopaging'                => array(
				array(
					'nopaging' => true,
					'paged'    => 2,
				),
				1,
				1000,
			),
			'archive per page'        => array( array( 'posts_per_archive_page' => 7 ), 1, 7 ),
			'negative below -1 → abs' => array( array( 'posts_per_page' => -5 ), 1, 5 ),
			'capped'                  => array( array( 'posts_per_page' => 5000 ), 1, 1000 ),
		);
	}

	/**
	 * Orderby mapping.
	 *
	 * @dataProvider sort_cases
	 *
	 * @param array<string, mixed> $vars     Query vars.
	 * @param string[]|null        $expected Sort rules, null = not intercepted.
	 */
	public function test_sort( array $vars, ?array $expected ): void {
		$request = $this->translator()->translate(
			$this->make_query(
				array_merge(
					array(
						's'         => 'x',
						'post_type' => 'post',
					),
					$vars
				)
			)
		);

		if ( null === $expected ) {
			self::assertNull( $request );
			return;
		}
		self::assertNotNull( $request );
		self::assertSame( array( 'content' => $expected ), $request->sort );
	}

	/**
	 * Query vars => sort rules.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string[]|null}>
	 */
	public static function sort_cases(): array {
		return array(
			'default'            => array( array(), array() ),
			'relevance'          => array( array( 'orderby' => 'relevance' ), array() ),
			'none'               => array( array( 'orderby' => 'none' ), array() ),
			'date asc'           => array(
				array(
					'orderby' => 'date',
					'order'   => 'ASC',
				),
				array( 'date:asc' ),
			),
			'title default desc' => array( array( 'orderby' => 'title' ), array( 'title:desc' ) ),
			'array form'         => array( array( 'orderby' => array( 'modified' => 'asc' ) ), array( 'modified:asc' ) ),
			'several keys'       => array(
				array(
					'orderby' => 'date title',
					'order'   => 'asc',
				),
				array( 'date:asc', 'title:asc' ),
			),
			'duplicate field'    => array( array( 'orderby' => 'post_date date' ), array( 'date:desc' ) ),
			'meta value'         => array( array( 'orderby' => 'meta_value_num' ), null ),
			'random'             => array( array( 'orderby' => 'rand' ), null ),
			'id'                 => array( array( 'orderby' => 'ID' ), null ),
			'relevance plus'     => array( array( 'orderby' => 'relevance date' ), null ),
		);
	}

	/**
	 * The author, author__in and author__not_in vars are merged into author_id filters.
	 */
	public function test_author_filters(): void {
		$request = $this->translate(
			$this->make_query(
				array(
					's'              => 'x',
					'post_type'      => 'post',
					'author'         => '3,-4',
					'author__in'     => array( 5, '3' ),
					'author__not_in' => array( 6 ),
				)
			)
		);

		self::assertSame(
			array( 'post_type IN ["post"]', 'author_id IN [3, 5]', 'author_id NOT IN [4, 6]' ),
			$request->filters['content']
		);
	}

	/**
	 * Non-numeric authors cannot be translated.
	 */
	public function test_non_numeric_author_is_not_intercepted(): void {
		self::assertNull(
			$this->translator()->translate(
				$this->make_query(
					array(
						's'      => 'x',
						'author' => 'bob',
					)
				)
			)
		);
	}

	/**
	 * Tax clauses come from $query->tax_query (cat, tag and custom taxonomy vars end up there).
	 */
	public function test_category_clause_from_tax_query(): void {
		$query = $this->with_tax_query(
			$this->make_query(
				array(
					's'         => 'x',
					'post_type' => 'post',
					'cat'       => '5',
				)
			),
			array(
				array(
					'taxonomy'         => 'category',
					'terms'            => array( 5 ),
					'field'            => 'term_id',
					'operator'         => 'IN',
					'include_children' => true,
				),
			)
		);

		self::assertSame(
			array( 'post_type IN ["post"]', 'tax_category_ids IN [5]' ),
			$this->translate( $query )->filters['content']
		);
	}

	/**
	 * Tax clause translations.
	 *
	 * @dataProvider tax_cases
	 *
	 * @param array<int|string, mixed> $queries  Sanitized tax queries.
	 * @param string                   $relation Top-level relation.
	 * @param string|null              $expected Tax filter ('' = none, '#never' = matches nothing), null = not intercepted.
	 */
	public function test_tax_clauses( array $queries, string $relation, ?string $expected ): void {
		$query   = $this->with_tax_query(
			$this->make_query(
				array(
					's'         => 'x',
					'post_type' => 'post',
				)
			),
			$queries,
			$relation
		);
		$request = $this->translator()->translate( $query );

		if ( null === $expected ) {
			self::assertNull( $request );
			return;
		}
		self::assertNotNull( $request );
		if ( '#never' === $expected ) {
			$expected_filters = array( 'post_type IN []' ); // No post can match: an empty result, still answered by Meilisearch.
		} elseif ( '' === $expected ) {
			$expected_filters = array( 'post_type IN ["post"]' );
		} else {
			$expected_filters = array( 'post_type IN ["post"]', $expected );
		}
		self::assertSame( $expected_filters, $request->filters['content'] );
	}

	/**
	 * Tax queries => filter.
	 *
	 * @return array<string, array{0: array<int|string, mixed>, 1: string, 2: string|null}>
	 */
	public static function tax_cases(): array {
		$tag = static function ( array $clause ): array {
			return array_merge(
				array(
					'taxonomy'         => 'post_tag',
					'terms'            => array(),
					'field'            => 'term_id',
					'operator'         => 'IN',
					'include_children' => true,
				),
				$clause
			);
		};
		return array(
			'slug resolved to ids'           => array(
				array(
					$tag(
						array(
							'terms' => array( 'news', 'tech' ),
							'field' => 'slug',
						)
					),
				),
				'AND',
				'tax_post_tag_ids IN [7, 8]',
			),
			'name resolved to ids'           => array(
				array(
					$tag(
						array(
							'terms' => array( 'News' ),
							'field' => 'name',
						)
					),
				),
				'AND',
				'tax_post_tag_ids IN [7]',
			),
			'term_taxonomy_id resolved'      => array(
				array(
					array(
						'taxonomy' => 'category',
						'terms'    => array( 55 ),
						'field'    => 'term_taxonomy_id',
					),
				),
				'AND',
				'tax_category_ids IN [5]',
			),
			'unknown slug matches nothing'   => array(
				array(
					$tag(
						array(
							'terms' => array( 'nope' ),
							'field' => 'slug',
						)
					),
				),
				'AND',
				'tax_post_tag_ids IN []',
			),
			'empty terms IN matches nothing' => array( array( $tag( array() ) ), 'AND', 'tax_post_tag_ids IN []' ),
			'NOT IN'                         => array(
				array(
					$tag(
						array(
							'terms'    => array( 9 ),
							'operator' => 'NOT IN',
						)
					),
				),
				'AND',
				'tax_post_tag_ids NOT IN [9]',
			),
			'NOT IN unknown = no constraint' => array(
				array(
					$tag(
						array(
							'terms'    => array( 'nope' ),
							'field'    => 'slug',
							'operator' => 'NOT IN',
						)
					),
				),
				'AND',
				'',
			),
			'AND'                            => array(
				array(
					$tag(
						array(
							'terms'    => array( 7, 8 ),
							'operator' => 'AND',
						)
					),
				),
				'AND',
				'(tax_post_tag_ids = 7) AND (tax_post_tag_ids = 8)',
			),
			'AND with unknown term'          => array(
				array(
					$tag(
						array(
							'terms'    => array( 'news', 'nope' ),
							'field'    => 'slug',
							'operator' => 'AND',
						)
					),
				),
				'AND',
				'tax_post_tag_ids IN []',
			),
			'EXISTS'                         => array( array( $tag( array( 'operator' => 'EXISTS' ) ) ), 'AND', '(tax_post_tag_ids EXISTS) AND (NOT (tax_post_tag_ids IS EMPTY))' ),
			'NOT EXISTS'                     => array( array( $tag( array( 'operator' => 'NOT EXISTS' ) ) ), 'AND', '(tax_post_tag_ids NOT EXISTS) OR (tax_post_tag_ids IS EMPTY)' ),
			'non-hierarchical exact terms'   => array(
				array(
					$tag(
						array(
							'terms'            => array( 7 ),
							'include_children' => false,
						)
					),
				),
				'AND',
				'tax_post_tag_ids IN [7]',
			),
			'nested relations'               => array(
				array(
					array(
						'taxonomy' => 'category',
						'terms'    => array( 5 ),
					),
					array(
						'relation' => 'AND',
						$tag(
							array(
								'terms' => array( 'news' ),
								'field' => 'slug',
							)
						),
						$tag(
							array(
								'terms'    => array( 9 ),
								'operator' => 'NOT IN',
							)
						),
					),
				),
				'OR',
				'(tax_category_ids IN [5]) OR ((tax_post_tag_ids IN [7]) AND (tax_post_tag_ids NOT IN [9]))',
			),
			'visibility exclusion dropped'   => array(
				array(
					array(
						'taxonomy' => 'product_visibility',
						'terms'    => array( 3 ),
						'field'    => 'term_taxonomy_id',
						'operator' => 'NOT IN',
					),
				),
				'AND',
				'',
			),
			'visibility exclusion in OR'     => array(
				array(
					$tag( array( 'terms' => array( 7 ) ) ),
					array(
						'taxonomy' => 'product_visibility',
						'terms'    => array( 3 ),
						'operator' => 'NOT IN',
					),
				),
				'OR',
				'',
			),
			'visibility inclusion'           => array(
				array(
					array(
						'taxonomy' => 'product_visibility',
						'terms'    => array( 3 ),
					),
				),
				'AND',
				'#never',
			),
			'other index taxonomy in AND'    => array(
				array(
					$tag( array( 'terms' => array( 7 ) ) ),
					array(
						'taxonomy' => 'product_cat',
						'terms'    => array( 12 ),
					),
				),
				'AND',
				'#never',
			),
			'other index taxonomy in OR'     => array(
				array(
					$tag( array( 'terms' => array( 7 ) ) ),
					array(
						'taxonomy' => 'product_cat',
						'terms'    => array( 12 ),
					),
				),
				'OR',
				'tax_post_tag_ids IN [7]',
			),
			'hierarchical without children'  => array(
				array(
					array(
						'taxonomy'         => 'category',
						'terms'            => array( 5 ),
						'include_children' => false,
					),
				),
				'AND',
				null,
			),
			'hierarchical AND'               => array(
				array(
					array(
						'taxonomy' => 'category',
						'terms'    => array( 5, 6 ),
						'operator' => 'AND',
					),
				),
				'AND',
				null,
			),
			'taxonomy not indexed'           => array(
				array(
					array(
						'taxonomy' => 'genre',
						'terms'    => array( 1 ),
					),
				),
				'AND',
				null,
			),
			'missing taxonomy'               => array(
				array(
					array(
						'terms' => array( 55 ),
						'field' => 'term_taxonomy_id',
					),
				),
				'AND',
				null,
			),
			'unknown operator'               => array(
				array(
					$tag(
						array(
							'terms'    => array( 7 ),
							'operator' => 'LIKE',
						)
					),
				),
				'AND',
				null,
			),
		);
	}

	/**
	 * Review Focus #5: a meta key with a dash filters on the same normalized field the index uses.
	 */
	public function test_meta_key_with_dash_uses_normalized_field(): void {
		$request = $this->translate(
			$this->make_query(
				array(
					's'          => 'x',
					'post_type'  => 'post',
					'meta_query' => array(
						array(
							'key'   => 'my-key',
							'value' => 'blue',
						),
					),
				)
			)
		);

		self::assertSame( array( 'post_type IN ["post"]', 'meta_my_key = "blue"' ), $request->filters['content'] );
	}

	/**
	 * Meta clause translations.
	 *
	 * @dataProvider meta_cases
	 *
	 * @param array<string, mixed> $vars     Query vars (merged over s + post_type=post).
	 * @param string|null          $expected Meta filter, null = not intercepted.
	 */
	public function test_meta_clauses( array $vars, ?string $expected ): void {
		$request = $this->translator()->translate(
			$this->make_query(
				array_merge(
					array(
						's'         => 'x',
						'post_type' => 'post',
					),
					$vars
				)
			)
		);

		if ( null === $expected ) {
			self::assertNull( $request );
			return;
		}
		self::assertNotNull( $request );
		self::assertSame( array( 'post_type IN ["post"]', $expected ), $request->filters['content'] );
	}

	/**
	 * Query vars => meta filter.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function meta_cases(): array {
		$meta = static function ( array ...$clauses ): array {
			return array( 'meta_query' => $clauses );
		};
		return array(
			'numeric greater'   => array(
				$meta(
					array(
						'key'     => 'price',
						'value'   => '10',
						'compare' => '>',
						'type'    => 'NUMERIC',
					)
				),
				'meta_price > 10',
			),
			'decimal lte'       => array(
				$meta(
					array(
						'key'     => 'price',
						'value'   => '9.5',
						'compare' => '<=',
						'type'    => 'DECIMAL(10,2)',
					)
				),
				'meta_price <= 9.5',
			),
			'numeric string eq' => array(
				$meta(
					array(
						'key'   => 'price',
						'value' => '49',
					)
				),
				'meta_price = 49',
			),
			'not equal'         => array(
				$meta(
					array(
						'key'     => 'my-key',
						'value'   => 'x',
						'compare' => '!=',
					)
				),
				'(meta_my_key EXISTS) AND (meta_my_key != "x")',
			),
			'in'                => array(
				$meta(
					array(
						'key'     => 'price',
						'value'   => array( '1', '2' ),
						'compare' => 'IN',
					)
				),
				'meta_price IN [1, 2]',
			),
			'in implied'        => array(
				$meta(
					array(
						'key'   => 'my-key',
						'value' => array( 'a', 'b' ),
					)
				),
				'meta_my_key IN ["a", "b"]',
			),
			'in from string'    => array(
				$meta(
					array(
						'key'     => 'price',
						'value'   => '1, 2',
						'compare' => 'IN',
					)
				),
				'meta_price IN [1, 2]',
			),
			'not in'            => array(
				$meta(
					array(
						'key'     => 'my-key',
						'value'   => array( 'a' ),
						'compare' => 'NOT IN',
						'type'    => 'CHAR',
					)
				),
				'(meta_my_key EXISTS) AND (meta_my_key NOT IN ["a"])',
			),
			'between'           => array(
				$meta(
					array(
						'key'     => 'price',
						'value'   => array( '10', '20.5' ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					)
				),
				'meta_price 10 TO 20.5',
			),
			'exists'            => array(
				$meta(
					array(
						'key'     => 'price',
						'compare' => 'EXISTS',
					)
				),
				'meta_price EXISTS',
			),
			'not exists'        => array(
				$meta(
					array(
						'key'     => 'price',
						'compare' => 'NOT EXISTS',
					)
				),
				'meta_price NOT EXISTS',
			),
			'key only'          => array( $meta( array( 'key' => 'price' ) ), 'meta_price EXISTS' ),
			'or relation'       => array(
				array(
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'     => 'price',
							'value'   => 10,
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
						array(
							'key'   => 'my-key',
							'value' => 'blue',
						),
					),
				),
				'(meta_price > 10) OR (meta_my_key = "blue")',
			),
			'shorthand vars'    => array(
				array(
					'meta_key'   => 'price',
					'meta_value' => '5',
				),
				'meta_price = 5',
			),
			'like'              => array(
				$meta(
					array(
						'key'     => 'my-key',
						'value'   => 'bl',
						'compare' => 'LIKE',
					)
				),
				null,
			),
			'date type'         => array(
				$meta(
					array(
						'key'   => 'price',
						'value' => '2024-01-01',
						'type'  => 'DATE',
					)
				),
				null,
			),
			'range on text'     => array(
				$meta(
					array(
						'key'     => 'my-key',
						'value'   => 'abc',
						'compare' => '>',
					)
				),
				null,
			),
			'not indexed'       => array(
				$meta(
					array(
						'key'   => 'secret',
						'value' => '1',
					)
				),
				null,
			),
			'value only'        => array( $meta( array( 'value' => '1' ) ), null ),
			'compare_key'       => array(
				$meta(
					array(
						'key'         => 'pri',
						'compare_key' => 'LIKE',
						'value'       => '1',
					)
				),
				null,
			),
		);
	}

	/**
	 * Meta keys must be indexed for every requested content type.
	 */
	public function test_meta_key_not_indexed_for_every_type_is_not_intercepted(): void {
		self::assertNull(
			$this->translator()->translate(
				$this->make_query(
					array(
						's'          => 'x',
						'post_type'  => array( 'post', 'page' ),
						'meta_query' => array(
							array(
								'key'   => 'price',
								'value' => '1',
							),
						),
					)
				)
			)
		);
	}

	/**
	 * Date query translations (site timezone stubbed to UTC).
	 *
	 * @dataProvider date_cases
	 *
	 * @param mixed       $date_query date_query var.
	 * @param string|null $expected   Date filter, null = not intercepted.
	 */
	public function test_date_query( mixed $date_query, ?string $expected ): void {
		$request = $this->translator()->translate(
			$this->make_query(
				array(
					's'          => 'x',
					'post_type'  => 'post',
					'date_query' => $date_query,
				)
			)
		);

		if ( null === $expected ) {
			self::assertNull( $request );
			return;
		}
		self::assertNotNull( $request );
		self::assertSame( array( 'post_type IN ["post"]', $expected ), $request->filters['content'] );
	}

	/**
	 * Date queries => filter. 1704067200 = 2024-01-01 00:00:00 UTC.
	 *
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function date_cases(): array {
		return array(
			'after inclusive'       => array(
				array(
					array(
						'after'     => '2024-01-01',
						'inclusive' => true,
					),
				),
				'date >= 1704067200',
			),
			'after month exclusive' => array( array( array( 'after' => '2024-01' ) ), 'date > 1706745599' ),
			'before year array'     => array( array( array( 'before' => array( 'year' => 2024 ) ) ), 'date < 1704067200' ),
			'range inclusive'       => array(
				array(
					array(
						'after'     => '2024-01-01',
						'before'    => '2024-12-31',
						'inclusive' => true,
					),
				),
				'(date >= 1704067200) AND (date <= 1735689599)',
			),
			'bare clause'           => array( array( 'after' => '2024-01-01 00:00:00' ), 'date > 1704067200' ),
			'modified column'       => array(
				array(
					'column' => 'post_modified_gmt',
					array( 'before' => '2024-01-01' ),
				),
				'modified < 1704067200',
			),
			'year clause'           => array( array( array( 'year' => 2024 ) ), null ),
			'compare key'           => array(
				array(
					'compare' => '>=',
					array( 'after' => '2024-01-01' ),
				),
				null,
			),
			'unknown column'        => array(
				array(
					array(
						'after'  => '2024-01-01',
						'column' => 'comment_date',
					),
				),
				null,
			),
			'invalid date'          => array( array( array( 'after' => 'not a date at all' ) ), null ),
		);
	}

	/**
	 * Query vars outside the translatable set → not intercepted.
	 *
	 * @dataProvider denied_cases
	 *
	 * @param string $query_var Query var.
	 * @param mixed  $value     Value.
	 */
	public function test_unsupported_vars_are_not_intercepted( string $query_var, mixed $value ): void {
		self::assertNull(
			$this->translator()->translate(
				$this->make_query(
					array(
						's'        => 'x',
						$query_var => $value,
					)
				)
			)
		);
	}

	/**
	 * Var => value.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function denied_cases(): array {
		return array(
			'p'                => array( 'p', 5 ),
			'page_id'          => array( 'page_id', 5 ),
			'name'             => array( 'name', 'hello' ),
			'pagename'         => array( 'pagename', 'about' ),
			'post__in'         => array( 'post__in', array( 1 ) ),
			'post__not_in'     => array( 'post__not_in', array( 1 ) ),
			'post_name__in'    => array( 'post_name__in', array( 'a' ) ),
			'post_parent zero' => array( 'post_parent', 0 ),
			'post_parent__in'  => array( 'post_parent__in', array( 2 ) ),
			'draft status'     => array( 'post_status', 'draft' ),
			'any status'       => array( 'post_status', 'any' ),
			'mime type'        => array( 'post_mime_type', 'image' ),
			'fields ids'       => array( 'fields', 'ids' ),
			'suppress_filters' => array( 'suppress_filters', true ),
			'has_password'     => array( 'has_password', true ),
			'post_password'    => array( 'post_password', 'secret' ),
			'comment_count'    => array( 'comment_count', 3 ),
			'year'             => array( 'year', 2024 ),
			'monthnum'         => array( 'monthnum', 1 ),
			'day'              => array( 'day', 1 ),
			'w'                => array( 'w', 2 ),
			'm'                => array( 'm', '202401' ),
			'hour'             => array( 'hour', 3 ),
			'minute'           => array( 'minute', 3 ),
			'second'           => array( 'second', 3 ),
			'author_name'      => array( 'author_name', 'bob' ),
			'offset'           => array( 'offset', 10 ),
			'feed'             => array( 'feed', 'rss2' ),
			'sentence'         => array( 'sentence', true ),
			'exact'            => array( 'exact', true ),
			'title'            => array( 'title', 'Hello' ),
			'menu_order'       => array( 'menu_order', 2 ),
			'search_columns'   => array( 'search_columns', array( 'post_title' ) ),
		);
	}

	/**
	 * Default-valued vars do not prevent interception.
	 */
	public function test_default_valued_vars_are_accepted(): void {
		$request = $this->translator()->translate(
			$this->make_query(
				array(
					's'                   => 'x',
					'post_type'           => 'post',
					'post_status'         => array( 'publish' ),
					'fields'              => 'all',
					'has_password'        => false,
					'suppress_filters'    => false,
					'post__in'            => array(),
					'post_parent'         => '',
					'ignore_sticky_posts' => true,
					'cache_results'       => false,
				)
			)
		);

		self::assertNotNull( $request );
	}

	/**
	 * Hybrid search is requested when an embedder and a positive ratio are configured.
	 */
	public function test_hybrid_from_options(): void {
		$this->stub_search_options(
			array(
				Options::SEARCH => array(
					'embedder'       => 'default',
					'semantic_ratio' => 0.7,
				),
			)
		);

		$request = $this->translate( $this->make_query( array( 's' => 'x' ) ) );

		self::assertSame(
			array(
				'embedder'      => 'default',
				'semanticRatio' => 0.7,
			),
			$request->hybrid
		);
	}

	/**
	 * A zero ratio means keyword search only.
	 */
	public function test_zero_ratio_disables_hybrid(): void {
		$this->stub_search_options( array( Options::SEARCH => array( 'embedder' => 'default' ) ) );

		self::assertNull( $this->translate( $this->make_query( array( 's' => 'x' ) ) )->hybrid );
	}

	/**
	 * A ratio without an embedder cannot run a hybrid search (SearchTab accepts that combination).
	 */
	public function test_ratio_without_embedder_disables_hybrid(): void {
		$this->stub_search_options( array( Options::SEARCH => array( 'semantic_ratio' => 0.7 ) ) );

		self::assertNull( $this->translate( $this->make_query( array( 's' => 'x' ) ) )->hybrid );
	}

	/**
	 * Numeric strings that overflow to INF cannot be expressed as filter values.
	 */
	public function test_non_finite_meta_value_is_not_intercepted(): void {
		foreach ( array( '1e999', '-1e999' ) as $value ) {
			foreach ( array( 'my-key', 'price' ) as $key ) {
				self::assertNull(
					$this->translator()->translate(
						$this->make_query(
							array(
								's'          => 'x',
								'post_type'  => 'post',
								'meta_query' => array(
									array(
										'key'   => $key,
										'value' => $value,
									),
								),
							)
						)
					)
				);
			}
		}
	}

	/**
	 * Quotes and backslashes in user-controlled values are escaped by the FilterBuilder.
	 */
	public function test_meta_value_is_escaped(): void {
		$request = $this->translate(
			$this->make_query(
				array(
					's'          => 'x',
					'post_type'  => 'post',
					'meta_query' => array(
						array(
							'key'   => 'my-key',
							'value' => 'a" OR post_type = "page\\',
						),
					),
				)
			)
		);

		self::assertSame( 'meta_my_key = "a\\" OR post_type = \\"page\\\\"', $request->filters['content'][1] );
	}

	/**
	 * A user who can read private posts of a requested type gets them from MySQL: not intercepted.
	 */
	public function test_user_who_can_read_private_posts_is_not_intercepted(): void {
		$this->private_caps = array( 'post' => 'read_private_posts' );
		$this->user_caps    = array( 'read_private_posts' );

		self::assertNull( $this->translator()->translate( $this->make_query( array( 's' => 'x' ) ) ) );
	}

	/**
	 * The type's own capability is checked, with the generic name as fallback.
	 */
	public function test_private_posts_check_uses_the_type_capability(): void {
		$this->private_caps = array( 'page' => 'read_private_pages' );
		$this->user_caps    = array( 'read_private_pages' );

		self::assertNull( $this->translator()->translate( $this->make_query( array( 's' => 'x' ) ) ) );
		self::assertNotNull(
			$this->translator()->translate(
				$this->make_query(
					array(
						's'         => 'x',
						'post_type' => 'post',
					)
				)
			)
		);
	}

	/**
	 * A logged-in user without the capability is translated normally.
	 */
	public function test_user_without_private_capability_is_translated(): void {
		$this->user_caps = array( 'edit_posts' );

		self::assertNotNull( $this->translator()->translate( $this->make_query( array( 's' => 'x' ) ) ) );
	}

	/**
	 * An explicit publish status is translated even for a privileged user.
	 */
	public function test_explicit_publish_status_with_privileged_user_is_translated(): void {
		$this->user_caps = array( 'read_private_posts' );

		foreach ( array( 'publish', array( 'publish' ) ) as $status ) {
			self::assertNotNull(
				$this->translator()->translate(
					$this->make_query(
						array(
							's'           => 'x',
							'post_status' => $status,
						)
					)
				)
			);
		}
	}

	/**
	 * The highlight flag follows the Search tab option.
	 */
	public function test_highlight_from_options(): void {
		$this->stub_search_options( array( Options::SEARCH => array( 'highlight' => true ) ) );

		self::assertTrue( $this->translate( $this->make_query( array( 's' => 'x' ) ) )->highlight );
	}
}
