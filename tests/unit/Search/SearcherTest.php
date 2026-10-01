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
			'title'   => '<mark>Nebula</mark> report',
			'content' => '…the <mark>nebula</mark> was…',
		);
		$this->transport->queue(
			self::ok(
				array(
					'hits'       => array(
						array(
							'id'         => 12,
							'title'      => 'Nebula report',
							'content'    => 'x',
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
				'attributesToRetrieve'  => array( 'id', 'title', 'content' ),
				'attributesToCrop'      => array( 'content:30' ),
				'attributesToHighlight' => array( 'title', 'content' ),
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
	 * Transport errors propagate as ApiError.
	 */
	public function test_api_error_propagates(): void {
		$this->transport->queue( ApiError::transport( 'Operation timed out' ) );

		$this->expectException( ApiError::class );
		$this->searcher()->execute( new SearchRequest( array( 'content' ), 'x', 1, 10, array(), array(), null, false ) );
	}
}
