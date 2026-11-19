<?php
/**
 * Finds code that constrains WP_Query through its SQL filters.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Ops;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the callbacks other code attaches to WP_Query's SQL-level filters.
 *
 * Searches answered by Meilisearch short-circuit WP_Query in posts_pre_query, after these filters
 * ran but before their SQL is executed: whatever they add (a language, an access rule) does not
 * apply. Callbacks defined in WordPress core, WooCommerce and this plugin are left out; they are
 * attached on every site, and the searches the plugin answers already account for them.
 */
final class SqlFilterAudit {

	/**
	 * SQL-level filters of WP_Query::get_posts() that run before posts_pre_query.
	 */
	public const HOOKS = array( 'posts_search', 'posts_where', 'posts_join', 'posts_where_paged', 'posts_join_paged', 'posts_clauses', 'posts_where_request', 'posts_join_request', 'posts_clauses_request', 'posts_request' );

	/**
	 * Most callbacks described; the rest are counted.
	 */
	public const MAX_LISTED = 10;

	/**
	 * Directories whose callbacks are not reported (normalized, with a trailing slash).
	 *
	 * @var list<string>
	 */
	private array $ignored;

	/**
	 * Constructor.
	 *
	 * @param list<string>|null $ignored Directories whose callbacks are not reported; null for WordPress
	 *                                   core (wp-includes, wp-admin), WooCommerce and this plugin's src/.
	 */
	public function __construct( ?array $ignored = null ) {
		if ( null === $ignored ) {
			$ignored = array( ABSPATH . 'wp-includes', ABSPATH . 'wp-admin', dirname( MEILISEARCH_FILE ) . '/src' );
			if ( defined( 'WC_PLUGIN_FILE' ) ) {
				$ignored[] = dirname( (string) WC_PLUGIN_FILE );
			}
		}
		$this->ignored = array_values( array_unique( array_map( static fn ( string $dir ): string => rtrim( self::normalize( $dir ), '/' ) . '/', $ignored ) ) );
	}

	/**
	 * Descriptions ("callback (owner)") of the callbacks other code attaches to the SQL filters.
	 *
	 * @param array<string, mixed>|null $filters Hook name => WP_Hook (or any object with a `callbacks`
	 *                                           array: priority => id => {function}); null for $wp_filter.
	 * @return list<string>
	 */
	public function foreign_callbacks( ?array $filters = null ): array {
		$filters = $filters ?? ( isset( $GLOBALS['wp_filter'] ) && is_array( $GLOBALS['wp_filter'] ) ? $GLOBALS['wp_filter'] : array() );
		$found   = array();
		foreach ( self::HOOKS as $hook ) {
			$callbacks = isset( $filters[ $hook ] ) && is_object( $filters[ $hook ] ) && isset( $filters[ $hook ]->callbacks ) && is_array( $filters[ $hook ]->callbacks ) ? $filters[ $hook ]->callbacks : array();
			foreach ( $callbacks as $priority ) {
				foreach ( (array) $priority as $callback ) {
					$function = is_array( $callback ) ? ( $callback['function'] ?? null ) : null;
					$file     = self::file_of( $function );
					if ( null !== $file && $this->is_ignored( $file ) ) {
						continue;
					}
					$description = self::name_of( $function );
					$owner       = null === $file ? null : self::owner_of( $file );
					if ( null !== $owner ) {
						$description .= ' (' . $owner . ')';
					}
					$found[ $hook . ': ' . $description ] = true;
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * Whether a file belongs to an ignored directory.
	 *
	 * @param string $file Normalized file path.
	 * @return bool
	 */
	private function is_ignored( string $file ): bool {
		foreach ( $this->ignored as $dir ) {
			if ( str_starts_with( $file, $dir ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * File that defines a callback; null for PHP built-ins and unresolvable callbacks.
	 *
	 * @param mixed $callback Callback.
	 * @return string|null Normalized path.
	 */
	private static function file_of( mixed $callback ): ?string {
		try {
			if ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && 2 === count( $callback ) && ( is_object( $callback[0] ) || is_string( $callback[0] ) ) && is_string( $callback[1] ) ) {
				$reflection = new \ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( $callback instanceof \Closure || is_string( $callback ) ) {
				$reflection = new \ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			} else {
				return null;
			}
		} catch ( \ReflectionException $e ) {
			return null;
		}
		$file = $reflection->getFileName();

		return is_string( $file ) ? self::normalize( $file ) : null;
	}

	/**
	 * Readable callback name.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function name_of( mixed $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_string( $callback[1] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $class . '::' . $callback[1];
		}
		if ( $callback instanceof \Closure ) {
			return 'closure';
		}

		return is_object( $callback ) ? get_class( $callback ) : 'callback';
	}

	/**
	 * Plugin, must-use plugin or theme a file belongs to; null when unknown.
	 *
	 * @param string $file Normalized file path.
	 * @return string|null
	 */
	private static function owner_of( string $file ): ?string {
		$roots = array();
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			/* translators: %s: must-use plugin file or folder name. */
			$roots[ (string) WPMU_PLUGIN_DIR ] = __( 'must-use plugin %s', 'meilisearch' );
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			/* translators: %s: plugin folder or file name. */
			$roots[ (string) WP_PLUGIN_DIR ] = __( 'plugin %s', 'meilisearch' );
		}
		if ( function_exists( 'get_theme_root' ) ) {
			/* translators: %s: theme folder name. */
			$roots[ (string) get_theme_root() ] = __( 'theme %s', 'meilisearch' );
		}
		foreach ( $roots as $root => $format ) {
			$root = rtrim( self::normalize( $root ), '/' ) . '/';
			if ( '/' !== $root && str_starts_with( $file, $root ) ) {
				return sprintf( $format, explode( '/', substr( $file, strlen( $root ) ) )[0] );
			}
		}

		return null;
	}

	/**
	 * Forward slashes, symbolic links resolved when the path exists.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalize( string $path ): string {
		$real = realpath( $path );
		return str_replace( '\\', '/', false === $real ? $path : $real );
	}
}
