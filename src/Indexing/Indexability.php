<?php
/**
 * The single rule deciding whether a post may exist in an index (spec § 5.2).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

use Meilisearch\WordPress\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Indexability rule.
 */
final class Indexability {

	/**
	 * Product visibility/stock rule; null when products are not indexed.
	 *
	 * @var null|callable(\WP_Post):bool
	 */
	private $product_rule;

	/**
	 * Constructor.
	 *
	 * @param Options                      $options      Plugin options.
	 * @param null|callable(\WP_Post):bool $product_rule Product rule, null when products are not indexed.
	 *
	 * @throws \InvalidArgumentException When the product rule is not callable.
	 */
	public function __construct( private Options $options, $product_rule = null ) {
		if ( null !== $product_rule && ! is_callable( $product_rule ) ) {
			throw new \InvalidArgumentException( 'The product rule must be callable or null.' );
		}
		$this->product_rule = $product_rule;
	}

	/**
	 * Logical index for a post type, based on the type alone.
	 *
	 * @param string $post_type Post type name.
	 * @return string|null 'products', 'content' or null.
	 */
	public function index_for_type( string $post_type ): ?string {
		if ( 'product' === $post_type ) {
			return null !== $this->product_rule ? 'products' : null;
		}
		if ( 'product_variation' === $post_type ) {
			return null;
		}

		return in_array( $post_type, $this->options->enabled_post_types(), true ) ? 'content' : null;
	}

	/**
	 * Logical index for a post; null for revisions, autosaves and non-indexed types.
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null
	 */
	public function index_for( \WP_Post $post ): ?string {
		if ( false !== wp_is_post_revision( $post ) || false !== wp_is_post_autosave( $post ) ) {
			return null;
		}

		return $this->index_for_type( (string) $post->post_type );
	}

	/**
	 * Full § 5.2 rule.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public function is_indexable( \WP_Post $post ): bool {
		$index = $this->index_for( $post );
		if ( null === $index ) {
			return false;
		}
		if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		if ( 'products' === $index && ! (bool) call_user_func( $this->product_rule, $post ) ) {
			return false;
		}

		/**
		 * Filters whether a public post may be indexed.
		 *
		 * @param bool     $index Whether to index the post. Default true.
		 * @param \WP_Post $post  The post.
		 */
		return (bool) apply_filters( 'meilisearch_should_index_post', true, $post );
	}
}
