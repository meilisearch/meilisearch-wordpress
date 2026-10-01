<?php
/**
 * HTTP transport contract.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one HTTP request.
 */
interface Transport {

	/**
	 * Sends a request and returns the response, whatever its status.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Raw body, null for none.
	 * @param float                 $timeout Timeout in seconds.
	 * @return Response
	 * @throws ApiError Code 'transport_error', http_status 0, on network failure or timeout.
	 */
	public function send( string $method, string $url, array $headers, ?string $body, float $timeout ): Response;
}
