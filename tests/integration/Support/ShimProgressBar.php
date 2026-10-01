<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\Support;

/**
 * Records progress-bar usage instead of drawing it.
 */
final class ShimProgressBar {

	public int $ticks = 0;

	public bool $finished = false;

	public function __construct( public readonly string $message, public readonly int $count ) {}

	public function tick( int $increment = 1 ): void {
		$this->ticks += $increment;
	}

	public function finish(): void {
		$this->finished = true;
	}
}
