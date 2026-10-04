<?php
/**
 * Pins the FakeTransport API other tests rely on.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Support;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Response;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class FakeTransportTest extends TestCase {

	public function test_queue_returns_responses_and_throws_errors_in_order(): void {
		$response  = new Response( 200, [ 'ok' => true ], '{"ok":true}' );
		$error     = ApiError::transport( 'down' );
		$transport = ( new FakeTransport() )->queue( $response )->queue( $error );

		$this->assertSame( 2, $transport->pending() );
		$this->assertSame( $response, $transport->send( 'GET', 'http://ms.test/a', [], null, 2.0 ) );

		try {
			$transport->send( 'POST', 'http://ms.test/b', [ 'X' => '1' ], '{"q":"x"}', 15.0 );
			$this->fail( 'The queued error was not thrown.' );
		} catch ( ApiError $thrown ) {
			$this->assertSame( $error, $thrown );
		}
		$this->assertSame( 0, $transport->pending() );
	}

	public function test_requests_records_every_call(): void {
		$transport = ( new FakeTransport() )->respond( 204 )->respond( 200, [] );
		$transport->send( 'DELETE', 'http://ms.test/keys/k', [], null, 15.0 );
		$transport->send( 'POST', 'http://ms.test/search', [ 'Content-Type' => 'application/json' ], '{"q":"x"}', 2.0 );

		$this->assertSame(
			[
				[
					'method'   => 'DELETE',
					'url'      => 'http://ms.test/keys/k',
					'headers'  => [],
					'body'     => null,
					'raw_body' => null,
					'timeout'  => 15.0,
				],
				[
					'method'   => 'POST',
					'url'      => 'http://ms.test/search',
					'headers'  => [ 'Content-Type' => 'application/json' ],
					'body'     => [ 'q' => 'x' ],
					'raw_body' => '{"q":"x"}',
					'timeout'  => 2.0,
				],
			],
			$transport->requests()
		);
		$this->assertSame( $transport->requests, $transport->requests() );
		$this->assertSame( 'POST', $transport->last()['method'] );
	}

	public function test_empty_queue_throws(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'FakeTransport: no response queued for GET http://ms.test/version.' );

		( new FakeTransport() )->send( 'GET', 'http://ms.test/version', [], null, 2.0 );
	}
}
