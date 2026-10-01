<?php
/**
 * HTTP response value object.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * A received HTTP response with its decoded JSON body.
 */
final class Response {

	/**
	 * Creates the response.
	 *
	 * @param int               $status HTTP status code.
	 * @param array<mixed>|null $body   Decoded JSON body; null when empty or not a JSON array/object.
	 * @param string            $raw    Raw body.
	 */
	public function __construct( public readonly int $status, public readonly ?array $body, public readonly string $raw ) {}
}
