<?php
/**
 * Scripted Transport for unit tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Support;

use Meilisearch\WordPress\Api\Response;
use Meilisearch\WordPress\Api\Transport;

final class FakeTransport implements Transport {

	/**
	 * Recorded requests, oldest first.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: mixed, raw_body: ?string, timeout: float}>
	 */
	public array $requests = [];

	/**
	 * Queued responses and exceptions.
	 *
	 * @var list<Response|\Throwable>
	 */
	private array $queue = [];

	public function queue( Response|\Throwable $next ): self {
		$this->queue[] = $next;
		return $this;
	}

	public function respond( int $status, ?array $body = null ): self {
		return $this->queue( new Response( $status, $body, null === $body ? '' : (string) json_encode( $body ) ) );
	}

	public function respond_raw( int $status, string $raw ): self {
		$decoded = json_decode( $raw, true );
		return $this->queue( new Response( $status, is_array( $decoded ) ? $decoded : null, $raw ) );
	}

	public function fail( \Throwable $error ): self {
		return $this->queue( $error );
	}

	public function requests(): array {
		return $this->requests;
	}

	public function send( string $method, string $url, array $headers, ?string $body, float $timeout ): Response {
		$this->requests[] = [
			'method'   => $method,
			'url'      => $url,
			'headers'  => $headers,
			'body'     => null === $body ? null : json_decode( $body, true ),
			'raw_body' => $body,
			'timeout'  => $timeout,
		];

		if ( [] === $this->queue ) {
			throw new \LogicException( sprintf( 'FakeTransport: no response queued for %s %s.', $method, $url ) );
		}

		$next = array_shift( $this->queue );
		if ( $next instanceof \Throwable ) {
			throw $next;
		}
		return $next;
	}

	public function last(): array {
		if ( [] === $this->requests ) {
			throw new \LogicException( 'FakeTransport: no request was sent.' );
		}
		return $this->requests[ count( $this->requests ) - 1 ];
	}

	public function pending(): int {
		return count( $this->queue );
	}
}
