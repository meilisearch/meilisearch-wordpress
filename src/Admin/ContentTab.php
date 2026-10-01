<?php
/**
 * Content tab: post types, taxonomies and meta keys to index.
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

use Meilisearch\WordPress\Indexing\FieldName;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Option group `meilisearch_content` holds exactly Options::CONTENT.
 */
final class ContentTab implements Tab, Registrable {

	public const GROUP          = 'meilisearch_content';
	public const EXCLUDED_TYPES = array( 'attachment', 'product', 'product_variation' );

	/**
	 * Constructor.
	 *
	 * @param Options $options Plugin options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * Flags the content index for reindex whenever the indexed fields change.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'update_option_' . Options::CONTENT, array( $this, 'on_update' ), 10, 2 );
		add_action( 'add_option_' . Options::CONTENT, array( $this, 'on_add' ), 10, 2 );
	}

	/**
	 * Tab slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'content';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Content', 'meilisearch' );
	}

	/**
	 * Visible to everyone who can open the page.
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		return current_user_can( Menu::CAPABILITY );
	}

	/**
	 * Registers Options::CONTENT in its own group.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Options::CONTENT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitizes Options::CONTENT. Accepts the raw form shape (meta keys as one
	 * textarea string per post type) and the sanitized shape (lists). Idempotent.
	 *
	 * @param mixed $input Submitted value.
	 * @return array{post_types: list<string>, taxonomies: array<string, list<string>>, meta_keys: array<string, list<string>>}
	 */
	public static function sanitize( mixed $input ): array {
		$out = array(
			'post_types' => array(),
			'taxonomies' => array(),
			'meta_keys'  => array(),
		);
		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['post_types'] = array_values( array_diff( self::keys( $input['post_types'] ?? array() ), self::EXCLUDED_TYPES ) );

		$taxonomies = is_array( $input['taxonomies'] ?? null ) ? $input['taxonomies'] : array();
		$meta_keys  = is_array( $input['meta_keys'] ?? null ) ? $input['meta_keys'] : array();
		foreach ( $out['post_types'] as $post_type ) {
			$out['taxonomies'][ $post_type ] = self::keys( $taxonomies[ $post_type ] ?? array() );
			$out['meta_keys'][ $post_type ]  = self::meta_keys( $meta_keys[ $post_type ] ?? array() );
		}

		return $out;
	}

	/**
	 * Flags a reindex when the indexed field set changed (update_option_meilisearch_content).
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public function on_update( mixed $old_value, mixed $value ): void {
		if ( self::signature( $old_value ) !== self::signature( $value ) ) {
			$this->options->flag_reindex( 'content', true );
		}
	}

	/**
	 * Flags a reindex when the option is first created with post types (add_option_meilisearch_content).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 * @return void
	 */
	public function on_add( string $option, mixed $value ): void {
		if ( array() !== self::sanitize( $value )['post_types'] ) {
			$this->options->flag_reindex( 'content', true );
		}
	}

	/**
	 * Prints the tab.
	 *
	 * @return void
	 */
	public function render(): void {
		$enabled = $this->options->enabled_post_types();

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<p>' . esc_html__( 'Choose what is sent to Meilisearch. Only published, public content is ever indexed. Changes take effect after a full reindex.', 'meilisearch' ) . '</p>';

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( in_array( $post_type->name, self::EXCLUDED_TYPES, true ) ) {
				continue;
			}
			$name    = (string) $post_type->name;
			$checked = in_array( $name, $enabled, true );

			printf( '<details class="meilisearch-post-type"%s>', $checked ? ' open' : '' );
			echo '<summary><label>';
			printf(
				'<input type="checkbox" name="%1$s[post_types][]" value="%2$s"%3$s /> %4$s <code>%2$s</code>',
				esc_attr( Options::CONTENT ),
				esc_attr( $name ),
				checked( $checked, true, false ),
				esc_html( (string) $post_type->label )
			);
			echo '</label></summary>';

			$this->render_taxonomies( $name );
			$this->render_meta_keys( $name );
			echo '</details>';
		}//end foreach

		submit_button();
		echo '</form>';
	}

	/**
	 * Taxonomy checkboxes for one post type.
	 *
	 * @param string $post_type Post type name.
	 * @return void
	 */
	private function render_taxonomies( string $post_type ): void {
		$selected   = $this->options->taxonomies_for( $post_type );
		$taxonomies = array_filter(
			get_object_taxonomies( $post_type, 'objects' ),
			static fn( $taxonomy ): bool => is_object( $taxonomy ) && ( ! empty( $taxonomy->public ) || ! empty( $taxonomy->show_ui ) )
		);
		if ( array() === $taxonomies ) {
			return;
		}

		echo '<fieldset><legend>' . esc_html__( 'Taxonomies', 'meilisearch' ) . '</legend><div class="meilisearch-checkbox-list">';
		foreach ( $taxonomies as $taxonomy ) {
			printf(
				'<label><input type="checkbox" name="%1$s[taxonomies][%2$s][]" value="%3$s"%4$s /> %5$s</label>',
				esc_attr( Options::CONTENT ),
				esc_attr( $post_type ),
				esc_attr( (string) $taxonomy->name ),
				checked( in_array( $taxonomy->name, $selected, true ), true, false ),
				esc_html( (string) $taxonomy->label )
			);
		}
		echo '</div></fieldset>';
	}

	/**
	 * Meta keys textarea for one post type.
	 *
	 * @param string $post_type Post type name.
	 * @return void
	 */
	private function render_meta_keys( string $post_type ): void {
		$id = 'meilisearch-meta-' . $post_type;
		printf(
			'<p><label for="%1$s">%2$s</label><br /><textarea id="%1$s" class="meilisearch-meta-keys" rows="3" name="%3$s[meta_keys][%4$s]">%5$s</textarea></p>',
			esc_attr( $id ),
			esc_html__( 'Meta keys (one per line; scalar values only)', 'meilisearch' ),
			esc_attr( Options::CONTENT ),
			esc_attr( $post_type ),
			esc_textarea( implode( "\n", $this->options->meta_keys_for( $post_type ) ) )
		);
	}

	/**
	 * Unique sanitize_key() values of a list; non-lists give [].
	 *
	 * @param mixed $value Raw list.
	 * @return list<string>
	 */
	private static function keys( mixed $value ): array {
		$keys = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_string( $item ) ) {
				$key = sanitize_key( $item );
				if ( '' !== $key ) {
					$keys[] = $key;
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Meta keys from a textarea string or a list: trimmed, valid field names,
	 * first key wins when two keys normalize to the same field name.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	private static function meta_keys( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		$keys  = array();
		$names = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$key  = sanitize_text_field( $item );
			$name = '' === $key ? null : FieldName::normalize( $key );
			if ( null === $name || isset( $names[ $name ] ) ) {
				continue;
			}
			$names[ $name ] = true;
			$keys[]         = $key;
		}

		return $keys;
	}

	/**
	 * Comparable form of the indexed field set.
	 *
	 * @param mixed $value Option value.
	 * @return string
	 */
	private static function signature( mixed $value ): string {
		$clean = self::sanitize( $value );
		sort( $clean['post_types'] );
		ksort( $clean['taxonomies'] );
		ksort( $clean['meta_keys'] );
		foreach ( $clean['taxonomies'] as &$taxonomies ) {
			sort( $taxonomies );
		}
		unset( $taxonomies );
		foreach ( $clean['meta_keys'] as &$keys ) {
			sort( $keys );
		}
		unset( $keys );

		return (string) wp_json_encode( $clean );
	}
}
