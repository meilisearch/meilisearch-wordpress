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
use Meilisearch\WordPress\Tests\Integration\WooCommerce\CreatesProducts;

require_once __DIR__ . '/WooCommerce/CreatesProducts.php';

/**
 * Real WP_Query searches answered by the test Meilisearch (spec § 12.2 SearchTest).
 *
 * @group search
 */
final class SearchTest extends TestCase {

	use CreatesProducts;

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
	 * Path suffix (/search or /multi-search) of the last search request.
	 *
	 * @var string
	 */
	private string $last_search_path = '';

	/**
	 * 25 matching posts + 1 other, indexed; search replacement on; breaker closed.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( '1' === getenv( 'MEILISEARCH_TEST_WC' ) ) {
			// Default searches include `product` (spec § 9.1); products must be indexed for them to be intercepted.
			$this->enable_products();
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
			$this->last_search_path = str_ends_with( (string) $url, '/multi-search' ) ? '/multi-search' : '/search';
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
	 * The content index is marked populated by the reindex in set_up(); until then (a new connection
	 * clears it), searches fall back to MySQL instead of returning zero results.
	 */
	public function test_unpopulated_index_falls_back_to_mysql(): void {
		$options = Plugin::instance()->get( 'options' );
		self::assertTrue( $options->is_populated( 'content' ) );
		$options->clear_populated();

		$this->go_to( home_url( '/?s=nebula' ) );

		self::assertNotTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 0, $this->search_requests );
		self::assertNotEmpty( $this->result_ids(), 'MySQL answers the search.' );
	}

	/**
	 * The host, admin key or prefix changed without the connection flow (here: the site was connected with
	 * another host, as when MEILISEARCH_HOST is edited in wp-config.php): the populated flag describes the
	 * previous connection's indexes, so MySQL answers and a reindex is refused until connect() runs again.
	 */
	public function test_connection_changed_outside_connect_falls_back_to_mysql_until_reconnected(): void {
		$plugin  = Plugin::instance();
		$options = $plugin->get( 'options' );
		$options->set_state( 'fingerprint', md5( 'http://previous-host.test:7700|' . $options->admin_key() . '|' . $plugin->get( 'names' )->prefix() ) );
		self::assertSame( array( 'content' ), array_values( array_intersect( array( 'content' ), (array) $options->state( 'populated' ) ) ) );

		$this->go_to( home_url( '/?s=nebula' ) );

		self::assertNotTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 0, $this->search_requests );
		self::assertNotEmpty( $this->result_ids(), 'MySQL answers the search.' );

		try {
			$plugin->get( 'reindexer' )->start( 'content' );
			self::fail( 'A reindex must be refused until the connection flow runs.' );
		} catch ( \RuntimeException $error ) {
			self::assertStringContainsString( 'wp meilisearch connect', $error->getMessage() );
		}

		$plugin->get( 'index_manager' )->connect();
		self::assertFalse( $options->connection_changed() );
		self::assertFalse( $options->is_populated( 'content' ), 'connect() clears the flag of the previous connection.' );
		$plugin->get( 'reindexer' )->run_sync( 'content', 200, static function (): void {} );
		if ( '1' === getenv( 'MEILISEARCH_TEST_WC' ) ) {
			$plugin->get( 'reindexer' )->run_sync( 'products', 200, static function (): void {} );
		}

		$this->go_to( home_url( '/?s=nebula' ) );
		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
	}

	/**
	 * Ticking a post type and a taxonomy in the Content tab: Meilisearch holds neither the existing posts
	 * of the type nor the taxonomy field until a full reindex, so MySQL answers until one has completed.
	 */
	public function test_newly_indexed_type_and_taxonomy_are_answered_by_mysql_until_reindexed(): void {
		register_post_type( 'meili_book', array( 'public' => true ) );
		try {
			$tagged = $this->matching[0];
			wp_set_post_tags( $tagged, array( 'scifi' ) );
			$book = self::factory()->post->create(
				array(
					'post_title' => 'Quasar atlas',
					'post_type'  => 'meili_book',
				)
			);

			update_option(
				Options::CONTENT,
				array(
					'post_types' => array( 'post', 'page', 'meili_book' ),
					'taxonomies' => array(
						'post'       => array( 'category', 'post_tag' ),
						'meili_book' => array(),
					),
					'meta_keys'  => array(),
				)
			);
			$this->wait_for_tasks();
			$this->search_requests = 0;

			$this->go_to( home_url( '/?s=quasar' ) );
			self::assertNotTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ), 'The index holds no book yet.' );
			self::assertContains( $book, $this->result_ids() );

			$this->go_to( home_url( '/?s=nebula&tag=scifi&post_type=post' ) );
			self::assertNotTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ), 'No document has the tag field yet.' );
			self::assertContains( $tagged, $this->result_ids() );
			self::assertSame( 0, $this->search_requests );

			Plugin::instance()->get( 'reindexer' )->run_sync( 'content', 200, static function (): void {} );

			$this->go_to( home_url( '/?s=nebula&tag=scifi&post_type=post' ) );
			self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
			self::assertSame( array( $tagged ), $this->result_ids() );
			$this->go_to( home_url( '/?s=quasar' ) );
			self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
			self::assertContains( $book, $this->result_ids() );
		} finally {
			unregister_post_type( 'meili_book' );
		}
	}

	/**
	 * Theme search results come from Meilisearch, in its ranking order.
	 */
	public function test_results_come_from_meilisearch_in_ranking_order(): void {
		$this->go_to( home_url( '/?s=nebula' ) );

		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 1, $this->search_requests );
		// Content + products federate through /multi-search once products are indexed.
		self::assertSame( '1' === getenv( 'MEILISEARCH_TEST_WC' ) ? '/multi-search' : '/search', $this->last_search_path );
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
	 * Meilisearch counts every match but serves only the first pagination.maxTotalHits (1000) of
	 * an index. The totals stop there: every advertised page has posts, and a query for all posts
	 * reports the number it returns.
	 */
	public function test_totals_stop_at_the_hits_the_engine_serves(): void {
		for ( $i = 1; $i <= 1020; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'   => 'Quasar note ' . $i,
					'post_content' => 'x',
				)
			);
		}
		Plugin::instance()->get( 'reindexer' )->run_sync(
			'content',
			500,
			static function (): void {}
		);
		$this->wait_for_tasks();

		$this->go_to( home_url( '/?s=quasar' ) );

		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 1000, $this->main_query()->found_posts );
		self::assertSame( 100, $this->main_query()->max_num_pages );
		self::assertCount( 10, $this->main_query()->posts );

		$this->go_to( home_url( '/?s=quasar&paged=100' ) );

		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 100, $this->main_query()->max_num_pages );
		self::assertCount( 10, $this->main_query()->posts, 'The last advertised page has posts.' );
		self::assertFalse( is_404() );

		$all = new \WP_Query(
			array(
				's'                   => 'quasar',
				'posts_per_page'      => -1,
				'meilisearch'         => true,
				'post_type'           => 'post',
				'ignore_sticky_posts' => true,
			)
		);

		self::assertTrue( $all->get( Interceptor::QUERY_FLAG ) );
		self::assertCount( 1000, $all->posts );
		self::assertSame( 1000, $all->found_posts );
		self::assertSame( 1, $all->max_num_pages );
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

	/**
	 * Highlighting retrieves only the IDs; the cropped, highlighted content still comes back in _formatted.
	 */
	public function test_highlighted_search_returns_only_the_cropped_content(): void {
		update_option(
			Options::SEARCH,
			array_merge(
				Options::defaults()[ Options::SEARCH ],
				array(
					'replace'   => true,
					'highlight' => true,
				)
			)
		);

		$this->go_to( home_url( '/?s=clouds' ) );

		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		$ids = $this->result_ids();
		self::assertNotEmpty( $ids );
		$formatted = Plugin::instance()->get( 'result_mapper' )->formatted( $ids[0] );
		self::assertIsArray( $formatted );
		self::assertStringContainsString( '<mark>clouds</mark>', (string) $formatted['content'] );
		self::assertArrayNotHasKey( 'title', $formatted );
	}

	/**
	 * Mixed author inclusion and exclusion: like core, only the exclusion applies.
	 */
	public function test_author_exclusion_wins_over_inclusion_like_mysql(): void {
		$a = self::factory()->user->create();
		$b = self::factory()->user->create();
		wp_update_post(
			array(
				'ID'          => $this->matching[0],
				'post_author' => $a,
			)
		);
		wp_update_post(
			array(
				'ID'          => $this->matching[1],
				'post_author' => $b,
			)
		);
		Plugin::instance()->get( 'reindexer' )->run_sync(
			'content',
			200,
			static function (): void {}
		);
		$url = home_url( '/?s=nebula&author=' . $a . ',-' . $b );

		update_option( Options::SEARCH, array_merge( Options::defaults()[ Options::SEARCH ], array( 'replace' => false ) ) );
		$this->go_to( $url );
		self::assertNotTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		$mysql = $this->main_query()->found_posts;

		update_option( Options::SEARCH, array_merge( Options::defaults()[ Options::SEARCH ], array( 'replace' => true ) ) );
		$this->go_to( $url );
		self::assertTrue( $this->main_query()->get( Interceptor::QUERY_FLAG ) );
		self::assertSame( 24, $mysql );
		self::assertSame( $mysql, $this->main_query()->found_posts );
	}

	/**
	 * A CHAR meta comparison against a non-canonical numeric string ("02134") matches the post like MySQL.
	 */
	public function test_meta_filter_matches_non_canonical_numeric_strings(): void {
		update_option(
			Options::CONTENT,
			array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array( 'post' => array( 'category' ) ),
				'meta_keys'  => array( 'post' => array( 'zip' ) ),
			)
		);
		update_post_meta( $this->matching[0], 'zip', '02134' );
		update_post_meta( $this->matching[1], 'zip', '+5' );
		$plugin = Plugin::instance();
		$plugin->get( 'index_manager' )->ensure_all();
		$plugin->get( 'reindexer' )->run_sync(
			'content',
			200,
			static function (): void {}
		);

		foreach ( array(
			'02134' => $this->matching[0],
			'+5'    => $this->matching[1],
		) as $zip => $expected ) {
			$args  = array(
				's'                   => 'nebula',
				'post_type'           => 'post',
				'ignore_sticky_posts' => true,
				'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Test query.
					array(
						'key'   => 'zip',
						'value' => (string) $zip,
					),
				),
			);
			$mysql = new \WP_Query( $args );
			$meili = new \WP_Query( array_merge( $args, array( 'meilisearch' => true ) ) );

			self::assertNotTrue( $mysql->get( Interceptor::QUERY_FLAG ) );
			self::assertTrue( $meili->get( Interceptor::QUERY_FLAG ), (string) $zip );
			self::assertSame( array( $expected ), array_map( 'intval', wp_list_pluck( $mysql->posts, 'ID' ) ), (string) $zip );
			self::assertSame( array( $expected ), array_map( 'intval', wp_list_pluck( $meili->posts, 'ID' ) ), (string) $zip );
		}
	}
}
