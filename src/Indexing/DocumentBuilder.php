<?php
/**
 * Document builder contract.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a post into a Meilisearch document.
 */
interface DocumentBuilder {

	/**
	 * Builds the document for a post.
	 *
	 * @param \WP_Post $post Post to convert.
	 * @return array<string, mixed>|null Null when the document cannot be built (skip and log).
	 */
	public function build( \WP_Post $post ): ?array;
}
