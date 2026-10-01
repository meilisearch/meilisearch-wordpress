<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\Options;

/**
 * Each admin tab owns its own Settings API option group: saving one tab never
 * touches another tab's options.
 */
final class SettingsGroupsTest extends TestCase {

	private const GROUPS = array(
		'meilisearch_connection' => array( Options::CONNECTION, Options::ADMIN_KEY ),
		'meilisearch_content'    => array( Options::CONTENT ),
		'meilisearch_search'     => array( Options::SEARCH ),
	);

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		foreach ( self::GROUPS as $group => $names ) {
			foreach ( $names as $name ) {
				unregister_setting( $group, $name );
			}
		}
		unset( $GLOBALS['pagenow'] );
		parent::tear_down();
	}

	/**
	 * Registers settings the way admin_init does during an options.php request.
	 */
	private function register_settings(): void {
		$GLOBALS['pagenow'] = 'options.php';
		Plugin::instance()->get( 'admin_menu' )->register_settings();
	}

	/**
	 * Replays the save loop of wp-admin/options.php for one option group.
	 *
	 * @param string               $group Option group.
	 * @param array<string, mixed> $post  Submitted $_POST data.
	 */
	private function submit( string $group, array $post ): void {
		global $new_allowed_options;
		foreach ( (array) $new_allowed_options[ $group ] as $option ) {
			$value = null;
			if ( isset( $post[ $option ] ) ) {
				$value = is_array( $post[ $option ] ) ? $post[ $option ] : trim( (string) $post[ $option ] );
				$value = wp_unslash( $value );
			}
			update_option( $option, $value );
		}
	}

	public function test_each_group_holds_exactly_its_options(): void {
		global $new_allowed_options;
		$this->register_settings();

		foreach ( self::GROUPS as $group => $names ) {
			$this->assertSame( $names, array_values( (array) $new_allowed_options[ $group ] ), $group );
		}
	}

	public function test_saving_connection_does_not_modify_content_or_search(): void {
		$content = array(
			'post_types' => array( 'post' ),
			'taxonomies' => array( 'post' => array( 'category' ) ),
			'meta_keys'  => array( 'post' => array( 'price' ) ),
		);
		$search  = array(
			'replace'        => true,
			'highlight'      => false,
			'embedder'       => 'default',
			'semantic_ratio' => 0.5,
			'autocomplete'   => true,
		);
		update_option( Options::CONTENT, $content );
		update_option( Options::SEARCH, $search );
		$connection = (array) get_option( Options::CONNECTION, array() );
		$admin_key  = get_option( Options::ADMIN_KEY );
		$this->register_settings();

		$this->submit(
			'meilisearch_connection',
			array(
				Options::CONNECTION => array(
					'host'   => (string) ( $connection['host'] ?? '' ),
					'prefix' => (string) ( $connection['prefix'] ?? '' ),
				),
				Options::ADMIN_KEY  => '',
			)
		);

		$this->assertSame( $content, get_option( Options::CONTENT ) );
		$this->assertSame( $search, get_option( Options::SEARCH ) );
		$this->assertSame( $admin_key, get_option( Options::ADMIN_KEY ), 'An empty admin key submission keeps the stored key.' );
	}

	public function test_admin_key_is_stored_without_autoload(): void {
		global $wpdb;
		if ( defined( 'MEILISEARCH_ADMIN_KEY' ) ) {
			$this->markTestSkipped( 'The admin key is defined as a constant.' );
		}
		$original = (string) get_option( Options::ADMIN_KEY, '' );
		delete_option( Options::ADMIN_KEY );
		$this->register_settings();

		$this->submit( 'meilisearch_connection', array( Options::ADMIN_KEY => 'fresh-key' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reads the raw autoload flag.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Options::ADMIN_KEY ) );
		$this->assertSame( 'off', $autoload );
		$this->assertSame( 'fresh-key', get_option( Options::ADMIN_KEY ) );
		update_option( Options::ADMIN_KEY, $original );
	}

	public function test_saving_search_does_not_modify_connection_or_content(): void {
		$content = array(
			'post_types' => array( 'page' ),
			'taxonomies' => array( 'page' => array() ),
			'meta_keys'  => array( 'page' => array() ),
		);
		update_option( Options::CONTENT, $content );
		$connection = get_option( Options::CONNECTION );
		$admin_key  = get_option( Options::ADMIN_KEY );
		$this->register_settings();

		$this->submit(
			'meilisearch_search',
			array(
				Options::SEARCH => array(
					'replace'        => '1',
					'semantic_ratio' => '2',
				),
			)
		);

		$this->assertSame( $content, get_option( Options::CONTENT ) );
		$this->assertSame( $connection, get_option( Options::CONNECTION ) );
		$this->assertSame( $admin_key, get_option( Options::ADMIN_KEY ) );
		$search = get_option( Options::SEARCH );
		$this->assertTrue( $search['replace'] );
		$this->assertSame( 1.0, $search['semantic_ratio'] );
	}

	public function test_saving_content_flags_reindex_and_leaves_other_tabs_alone(): void {
		$search = array(
			'replace'        => false,
			'highlight'      => true,
			'embedder'       => '',
			'semantic_ratio' => 0.0,
			'autocomplete'   => false,
		);
		update_option( Options::SEARCH, $search );
		update_option(
			Options::CONTENT,
			array(
				'post_types' => array( 'post' ),
				'taxonomies' => array( 'post' => array() ),
				'meta_keys'  => array( 'post' => array() ),
			)
		);
		$options = Plugin::instance()->get( 'options' );
		$options->flag_reindex( 'content', false );
		$connection = get_option( Options::CONNECTION );
		$this->register_settings();

		$this->submit(
			'meilisearch_content',
			array(
				Options::CONTENT => array(
					'post_types' => array( 'post', 'page' ),
					'taxonomies' => array( 'post' => array( 'category' ) ),
					'meta_keys'  => array( 'post' => "price\nrating" ),
				),
			)
		);

		$this->assertSame(
			array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array(
					'post' => array( 'category' ),
					'page' => array(),
				),
				'meta_keys'  => array(
					'post' => array( 'price', 'rating' ),
					'page' => array(),
				),
			),
			get_option( Options::CONTENT )
		);
		$this->assertTrue( $options->needs_reindex( 'content' ) );
		$this->assertSame( $search, get_option( Options::SEARCH ) );
		$this->assertSame( $connection, get_option( Options::CONNECTION ) );
	}
}
