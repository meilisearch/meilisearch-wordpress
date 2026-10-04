<?php
/**
 * Normalizes taxonomy slugs and meta keys into Meilisearch field names.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * One normalization rule shared by indexing, settings and filtering.
 */
final class FieldName {

	/**
	 * Normalizes a raw name: every byte outside [A-Za-z0-9_] becomes '_', runs of
	 * '_' collapse to one, leading/trailing '_' are trimmed.
	 *
	 * Works byte-wise on purpose so invalid UTF-8 never makes the regex fail.
	 *
	 * @param string $raw Taxonomy slug or meta key.
	 * @return string|null Null when nothing usable remains.
	 */
	public static function normalize( string $raw ): ?string {
		$name = preg_replace( '/[^A-Za-z0-9_]/', '_', $raw );
		$name = preg_replace( '/_+/', '_', (string) $name );
		$name = trim( (string) $name, '_' );

		return '' === $name ? null : $name;
	}
}
