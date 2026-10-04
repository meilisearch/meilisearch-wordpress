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
	 * POST /keys.
	 *
	 * @param array<string, mixed> $payload Key definition (name, actions, indexes, expiresAt, …).
	 * @return array<string, mixed> The created key, including 'key' and 'uid'.
	 * @throws ApiError On any failure.
	 */
	public function create_key( array $payload ): array {
		return $this->request( 'POST', '/keys', null, $payload )->body ?? array();
	}

	/**
	 * GET /keys/{key_or_uid}.
	 *
	 * @param string $key_or_uid Key value or uid.
	 * @return array<string, mixed>
	 * @throws ApiError On any failure.
	 */
	public function get_key( string $key_or_uid ): array {
		return $this->request( 'GET', '/keys/' . rawurlencode( $key_or_uid ) )->body ?? array();
	}

	/**
	 * DELETE /keys/{key_or_uid}.
	 *
	 * @param string $key_or_uid Key value or uid.
	 * @throws ApiError On any failure.
	 */
	public function delete_key( string $key_or_uid ): void {
		$this->request( 'DELETE', '/keys/' . rawurlencode( $key_or_uid ) );
	}

	/**
	 * GET /indexes/{uid}.
	 *
	 * @param string $uid Index uid.
	 * @return bool False when Meilisearch answers index_not_found.
	 * @throws ApiError On any other failure.
	 */
	public function index_exists( string $uid ): bool {
		try {
			$this->request( 'GET', '/indexes/' . rawurlencode( $uid ) );
			return true;
		} catch ( ApiError $error ) {
			if ( 'index_not_found' === $error->error_code ) {
				return false;
			}
			throw $error;
		}
	}

	/**
	 * POST /indexes.
	 *
	 * @param string $uid         Index uid.
	 * @param string $primary_key Primary key attribute.
	 * @return Task
	 * @throws ApiError On any failure.
	 */
	public function create_index( string $uid, string $primary_key = 'id' ): Task {
		return $this->task(
			$this->request(
				'POST',
				'/indexes',
				null,
				array(
					'uid'        => $uid,
					'primaryKey' => $primary_key,
				)
			)
		);
	}

	/**
	 * DELETE /indexes/{uid}.
	 *
	 * @param string $uid Index uid.
	 * @return Task
	 * @throws ApiError On any failure.
	 */
	public function delete_index( string $uid ): Task {
		return $this->task( $this->request( 'DELETE', '/indexes/' . rawurlencode( $uid ) ) );
	}

	/**
	 * GET /indexes?limit=1000.
	 *
	 * @return list<array<string, mixed>> The 'results' list.
	 * @throws ApiError On any failure.
	 */
	public function list_indexes(): array {
		$body    = $this->request( 'GET', '/indexes', array( 'limit' => 1000 ) )->body ?? array();
		$results = $body['results'] ?? array();
		return is_array( $results ) ? array_values( $results ) : array();
	}

	/**
	 * GET /indexes/{uid}/stats.
	 *
	 * @param string $uid Index uid.
	 * @return array<string, mixed>
	 * @throws ApiError On any failure.
	 */
	public function index_stats( string $uid ): array {
		return $this->request( 'GET', '/indexes/' . rawurlencode( $uid ) . '/stats' )->body ?? array();
	}

	/**
	 * GET /indexes/{uid}/settings.
	 *
	 * @param string $uid Index uid.
	 * @return array<string, mixed>
	 * @throws ApiError On any failure.
	 */
	public function get_settings( string $uid ): array {
		return $this->request( 'GET', '/indexes/' . rawurlencode( $uid ) . '/settings' )->body ?? array();
	}

	/**
	 * PATCH /indexes/{uid}/settings.
	 *
	 * @param string               $uid      Index uid.
	 * @param array<string, mixed> $settings Settings to change.
	 * @return Task
	 * @throws ApiError On any failure.
	 */
	public function update_settings( string $uid, array $settings ): Task {
		return $this->task( $this->request( 'PATCH', '/indexes/' . rawurlencode( $uid ) . '/settings', null, $settings ) );
	}

	/**
	 * POST /indexes/{uid}/documents?primaryKey=id (add or replace).
	 *
	 * @param string                           $uid       Index uid.
	 * @param array<int, array<string, mixed>> $documents Documents.
	 * @return Task
	 * @throws ApiError On any failure, including 'encode_error' when a document is not JSON-encodable.
	 */
	public function add_documents( string $uid, array $documents ): Task {
		return $this->task( $this->request( 'POST', '/indexes/' . rawurlencode( $uid ) . '/documents', array( 'primaryKey' => 'id' ), array_values( $documents ) ) );
	}

	/**
	 * POST /indexes/{uid}/documents/delete-batch.
	 *
	 * @param string            $uid Index uid.
	 * @param array<int|string> $ids Document ids.
	 * @return Task
	 * @throws ApiError On any failure.
	 */
	public function delete_documents( string $uid, array $ids ): Task {
		return $this->task( $this->request( 'POST', '/indexes/' . rawurlencode( $uid ) . '/documents/delete-batch', null, array_values( $ids ) ) );
	}

	/**
	 * POST /indexes/{uid}/documents/delete (delete by filter, Meilisearch 1.2+).
	 *
	 * @param string $uid    Index uid.
	 * @param string $filter Filter expression on filterable attributes.
	 * @return Task
	 * @throws ApiError On any failure.
	 */
	public function delete_documents_by_filter( string $uid, string $filter ): Task {
		return $this->task( $this->request( 'POST', '/indexes/' . rawurlencode( $uid ) . '/documents/delete', null, array( 'filter' => $filter ) ) );
	}

	/**
	 * DELETE /indexes/{uid}/documents.
	 *
	 * @param string $uid Index uid.
	 * @return Task
	 * @throws ApiError On any failure.
	 */
	public function delete_all_documents( string $uid ): Task {
		return $this->task( $this->request( 'DELETE', '/indexes/' . rawurlencode( $uid ) . '/documents' ) );
	}

	/**
	 * POST /indexes/{uid}/documents/fetch (browse with filter/sort, Meilisearch 1.34+).
	 *
	 * @param string               $uid  Index uid.
	 * @param array<string, mixed> $body Fetch parameters: fields, filter, sort, limit, offset, ids.
	 * @return array<string, mixed> The full response {results, offset, limit, total}.
	 * @throws ApiError On any failure.
	 */
	public function fetch_documents( string $uid, array $body ): array {
		$payload = array() === $body ? new \stdClass() : $body;
		return $this->request( 'POST', '/indexes/' . rawurlencode( $uid ) . '/documents/fetch', null, $payload )->body ?? array();
	}

	/**
	 * GET /tasks/{uid}.
	 *
	 * @param int $task_uid Task uid.
	 * @return array<string, mixed>
	 * @throws ApiError On any failure.
	 */
	public function get_task( int $task_uid ): array {
		return $this->request( 'GET', '/tasks/' . $task_uid )->body ?? array();
	}

	/**
	 * GET /tasks with filters; list values are comma-joined, booleans become 'true'/'false'.
	 *
	 * @param array<string, mixed> $query Filters, e.g. array( 'statuses' => array( 'failed' ), 'indexUids' => array( … ), 'limit' => 20 ).
	 * @return array<string, mixed> The full response {results, total, limit, from, next}.
	 * @throws ApiError On any failure.
	 */
	public function get_tasks( array $query ): array {
		$normalized = array();
		foreach ( $query as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'strval', $value ) );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$normalized[ (string) $name ] = (string) $value;
		}
		return $this->request( 'GET', '/tasks', $normalized )->body ?? array();
	}

	/**
	 * POST /indexes/{uid}/search.
	 *
	 * @param string               $uid     Index uid.
	 * @param array<string, mixed> $params  Search parameters.
	 * @param float                $timeout Timeout in seconds.
	 * @return array<string, mixed>
	 * @throws ApiError On any failure.
	 */
	public function search( string $uid, array $params, float $timeout = self::SEARCH_TIMEOUT ): array {
		$body = array() === $params ? new \stdClass() : $params;
		return $this->request( 'POST', '/indexes/' . rawurlencode( $uid ) . '/search', null, $body, $timeout )->body ?? array();
	}

	/**
	 * POST /multi-search.
	 *
	 * @param list<array<string, mixed>> $queries    Queries, each with an indexUid.
	 * @param array<string, mixed>|null  $federation Federation options; null for a non-federated search.
	 * @param float                      $timeout    Timeout in seconds.
	 * @return array<string, mixed>
	 * @throws ApiError On any failure.
	 */
	public function multi_search( array $queries, ?array $federation = null, float $timeout = self::SEARCH_TIMEOUT ): array {
		$body = array( 'queries' => array_values( $queries ) );
		if ( null !== $federation ) {
			$body['federation'] = array() === $federation ? new \stdClass() : $federation;
		}
		return $this->request( 'POST', '/multi-search', null, $body, $timeout )->body ?? array();
	}

	/**
	 * Builds a Task from a 202 response.
	 *
	 * @param Response $response Response.
	 * @return Task
	 * @throws ApiError Code 'invalid_response' when the body has no integer taskUid.
	 */
	private function task( Response $response ): Task {
		$uid = $response->body['taskUid'] ?? null;
		if ( ! is_int( $uid ) ) {
			throw new ApiError( __( 'Meilisearch did not return a task identifier.', 'meilisearch' ), 'invalid_response', $response->status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is data; escaped where displayed.
		}
		return new Task( $this, $uid );
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
				throw new ApiError( __( 'The request body could not be encoded as JSON.', 'meilisearch' ), 'encode_error' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is data; escaped where displayed.
			}
		}

		$response = $this->transport->send( $method, $url, $headers, $payload, $timeout );
		if ( $response->status < 200 || $response->status > 299 ) {
			throw ApiError::from_response( $response ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is data; escaped where displayed.
		}

		return $response;
	}
}
