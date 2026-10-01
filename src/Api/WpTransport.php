<?php
/**
 * Transport over the WordPress HTTP API.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests with wp_remote_request(); redirects are never followed.
 */
final class WpTransport implements Transport {

	/**
	 * Sends a request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Raw body, null for none.
	 * @param float                 $timeout Timeout in seconds.
	 * @return Response
	 * @throws ApiError Code 'transport_error' on network failure or timeout.
	 */
	public function send( string $method, string $url, array $headers, ?string $body, float $timeout ): Response {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw ApiError::transport( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Transport message is data; display points escape it.
		}

		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = '' === $raw ? null : json_decode( $raw, true );

		return new Response( (int) wp_remote_retrieve_response_code( $response ), is_array( $decoded ) ? $decoded : null, $raw );
	}
}
