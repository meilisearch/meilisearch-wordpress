<?php
/**
 * QueryTranslator tests (products index).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Search\ProductTranslator;
use Meilisearch\WordPress\Search\QueryTranslator;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Product and mixed searches. Each test runs in its own process because it defines the
 * WooCommerce class that Options::products_enabled() looks for.
 *
 * @covers \Meilisearch\WordPress\Search\QueryTranslator
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class QueryTranslatorProductsTest extends TestCase {

	use SearchStubs;

	/**
	 * WooCommerce "active", products indexed, product searchable.
	 */
	protected function set_up(): void {
		parent::set_up();
		require_once dirname( __DIR__ ) . '/Support/woocommerce-active.php';
		$this->stub_search_options( array( Options::WOOCOMMERCE => array( 'enabled' => true ) ) );
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'       => 'post',
				'page'       => 'page',
				'attachment' => 'attachment',
				'product'    => 'product',
			)
		);
		Functions\when( 'is_taxonomy_hierarchical' )->alias(
			static function ( $taxonomy ) {
				return in_array( $taxonomy, array( 'category', 'product_cat' ), true );
			}
		);
		Functions\when( 'is_object_in_taxonomy' )->alias(
			static function ( $type, $taxonomy ) {
				$map = array(
					'post'    => array( 'category', 'post_tag' ),
					'product' => array( 'product_cat', 'product_tag', 'product_visibility', 'pa_color', 'product_brand' ),
				);
				return in_array( $taxonomy, $map[ $type ] ?? array(), true );
			}
		);
		Functions\when( 'get_term_by' )->alias(
			static function ( $field, $value, $taxonomy, $output ) {
				return 'ARRAY_A' === $output && 'product_cat' === $taxonomy && 'slug' === $field && 'shoes' === $value ? array( 'term_id' => 12 ) : false;
			}
		);
	}

	/**
	 * Sanitized tax clause.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param mixed  $terms    Terms.
	 * @param string $field    Field.
	 * @param string $operator Operator.
	 * @return array<string, mixed>
	 */
	private static function clause( string $taxonomy, mixed $terms, string $field = 'term_id', string $operator = 'IN' ): array {
		return array(
			'taxonomy'         => $taxonomy,
			'terms'            => (array) $terms,
			'field'            => $field,
			'operator'         => $operator,
			'include_children' => true,
		);
	}

	/**
	 * No-op constraints.
	 *
	 * @return ProductTranslator
	 */
	private function no_constraints(): ProductTranslator {
		return $this->products(
			array(
				'filters' => array(),
				'sort'    => array(),
			)
		);
	}

	/**
	 * A ProductTranslator returning a fixed result.
	 *
	 * @param array{filters: list<string>, sort: list<string>}|null $result Result.
	 * @return ProductTranslator
	 */
	private function products( ?array $result ): ProductTranslator {
		return new class( $result ) implements ProductTranslator {
			/**
			 * Constructor.
			 *
			 * @param array{filters: list<string>, sort: list<string>}|null $result Result.
			 */
			public function __construct( private ?array $result ) {}

			/**
			 * Fixed constraints.
			 *
			 * @param \WP_Query $query Query.
			 * @return array{filters: list<string>, sort: list<string>}|null
			 */
			public function constraints( \WP_Query $query ): ?array {
				return $this->result;
			}
		};
	}

	/**
	 * Translator with the given product translator.
	 *
	 * @param ProductTranslator|null $products Product translator.
	 * @return QueryTranslator
	 */
	private function translator( ?ProductTranslator $products ): QueryTranslator {
		$options = new Options();
		return new QueryTranslator( $options, new Indexability( $options, static fn(): bool => true ), $products );
	}

	/**
	 * A product search takes its filters and sort from the ProductTranslator; WooCommerce's
	 * orderby values are not evaluated by the content rules.
	 */
	public function test_product_search_uses_product_translator(): void {
		$translator = $this->translator(
			$this->products(
				array(
					'filters' => array( 'in_stock = true' ),
					'sort'    => array( 'price:asc' ),
				)
			)
		);

		$request = $translator->translate(
			$this->make_query(
				array(
					's'         => 'shoe',
					'post_type' => 'product',
					'orderby'   => 'price',
				)
			)
		);

		self::assertNotNull( $request );
		self::assertSame( array( 'products' ), $request->logicals );
		self::assertSame( array( 'products' => array( 'in_stock = true' ) ), $request->filters );
		self::assertSame( array( 'products' => array( 'price:asc' ) ), $request->sort );
	}

	/**
	 * Products without a ProductTranslator cannot be searched.
	 */
	public function test_product_search_without_translator_is_not_intercepted(): void {
		self::assertNull(
			$this->translator( null )->translate(
				$this->make_query(
					array(
						's'         => 'shoe',
						'post_type' => 'product',
					)
				)
			)
		);
	}

	/**
	 * The ProductTranslator can veto.
	 */
	public function test_product_translator_veto(): void {
		self::assertNull(
			$this->translator( $this->products( null ) )->translate(
				$this->make_query(
					array(
						's'         => 'shoe',
						'post_type' => 'product',
					)
				)
			)
		);
	}

	/**
	 * A theme search with WooCommerce: both indexes; WooCommerce's product_visibility exclusion is
	 * dropped from the content side.
	 */
	public function test_mixed_search_queries_both_indexes(): void {
		$query = $this->with_tax_query(
			$this->make_query( array( 's' => 'nebula' ) ),
			array(
				array(
					'taxonomy'         => 'product_visibility',
					'terms'            => array( 3 ),
					'field'            => 'term_taxonomy_id',
					'operator'         => 'NOT IN',
					'include_children' => true,
				),
			)
		);

		$request = $this->translator(
			$this->products(
				array(
					'filters' => array(),
					'sort'    => array(),
				)
			)
		)->translate( $query );

		self::assertNotNull( $request );
		self::assertSame( array( 'content', 'products' ), $request->logicals );
		self::assertSame(
			array(
				'content'  => array( 'post_type IN ["post", "page"]' ),
				'products' => array(),
			),
			$request->filters
		);
	}

	/**
	 * The product_cat / product_tag clauses are translated here; top-level product_visibility and pa_*
	 * clauses are left to the ProductTranslator.
	 */
	public function test_product_taxonomies_on_products_index(): void {
		$query = $this->with_tax_query(
			$this->make_query(
				array(
					's'         => 'shoe',
					'post_type' => 'product',
				)
			),
			array(
				self::clause( 'product_cat', 'shoes', 'slug' ),
				self::clause( 'product_visibility', array( 1 ), 'term_taxonomy_id', 'NOT IN' ),
				self::clause( 'pa_color', 'red', 'slug', 'AND' ),
			)
		);

		$request = $this->translator(
			$this->products(
				array(
					'filters' => array( 'attr_color = "Red"' ),
					'sort'    => array(),
				)
			)
		)->translate( $query );

		self::assertNotNull( $request );
		self::assertSame( array( 'products' => array( 'tax_product_cat_ids IN [12]', 'attr_color = "Red"' ) ), $request->filters );
	}

	/**
	 * The product_visibility / pa_* clauses that are nested or OR-ed cannot be delegated.
	 */
	public function test_nested_or_ored_product_filter_clauses_are_not_intercepted(): void {
		$base   = $this->make_query(
			array(
				's'         => 'shoe',
				'post_type' => 'product',
			)
		);
		$nested = $this->with_tax_query(
			clone $base,
			array(
				array(
					'relation' => 'AND',
					self::clause( 'pa_color', 'red', 'slug' ),
				),
			)
		);
		$ored   = $this->with_tax_query(
			clone $base,
			array(
				self::clause( 'product_cat', 'shoes', 'slug' ),
				self::clause( 'product_visibility', array( 3 ), 'term_taxonomy_id' ),
			),
			'OR'
		);

		self::assertNull( $this->translator( $this->no_constraints() )->translate( $nested ) );
		self::assertNull( $this->translator( $this->no_constraints() )->translate( $ored ) );
	}

	/**
	 * A product taxonomy that is not indexed cannot be translated; a taxonomy products never use
	 * means no product can match, so a products-only search falls back to MySQL.
	 */
	public function test_unindexed_or_foreign_taxonomy_on_products(): void {
		foreach ( array( 'product_brand', 'category' ) as $taxonomy ) {
			$query = $this->with_tax_query(
				$this->make_query(
					array(
						's'         => 'shoe',
						'post_type' => 'product',
					)
				),
				array( self::clause( $taxonomy, array( 5 ) ) )
			);
			self::assertNull( $this->translator( $this->no_constraints() )->translate( $query ) );
		}
	}

	/**
	 * A category clause on a mixed search: products cannot match, so only the content index is
	 * queried.
	 */
	public function test_mixed_search_with_category_clause_queries_content_only(): void {
		$query = $this->with_tax_query(
			$this->make_query( array( 's' => 'nebula' ) ),
			array( self::clause( 'category', array( 5 ) ) )
		);

		$request = $this->translator( $this->no_constraints() )->translate( $query );

		self::assertNotNull( $request );
		self::assertSame( array( 'content' ), $request->logicals );
		self::assertSame( array( 'content' => array( 'post_type IN ["post", "page"]', 'tax_category_ids IN [5]' ) ), $request->filters );
	}

	/**
	 * A product_cat clause on a mixed search: posts cannot match, so only products are queried.
	 */
	public function test_mixed_search_with_product_cat_clause_queries_products_only(): void {
		$query = $this->with_tax_query(
			$this->make_query( array( 's' => 'nebula' ) ),
			array( self::clause( 'product_cat', 'shoes', 'slug' ) )
		);

		$request = $this->translator( $this->no_constraints() )->translate( $query );

		self::assertNotNull( $request );
		self::assertSame( array( 'products' ), $request->logicals );
		self::assertSame( array( 'products' => array( 'tax_product_cat_ids IN [12]' ) ), $request->filters );
	}

	/**
	 * Federated queries need identical sort rules.
	 */
	public function test_mixed_search_with_different_sorts_is_not_intercepted(): void {
		$translator = $this->translator(
			$this->products(
				array(
					'filters' => array(),
					'sort'    => array(),
				)
			)
		);

		self::assertNull(
			$translator->translate(
				$this->make_query(
					array(
						's'       => 'nebula',
						'orderby' => 'date',
					)
				)
			)
		);
	}

	/**
	 * Equal sort rules are accepted.
	 */
	public function test_mixed_search_with_equal_sorts(): void {
		$request = $this->translator(
			$this->products(
				array(
					'filters' => array(),
					'sort'    => array( 'date:desc' ),
				)
			)
		)->translate(
			$this->make_query(
				array(
					's'       => 'nebula',
					'orderby' => 'date',
				)
			)
		);

		self::assertNotNull( $request );
		self::assertSame(
			array(
				'content'  => array( 'date:desc' ),
				'products' => array( 'date:desc' ),
			),
			$request->sort
		);
	}

	/**
	 * Author, meta and date constraints are not filterable on the products index.
	 */
	public function test_products_with_author_meta_or_date_are_not_intercepted(): void {
		$translator = $this->translator(
			$this->products(
				array(
					'filters' => array(),
					'sort'    => array(),
				)
			)
		);
		$extra      = array(
			array( 'author' => '3' ),
			array( 'date_query' => array( array( 'after' => '2024-01-01' ) ) ),
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Fixture.
					array(
						'key'   => 'price',
						'value' => '1',
					),
				),
			),
		);
		foreach ( $extra as $vars ) {
			self::assertNull(
				$translator->translate(
					$this->make_query(
						array_merge(
							array(
								's'         => 'shoe',
								'post_type' => 'product',
							),
							$vars
						)
					)
				)
			);
		}
	}

	/**
	 * Products follow the same private-posts rule.
	 */
	public function test_product_search_by_user_who_can_read_private_products_is_not_intercepted(): void {
		$this->private_caps = array( 'product' => 'read_private_products' );
		$this->user_caps    = array( 'read_private_products' );

		self::assertNull(
			$this->translator( $this->no_constraints() )->translate(
				$this->make_query(
					array(
						's'         => 'shoe',
						'post_type' => 'product',
					)
				)
			)
		);
	}
}
