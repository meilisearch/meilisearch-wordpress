<?php
/**
 * Tests for the Client endpoint methods.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Api;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\Task;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class ClientEndpointsTest extends TestCase {

	private const HOST = 'https://ms.example.com/meili';

	private FakeTransport $transport;

	private Client $client;

	protected function set_up(): void {
		parent::set_up();
		$this->transport = new FakeTransport();
		$this->client    = new Client( $this->transport, self::HOST, 'admin-key', 'UA' );
	}

	private function enqueued( int $task_uid ): void {
		$this->transport->respond(
			202,
			[
				'taskUid'    => $task_uid,
				'indexUid'   => 'wp_abc_content',
				'status'     => 'enqueued',
				'type'       => 'documentAdditionOrUpdate',
				'enqueuedAt' => '2026-10-01T00:00:00Z',
			]
		);
	}

	/**
	 * Asserts the last request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Path and query after the host.
	 * @param mixed  $body    Expected decoded body.
	 * @param float  $timeout Expected timeout.
	 */
	private function assert_request( string $method, string $path, $body = null, float $timeout = Client::WRITE_TIMEOUT ): void {
		$request = $this->transport->last();
		$this->assertSame( $method, $request['method'] );
		$this->assertSame( self::HOST . $path, $request['url'] );
		$this->assertSame( $body, $request['body'] );
		$this->assertSame( $timeout, $request['timeout'] );
	}

	public function test_create_key_posts_the_payload(): void {
		$payload = [
			'name'      => 'WordPress search (https://example.com)',
			'actions'   => [ 'search' ],
			'indexes'   => [ 'wp_abc_content', 'wp_abc_products' ],
			'expiresAt' => null,
		];
		$this->transport->respond(
			201,
			[
				'key' => 'k-123',
				'uid' => '6062abda-a5aa-4414-ac91-ecd7944c0f8d',
			]
		);

		$key = $this->client->create_key( $payload );

		$this->assertSame( 'k-123', $key['key'] );
		$this->assert_request( 'POST', '/keys', $payload );
		$this->assertStringContainsString( '"expiresAt":null', (string) $this->transport->last()['raw_body'] );
	}

	public function test_get_key_and_delete_key(): void {
		$this->transport->respond( 200, [ 'actions' => [ 'search' ] ] )->respond( 204 );

		$this->assertSame( [ 'actions' => [ 'search' ] ], $this->client->get_key( 'abc-uid' ) );
		$this->assert_request( 'GET', '/keys/abc-uid' );

		$this->client->delete_key( 'abc-uid' );
		$this->assert_request( 'DELETE', '/keys/abc-uid' );
	}

	public function test_index_exists(): void {
		$this->transport->respond( 200, [ 'uid' => 'wp_abc_content' ] );

		$this->assertTrue( $this->client->index_exists( 'wp_abc_content' ) );
		$this->assert_request( 'GET', '/indexes/wp_abc_content' );
	}

	public function test_index_exists_is_false_for_index_not_found(): void {
		$this->transport->respond(
			404,
			[
				'message' => 'Index `wp_abc_content` not found.',
				'code'    => 'index_not_found',
			]
		);

		$this->assertFalse( $this->client->index_exists( 'wp_abc_content' ) );
	}

	public function test_index_exists_rethrows_other_errors(): void {
		$this->transport->respond(
			403,
			[
				'message' => 'The provided API key is invalid.',
				'code'    => 'invalid_api_key',
			]
		);

		$this->expectException( ApiError::class );
		$this->expectExceptionMessage( 'The provided API key is invalid.' );

		$this->client->index_exists( 'wp_abc_content' );
	}

	public function test_create_and_delete_index_return_tasks(): void {
		$this->enqueued( 7 );
		$this->enqueued( 8 );

		$created = $this->client->create_index( 'wp_abc_content' );
		$this->assertInstanceOf( Task::class, $created );
		$this->assertSame( 7, $created->uid );
		$this->assert_request(
			'POST',
			'/indexes',
			[
				'uid'        => 'wp_abc_content',
				'primaryKey' => 'id',
			]
		);

		$this->assertSame( 8, $this->client->delete_index( 'wp_abc_content' )->uid );
		$this->assert_request( 'DELETE', '/indexes/wp_abc_content' );
	}

	public function test_list_indexes_returns_results(): void {
		$this->transport->respond(
			200,
			[
				'results' => [ [ 'uid' => 'a' ], [ 'uid' => 'b' ] ],
				'offset'  => 0,
				'limit'   => 1000,
				'total'   => 2,
			]
		);

		$this->assertSame( [ [ 'uid' => 'a' ], [ 'uid' => 'b' ] ], $this->client->list_indexes() );
		$this->assert_request( 'GET', '/indexes?limit=1000' );
	}

	public function test_index_stats_and_settings(): void {
		$this->transport->respond( 200, [ 'numberOfDocuments' => 3 ] )->respond( 200, [ 'searchableAttributes' => [ '*' ] ] );

		$this->assertSame( [ 'numberOfDocuments' => 3 ], $this->client->index_stats( 'wp_abc_content' ) );
		$this->assert_request( 'GET', '/indexes/wp_abc_content/stats' );

		$this->assertSame( [ 'searchableAttributes' => [ '*' ] ], $this->client->get_settings( 'wp_abc_content' ) );
		$this->assert_request( 'GET', '/indexes/wp_abc_content/settings' );
	}

	public function test_update_settings_patches(): void {
		$this->enqueued( 9 );
		$settings = [ 'filterableAttributes' => [ 'post_type' ] ];

		$this->assertSame( 9, $this->client->update_settings( 'wp_abc_content', $settings )->uid );
		$this->assert_request( 'PATCH', '/indexes/wp_abc_content/settings', $settings );
	}

	public function test_add_documents_sets_the_primary_key(): void {
		$this->enqueued( 10 );
		$documents = [
			[
				'id'    => 1,
				'price' => 49.0,
			],
		];

		$this->client->add_documents( 'wp_abc_content', $documents );

		$this->assert_request( 'POST', '/indexes/wp_abc_content/documents?primaryKey=id', $documents );
		$this->assertStringContainsString( '"price":49.0', (string) $this->transport->last()['raw_body'] );
	}

	public function test_delete_documents_and_delete_all(): void {
		$this->enqueued( 11 );
		$this->enqueued( 12 );

		$this->client->delete_documents(
			'wp_abc_content',
			[
				3 => 5,
				9 => 8,
			]
		);
		$this->assert_request( 'POST', '/indexes/wp_abc_content/documents/delete-batch', [ 5, 8 ] );

		$this->client->delete_all_documents( 'wp_abc_content' );
		$this->assert_request( 'DELETE', '/indexes/wp_abc_content/documents' );
	}

	public function test_fetch_documents_returns_the_full_response(): void {
		$response = [
			'results' => [ [ 'id' => 3 ], [ 'id' => 9 ] ],
			'offset'  => 0,
			'limit'   => 2,
			'total'   => 40,
		];
		$body     = [
			'fields' => [ 'id' ],
			'filter' => 'id > 2',
			'sort'   => [ 'id:asc' ],
			'limit'  => 2,
			'offset' => 0,
		];
		$this->transport->respond( 200, $response );

		$this->assertSame( $response, $this->client->fetch_documents( 'wp_abc_content', $body ) );
		$this->assert_request( 'POST', '/indexes/wp_abc_content/documents/fetch', $body );
	}

	public function test_get_task_and_get_tasks(): void {
		$this->transport->respond(
			200,
			[
				'uid'    => 4,
				'status' => 'succeeded',
			]
		)->respond(
			200,
			[
				'results' => [],
				'total'   => 0,
			]
		);

		$this->assertSame( 'succeeded', $this->client->get_task( 4 )['status'] );
		$this->assert_request( 'GET', '/tasks/4' );

		$response = $this->client->get_tasks(
			[
				'statuses'  => [ 'failed', 'canceled' ],
				'indexUids' => [ 'wp_abc_content', 'wp_abc_products' ],
				'limit'     => 20,
				'reverse'   => true,
			]
		);
		$this->assertSame(
			[
				'results' => [],
				'total'   => 0,
			],
			$response
		);
		$this->assert_request( 'GET', '/tasks?statuses=failed%2Ccanceled&indexUids=wp_abc_content%2Cwp_abc_products&limit=20&reverse=true' );
	}

	public function test_search_uses_the_search_timeout(): void {
		$this->transport->respond(
			200,
			[
				'hits'       => [ [ 'id' => 1 ] ],
				'totalHits'  => 1,
				'totalPages' => 1,
			]
		)->respond( 200, [ 'hits' => [] ] );

		$result = $this->client->search( 'wp_abc_content', [ 'q' => 'hello' ] );
		$this->assertSame( 1, $result['totalHits'] );
		$this->assert_request( 'POST', '/indexes/wp_abc_content/search', [ 'q' => 'hello' ], Client::SEARCH_TIMEOUT );

		$this->client->search( 'wp_abc_content', [], 0.5 );
		$this->assertSame( '{}', $this->transport->last()['raw_body'] );
		$this->assertSame( 0.5, $this->transport->last()['timeout'] );
	}

	public function test_multi_search_with_and_without_federation(): void {
		$queries = [
			[
				'indexUid' => 'wp_abc_content',
				'q'        => 'shoe',
			],
		];
		$this->transport->respond( 200, [ 'results' => [] ] )->respond( 200, [ 'hits' => [] ] )->respond( 200, [ 'hits' => [] ] );

		$this->client->multi_search( $queries );
		$this->assert_request( 'POST', '/multi-search', [ 'queries' => $queries ], Client::SEARCH_TIMEOUT );

		$this->client->multi_search(
			$queries,
			[
				'page'        => 2,
				'hitsPerPage' => 10,
			]
		);
		$this->assert_request(
			'POST',
			'/multi-search',
			[
				'queries'    => $queries,
				'federation' => [
					'page'        => 2,
					'hitsPerPage' => 10,
				],
			],
			Client::SEARCH_TIMEOUT
		);

		$this->client->multi_search( $queries, [] );
		$this->assertStringContainsString( '"federation":{}', (string) $this->transport->last()['raw_body'] );
	}

	public function test_missing_task_uid_is_an_invalid_response(): void {
		$this->transport->respond( 202, [ 'status' => 'enqueued' ] );

		try {
			$this->client->delete_index( 'wp_abc_content' );
			$this->fail( 'ApiError was not thrown.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'invalid_response', $error->error_code );
			$this->assertSame( 202, $error->http_status );
		}
	}

	public function test_unencodable_body_is_rejected_before_sending(): void {
		try {
			$this->client->add_documents( 'wp_abc_content', [ [ 'title' => "\xB1\x31" ] ] );
			$this->fail( 'ApiError was not thrown.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'encode_error', $error->error_code );
			$this->assertSame( [], $this->transport->requests );
		}
	}

	public function test_path_segments_are_encoded(): void {
		$this->transport->respond( 200, [] );

		$this->client->get_key( 'a/b c' );

		$this->assert_request( 'GET', '/keys/a%2Fb%20c' );
	}

	public function test_non_finite_number_is_rejected_before_sending(): void {
		try {
			$this->client->add_documents( 'wp_abc_content', [ [ 'price' => NAN ] ] );
			$this->fail( 'ApiError was not thrown.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'encode_error', $error->error_code );
			$this->assertSame( 0, $error->http_status );
			$this->assertSame( [], $this->transport->requests );
		}
	}

	public function test_get_tasks_percent_encodes_scalar_values(): void {
		$this->transport->respond( 200, [ 'results' => [] ] );

		$this->client->get_tasks(
			[
				'afterEnqueuedAt' => '2026-10-01T00:00:00Z',
				'types'           => [ 'documentAdditionOrUpdate' ],
				'canceledBy'      => [ 3, 4 ],
			]
		);

		$this->assert_request( 'GET', '/tasks?afterEnqueuedAt=2026-10-01T00%3A00%3A00Z&types=documentAdditionOrUpdate&canceledBy=3%2C4' );
	}
}
