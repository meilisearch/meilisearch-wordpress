<?php
/**
 * Typed Meilisearch client.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Typed calls to the Meilisearch HTTP API.
 */
final class Client {

	public const SEARCH_TIMEOUT = 2.0;
	public const WRITE_TIMEOUT  = 15.0;

	/**
	 * Base URL without trailing slash; may contain a path prefix.
	 *
	 * @var string
	 */
	private readonly string $host;

	/**
	 * Creates the client.
	 *
	 * @param Transport $transport  HTTP transport.
	 * @param string    $host       Base URL, e.g. https://ms-123.meilisearch.io or https://example.com/meili.
	 * @param string    $api_key    API key; '' sends no Authorization header.
	 * @param string    $user_agent User-Agent header value.
	 */
	public function __construct( private readonly Transport $transport, string $host, private readonly string $api_key, private readonly string $user_agent ) {
		$this->host = rtrim( $host, '/' );
	}

	/**
	 * GET /version.
	 *
	 * @return array<string, mixed> {commitSha, commitDate, pkgVersion}.
	 * @throws ApiError On any failure.
	 */
	public function version(): array {
		return $this->request( 'GET', '/version' )->body ?? array();
	}

	/**
	 * Sends a request and maps non-2xx responses to ApiError.
	 *
	 * @param string                                  $method  HTTP method.
	 * @param string                                  $path    Path starting with '/', appended to the host verbatim.
	 * @param array<string, string|int>|null          $query   Query parameters.
	 * @param array<int|string, mixed>|\stdClass|null $body    JSON body.
	 * @param float                                   $timeout Timeout in seconds.
	 * @return Response
	 * @throws ApiError On encoding, transport or HTTP failure.
	 */
	private function request( string $method, string $path, ?array $query = null, array|\stdClass|null $body = null, float $timeout = self::WRITE_TIMEOUT ): Response {
		$url = $this->host . $path;
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => $this->user_agent,
		);
		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$payload = null;
		if ( null !== $body ) {
			$payload = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
			if ( false === $payload ) {
				throw new ApiError( __( 'The request body could not be encoded as JSON.', 'meilisearch' ), 'encode_error' );
			}
		}

		$response = $this->transport->send( $method, $url, $headers, $payload, $timeout );
		if ( $response->status < 200 || $response->status > 299 ) {
			throw ApiError::from_response( $response );
		}

		return $response;
	}
}
