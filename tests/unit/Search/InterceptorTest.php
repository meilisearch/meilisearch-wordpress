<?php
/**
 * Interceptor tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Api\Response;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Search\CircuitBreaker;
use Meilisearch\WordPress\Search\Interceptor;
use Meilisearch\WordPress\Search\QueryTranslator;
use Meilisearch\WordPress\Search\ResultMapper;
use Meilisearch\WordPress\Search\Searcher;
use Meilisearch\WordPress\Search\SearchResult;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Decision (pre_get_posts) and replacement (posts_pre_query).
 *
 * @covers \Meilisearch\WordPress\Search\Interceptor
 */
final class InterceptorTest extends TestCase {

	use SearchStubs;

	/**
	 * Fake transport.
	 *
	 * @var FakeTransport
	 */
	private FakeTransport $transport;

	/**
	 * Result mapper shared with the interceptor.
	 *
	 * @var ResultMapper
	 */
	private ResultMapper $mapper;

	/**
	 * Front-end request, replace enabled, configured, breaker closed.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_search_options();
		$this->transport = new FakeTransport();
		$this->mapper    = new ResultMapper();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_is_serving_rest_request' )->justReturn( false );
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post' => 'post',
				'page' => 'page',
			)
		);
	}

	/**
	 * Interceptor wired with real collaborators over the fake transport.
	 *
	 * @return Interceptor
	 */
	private function interceptor(): Interceptor {
		$options = new Options();
		return new Interceptor(
			new QueryTranslator( $options, new Indexability( $options ) ),
			new Searcher( new ClientFactory( $options, $this->transport ), new IndexNames( $options ) ),
			$this->mapper,
			new CircuitBreaker(),
			$options,
			new ErrorLog()
		);
	}

	/**
	 * The theme's main search query.
	 *
	 * @return \WP_Query
	 */
	private function main_search(): \WP_Query {
		return $this->make_query(
			array(
				's'         => 'nebula',
				'post_type' => 'post',
			)
		);
	}

	/**
	 * Hooks and priorities.
	 */
	public function test_register_hooks(): void {
		$interceptor = $this->interceptor();
		$interceptor->register();

		self::assertSame( 20, has_action( 'pre_get_posts', array( $interceptor, 'on_pre_get_posts' ) ) );
		self::assertSame( 10, has_filter( 'posts_pre_query', array( $interceptor, 'on_posts_pre_query' ) ) );
	}

	/**
	 * The main search query is flagged.
	 */
	public function test_main_search_query_is_flagged(): void {
		$query = $this->main_search();

		$this->interceptor()->on_pre_get_posts( $query );

		self::assertTrue( $query->get( Interceptor::QUERY_FLAG ) );
	}

	/**
	 * Each § 9.1 condition blocks interception.
	 *
	 * @dataProvider blocked_cases
	 *
	 * @param string $condition Condition to break.
	 */
	public function test_conditions_block_interception( string $condition ): void {
		$query = $this->main_search();
		switch ( $condition ) {
			case 'not main':
				$query->main_query = false;
				break;
			case 'not search':
				$query->search = false;
				break;
			case 'admin':
				Functions\when( 'is_admin' )->justReturn( true );
				break;
			case 'rest':
				Functions\when( 'wp_is_serving_rest_request' )->justReturn( true );
				break;
			case 'replace off':
				$this->stub_search_options( array( Options::SEARCH => array( 'replace' => false ) ) );
				break;
			case 'not configured':
				$this->stub_search_options( array( Options::ADMIN_KEY => '' ) );
				break;
			case 'breaker open':
				$this->transients[ CircuitBreaker::TRANSIENT ] = 1;
				break;
			case 'untranslatable':
				$query->set( 'post__in', array( 1, 2 ) );
				break;
			case 'filter veto':
				Filters\expectApplied( 'meilisearch_should_intercept' )->once()->with( true, $query )->andReturn( false );
				break;
		}

		self::assertFalse( $this->interceptor()->should_intercept( $query ) );
	}

	/**
	 * Conditions.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function blocked_cases(): array {
		$conditions = array();
		foreach ( array( 'not main', 'not search', 'admin', 'rest', 'replace off', 'not configured', 'breaker open', 'untranslatable', 'filter veto' ) as $condition ) {
			$conditions[ $condition ] = array( $condition );
		}
		return $conditions;
	}

	/**
	 * A developer opt-in ('meilisearch' => true) works on any query, even with replace off.
	 */
	public function test_opt_in_query_is_flagged(): void {
		$this->stub_search_options( array( Options::SEARCH => array( 'replace' => false ) ) );
		$query = $this->make_query(
			array(
				's'           => 'nebula',
				'meilisearch' => true,
			),
			false,
			false
		);

		$this->interceptor()->on_pre_get_posts( $query );

		self::assertTrue( $query->get( Interceptor::QUERY_FLAG ) );
	}

	/**
	 * A previously flagged query object that no longer qualifies is unflagged.
	 */
	public function test_flag_is_cleared_when_query_no_longer_qualifies(): void {
		$query = $this->main_search();
		$query->set( Interceptor::QUERY_FLAG, true );
		$query->set( 's', '   ' );

		$this->interceptor()->on_pre_get_posts( $query );

		self::assertFalse( $query->get( Interceptor::QUERY_FLAG ) );
	}

	/**
	 * A flagged query gets the posts in Meilisearch order and the pagination totals.
	 */
	public function test_flagged_query_returns_posts_and_totals(): void {
		$posts = array(
			12 => new \WP_Post( array( 'ID' => 12 ) ),
			7  => new \WP_Post( array( 'ID' => 7 ) ),
		);
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) use ( $posts ) {
				return $posts[ $id ] ?? null;
			}
		);
		$this->transport->queue(
			new Response(
				200,
				array(
					'hits'       => array( array( 'id' => 12 ), array( 'id' => 7 ) ),
					'totalHits'  => 12,
					'totalPages' => 2,
				),
				''
			)
		);
		$interceptor = $this->interceptor();
		$query       = $this->main_search();
		$interceptor->on_pre_get_posts( $query );

		$result = $interceptor->on_posts_pre_query( null, $query );

		self::assertSame( array( $posts[12], $posts[7] ), $result );
		self::assertSame( 12, $query->found_posts );
		self::assertSame( 2, $query->max_num_pages );
		self::assertCount( 1, $this->transport->requests() );
	}

	/**
	 * Meilisearch down → null (WordPress runs MySQL), error logged, breaker opened.
	 */
	public function test_api_error_falls_back_and_trips_breaker(): void {
		$this->transport->queue( ApiError::transport( 'cURL error 28: Operation timed out' ) );
		$interceptor = $this->interceptor();
		$query       = $this->main_search();
		$interceptor->on_pre_get_posts( $query );

		self::assertNull( $interceptor->on_posts_pre_query( null, $query ) );
		self::assertSame( 1, $this->transients[ CircuitBreaker::TRANSIENT ] );
		self::assertSame( 'search', $this->option_store[ Options::LOG ][0]['context'] );
		self::assertSame( 'cURL error 28: Operation timed out', $this->option_store[ Options::LOG ][0]['message'] );

		$next = $this->main_search();
		$interceptor->on_pre_get_posts( $next );
		self::assertNotTrue( $next->get( Interceptor::QUERY_FLAG ) );
		self::assertNull( $interceptor->on_posts_pre_query( null, $next ) );
		self::assertCount( 1, $this->transport->requests() );
	}

	/**
	 * Unflagged queries and queries already answered by another plugin are untouched.
	 */
	public function test_unflagged_or_answered_queries_are_untouched(): void {
		$interceptor = $this->interceptor();
		$answered    = array( new \WP_Post( array( 'ID' => 3 ) ) );

		self::assertNull( $interceptor->on_posts_pre_query( null, $this->main_search() ) );

		$flagged = $this->main_search();
		$flagged->set( Interceptor::QUERY_FLAG, true );
		self::assertSame( $answered, $interceptor->on_posts_pre_query( $answered, $flagged ) );
		self::assertSame( array(), $this->transport->requests() );
	}

	/**
	 * A query made untranslatable after the flag (e.g. by a later pre_get_posts callback)
	 * falls back without calling Meilisearch.
	 */
	public function test_query_changed_after_flag_falls_back(): void {
		$interceptor = $this->interceptor();
		$query       = $this->main_search();
		$interceptor->on_pre_get_posts( $query );
		$query->set( 'post__in', array( 4 ) );

		self::assertNull( $interceptor->on_posts_pre_query( null, $query ) );
		self::assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Each main query starts with an empty highlight map.
	 */
	public function test_main_query_resets_the_mapper(): void {
		$this->mapper->apply( new \WP_Query( array() ), new SearchResult( array(), 0, 0, array( 5 => array( 'content' => 'x' ) ) ) );
		$query         = $this->main_search();
		$query->search = false;

		$this->interceptor()->on_pre_get_posts( $query );

		self::assertNull( $this->mapper->formatted( 5 ) );
	}

	/**
	 * Values other than null or an array, or a missing query, come back unchanged.
	 */
	public function test_posts_pre_query_returns_unexpected_values_unchanged(): void {
		$query = $this->main_search();
		$query->set( Interceptor::QUERY_FLAG, true );
		$interceptor = $this->interceptor();

		self::assertSame( 'oops', $interceptor->on_posts_pre_query( 'oops', $query ) );
		self::assertSame( 0, $interceptor->on_posts_pre_query( 0, $query ) );
		self::assertSame( array( 1 ), $interceptor->on_posts_pre_query( array( 1 ), $query ) );
		self::assertNull( $interceptor->on_posts_pre_query( null, 'not a query' ) );
		self::assertNull( $interceptor->on_posts_pre_query( null ) );
		self::assertSame( array(), $this->transport->requests() );
	}
}
