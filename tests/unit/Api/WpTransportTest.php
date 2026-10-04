<?php
/**
 * Tests for WpTransport.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\WpTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class WpTransportTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);
	}

	public function test_send_passes_method_headers_body_timeout_and_no_redirects(): void {
		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				'https://ms.example.com/indexes',
				[
					'method'      => 'POST',
					'headers'     => [ 'Content-Type' => 'application/json' ],
					'timeout'     => 15.0,
					'redirection' => 0,
					'body'        => '{"uid":"wp_abc_content"}',
				]
			)
			->andReturn(
				[
					'response' => [
						'code'    => 202,
						'message' => 'Accepted',
					],
					'body'     => '{"taskUid":3}',
				]
			);

		$response = ( new WpTransport() )->send( 'POST', 'https://ms.example.com/indexes', [ 'Content-Type' => 'application/json' ], '{"uid":"wp_abc_content"}', 15.0 );

		$this->assertSame( 202, $response->status );
		$this->assertSame( [ 'taskUid' => 3 ], $response->body );
		$this->assertSame( '{"taskUid":3}', $response->raw );
	}

	public function test_send_without_body_omits_the_body_argument(): void {
		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				'https://ms.example.com/keys/abc',
				[
					'method'      => 'DELETE',
					'headers'     => [],
					'timeout'     => 2.0,
					'redirection' => 0,
				]
			)
			->andReturn(
				[
					'response' => [ 'code' => 204 ],
					'body'     => '',
				]
			);

		$response = ( new WpTransport() )->send( 'DELETE', 'https://ms.example.com/keys/abc', [], null, 2.0 );

		$this->assertSame( 204, $response->status );
		$this->assertNull( $response->body );
		$this->assertSame( '', $response->raw );
	}

	public function test_non_json_body_gives_a_null_body(): void {
		Functions\when( 'wp_remote_request' )->justReturn(
			[
				'response' => [ 'code' => 502 ],
				'body'     => '<html>Bad Gateway</html>',
			]
		);

		$response = ( new WpTransport() )->send( 'GET', 'https://ms.example.com/version', [], null, 15.0 );

		$this->assertSame( 502, $response->status );
		$this->assertNull( $response->body );
		$this->assertSame( '<html>Bad Gateway</html>', $response->raw );
	}

	public function test_wp_error_becomes_a_transport_error(): void {
		Functions\when( 'wp_remote_request' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		try {
			( new WpTransport() )->send( 'GET', 'https://ms.example.com/version', [], null, 2.0 );
			$this->fail( 'ApiError was not thrown.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'transport_error', $error->error_code );
			$this->assertSame( 0, $error->http_status );
			$this->assertSame( 'cURL error 28: Operation timed out', $error->getMessage() );
		}
	}
}
