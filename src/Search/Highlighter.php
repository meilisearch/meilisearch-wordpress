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
	 * Highlighted excerpts served during this request, keyed by their text.
	 *
	 * @var array<string, true>
	 */
	private array $served = array();

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
			add_filter( 'wp_trim_words', array( $this, 'keep_highlighted_excerpt' ), 20, 4 );
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
		$highlighted                  = wp_kses( $formatted['content'], array( 'mark' => array() ) );
		$this->served[ $highlighted ] = true;
		return $highlighted;
	}

	/**
	 * Keeps a highlighted excerpt intact through wp_trim_words().
	 *
	 * Block themes' Post Excerpt block trims every excerpt with wp_trim_words(), which strips all tags and
	 * would drop the <mark> elements. Meilisearch already cropped the text (attributesToCrop), so an excerpt
	 * this class served is returned as is; any other text keeps WordPress's result.
	 *
	 * @param mixed $text          Trimmed text.
	 * @param mixed $num_words     Word limit.
	 * @param mixed $more          More string.
	 * @param mixed $original_text Text before trimming.
	 * @return mixed
	 */
	public function keep_highlighted_excerpt( mixed $text, mixed $num_words = null, mixed $more = null, mixed $original_text = null ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- filter signature.
		return is_string( $original_text ) && isset( $this->served[ $original_text ] ) ? $original_text : $text;
	}
}
