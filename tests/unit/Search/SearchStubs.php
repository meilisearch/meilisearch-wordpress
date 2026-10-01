<?php
/**
 * Shared WordPress stubs for the Search unit tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Settings\Options;

/**
 * Search defaults on top of TestCase::stub_options() (the in-memory option table), an in-memory
 * transient store, and the small helpers Options, IndexNames and ClientFactory may call. Options, Indexability and
 * ClientFactory are final, so tests use the real classes on top of these stubs.
 */
trait SearchStubs {

	/**
	 * Transient name => value.
	 *
	 * @var array<string, mixed>
	 */
	protected array $transients = array();

	/**
	 * Capabilities the current user holds (current_user_can()).
	 *
	 * @var string[]
	 */
	protected array $user_caps = array();

	/**
	 * Post type => read_private_posts capability name (get_post_type_object()); others have no object.
	 *
	 * @var array<string, string>
	 */
	protected array $private_caps = array();

	/**
	 * Installs the stubs. Array overrides are merged (one level) over the default of that option and
	 * handed to TestCase::stub_options(), which backs get_option() and friends.
	 *
	 * @param array<string, mixed> $overrides Option name => value.
	 */
	protected function stub_search_options( array $overrides = array() ): void {
		$defaults = array(
			Options::CONNECTION  => array(
				'host'                => 'http://meili.test',
				'prefix'              => 'wp_test',
				'search_key'          => '',
				'search_key_uid'      => '',
				'delete_on_uninstall' => false,
			),
			Options::ADMIN_KEY   => 'admin-key',
			Options::CONTENT     => array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array(
					'post' => array( 'category', 'post_tag' ),
					'page' => array(),
				),
				'meta_keys'  => array(
					'post' => array( 'price', 'my-key' ),
					'page' => array(),
				),
			),
			Options::WOOCOMMERCE => array(
				'enabled'           => false,
				'attributes'        => null,
				'custom_attributes' => false,
				'variation_skus'    => false,
			),
			Options::SEARCH      => array(
				'replace'        => true,
				'highlight'      => false,
				'embedder'       => '',
				'semantic_ratio' => 0.0,
				'autocomplete'   => false,
			),
			Options::STATE       => array(
				'first_reindex_done' => true,
				'populated'          => array( 'content', 'products' ),
			),
			Options::LOG         => array(),
			'posts_per_page'     => '10',
		);
		foreach ( $overrides as $name => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $name ] ) && is_array( $defaults[ $name ] ) ) {
				$value = array_replace( $defaults[ $name ], $value );
			}
			$defaults[ $name ] = $value;
		}
		$this->stub_options( $defaults );
		$this->transients   = array();
		$this->user_caps    = array();
		$this->private_caps = array();
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, $this->user_caps, true );
			}
		);
		Functions\when( 'get_post_type_object' )->alias(
			function ( $type ) {
				if ( ! isset( $this->private_caps[ $type ] ) ) {
					return null;
				}
				return (object) array( 'cap' => (object) array( 'read_private_posts' => $this->private_caps[ $type ] ) );
			}
		);

		$GLOBALS['wp_version'] = '7.1.2'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Unit tests run without WordPress.

		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return array_key_exists( $name, $this->transients ) ? $this->transients[ $name ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				if ( is_object( $args ) ) {
					$args = get_object_vars( $args );
				} elseif ( ! is_array( $args ) ) {
					parse_str( (string) $args, $args );
				}
				return array_merge( (array) $defaults, $args );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Stub of wp_parse_url().
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/\\' );
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/\\' ) . '/';
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.2' );
		Functions\when( 'home_url' )->justReturn( 'http://example.test' );
		Functions\when( 'network_home_url' )->justReturn( 'http://example.test' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_timezone' )->alias(
			static function () {
				return new \DateTimeZone( 'UTC' );
			}
		);
	}

	/**
	 * A WP_Query double.
	 *
	 * @param array<string, mixed> $vars   Query vars.
	 * @param bool                 $search is_search().
	 * @param bool                 $main   is_main_query().
	 * @return \WP_Query
	 */
	protected function make_query( array $vars, bool $search = true, bool $main = true ): \WP_Query {
		$query             = new \WP_Query( $vars );
		$query->search     = $search;
		$query->main_query = $main;
		return $query;
	}

	/**
	 * Attaches a WP_Tax_Query double with the given (already sanitized) queries.
	 *
	 * @param \WP_Query                $query    Query.
	 * @param array<int|string, mixed> $queries  Clauses.
	 * @param string                   $relation Top-level relation.
	 * @return \WP_Query
	 */
	protected function with_tax_query( \WP_Query $query, array $queries, string $relation = 'AND' ): \WP_Query {
		$tax_query           = new \WP_Tax_Query( array() );
		$tax_query->queries  = $queries;
		$tax_query->relation = $relation;
		$query->tax_query    = $tax_query;
		return $query;
	}
}
