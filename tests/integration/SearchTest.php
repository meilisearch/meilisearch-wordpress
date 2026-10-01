<?php
/**
 * Search integration tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Search\CircuitBreaker;
use Meilisearch\WordPress\Search\Interceptor;
use Meilisearch\WordPress\Settings\Options;

/**
 * Real WP_Query searches answered by the test Meilisearch (spec § 12.2 SearchTest).
 *
 * @group search
 */
final class SearchTest extends TestCase {

	/**
	 * IDs of the 25 "Nebula report" posts, oldest first.
	 *
	 * @var int[]
	 */
	private array $matching = array();

	/**
	 * Category holding the first five matching posts.
	 *
	 * @var int
	 */
	private int $category = 0;

	/**
	 * Search requests sent to Meilisearch through wp_remote_request().
	 *
	 * @var int
	 */
	private int $search_requests = 0;

	/**
	 * 25 matching posts + 1 other, indexed; search replacement on; breaker closed.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( '1' === getenv( 'MEILISEARCH_TEST_WC' ) ) {
			$this->markTestSkipped( 'Product search lands in Task 22; SearchTest under WooCommerce is re-enabled there.' );
		}
		update_option( 'posts_per_page', 10 );
		update_option(
			Options::CONTENT,
			array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array( 'post' => array( 'category' ) ),
				'meta_keys'  => array(),
			)
		);
		update_option( Options::SEARCH, array_merge( Options::defaults()[ Options::SEARCH ], array( 'replace' => true ) ) );
		delete_transient( CircuitBreaker::TRANSIENT );

		$this->category = self::factory()->category->create( array( 'name' => 'Astronomy' ) );
		$this->matching = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$id = self::factory()->post->create(
				array(
					'post_title'   => sprintf( 'Nebula report %02d', $i ),
					'post_content' => str_repeat( 'Observation notes about gas clouds. ', $i ),
					'post_date'    => sprintf( '2024-01-%02d 10:00:00', $i ),
				)
			);
			if ( $i <= 5 ) {
				wp_set_post_categories( $id, array( $this->category ) );
			}
			$this->matching[] = $id;
		}
		self::factory()->post->create(
			array(
				'post_title'   => 'Unrelated gardening tips',
				'post_content' => 'Tomatoes need sun.',
			)
		);

		$plugin = Plugin::instance();
		$plugin->get( 'index_manager' )->ensure_all();
		$plugin->get( 'reindexer' )->run_sync(
			'content',
			200,
			static function (): void {}
		);

		$this->search_requests = 0;
		add_filter( 'pre_http_request', array( $this, 'count_search_requests' ), 1, 3 );
	}

	/**
	 * Removes the HTTP filters and closes the breaker.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'count_search_requests' ), 1 );
		remove_filter( 'pre_http_request', array( $this, 'refuse_search_requests' ), 10 );
		delete_transient( CircuitBreaker::TRANSIENT );
		parent::tear_down();
	}

	/**
	 * Counts Meilisearch search calls; lets the request through.
	 *
	 * @param false|array|\WP_Error $pre  Short-circuit value.
	 * @param array                 $args Request arguments.
	 * @param string                $url  URL.
	 * @return false|array|\WP_Error
	 */
	public function count_search_requests( $pre, $args, $url ) {
		if ( str_ends_with( (string) $url, '/search' ) || str_ends_with( (string) $url, '/multi-search' ) ) {
			++$this->search_requests;
		}
		return $pre;
	}

	/**
	 * Simulates Meilisearch being unreachable for search calls.
	 *
	 * @param false|array|\WP_Error $pre  Short-circuit value.
	 * @param array                 $args Request arguments.
	 * @param string                $url  URL.
	 * @return false|array|\WP_Error
	 */
	public function refuse_search_requests( $pre, $args, $url ) {
		if ( str_ends_with( (string) $url, '/search' ) || str_ends_with( (string) $url, '/multi-search' ) ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to meilisearch port 7700' );
		}
		return $pre;
	}

	/**
	 * The main query.
	 *
	 * @return \WP_Query
	 */
	private function main_query(): \WP_Query {
		return $GLOBALS['wp_query'];
	}

	/**
	 * IDs of the posts of the main query.
	 *
	 * @return int[]
	 */
	private function result_ids(): array {
		return array_map( 'intval', wp_list_pluck( $this->main_query()->posts, 'ID' ) );
	}

	/**
	 * Theme search results come from Meilisearch, in its ranking order.
	 */
	public function test_results_come_from_meilisearch_in_ranking_order(): void {
		$this->go_to( home_url( '/?s=nebula' ) );

		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 1, $this->search_requests );
		$results = $this->result_ids();

		$plugin   = Plugin::instance();
		$response = $plugin->get( 'clients' )->client()->search(
			$plugin->get( 'names' )->uid( 'content' ),
			array(
				'q'                    => 'nebula',
				'page'                 => 1,
				'hitsPerPage'          => 10,
				'attributesToRetrieve' => array( 'id' ),
			)
		);
		$expected = array_map(
			static function ( array $hit ): int {
				return (int) $hit['id'];
			},
			$response['hits']
		);
		self::assertSame( $expected, $results );
	}

	/**
	 * A typo MySQL cannot match proves Meilisearch answered.
	 */
	public function test_typo_tolerant_search(): void {
		$this->go_to( home_url( '/?s=nebulla' ) );

		self::assertSame( 25, $this->main_query()->found_posts );
		self::assertCount( 10, $this->main_query()->posts );
	}

	/**
	 * The found_posts and max_num_pages totals are set; pages partition the results.
	 */
	public function test_pagination_totals(): void {
		$seen = array();
		foreach ( array(
			1 => 10,
			2 => 10,
			3 => 5,
		) as $page => $count ) {
			$this->go_to( home_url( '/?s=nebula&paged=' . $page ) );

			self::assertSame( 25, $this->main_query()->found_posts );
			self::assertSame( 3, $this->main_query()->max_num_pages );
			self::assertCount( $count, $this->main_query()->posts );
			$seen = array_merge( $seen, $this->result_ids() );
		}
		self::assertEqualsCanonicalizing( $this->matching, $seen );
	}

	/**
	 * Review Focus #3: paged past the end → no posts, real totals, no warnings, and the same
	 * template decision (is_404 / is_search) WordPress makes for that URL without Meilisearch.
	 */
	public function test_page_beyond_results(): void {
		update_option( Options::SEARCH, array_merge( Options::defaults()[ Options::SEARCH ], array( 'replace' => false ) ) );
		$this->go_to( home_url( '/?s=nebula&paged=99' ) );
		$mysql_is_404    = is_404();
		$mysql_is_search = is_search();
		update_option( Options::SEARCH, array_merge( Options::defaults()[ Options::SEARCH ], array( 'replace' => true ) ) );

		$this->go_to( home_url( '/?s=nebula&paged=99' ) );

		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( array(), $this->main_query()->posts );
		self::assertSame( 25, $this->main_query()->found_posts );
		self::assertSame( 3, $this->main_query()->max_num_pages );
		self::assertSame( $mysql_is_search, is_search() ); // WP::handle_404() resets the flags of a 404.
		self::assertFalse( have_posts() );
		self::assertSame( $mysql_is_404, is_404() );
	}

	/**
	 * URL taxonomy vars (cat) reach Meilisearch through $query->tax_query.
	 */
	public function test_category_query_var_filters_results(): void {
		$this->go_to( home_url( '/?s=nebula&cat=' . $this->category ) );

		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 5, $this->main_query()->found_posts );
		self::assertEqualsCanonicalizing( array_slice( $this->matching, 0, 5 ), $this->result_ids() );
	}

	/**
	 * An untranslatable query (post__in) runs on MySQL without calling Meilisearch.
	 */
	public function test_untranslatable_query_falls_back_to_mysql(): void {
		$query = new \WP_Query(
			array(
				's'           => 'nebula',
				'post__in'    => array( $this->matching[0], $this->matching[1] ),
				'meilisearch' => true,
			)
		);

		self::assertNotTrue( $query->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 2, $query->found_posts );
		self::assertEqualsCanonicalizing( array( $this->matching[0], $this->matching[1] ), array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) ) );
		self::assertSame( 0, $this->search_requests );
	}

	/**
	 * Meilisearch unreachable → MySQL results, error logged, breaker open; the next search skips
	 * Meilisearch entirely.
	 */
	public function test_unreachable_meilisearch_falls_back_and_opens_breaker(): void {
		add_filter( 'pre_http_request', array( $this, 'refuse_search_requests' ), 10, 3 );
		$plugin = Plugin::instance();

		$this->go_to( home_url( '/?s=nebula' ) );

		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 1, $this->search_requests );
		self::assertSame( 25, $this->main_query()->found_posts );
		self::assertCount( 10, $this->main_query()->posts );
		self::assertTrue( $plugin->get( 'circuit_breaker' )->is_open() );
		self::assertSame( 'search', $plugin->get( 'error_log' )->all()[0]['context'] );

		$started = microtime( true );
		$this->go_to( home_url( '/?s=nebula' ) );

		self::assertNotTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 1, $this->search_requests );
		self::assertSame( 25, $this->main_query()->found_posts );
		self::assertLessThan( 1.0, microtime( true ) - $started );
	}
}
