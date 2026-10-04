<?php
/**
 * Meilisearch API error.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * An error returned by Meilisearch, raised by the transport, or detected by the client.
 */
final class ApiError extends \RuntimeException {

	/**
	 * Creates the error.
	 *
	 * @param string          $message     Human-readable message.
	 * @param string          $error_code  Meilisearch error code (e.g. 'index_not_found') or a plugin code.
	 * @param int             $http_status HTTP status; 0 when no response was received.
	 * @param \Throwable|null $previous    Previous throwable.
	 */
	public function __construct( string $message, public readonly string $error_code, public readonly int $http_status = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $http_status, $previous );
	}

	/**
	 * Builds the error for a non-2xx response.
	 *
	 * @param Response $response Response.
	 * @return self
	 */
	public static function from_response( Response $response ): self {
		$body = $response->body ?? array();
		$code = isset( $body['code'] ) && is_string( $body['code'] ) && '' !== $body['code'] ? $body['code'] : 'http_' . $response->status;

		if ( isset( $body['message'] ) && is_string( $body['message'] ) && '' !== $body['message'] ) {
			$message = $body['message'];
		} else {
			$message = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Meilisearch returned an unexpected HTTP %d response.', 'meilisearch' ),
				$response->status
			);
		}

		return new self( $message, $code, $response->status );
	}

	/**
	 * Builds the error for a network failure or timeout.
	 *
	 * @param string $message Transport error message.
	 * @return self
	 */
	public static function transport( string $message ): self {
		return new self( $message, 'transport_error', 0 );
	}
}
