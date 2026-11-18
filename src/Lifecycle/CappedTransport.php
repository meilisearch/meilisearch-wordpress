<?php
/**
 * Transport decorator that caps the timeout of every request.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Lifecycle;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Api\Response;
use Meilisearch\WordPress\Api\Transport;

/**
 * Sends through another transport with at most $max_timeout seconds per request, so a cleanup run from
 * a request that must not hang (a site deletion) is bounded against an unreachable host.
 */
final class CappedTransport implements Transport {

	/**
	 * Constructor.
	 *
	 * @param Transport $inner       Transport that sends the request.
	 * @param float     $max_timeout Maximum timeout in seconds.
	 */
	public function __construct( private readonly Transport $inner, private readonly float $max_timeout ) {}

	/**
	 * Sends the request with the smaller of the requested and the maximum timeout.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Raw body, null for none.
	 * @param float                 $timeout Requested timeout in seconds.
	 * @return Response
	 */
	public function send( string $method, string $url, array $headers, ?string $body, float $timeout ): Response {
		return $this->inner->send( $method, $url, $headers, $body, min( $timeout, $this->max_timeout ) );
	}
}
