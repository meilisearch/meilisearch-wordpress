<?php
/**
 * Highlighted excerpts.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\Options;

/**
 * Replaces excerpts of intercepted results with Meilisearch's cropped, highlighted content
 * (spec § 9.6). Only <mark> survives.
 */
final class Highlighter implements Registrable {

	/**
	 * Constructor.
	 *
	 * @param ResultMapper $mapper  Result mapper holding _formatted.
	 * @param Options      $options Options.
	 */
	public function __construct(
		private ResultMapper $mapper,
		private Options $options
	) {}

	/**
	 * Hooks get_the_excerpt when highlighting is enabled.
	 */
	public function register(): void {
		if ( $this->options->search()['highlight'] ) {
			add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 20, 2 );
		}
	}

	/**
	 * Returns the highlighted crop for posts of an intercepted result, the excerpt otherwise.
	 *
	 * Other code may apply get_the_excerpt with one argument or unexpected values: anything but a
	 * string excerpt and a resolvable post comes back unchanged.
	 *
	 * @param mixed $excerpt Excerpt.
	 * @param mixed $post    Post, post ID or null for the global post.
	 * @return mixed
	 */
	public function filter_excerpt( mixed $excerpt, mixed $post = null ): mixed {
		if ( ! is_string( $excerpt ) ) {
			return $excerpt;
		}
		$post = $post instanceof \WP_Post ? $post : get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return $excerpt;
		}
		$formatted = $this->mapper->formatted( (int) $post->ID );
		if ( null === $formatted || ! isset( $formatted['content'] ) || ! is_string( $formatted['content'] ) || '' === trim( $formatted['content'] ) ) {
			return $excerpt;
		}
		return wp_kses( $formatted['content'], array( 'mark' => array() ) );
	}
}
