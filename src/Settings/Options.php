<?php
/**
 * Typed access to the plugin options.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin options; wp-config.php constants override host and admin key.
 */
final class Options {

	public const CONNECTION  = 'meilisearch_connection';
	public const ADMIN_KEY   = 'meilisearch_admin_key';
	public const CONTENT     = 'meilisearch_content';
	public const WOOCOMMERCE = 'meilisearch_woocommerce';
	public const SEARCH      = 'meilisearch_search';
	public const STATE       = 'meilisearch_state';
	public const LOG         = 'meilisearch_log';

	/**
	 * Autoload flag of each option.
	 */
	private const AUTOLOAD = array(
		self::CONNECTION  => false,
		self::ADMIN_KEY   => false,
		self::CONTENT     => true,
		self::WOOCOMMERCE => true,
		self::SEARCH      => true,
		self::STATE       => false,
		self::LOG         => false,
	);

	/**
	 * Post types that belong to the products index, never to the content index.
	 */
	private const PRODUCT_TYPES = array( 'product', 'product_variation' );

	/**
	 * Returns a constant's value, or null when it is not defined.
	 *
	 * @var \Closure(string): mixed
	 */
	private \Closure $constant_reader;

	/**
	 * Creates the accessor.
	 *
	 * @param callable|null $constant_reader Receives a constant name, returns its value or null. Defaults to defined()/constant().
	 */
	public function __construct( ?callable $constant_reader = null ) {
		$this->constant_reader = null === $constant_reader
			? static fn( string $name ): mixed => defined( $name ) ? constant( $name ) : null
			: \Closure::fromCallable( $constant_reader );
	}

	/**
	 * Default value of every option.
	 *
	 * @return array<string, mixed> Option name => default value.
	 */
	public static function defaults(): array {
		return array(
			self::CONNECTION  => array(
				'host'                => '',
				'prefix'              => '',
				'search_key'          => '',
				'search_key_uid'      => '',
				'delete_on_uninstall' => false,
			),
			self::ADMIN_KEY   => '',
			self::CONTENT     => array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array( 'post' => array( 'category', 'post_tag' ) ),
				'meta_keys'  => array(),
			),
			self::WOOCOMMERCE => array(
				'enabled'           => true,
				'attributes'        => null,
				'custom_attributes' => false,
				'variation_skus'    => true,
			),
			self::SEARCH      => array(
				'replace'        => false,
				'highlight'      => false,
				'embedder'       => '',
				'semantic_ratio' => 0.5,
				'autocomplete'   => false,
			),
			self::STATE       => array(
				'fingerprint'        => '',
				'search_key_manual'  => false,
				'needs_reindex'      => array(),
				'reindex'            => array(),
				'first_reindex_done' => false,
			),
			self::LOG         => array(),
		);
	}

	/**
	 * Normalizes a Meilisearch host URL; returns '' when it is not a usable http(s) URL.
	 *
	 * @param string $raw User input or constant value.
	 * @return string
	 */
	public static function normalize_host( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( 1 !== preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $raw ) ) {
			$probe  = wp_parse_url( 'http://' . $raw, PHP_URL_HOST );
			$scheme = is_string( $probe ) && self::is_local_host( strtolower( $probe ) ) ? 'http' : 'https';
			$raw    = $scheme . '://' . $raw;
		}

		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/^(?:[a-z0-9-]+(?:\.[a-z0-9-]+)*|\[[0-9a-f:.]+\])$/', $host ) ) {
			return '';
		}

		$url = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$url .= ':' . $parts['port'];
		}
		if ( isset( $parts['path'] ) ) {
			$url .= rtrim( $parts['path'], '/' );
		}
		return $url;
	}

	/**
	 * Adds every missing option with its default value and autoload flag.
	 */
	public function seed_defaults(): void {
		foreach ( self::defaults() as $name => $value ) {
			add_option( $name, $value, '', self::AUTOLOAD[ $name ] );
		}
	}

	/**
	 * Normalized host; the MEILISEARCH_HOST constant wins.
	 *
	 * @return string '' when not configured or invalid.
	 */
	public function host(): string {
		$constant = $this->constant( 'MEILISEARCH_HOST' );
		if ( null !== $constant ) {
			return self::normalize_host( $constant );
		}
		return self::normalize_host( self::to_string( $this->group( self::CONNECTION )['host'] ) );
	}

	/**
	 * Admin API key; the MEILISEARCH_ADMIN_KEY constant wins.
	 *
	 * @return string
	 */
	public function admin_key(): string {
		$constant = $this->constant( 'MEILISEARCH_ADMIN_KEY' );
		if ( null !== $constant ) {
			return $constant;
		}
		return trim( self::to_string( get_option( self::ADMIN_KEY, '' ) ) );
	}

	/**
	 * Whether MEILISEARCH_HOST is defined (the field is then read-only).
	 *
	 * @return bool
	 */
	public function host_is_constant(): bool {
		return null !== $this->constant( 'MEILISEARCH_HOST' );
	}

	/**
	 * Whether MEILISEARCH_ADMIN_KEY is defined (the field is then read-only).
	 *
	 * @return bool
	 */
	public function admin_key_is_constant(): bool {
		return null !== $this->constant( 'MEILISEARCH_ADMIN_KEY' );
	}

	/**
	 * Whether both a valid host and an admin key are available.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->host() && '' !== $this->admin_key();
	}

	/**
	 * Admin-entered index prefix, restricted to [a-z0-9_].
	 *
	 * @return string '' when unset.
	 */
	public function prefix_override(): string {
		$prefix = strtolower( trim( self::to_string( $this->group( self::CONNECTION )['prefix'] ) ) );
		return (string) preg_replace( '/[^a-z0-9_]/', '', $prefix );
	}

	/**
	 * Browser search key value.
	 *
	 * @return string
	 */
	public function search_key(): string {
		return self::to_string( $this->group( self::CONNECTION )['search_key'] );
	}

	/**
	 * Browser search key uid ('' when the key was pasted manually and is unknown).
	 *
	 * @return string
	 */
	public function search_key_uid(): string {
		return self::to_string( $this->group( self::CONNECTION )['search_key_uid'] );
	}

	/**
	 * Set while save_search_key() writes, so the connection sanitizer can tell a programmatic write
	 * from a form submission.
	 *
	 * @var bool
	 */
	private static bool $internal_key_write = false;

	/**
	 * Whether a save_search_key() write is in progress.
	 *
	 * @return bool
	 */
	public static function is_internal_key_write(): bool {
		return self::$internal_key_write;
	}

	/**
	 * Stores the browser search key.
	 *
	 * @param string $key Key value.
	 * @param string $uid Key uid.
	 */
	public function save_search_key( string $key, string $uid ): void {
		$connection                   = $this->group( self::CONNECTION );
		$connection['search_key']     = $key;
		$connection['search_key_uid'] = $uid;
		self::$internal_key_write     = true;
		try {
			update_option( self::CONNECTION, $connection, false );
		} finally {
			self::$internal_key_write = false;
		}
	}

	/**
	 * Hash recorded in the `search_key_verified` state for a key that may be served to browsers.
	 *
	 * @param string $key Key value.
	 * @return string
	 */
	public static function key_fingerprint( string $key ): string {
		return hash( 'sha256', $key );
	}

	/**
	 * Records that the current search key may be served to browsers (created search-only, or verified).
	 */
	public function mark_search_key_verified(): void {
		$this->set_state( 'search_key_verified', self::key_fingerprint( $this->search_key() ) );
	}

	/**
	 * Forgets any verification (the key was found too broad).
	 */
	public function clear_search_key_verified(): void {
		$this->set_state( 'search_key_verified', null );
	}

	/**
	 * Whether the current search key is the one last recorded as servable.
	 *
	 * @return bool
	 */
	public function search_key_is_verified(): bool {
		$verified = $this->state( 'search_key_verified' );
		return is_string( $verified ) && '' !== $verified && hash_equals( $verified, self::key_fingerprint( $this->search_key() ) );
	}

	/**
	 * Whether uninstall deletes the indexes and the plugin-created search key.
	 *
	 * @return bool
	 */
	public function delete_on_uninstall(): bool {
		return (bool) $this->group( self::CONNECTION )['delete_on_uninstall'];
	}

	/**
	 * Post types indexed in the content index.
	 *
	 * @return list<string> Never contains 'product' or 'product_variation'.
	 */
	public function enabled_post_types(): array {
		return array_values( array_diff( self::to_string_list( $this->group( self::CONTENT )['post_types'] ), self::PRODUCT_TYPES ) );
	}

	/**
	 * Taxonomies indexed for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return list<string>
	 */
	public function taxonomies_for( string $post_type ): array {
		$map = $this->group( self::CONTENT )['taxonomies'];
		return is_array( $map ) && isset( $map[ $post_type ] ) ? self::to_string_list( $map[ $post_type ] ) : array();
	}

	/**
	 * Meta keys indexed for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return list<string>
	 */
	public function meta_keys_for( string $post_type ): array {
		$map = $this->group( self::CONTENT )['meta_keys'];
		return is_array( $map ) && isset( $map[ $post_type ] ) ? self::to_string_list( $map[ $post_type ] ) : array();
	}

	/**
	 * Union of the taxonomies of every enabled post type.
	 *
	 * @return list<string>
	 */
	public function all_taxonomies(): array {
		$all = array();
		foreach ( $this->enabled_post_types() as $post_type ) {
			$all = array_merge( $all, $this->taxonomies_for( $post_type ) );
		}
		return array_values( array_unique( $all ) );
	}

	/**
	 * Union of the meta keys of every enabled post type.
	 *
	 * @return list<string>
	 */
	public function all_meta_keys(): array {
		$all = array();
		foreach ( $this->enabled_post_types() as $post_type ) {
			$all = array_merge( $all, $this->meta_keys_for( $post_type ) );
		}
		return array_values( array_unique( $all ) );
	}

	/**
	 * WooCommerce settings.
	 *
	 * @return array{enabled: bool, attributes: ?list<string>, custom_attributes: bool, variation_skus: bool}
	 */
	public function woocommerce(): array {
		$settings = $this->group( self::WOOCOMMERCE );
		return array(
			'enabled'           => (bool) $settings['enabled'],
			'attributes'        => null === $settings['attributes'] ? null : self::to_string_list( $settings['attributes'] ),
			'custom_attributes' => (bool) $settings['custom_attributes'],
			'variation_skus'    => (bool) $settings['variation_skus'],
		);
	}

	/**
	 * Whether products are indexed: WooCommerce is active and "Index products" is on.
	 *
	 * @return bool
	 */
	public function products_enabled(): bool {
		return class_exists( 'WooCommerce' ) && $this->woocommerce()['enabled'];
	}

	/**
	 * Search settings.
	 *
	 * @return array{replace: bool, highlight: bool, embedder: string, semantic_ratio: float, autocomplete: bool}
	 */
	public function search(): array {
		$settings = $this->group( self::SEARCH );
		$ratio    = is_numeric( $settings['semantic_ratio'] ) ? (float) $settings['semantic_ratio'] : 0.5;
		return array(
			'replace'        => (bool) $settings['replace'],
			'highlight'      => (bool) $settings['highlight'],
			'embedder'       => trim( self::to_string( $settings['embedder'] ) ),
			'semantic_ratio' => max( 0.0, min( 1.0, $ratio ) ),
			'autocomplete'   => (bool) $settings['autocomplete'],
		);
	}

	/**
	 * Reads a key of the internal state option.
	 *
	 * @param string $key           State key.
	 * @param mixed  $default_value Value when the key is absent.
	 * @return mixed
	 */
	public function state( string $key, mixed $default_value = null ): mixed {
		$state = $this->group( self::STATE );
		return array_key_exists( $key, $state ) ? $state[ $key ] : $default_value;
	}

	/**
	 * Writes a key of the internal state option.
	 *
	 * @param string $key   State key.
	 * @param mixed  $value Value.
	 */
	public function set_state( string $key, mixed $value ): void {
		$this->mutate_state(
			static function ( array $state ) use ( $key, $value ): array {
				$state[ $key ] = $value;
				return $state;
			}
		);
	}

	/**
	 * Whether a logical index is flagged as needing a full reindex.
	 *
	 * @param string $logical 'content' | 'products'.
	 * @return bool
	 */
	public function needs_reindex( string $logical ): bool {
		return in_array( $logical, self::to_string_list( $this->state( 'needs_reindex', array() ) ), true );
	}

	/**
	 * Sets or clears the "needs reindex" flag of a logical index.
	 *
	 * @param string $logical 'content' | 'products'.
	 * @param bool   $flag    New flag.
	 */
	public function flag_reindex( string $logical, bool $flag ): void {
		$this->mutate_state(
			static function ( array $state ) use ( $logical, $flag ): array {
				$flags = array_values( array_diff( self::to_string_list( $state['needs_reindex'] ?? array() ), array( $logical ) ) );
				if ( $flag ) {
					$flags[] = $logical;
				}
				$state['needs_reindex'] = $flags;
				return $state;
			}
		);
	}

	/**
	 * Reindex run state of a logical index.
	 *
	 * @param string $logical 'content' | 'products'.
	 * @return array<string, mixed>|null
	 */
	public function reindex_state( string $logical ): ?array {
		$this->drop_state_cache();
		$all = $this->state( 'reindex', array() );
		return is_array( $all ) && isset( $all[ $logical ] ) && is_array( $all[ $logical ] ) ? $all[ $logical ] : null;
	}

	/**
	 * Stores (or clears, with null) the reindex run state of a logical index.
	 *
	 * @param string                    $logical 'content' | 'products'.
	 * @param array<string, mixed>|null $state   Run state.
	 */
	public function set_reindex_state( string $logical, ?array $state ): void {
		$this->mutate_state(
			static function ( array $current ) use ( $logical, $state ): array {
				$all = isset( $current['reindex'] ) && is_array( $current['reindex'] ) ? $current['reindex'] : array();
				if ( null === $state ) {
					unset( $all[ $logical ] );
				} else {
					$all[ $logical ] = $state;
				}
				$current['reindex'] = $all;
				return $current;
			}
		);
	}

	/**
	 * Read-modify-write of the state option from a fresh read. The option is not autoloaded and
	 * get_option() caches it per process, so a long-running process (WP-CLI) would otherwise rewrite
	 * the whole option from a stale snapshot and revert other writers' changes.
	 *
	 * @param callable $change Receives the current state array, returns the new one.
	 */
	private function mutate_state( callable $change ): void {
		$this->drop_state_cache();
		update_option( self::STATE, $change( $this->group( self::STATE ) ), false );
	}

	/**
	 * Drops the cached copy of the state option so the next read hits the database.
	 */
	private function drop_state_cache(): void {
		wp_cache_delete( self::STATE, 'options' );
	}

	/**
	 * Reads an array option merged over its defaults.
	 *
	 * @param string $name Option name.
	 * @return array<string, mixed>
	 */
	private function group( string $name ): array {
		$defaults = self::defaults()[ $name ] ?? array();
		if ( ! is_array( $defaults ) ) {
			$defaults = array();
		}
		$value = get_option( $name, $defaults );
		return is_array( $value ) ? array_merge( $defaults, $value ) : $defaults;
	}

	/**
	 * Returns a constant's trimmed value when it is a non-empty string.
	 *
	 * @param string $name Constant name.
	 * @return string|null
	 */
	private function constant( string $name ): ?string {
		$value = ( $this->constant_reader )( $name );
		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	/**
	 * Whether a host (lowercase) is a local development host that gets http:// by default.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_local_host( string $host ): bool {
		if ( 'localhost' === $host || '[::1]' === $host || str_starts_with( $host, '127.' ) ) {
			return true;
		}
		foreach ( array( '.localhost', '.test', '.local' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	}

	/**
	 * Casts a scalar to string; anything else becomes ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function to_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Keeps the non-empty strings of an array, unique, re-indexed.
	 *
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private static function to_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$strings = array_filter(
			$value,
			static fn( $item ): bool => is_string( $item ) && '' !== $item
		);
		return array_values( array_unique( $strings ) );
	}
}
