<?php
/**
 * Circuit breaker.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

/**
 * After a search error or timeout, skip Meilisearch for TTL seconds (spec § 9.7).
 */
final class CircuitBreaker {

	public const TRANSIENT = 'meilisearch_circuit_open';
	public const TTL       = 60;

	/**
	 * Whether searches must skip Meilisearch.
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return false !== get_transient( self::TRANSIENT );
	}

	/**
	 * Opens the breaker for TTL seconds.
	 */
	public function trip(): void {
		set_transient( self::TRANSIENT, 1, self::TTL );
	}
}
