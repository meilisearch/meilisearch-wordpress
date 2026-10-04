<?php
/**
 * WP_CLI\Utils functions used by the plugin, recording instead of printing.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace WP_CLI\Utils;

use Meilisearch\WordPress\Tests\Integration\Support\ShimProgressBar;

if ( ! function_exists( __NAMESPACE__ . '\make_progress_bar' ) ) {
	function make_progress_bar( string $message, int $count, int $interval = 100 ): ShimProgressBar {
		$bar             = new ShimProgressBar( $message, $count );
		\WP_CLI::$bars[] = $bar;

		return $bar;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
	/**
	 * @param iterable<int|string, mixed> $items
	 * @param array<int, string>|string   $fields
	 */
	function format_items( string $format, iterable $items, array|string $fields ): void {
		\WP_CLI::$items[] = array(
			'format' => $format,
			'items'  => is_array( $items ) ? $items : iterator_to_array( $items ),
			'fields' => $fields,
		);
	}
}
