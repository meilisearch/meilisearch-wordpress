<?php
/**
 * Tests for ApiError.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Api;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Response;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class ApiErrorTest extends TestCase {

	public function test_from_response_uses_meilisearch_code_and_message(): void {
		$error = ApiError::from_response(
			new Response(
				404,
				[
					'message' => 'Index `wp_abc_content` not found.',
					'code'    => 'index_not_found',
				],
				'{}'
			)
		);

		$this->assertSame( 'index_not_found', $error->error_code );
		$this->assertSame( 404, $error->http_status );
		$this->assertSame( 404, $error->getCode() );
		$this->assertSame( 'Index `wp_abc_content` not found.', $error->getMessage() );
	}

	public function test_from_response_without_body(): void {
		$error = ApiError::from_response( new Response( 500, null, '' ) );

		$this->assertSame( 'http_500', $error->error_code );
		$this->assertSame( 'Meilisearch returned an unexpected HTTP 500 response.', $error->getMessage() );
	}

	public function test_from_response_ignores_non_string_fields(): void {
		$error = ApiError::from_response(
			new Response(
				400,
				[
					'message' => [ 'nested' ],
					'code'    => 12,
				],
				'{}'
			)
		);

		$this->assertSame( 'http_400', $error->error_code );
		$this->assertSame( 'Meilisearch returned an unexpected HTTP 400 response.', $error->getMessage() );
	}

	public function test_transport_error(): void {
		$error = ApiError::transport( 'Connection refused' );

		$this->assertSame( 'transport_error', $error->error_code );
		$this->assertSame( 0, $error->http_status );
		$this->assertSame( 'Connection refused', $error->getMessage() );
	}

	public function test_previous_is_kept(): void {
		$previous = new \RuntimeException( 'root cause' );
		$error    = new ApiError( 'wrapped', 'task_failed', 0, $previous );

		$this->assertSame( $previous, $error->getPrevious() );
	}
}
