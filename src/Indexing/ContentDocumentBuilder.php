<?php
/**
 * Builds content documents (spec § 5.3).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

use Meilisearch\WordPress\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * WP_Post → `{prefix}_content` document.
 */
final class ContentDocumentBuilder implements DocumentBuilder {

	public const MAX_CONTENT_LENGTH = 20000;
	public const EXCERPT_WORDS      = 55;
	private const ZERO_DATE         = '0000-00-00 00:00:00';

	/**
	 * Constructor.
	 *
	 * @param Options $options Plugin options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * Builds the full content document.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>|null Null when the document cannot be JSON-encoded.
	 */
	public function build( \WP_Post $post ): ?array {
		$post_type = (string) $post->post_type;
		$doc       = array_merge(
			$this->core_fields( $post ),
			$this->taxonomy_fields( $post, $this->options->taxonomies_for( $post_type ) ),
			$this->meta_fields( (int) $post->ID, $this->options->meta_keys_for( $post_type ) )
		);

		/**
		 * Filters a document before it is sent to Meilisearch.
		 *
		 * @param array<string, mixed> $doc   Document.
		 * @param \WP_Post             $post  Source post.
		 * @param string               $index Logical index ('content' or 'products').
		 */
		$doc = apply_filters( 'meilisearch_document', $doc, $post, 'content' );
		if ( ! is_array( $doc ) || false === wp_json_encode( $doc ) ) {
			return null;
		}

		return $doc;
	}

	/**
	 * Core fields shared by content and product documents.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public function core_fields( \WP_Post $post ): array {
		$raw     = wp_strip_all_tags( do_blocks( strip_shortcodes( (string) $post->post_content ) ) );
		$content = self::clean_text( $raw );

		/**
		 * Filters the indexed content text (e.g. to use `the_content` on page-builder sites).
		 *
		 * The result is cleaned again and truncated.
		 *
		 * @param string   $content Cleaned plain text.
		 * @param \WP_Post $post    Source post.
		 */
		$content = apply_filters( 'meilisearch_document_content', $content, $post );
		$content = mb_substr( self::clean_text( is_string( $content ) ? $content : '' ), 0, self::MAX_CONTENT_LENGTH );

		$excerpt = self::clean_text( (string) $post->post_excerpt );
		if ( '' === $excerpt ) {
			$excerpt = wp_trim_words( $content, self::EXCERPT_WORDS, '' );
		}

		$author_id = (int) $post->post_author;
		$permalink = get_permalink( $post );
		$thumbnail = get_the_post_thumbnail_url( $post, 'thumbnail' );

		return array(
			'id'            => (int) $post->ID,
			'post_type'     => (string) $post->post_type,
			'title'         => self::clean_text( (string) get_the_title( $post ) ),
			'content'       => $content,
			'excerpt'       => $excerpt,
			'permalink'     => is_string( $permalink ) ? $permalink : '',
			'date'          => self::timestamp( (string) $post->post_date_gmt ),
			'modified'      => self::timestamp( (string) $post->post_modified_gmt ),
			'author_id'     => $author_id,
			'author_name'   => self::clean_text( (string) get_the_author_meta( 'display_name', $author_id ) ),
			'thumbnail_url' => is_string( $thumbnail ) && '' !== $thumbnail ? $thumbnail : null,
		);
	}

	/**
	 * Taxonomy fields: `tax_{t}` (names) and `tax_{t}_ids` (IDs including ancestors).
	 *
	 * @param \WP_Post $post       Post.
	 * @param string[] $taxonomies Taxonomy names.
	 * @return array<string, list<string>|list<int>>
	 */
	public function taxonomy_fields( \WP_Post $post, array $taxonomies ): array {
		$fields = array();
		foreach ( $taxonomies as $taxonomy ) {
			$name = FieldName::normalize( (string) $taxonomy );
			if ( null === $name || isset( $fields[ 'tax_' . $name ] ) ) {
				continue;
			}

			$names = array();
			$ids   = array();
			$terms = get_the_terms( $post, (string) $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$props = is_object( $term ) ? get_object_vars( $term ) : array();
					if ( ! isset( $props['term_id'], $props['name'] ) ) {
						continue;
					}
					$term_id = (int) $props['term_id'];
					$names[] = self::clean_text( (string) $props['name'] );
					$ids[]   = $term_id;
					foreach ( (array) get_ancestors( $term_id, (string) $taxonomy, 'taxonomy' ) as $ancestor ) {
						$ids[] = (int) $ancestor;
					}
				}
			}

			$ids = array_values( array_unique( $ids ) );
			sort( $ids );

			$fields[ 'tax_' . $name ]          = array_values( array_unique( array_filter( $names, static fn( string $term_name ): bool => '' !== $term_name ) ) );
			$fields[ 'tax_' . $name . '_ids' ] = $ids;
		}//end foreach

		return $fields;
	}

	/**
	 * Meta fields: `meta_{key}` for scalar values; numeric strings are cast.
	 *
	 * The first key that normalizes to a given field name wins.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $keys    Meta keys.
	 * @return array<string, string|int|float|bool>
	 */
	public function meta_fields( int $post_id, array $keys ): array {
		$fields = array();
		foreach ( $keys as $key ) {
			$name = FieldName::normalize( (string) $key );
			if ( null === $name || array_key_exists( 'meta_' . $name, $fields ) ) {
				continue;
			}

			$value = self::meta_value( get_post_meta( $post_id, (string) $key, true ) );
			if ( null !== $value ) {
				$fields[ 'meta_' . $name ] = $value;
			}
		}

		return $fields;
	}

	/**
	 * Scrubs invalid UTF-8, strips tags, decodes entities and collapses whitespace.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$flat = preg_replace( '/[\s\x{00A0}]+/u', ' ', $text );

		return trim( is_string( $flat ) ? $flat : $text );
	}

	/**
	 * Converts a stored meta value to an indexable scalar.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string|int|float|bool|null Null when the value must be skipped.
	 */
	private static function meta_value( mixed $value ): string|int|float|bool|null {
		if ( is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : null;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$value = wp_check_invalid_utf8( $value, true );
		if ( 1 === preg_match( '/^-?(0|[1-9][0-9]*)$/', $value ) && (string) (int) $value === $value ) {
			return (int) $value;
		}
		if ( 1 === preg_match( '/^-?(0|[1-9][0-9]*)\.[0-9]+$/', $value ) ) {
			return (float) $value;
		}

		return $value;
	}

	/**
	 * GMT MySQL datetime → Unix timestamp; the zero date and unparsable values give 0.
	 *
	 * @param string $gmt GMT datetime.
	 * @return int
	 */
	private static function timestamp( string $gmt ): int {
		if ( '' === $gmt || self::ZERO_DATE === $gmt ) {
			return 0;
		}
		$time = strtotime( $gmt . ' UTC' );

		return false === $time ? 0 : $time;
	}
}
