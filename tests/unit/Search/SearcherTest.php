<?php
/**
 * Searcher tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Brain\Monkey\Filters;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Api\Response;
use Meilisearch\WordPress\Search\Searcher;
use Meilisearch\WordPress\Search\SearchRequest;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Exact request bodies for single-index and federated searches.
 *
 * @covers \Meilisearch\WordPress\Search\Searcher
 */
final class SearcherTest extends TestCase {

	use SearchStubs;

	/**
	 * Fake transport.
	 *
	 * @var FakeTransport
	 */
	private FakeTransport $transport;

	/**
	 * Installs stubs (prefix wp_test → wp_test_content / wp_test_products).
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_search_options();
		$this->transport = new FakeTransport();
	}

	/**
	 * Searcher over the fake transport.
	 *
	 * @return Searcher
	 */
	private function searcher(): Searcher {
		$options = new Options();
		return new Searcher( new ClientFactory( $options, $this->transport ), new IndexNames( $options ) );
	}

	/**
	 * A 200 response.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return Response
	 */
	private static function ok( array $body ): Response {
		return new Response( 200, $body, '' );
	}

	/**
	 * Single index: page mode, joined filters, sort, id only.
	 */
	public function test_single_index_request(): void {
		$this->transport->queue(
			self::ok(
				array(
					'hits'        => array( array( 'id' => 12 ), array( 'id' => 7 ) ),
					'query'       => 'hello',
					'page'        => 2,
					'hitsPerPage' => 10,
					'totalHits'   => 25,
					'totalPages'  => 3,
				)
			)
		);
		$request = new SearchRequest(
			array( 'content' ),
			'hello',
			2,
			10,
			array( 'content' => array( 'post_type IN ["post"]', 'author_id IN [3]' ) ),
			array( 'content' => array( 'date:desc' ) ),
			null,
			false
		);

		$result = $this->searcher()->execute( $request );

		$sent = $this->transport->requests();
		self::assertCount( 1, $sent );
		self::assertSame( 'POST', $sent[0]['method'] );
		self::assertStringEndsWith( '/indexes/wp_test_content/search', $sent[0]['url'] );
		self::assertSame( 2.0, $sent[0]['timeout'] );
		self::assertSame(
			array(
				'q'                    => 'hello',
				'page'                 => 2,
				'hitsPerPage'          => 10,
				'filter'               => '(post_type IN ["post"]) AND (author_id IN [3])',
				'sort'                 => array( 'date:desc' ),
				'attributesToRetrieve' => array( 'id' ),
			),
			$sent[0]['body']
		);
		self::assertSame( array( 12, 7 ), $result->ids );
		self::assertSame( 25, $result->total_hits );
		self::assertSame( 3, $result->total_pages );
		self::assertSame( array(), $result->formatted );
	}

	/**
	 * Highlighting and hybrid parameters; _formatted is collected by post ID.
	 */
	public function test_single_index_with_highlight_and_hybrid(): void {
		$formatted = array(
			'id'      => '12',
			'content' => '…the <mark>nebula</mark> was…',
		);
		$this->transport->queue(
			self::ok(
				array(
					'hits'       => array(
						array(
							'id'         => 12,
							'_formatted' => $formatted,
						),
					),
					'totalHits'  => 1,
					'totalPages' => 1,
				)
			)
		);
		$request = new SearchRequest(
			array( 'content' ),
			'nebula',
			1,
			10,
			array( 'content' => array() ),
			array( 'content' => array() ),
			array(
				'embedder'      => 'default',
				'semanticRatio' => 0.7,
			),
			true
		);

		$result = $this->searcher()->execute( $request );

		self::assertSame(
			array(
				'q'                     => 'nebula',
				'page'                  => 1,
				'hitsPerPage'           => 10,
				'hybrid'                => array(
					'embedder'      => 'default',
					'semanticRatio' => 0.7,
				),
				'attributesToRetrieve'  => array( 'id' ),
				'attributesToCrop'      => array( 'content:30' ),
				'attributesToHighlight' => array( 'content' ),
				'highlightPreTag'       => '<mark>',
				'highlightPostTag'      => '</mark>',
			),
			$this->transport->requests()[0]['body']
		);
		self::assertSame( array( 12 => $formatted ), $result->formatted );
	}

	/**
	 * Several indexes: one federated multi-search, page/hitsPerPage on the federation only, exact totals.
	 */
	public function test_federated_request(): void {
		$this->transport->queue(
			self::ok(
				array(
					'hits'        => array(
						array(
							'id'          => 41,
							'_federation' => array(
								'indexUid'             => 'wp_test_products',
								'queriesPosition'      => 1,
								'weightedRankingScore' => 0.9,
							),
						),
						array(
							'id'          => 7,
							'_federation' => array(
								'indexUid'             => 'wp_test_content',
								'queriesPosition'      => 0,
								'weightedRankingScore' => 0.8,
							),
						),
					),
					'page'        => 2,
					'hitsPerPage' => 5,
					'totalHits'   => 12,
					'totalPages'  => 3,
				)
			)
		);
		$request = new SearchRequest(
			array( 'content', 'products' ),
			'shoe',
			2,
			5,
			array(
				'content'  => array( 'post_type IN ["post", "page"]' ),
				'products' => array( 'in_stock = true' ),
			),
			array(
				'content'  => array( 'date:desc' ),
				'products' => array( 'date:desc' ),
			),
			null,
			false
		);

		$result = $this->searcher()->execute( $request );

		$sent = $this->transport->requests();
		self::assertCount( 1, $sent );
		self::assertStringEndsWith( '/multi-search', $sent[0]['url'] );
		self::assertSame(
			array(
				array(
					'indexUid'             => 'wp_test_content',
					'q'                    => 'shoe',
					'filter'               => 'post_type IN ["post", "page"]',
					'sort'                 => array( 'date:desc' ),
					'attributesToRetrieve' => array( 'id' ),
				),
				array(
					'indexUid'             => 'wp_test_products',
					'q'                    => 'shoe',
					'filter'               => 'in_stock = true',
					'sort'                 => array( 'date:desc' ),
					'attributesToRetrieve' => array( 'id' ),
				),
			),
			$sent[0]['body']['queries']
		);
		self::assertSame(
			array(
				'page'        => 2,
				'hitsPerPage' => 5,
			),
			$sent[0]['body']['federation']
		);
		self::assertSame( array( 41, 7 ), $result->ids );
		self::assertSame( 12, $result->total_hits );
		self::assertSame( 3, $result->total_pages );
	}

	/**
	 * The meilisearch_search_params filter runs once per query; pagination keys added to a
	 * federated query are removed (the engine rejects them).
	 */
	public function test_search_params_filter_runs_per_query(): void {
		$this->transport->queue(
			self::ok(
				array(
					'hits'       => array(),
					'totalHits'  => 0,
					'totalPages' => 0,
				)
			)
		);
		$logicals = array();
		Filters\expectApplied( 'meilisearch_search_params' )
			->twice()
			->andReturnUsing(
				static function ( array $params, SearchRequest $request, string $logical ) use ( &$logicals ): array {
					$logicals[]                 = $logical;
					$params['showRankingScore'] = true;
					$params['limit']            = 3;
					return $params;
				}
			);
		$request = new SearchRequest(
			array( 'content', 'products' ),
			'x',
			1,
			10,
			array(),
			array(),
			null,
			false
		);

		$this->searcher()->execute( $request );

		self::assertSame( array( 'content', 'products' ), $logicals );
		foreach ( $this->transport->requests()[0]['body']['queries'] as $query ) {
			self::assertTrue( $query['showRankingScore'] );
			self::assertArrayNotHasKey( 'limit', $query );
		}
	}

	/**
	 * The timeout comes from the meilisearch_search_timeout filter.
	 */
	public function test_timeout_filter(): void {
		$this->transport->queue( self::ok( array( 'hits' => array() ) ) );
		Filters\expectApplied( 'meilisearch_search_timeout' )->once()->andReturn( 0.5 );

		$this->searcher()->execute( new SearchRequest( array( 'content' ), 'x', 1, 10, array(), array(), null, false ) );

		self::assertSame( 0.5, $this->transport->requests()[0]['timeout'] );
	}

	/**
	 * Malformed hits are skipped; missing totals fall back to the hits received.
	 */
	public function test_defensive_parsing(): void {
		$this->transport->queue(
			self::ok(
				array(
					'hits' => array( array( 'id' => '5' ), array( 'title' => 'no id' ), 'junk', array( 'id' => -1 ) ),
				)
			)
		);

		$result = $this->searcher()->execute( new SearchRequest( array( 'content' ), 'x', 1, 10, array(), array(), null, false ) );

		self::assertSame( array( 5 ), $result->ids );
		self::assertSame( 1, $result->total_hits );
		self::assertSame( 1, $result->total_pages );
	}

	/**
	 * Runs a one-page-of-ten search answered with the given totals.
	 *
	 * @param string[]             $logicals Logical indexes.
	 * @param int                  $page     Requested page.
	 * @param int                  $per_page Hits per page.
	 * @param array<string, mixed> $body     Response body.
	 * @return \Meilisearch\WordPress\Search\SearchResult
	 */
	private function totals( array $logicals, int $page, int $per_page, array $body ): \Meilisearch\WordPress\Search\SearchResult {
		$this->transport->queue( self::ok( $body ) );
		return $this->searcher()->execute( new SearchRequest( $logicals, 'x', $page, $per_page, array(), array(), null, false ) );
	}

	/**
	 * The engine counts every match but only serves the first pagination.maxTotalHits (1000 by
	 * default) of an index: the totals must not advertise pages that would come back empty.
	 */
	public function test_totals_are_clamped_to_max_total_hits(): void {
		$result = $this->totals(
			array( 'content' ),
			1,
			10,
			array(
				'hits'       => array( array( 'id' => 1 ) ),
				'totalHits'  => 1500,
				'totalPages' => 150,
			)
		);

		self::assertSame( 1000, $result->total_hits );
		self::assertSame( 100, $result->total_pages );
	}

	/**
	 * A last page that is cut by the limit still counts as a page; posts_per_page = -1 (1000 per
	 * page) reports what it returns.
	 */
	public function test_clamped_totals_round_pages_up(): void {
		$result = $this->totals(
			array( 'content' ),
			1,
			30,
			array(
				'hits'       => array( array( 'id' => 1 ) ),
				'totalHits'  => 1020,
				'totalPages' => 34,
			)
		);
		self::assertSame( 1000, $result->total_hits );
		self::assertSame( 34, $result->total_pages );

		$result = $this->totals(
			array( 'content' ),
			1,
			1000,
			array(
				'hits'       => array( array( 'id' => 1 ) ),
				'totalHits'  => 1020,
				'totalPages' => 2,
			)
		);
		self::assertSame( 1000, $result->total_hits );
		self::assertSame( 1, $result->total_pages );
	}

	/**
	 * The meilisearch_max_total_hits filter states the limit of an index whose
	 * pagination.maxTotalHits was changed in Meilisearch.
	 */
	public function test_max_total_hits_filter(): void {
		Filters\expectApplied( 'meilisearch_max_total_hits' )
			->once()
			->with( 1000, 'content', \Mockery::type( SearchRequest::class ) )
			->andReturn( 5000 );

		$result = $this->totals(
			array( 'content' ),
			1,
			10,
			array(
				'hits'       => array( array( 'id' => 1 ) ),
				'totalHits'  => 1500,
				'totalPages' => 150,
			)
		);

		self::assertSame( 1500, $result->total_hits );
		self::assertSame( 150, $result->total_pages );
	}

	/**
	 * A filtered limit that is not a positive number is ignored.
	 *
	 * @dataProvider invalid_limits
	 *
	 * @param mixed $limit Filtered value.
	 */
	public function test_invalid_max_total_hits_is_ignored( mixed $limit ): void {
		Filters\expectApplied( 'meilisearch_max_total_hits' )->once()->andReturn( $limit );

		$result = $this->totals(
			array( 'content' ),
			1,
			10,
			array(
				'hits'       => array( array( 'id' => 1 ) ),
				'totalHits'  => 1500,
				'totalPages' => 150,
			)
		);

		self::assertSame( 1000, $result->total_hits );
		self::assertSame( 100, $result->total_pages );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid_limits(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
			'string'   => array( 'many' ),
			'null'     => array( null ),
			'infinite' => array( INF ),
		);
	}

	/**
	 * A federated search serves at most maxTotalHits hits of each index, and the response does not
	 * say how many matches each index has: only the smallest limit is guaranteed to be servable.
	 */
	public function test_federated_totals_use_the_smallest_limit(): void {
		Filters\expectApplied( 'meilisearch_max_total_hits' )
			->twice()
			->andReturnUsing( static fn ( int $limit, string $logical ): int => 'products' === $logical ? 200 : 3000 );

		$result = $this->totals(
			array( 'content', 'products' ),
			1,
			10,
			array(
				'hits'       => array( array( 'id' => 1 ) ),
				'totalHits'  => 1800,
				'totalPages' => 180,
			)
		);

		self::assertSame( 200, $result->total_hits );
		self::assertSame( 20, $result->total_pages );
	}

	/**
	 * An empty page after the first proves that nothing from that offset on can be served,
	 * whatever the limit is believed to be.
	 */
	public function test_empty_page_bounds_the_totals(): void {
		Filters\expectApplied( 'meilisearch_max_total_hits' )->once()->andReturn( 5000 );

		$result = $this->totals(
			array( 'content' ),
			101,
			10,
			array(
				'hits'       => array(),
				'totalHits'  => 1500,
				'totalPages' => 150,
			)
		);

		self::assertSame( array(), $result->ids );
		self::assertSame( 1000, $result->total_hits );
		self::assertSame( 100, $result->total_pages );
	}

	/**
	 * A page that is simply past the end keeps the exact totals.
	 */
	public function test_page_past_the_end_keeps_exact_totals(): void {
		$result = $this->totals(
			array( 'content' ),
			5,
			10,
			array(
				'hits'       => array(),
				'totalHits'  => 25,
				'totalPages' => 3,
			)
		);

		self::assertSame( 25, $result->total_hits );
		self::assertSame( 3, $result->total_pages );
	}

	/**
	 * A 2xx answer without a hits list is an invalid response, not an empty result: the caller
	 * falls back to MySQL and trips the breaker.
	 *
	 * @dataProvider responses_without_hits
	 *
	 * @param array<string, mixed> $body Response body.
	 */
	public function test_response_without_hits_is_an_invalid_response( array $body ): void {
		$this->transport->queue( self::ok( $body ) );

		try {
			$this->searcher()->execute( new SearchRequest( array( 'content' ), 'x', 1, 10, array(), array(), null, false ) );
			self::fail( 'Expected an ApiError.' );
		} catch ( ApiError $e ) {
			self::assertSame( 'invalid_response', $e->error_code );
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function responses_without_hits(): array {
		return array(
			'no hits key'    => array( array( 'totalHits' => 3 ) ),
			'hits not array' => array( array( 'hits' => 'nope' ) ),
		);
	}

	/**
	 * Transport errors propagate as ApiError.
	 */
	public function test_api_error_propagates(): void {
		$this->transport->queue( ApiError::transport( 'Operation timed out' ) );

		$this->expectException( ApiError::class );
		$this->searcher()->execute( new SearchRequest( array( 'content' ), 'x', 1, 10, array(), array(), null, false ) );
	}
}
