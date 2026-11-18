<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\WooCommerce;

use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Search\CircuitBreaker;
use Meilisearch\WordPress\Search\Interceptor;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Integration\TestCase;

require_once __DIR__ . '/CreatesProducts.php';

/**
 * Real main-query product searches. The query "shirtt" contains a typo that MySQL LIKE
 * cannot match, so any result proves Meilisearch answered the query.
 */
final class ProductSearchTest extends TestCase {

	use CreatesProducts;

	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$this->skip_without_woocommerce();

		$color = $this->create_attribute( 'color', array( 'Red', 'Blue' ) );
		$this->enable_products();
		update_option(
			Options::SEARCH,
			array(
				'replace'        => true,
				'highlight'      => false,
				'embedder'       => '',
				'semantic_ratio' => 0.5,
				'autocomplete'   => false,
			)
		);

		$tees      = wp_insert_term( 'Tees', 'product_cat', array( 'slug' => 'tees' ) );
		$in_tees   = array( 'category_ids' => array( (int) $tees['term_id'] ) );
		$this->ids = array(
			'alpha'   => $this->create_simple_product( 'Meili Shirt Alpha', '30', $in_tees, $color, array( 'Red' ) )->get_id(),
			'bravo'   => $this->create_simple_product( 'Meili Shirt Bravo', '10', array(), $color, array( 'Blue' ) )->get_id(),
			'charlie' => $this->create_simple_product( 'Meili Shirt Charlie', '20', $in_tees, $color, array( 'Red' ) )->get_id(),
			'hidden'  => $this->create_simple_product( 'Meili Shirt Hidden', '5', array( 'catalog_visibility' => 'hidden' ), $color, array( 'Red' ) )->get_id(),
		);
		$this->sync_products();
	}

	public function tear_down(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->remove_attributes();
			\WC_Query::reset_chosen_attributes();
		}
		delete_transient( CircuitBreaker::TRANSIENT );
		parent::tear_down();
	}

	/**
	 * Runs a front-end main query and returns the result IDs in order.
	 *
	 * @return list<int>
	 */
	private function search( array $args ): array {
		\WC_Query::reset_chosen_attributes();
		$defaults = array(
			's'         => 'shirtt',
			'post_type' => 'product',
		);
		$this->go_to( add_query_arg( array_merge( $defaults, $args ), home_url( '/' ) ) );
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	/**
	 * The same price-ordered product search answered by the database (interception vetoed).
	 *
	 * @return list<int>
	 */
	private function database_search( array $args ): array {
		add_filter( 'meilisearch_should_intercept', '__return_false' );
		try {
			return $this->search( array_merge( array( 's' => 'Meili Shirt' ), $args ) );
		} finally {
			remove_filter( 'meilisearch_should_intercept', '__return_false' );
		}
	}

	/**
	 * Asserts a price-ordered search with the given product-filter block parameters returns
	 * $expected from Meilisearch, and that the database returns the same.
	 *
	 * @param list<int> $expected Product IDs, cheapest first.
	 */
	private function assert_filtered_search( array $expected, array $args ): void {
		$args = array_merge( array( 'orderby' => 'price' ), $args );

		$this->assertSame( $expected, $this->database_search( $args ), 'Database result.' );
		$this->assertSame( $expected, $this->search( $args ), 'Meilisearch result.' );
		$this->assertTrue( $GLOBALS['wp_query']->get( Interceptor::QUERY_FLAG ), 'Answered by Meilisearch.' );
	}

	public function test_product_filter_block_stock_status_is_applied(): void {
		$sold = $this->create_simple_product( 'Meili Shirt Sold', '25', array( 'stock_status' => 'outofstock' ) )->get_id();
		$this->sync_products();

		$this->assert_filtered_search( array( $this->ids['bravo'], $this->ids['charlie'], $this->ids['alpha'] ), array( 'filter_stock_status' => 'instock' ) );
		$this->assert_filtered_search( array( $sold ), array( 'filter_stock_status' => 'outofstock' ) );
		$this->assert_filtered_search(
			array( $this->ids['bravo'], $this->ids['charlie'], $sold, $this->ids['alpha'] ),
			array( 'filter_stock_status' => 'instock,outofstock' )
		);
	}

	public function test_product_filter_block_categories_include_child_categories(): void {
		$tees  = get_term_by( 'slug', 'tees', 'product_cat' );
		$child = wp_insert_term(
			'Long Sleeve',
			'product_cat',
			array(
				'slug'   => 'long-sleeve',
				'parent' => (int) $tees->term_id,
			)
		);
		$delta = $this->create_simple_product( 'Meili Shirt Delta', '15', array( 'category_ids' => array( (int) $child['term_id'] ) ) )->get_id();
		$this->sync_products();

		$this->assert_filtered_search( array( $delta, $this->ids['charlie'], $this->ids['alpha'] ), array( 'categories' => 'tees' ) );
		$this->assert_filtered_search( array( $delta ), array( 'categories' => 'long-sleeve' ) );
		// An unknown slug next to a known one is dropped, as WooCommerce does.
		$this->assert_filtered_search( array( $delta ), array( 'categories' => 'long-sleeve,unknown' ) );
	}

	public function test_product_filter_block_tags_combine_with_categories(): void {
		$summer = wp_insert_term( 'Summer', 'product_tag', array( 'slug' => 'summer' ) );
		wp_set_object_terms( $this->ids['alpha'], array( (int) $summer['term_id'] ), 'product_tag' );
		wp_set_object_terms( $this->ids['bravo'], array( (int) $summer['term_id'] ), 'product_tag' );
		$this->sync_products();

		$this->assert_filtered_search( array( $this->ids['bravo'], $this->ids['alpha'] ), array( 'tags' => 'summer' ) );
		$this->assert_filtered_search(
			array( $this->ids['alpha'] ),
			array(
				'tags'       => 'summer',
				'categories' => 'tees',
			)
		);
	}

	/**
	 * @dataProvider untranslated_block_filters
	 */
	public function test_product_filter_block_parameter_that_cannot_be_translated_falls_back_to_mysql( array $args ): void {
		if ( isset( $args['brands'] ) && ! taxonomy_exists( 'product_brand' ) ) {
			$this->markTestSkipped( 'This WooCommerce has no product_brand taxonomy.' );
		}
		$args = array_merge(
			array(
				's'       => 'Meili Shirt',
				'orderby' => 'price',
			),
			$args
		);

		$expected = $this->database_search( $args );
		$actual   = $this->search( $args );

		$this->assertNotTrue( $GLOBALS['wp_query']->get( Interceptor::QUERY_FLAG ) );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function untranslated_block_filters(): array {
		return array(
			'unknown stock status'  => array( array( 'filter_stock_status' => 'nonsense' ) ),
			'unknown category slug' => array( array( 'categories' => 'unknown' ) ),
			'brand (not indexed)'   => array( array( 'brands' => 'acme' ) ),
		);
	}

	public function test_orderby_price_returns_products_in_price_order(): void {
		$ids = $this->search( array( 'orderby' => 'price' ) );

		$this->assertSame( array( $this->ids['bravo'], $this->ids['charlie'], $this->ids['alpha'] ), $ids );
		$this->assertSame( 3, (int) $GLOBALS['wp_query']->found_posts );
	}

	public function test_orderby_price_desc(): void {
		$this->assertSame( array( $this->ids['alpha'], $this->ids['charlie'], $this->ids['bravo'] ), $this->search( array( 'orderby' => 'price-desc' ) ) );
	}

	public function test_min_and_max_price_filter(): void {
		$ids = $this->search(
			array(
				'orderby'   => 'price',
				'min_price' => '15',
				'max_price' => '35',
			)
		);

		$this->assertSame( array( $this->ids['charlie'], $this->ids['alpha'] ), $ids );
	}

	public function test_layered_nav_attribute_filter(): void {
		$ids = $this->search(
			array(
				'orderby'      => 'price',
				'filter_color' => 'red',
			)
		);

		$this->assertSame( array( $this->ids['charlie'], $this->ids['alpha'] ), $ids );
	}

	public function test_filter_on_an_attribute_added_after_the_index_falls_back_without_opening_the_breaker(): void {
		delete_transient( CircuitBreaker::TRANSIENT );
		Plugin::instance()->get( 'error_log' )->clear();

		// The merchant adds a global attribute once the index exists and has not reindexed yet:
		// attr_material is not in the index's filterableAttributes.
		$material = $this->create_attribute( 'material', array( 'Cotton' ) );
		$cotton   = $this->create_simple_product( 'Meili Shirt Delta', '40', array(), $material, array( 'Cotton' ) )->get_id();
		$this->sync_products();
		$this->assertTrue( Plugin::instance()->get( 'options' )->needs_reindex( 'products' ) );

		$ids = $this->search(
			array(
				's'               => 'Meili Shirt',
				'filter_material' => 'cotton',
			)
		);

		$this->assertSame( array( $cotton ), $ids, 'MySQL answers the filtered search' );
		$this->assertFalse( get_transient( CircuitBreaker::TRANSIENT ), 'the circuit breaker stays closed' );
		$this->assertSame( array(), Plugin::instance()->get( 'error_log' )->all() );
		$this->assertSame(
			array( $this->ids['bravo'], $this->ids['charlie'], $this->ids['alpha'], $cotton ),
			$this->search( array( 'orderby' => 'price' ) ),
			'other searches are still answered by Meilisearch'
		);
	}

	/**
	 * The merchant selects an attribute the existing documents were built without, then starts the reindex
	 * the notice asks for. The run clears the "needs reindex" flag when it starts, but until it has rewritten
	 * every document a filter on that attribute would only match the documents sent so far.
	 */
	public function test_filter_on_a_newly_selected_attribute_falls_back_until_the_reindex_has_completed(): void {
		$options = Plugin::instance()->get( 'options' );
		$fabric  = array_merge( $options->woocommerce(), array( 'attributes' => array( 'pa_color' ) ) );
		update_option( Options::WOOCOMMERCE, $fabric );
		$material = $this->create_attribute( 'material', array( 'Cotton' ) );
		$cotton   = $this->create_simple_product( 'Meili Shirt Delta', '40', array(), $material, array( 'Cotton' ) )->get_id();
		$this->sync_products();
		$this->assertArrayNotHasKey( 'attr_material', $this->product_document( $cotton ), 'Built while material was not selected.' );

		// WooCommerce tab: material selected (the tab flags a products reindex), then the reindex is started.
		update_option( Options::WOOCOMMERCE, array_merge( $fabric, array( 'attributes' => array( 'pa_color', 'pa_material' ) ) ) );
		$options->flag_reindex( 'products', true );
		Plugin::instance()->get( 'reindexer' )->start( 'products' );
		$this->wait_for_tasks();
		$this->assertFalse( $options->needs_reindex( 'products' ) );
		delete_transient( CircuitBreaker::TRANSIENT );

		$args = array(
			's'               => 'Meili Shirt',
			'filter_material' => 'cotton',
		);
		$this->assertSame( array( $cotton ), $this->search( $args ), 'The database answers while the run is in progress.' );
		$this->assertNotTrue( $GLOBALS['wp_query']->get( Interceptor::QUERY_FLAG ) );
		$this->assertFalse( get_transient( CircuitBreaker::TRANSIENT ), 'the circuit breaker stays closed' );

		$this->run_actions();
		$this->wait_for_tasks();
		$this->assertSame( 'done', Plugin::instance()->get( 'reindexer' )->status( 'products' )['status'] ?? null );
		$this->assertSame( array( $cotton ), $this->search( array_merge( $args, array( 's' => 'shirtt' ) ) ), 'Meilisearch answers once every document has the field.' );
		$this->assertTrue( $GLOBALS['wp_query']->get( Interceptor::QUERY_FLAG ) );
	}

	public function test_product_category_clause_is_translated_by_the_query_translator(): void {
		$ids = $this->search(
			array(
				'orderby'     => 'price',
				'product_cat' => 'tees',
			)
		);

		$this->assertSame( array( $this->ids['charlie'], $this->ids['alpha'] ), $ids );
	}

	public function test_hidden_product_never_appears(): void {
		$this->assertNotContains( $this->ids['hidden'], $this->search( array() ) );
		$this->assertNotContains( $this->ids['hidden'], $this->search( array( 's' => 'meili shirtt hidden' ) ) );
	}

	public function test_search_without_a_woocommerce_product_query_falls_back_to_mysql(): void {
		$query = new \WP_Query(
			array(
				's'           => 'Meili Shirt',
				'post_type'   => 'product',
				'orderby'     => 'price',
				'order'       => 'ASC',
				'meilisearch' => true,
			)
		);

		$this->assertNotTrue( $query->get( Interceptor::QUERY_FLAG ) );
		$this->assertSame( '', (string) $query->get( 'wc_query' ) );
		$this->assertEqualsCanonicalizing( array_values( $this->ids ), array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) ) );
	}

	public function test_non_finite_price_bound_falls_back_to_mysql(): void {
		global $wpdb;
		// WooCommerce writes the overflowing bound into its own SQL as INF, which MySQL rejects.
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$this->search(
				array(
					's'         => 'Meili Shirt',
					'min_price' => '1e999',
				)
			);
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		$this->assertNotTrue( $GLOBALS['wp_query']->get( Interceptor::QUERY_FLAG ) );
	}
}
