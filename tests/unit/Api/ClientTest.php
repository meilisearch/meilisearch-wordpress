<?php
/**
 * Tests for the Client request core.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Api;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class ClientTest extends TestCase {

	private FakeTransport $transport;

	protected function set_up(): void {
		parent::set_up();
		$this->transport = new FakeTransport();
	}

	private function client( string $host = 'https://ms.example.com', string $key = 'admin-key' ): Client {
		return new Client( $this->transport, $host, $key, 'Meilisearch-WordPress/1.0.0 WordPress/7.1.2' );
	}

	public function test_version_sends_an_authenticated_get(): void {
		$this->transport->respond(
			200,
			[
				'commitSha'  => 'abc',
				'commitDate' => '2026-09-01T00:00:00Z',
				'pkgVersion' => '1.34.0',
			]
		);

		$version = $this->client()->version();
		$request = $this->transport->last();

		$this->assertSame( '1.34.0', $version['pkgVersion'] );
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'https://ms.example.com/version', $request['url'] );
		$this->assertSame(
			[
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'Meilisearch-WordPress/1.0.0 WordPress/7.1.2',
				'Authorization' => 'Bearer admin-key',
			],
			$request['headers']
		);
		$this->assertNull( $request['raw_body'] );
		$this->assertSame( Client::WRITE_TIMEOUT, $request['timeout'] );
	}

	/**
	 * @dataProvider provide_prefixed_hosts
	 */
	public function test_path_prefix_is_preserved( string $host ): void {
		$this->transport->respond( 200, [ 'pkgVersion' => '1.34.0' ] );

		$this->client( $host )->version();

		$this->assertSame( 'https://example.com/meili/version', $this->transport->last()['url'] );
	}

	public static function provide_prefixed_hosts(): array {
		return [
			'prefix without trailing slash' => [ 'https://example.com/meili' ],
			'prefix with trailing slash'    => [ 'https://example.com/meili/' ],
			'prefix with double slash'      => [ 'https://example.com/meili//' ],
		];
	}

	public function test_empty_key_sends_no_authorization_header(): void {
		$this->transport->respond( 200, [ 'pkgVersion' => '1.34.0' ] );

		$this->client( 'http://localhost:7700', '' )->version();

		$this->assertArrayNotHasKey( 'Authorization', $this->transport->last()['headers'] );
	}

	public function test_error_body_maps_to_api_error(): void {
		$this->transport->respond(
			403,
			[
				'message' => 'The provided API key is invalid.',
				'code'    => 'invalid_api_key',
				'type'    => 'auth',
				'link'    => 'https://docs.meilisearch.com/errors#invalid_api_key',
			]
		);

		try {
			$this->client()->version();
			$this->fail( 'ApiError was not thrown.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'invalid_api_key', $error->error_code );
			$this->assertSame( 403, $error->http_status );
			$this->assertSame( 'The provided API key is invalid.', $error->getMessage() );
		}
	}

	public function test_non_json_error_falls_back_to_the_http_status(): void {
		$this->transport->respond_raw( 502, '<html>Bad Gateway</html>' );

		try {
			$this->client()->version();
			$this->fail( 'ApiError was not thrown.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'http_502', $error->error_code );
			$this->assertSame( 502, $error->http_status );
			$this->assertSame( 'Meilisearch returned an unexpected HTTP 502 response.', $error->getMessage() );
		}
	}

	public function test_transport_errors_propagate(): void {
		$this->transport->fail( ApiError::transport( 'cURL error 28: Operation timed out' ) );

		$this->expectException( ApiError::class );
		$this->expectExceptionMessage( 'cURL error 28: Operation timed out' );

		$this->client()->version();
	}

	public function test_non_json_success_body_yields_an_empty_array(): void {
		$this->transport->respond_raw( 200, 'OK' );

		$this->assertSame( [], $this->client()->version() );
	}
}
