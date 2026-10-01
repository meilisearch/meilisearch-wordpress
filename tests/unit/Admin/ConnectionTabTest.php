<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\ConnectionTab;
use Meilisearch\WordPress\Admin\Menu;
use Meilisearch\WordPress\Admin\Notices;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Api\Response;
use Meilisearch\WordPress\Indexing\ContentSchema;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class ConnectionTabTest extends TestCase {

	private const SECRET = 'super-secret-admin-key-123';

	/**
	 * Transients set during the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Settings errors added during the test.
	 *
	 * @var list<string>
	 */
	private array $errors = array();

	private FakeTransport $transport;

	protected function set_up(): void {
		parent::set_up();
		$this->stub_options(
			array(
				Options::CONNECTION => array(
					'host'                => 'https://stored.example',
					'prefix'              => '',
					'search_key'          => 'stored-search-key',
					'search_key_uid'      => 'stored-uid',
					'delete_on_uninstall' => true,
				),
				Options::ADMIN_KEY  => self::SECRET,
				Options::CONTENT    => array(
					'post_types' => array( 'post' ),
					'taxonomies' => array(),
					'meta_keys'  => array(),
				),
				Options::STATE      => array(),
			)
		);
		$this->transients = array();
		$this->errors     = array();
		$this->transport  = new FakeTransport();

		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_settings_error' )->alias(
			function ( $setting, $code ) {
				$this->errors[] = $code;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://site.example' );
		Functions\when( 'network_home_url' )->justReturn( 'https://site.example' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.2' );
		unset( $_GET['settings-updated'], $_GET['tab'] );
	}

	protected function tear_down(): void {
		unset( $_GET['settings-updated'], $_GET['tab'] );
		parent::tear_down();
	}

	private function tab(): ConnectionTab {
		$options = new Options();
		$names   = new IndexNames( $options );
		$indexes = new IndexManager(
			new ClientFactory( $options, $this->transport ),
			$names,
			new SettingsBuilder( array( 'content' => new ContentSchema( $options ) ) ),
			$options
		);

		return new ConnectionTab( $options, $indexes, $names );
	}

	public function test_identity(): void {
		$tab = $this->tab();

		$this->assertSame( 'connection', $tab->slug() );
		$this->assertSame( 'Connection', $tab->label() );
		$this->assertTrue( $tab->is_visible() );
	}

	public function test_register_hooks(): void {
		$tab = $this->tab();
		$tab->register();

		$this->assertNotFalse( has_filter( 'option_page_capability_meilisearch_connection', array( $tab, 'capability' ) ) );
		$this->assertNotFalse( has_action( 'load-toplevel_page_meilisearch', array( $tab, 'after_save' ) ) );
	}

	public function test_multisite_requires_network_capability(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$caps = array();
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) use ( &$caps ) {
				$caps[] = $cap;
				return false;
			}
		);
		$tab = $this->tab();

		$this->assertSame( 'manage_network_options', $tab->capability( 'manage_options' ) );
		$this->assertFalse( $tab->is_visible() );
		$this->assertSame( array( 'manage_network_options' ), $caps );
	}

	public function test_single_site_keeps_default_capability(): void {
		$this->assertSame( 'manage_options', $this->tab()->capability( 'manage_options' ) );
	}

	public function test_register_settings_uses_one_group_for_exactly_two_options(): void {
		$GLOBALS['pagenow'] = 'admin.php';
		$registered         = array();
		Functions\when( 'register_setting' )->alias(
			static function ( $group, $name, $args ) use ( &$registered ) {
				$registered[] = array( $group, $name, $args['type'], $args['show_in_rest'] );
			}
		);

		$this->tab()->register_settings();

		$this->assertSame(
			array(
				array( 'meilisearch_connection', Options::CONNECTION, 'array', false ),
				array( 'meilisearch_connection', Options::ADMIN_KEY, 'string', false ),
			),
			$registered
		);
	}

	public function test_register_settings_on_options_php_creates_missing_private_options_with_autoload_off(): void {
		$GLOBALS['pagenow'] = 'options.php';
		unset( $this->option_store[ Options::ADMIN_KEY ], $this->option_store[ Options::CONNECTION ] );
		Functions\when( 'register_setting' )->justReturn( null );

		$this->tab()->register_settings();

		$this->assertSame(
			array(
				Options::ADMIN_KEY  => false,
				Options::CONNECTION => false,
			),
			$this->option_autoload
		);
		unset( $GLOBALS['pagenow'] );
	}

	public function test_sanitize_connection_form_submission(): void {
		$out = $this->tab()->sanitize_connection(
			array(
				'host'   => ' HTTPS://ms-123.meilisearch.io/ ',
				'prefix' => 'My-Site_01!',
			)
		);

		$this->assertSame(
			array(
				'host'                => 'https://ms-123.meilisearch.io',
				'prefix'              => 'mysite_01',
				'search_key'          => 'stored-search-key',
				'search_key_uid'      => 'stored-uid',
				'delete_on_uninstall' => false,
			),
			$out
		);
		$this->assertSame( $out, $this->tab()->sanitize_connection( $out ), 'Sanitizing twice gives the same value.' );
	}

	public function test_sanitize_connection_keeps_stored_host_on_invalid_url(): void {
		$out = $this->tab()->sanitize_connection( array( 'host' => 'ftp://nope' ) );

		$this->assertSame( 'https://stored.example', $out['host'] );
		$this->assertSame( array( 'meilisearch_invalid_host' ), $this->errors );
	}

	public function test_sanitize_connection_allows_clearing_the_host(): void {
		$this->assertSame( '', $this->tab()->sanitize_connection( array( 'host' => '' ) )['host'] );
	}

	public function test_sanitize_connection_prefix_length_is_capped(): void {
		$this->assertSame( str_repeat( 'a', 40 ), $this->tab()->sanitize_connection( array( 'prefix' => str_repeat( 'a', 60 ) ) )['prefix'] );
	}

	public function test_sanitize_connection_checkbox(): void {
		$tab = $this->tab();

		$this->assertTrue( $tab->sanitize_connection( array( 'delete_on_uninstall' => '1' ) )['delete_on_uninstall'] );
		$this->assertFalse( $tab->sanitize_connection( array( 'prefix' => '' ) )['delete_on_uninstall'] );
	}

	public function test_sanitize_connection_ignores_search_key_outside_manual_mode(): void {
		$out = $this->tab()->sanitize_connection( array( 'search_key' => 'attacker-chosen' ) );

		$this->assertSame( 'stored-search-key', $out['search_key'] );
		$this->assertSame( 'stored-uid', $out['search_key_uid'] );
	}

	public function test_sanitize_connection_accepts_search_key_in_manual_mode_and_forgets_uid(): void {
		$this->option_store[ Options::STATE ] = array( 'search_key_manual' => true );

		$out = $this->tab()->sanitize_connection( array( 'search_key' => ' pasted-key ' ) );

		$this->assertSame( 'pasted-key', $out['search_key'] );
		$this->assertSame( '', $out['search_key_uid'], 'A pasted key was not created by the plugin and must never be deleted by it.' );
	}

	public function test_sanitize_connection_keeps_programmatic_search_key_writes(): void {
		$full = array(
			'host'                => 'https://stored.example',
			'prefix'              => '',
			'search_key'          => 'rotated-key',
			'search_key_uid'      => 'rotated-uid',
			'delete_on_uninstall' => true,
		);

		$this->assertSame( $full, $this->tab()->sanitize_connection( $full ) );
	}

	public function test_sanitize_connection_non_array_returns_stored_value(): void {
		$this->assertSame( $this->option_store[ Options::CONNECTION ], $this->tab()->sanitize_connection( 'garbage' ) );
		$this->assertSame( $this->option_store[ Options::CONNECTION ], $this->tab()->sanitize_connection( null ) );
	}

	/**
	 * @dataProvider kept_admin_keys
	 *
	 * @param mixed $input Submitted value.
	 */
	public function test_sanitize_admin_key_keeps_stored_value( $input ): void {
		$this->assertSame( self::SECRET, $this->tab()->sanitize_admin_key( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function kept_admin_keys(): array {
		return array(
			'empty string' => array( '' ),
			'whitespace'   => array( "  \t " ),
			'null'         => array( null ),
			'array'        => array( array( 'x' ) ),
			'placeholder'  => array( ConnectionTab::PLACEHOLDER ),
		);
	}

	public function test_sanitize_admin_key_accepts_new_key_idempotently(): void {
		$tab = $this->tab();

		$this->assertSame( 'new-key', $tab->sanitize_admin_key( '  new-key ' ) );
		$this->assertSame( 'new-key', $tab->sanitize_admin_key( $tab->sanitize_admin_key( '  new-key ' ) ) );
	}

	public function test_render_never_prints_the_admin_key(): void {
		Functions\when( 'settings_fields' )->alias(
			static function ( $group ) {
				echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function () {
				echo '<button>Save</button>';
			}
		);
		Functions\when( 'checked' )->alias( static fn( $a, $b ) => $a === $b ? ' checked="checked"' : '' );

		ob_start();
		$this->tab()->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringContainsString( 'name="meilisearch_admin_key" value=""', $html );
		$this->assertStringContainsString( 'placeholder="' . ConnectionTab::PLACEHOLDER . '"', $html );
		$this->assertStringContainsString( 'value="meilisearch_connection"', $html );
		$this->assertStringContainsString( 'value="https://stored.example"', $html );
		$this->assertStringNotContainsString( '[search_key]', $html, 'The search key field only appears in manual mode.' );
		$this->assertStringContainsString( 'checked="checked"', $html );
	}

	public function test_render_shows_search_key_field_in_manual_mode(): void {
		$this->option_store[ Options::STATE ] = array( 'search_key_manual' => true );
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->justReturn( '' );

		ob_start();
		$this->tab()->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="meilisearch_connection[search_key]" value="stored-search-key"', $html );
	}

	public function test_after_save_does_nothing_without_settings_updated(): void {
		$_GET['tab'] = 'connection';

		$this->tab()->after_save();

		$this->assertSame( array(), $this->transport->requests() );
		$this->assertSame( array(), $this->transients );
	}

	public function test_after_save_does_nothing_on_other_tabs(): void {
		$_GET['settings-updated'] = 'true';
		$_GET['tab']              = 'content';

		$this->tab()->after_save();

		$this->assertSame( array(), $this->transport->requests() );
	}

	public function test_after_save_connects_and_stores_success(): void {
		$_GET['settings-updated'] = 'true';
		$_GET['tab']              = 'connection';
		$this->option_store[ Options::CONNECTION ]['prefix'] = 'wp_unit';
		$this->transport
			->queue( new Response( 200, array( 'pkgVersion' => '1.53.1' ), '' ) )
			->queue(
				new Response(
					201,
					array(
						'key' => 'new-search',
						'uid' => 'new-uid',
					),
					''
				)
			)
			->queue( new Response( 404, array( 'code' => 'api_key_not_found' ), '' ) )
			->queue( new Response( 200, array( 'uid' => 'wp_unit_content' ), '' ) )
			->queue(
				new Response(
					200,
					array(
						'searchableAttributes' => array( '*' ),
						'filterableAttributes' => array( 'id', 'post_type', 'author_id', 'date', 'modified' ),
						'sortableAttributes'   => array( 'id', 'date', 'modified', 'title' ),
					),
					''
				)
			);

		$this->tab()->after_save();

		$result = $this->transients[ Notices::connect_result_key( 3 ) ];
		$this->assertSame( 'success', $result['type'] );
		$this->assertStringContainsString( '1.53.1', $result['message'] );
		$this->assertStringContainsString( 'search-only key was created', $result['message'] );
		$this->assertSame( '1.53.1', ( new Options() )->state( 'last_connect' )['version'] );
	}

	public function test_after_save_stores_error_without_leaking_the_key(): void {
		$_GET['settings-updated'] = 'true';
		$_GET['tab']              = 'connection';
		$this->transport->queue(
			new Response(
				403,
				array(
					'code'    => 'invalid_api_key',
					'message' => 'The provided API key is invalid.',
				),
				''
			)
		);

		$this->tab()->after_save();

		$result = $this->transients[ Notices::connect_result_key( 3 ) ];
		$this->assertSame( 'error', $result['type'] );
		$this->assertStringContainsString( 'The provided API key is invalid.', $result['message'] );
		$this->assertStringNotContainsString( self::SECRET, $result['message'] );
	}

	public function test_after_save_strips_machine_prefix_from_messages(): void {
		$_GET['settings-updated'] = 'true';
		$_GET['tab']              = 'connection';
		$this->transport->queue( new Response( 200, array( 'pkgVersion' => '1.12.0' ), '' ) );

		$this->tab()->after_save();

		$message = $this->transients[ Notices::connect_result_key( 3 ) ]['message'];
		$this->assertStringNotContainsString( 'unsupported_version', $message );
		$this->assertStringContainsString( '1.12.0', $message );
	}

	public function test_after_save_does_not_double_escape_the_unsupported_version(): void {
		$_GET['settings-updated'] = 'true';
		$_GET['tab']              = 'connection';
		$this->transport->queue( new Response( 200, array( 'pkgVersion' => '<b>1.0' ), '' ) );

		$this->tab()->after_save();

		$message = $this->transients[ Notices::connect_result_key( 3 ) ]['message'];
		$this->assertStringContainsString( '<b>1.0', $message, 'The message is stored raw and escaped once by Notices.' );
		$this->assertStringNotContainsString( '&lt;', $message );
	}

	public function test_after_save_requires_configuration(): void {
		$_GET['settings-updated']                 = 'true';
		$_GET['tab']                              = 'connection';
		$this->option_store[ Options::ADMIN_KEY ] = '';

		$this->tab()->after_save();

		$this->assertSame( 'error', $this->transients[ Notices::connect_result_key( 3 ) ]['type'] );
		$this->assertSame( array(), $this->transport->requests() );
	}

	public function test_after_save_warns_when_manual_key_is_too_broad(): void {
		$_GET['settings-updated'] = 'true';
		$_GET['tab']              = 'connection';
		$this->option_store[ Options::CONNECTION ]['prefix'] = 'wp_unit';
		$this->option_store[ Options::STATE ]                = array(
			'fingerprint'       => md5( 'https://stored.example|' . self::SECRET . '|wp_unit' ),
			'search_key_manual' => true,
		);
		$this->transport
			->queue( new Response( 200, array( 'pkgVersion' => '1.53.1' ), '' ) )
			->queue( new Response( 200, array( 'uid' => 'wp_unit_content' ), '' ) )
			->queue(
				new Response(
					200,
					array(
						'searchableAttributes' => array( '*' ),
						'filterableAttributes' => array( 'id', 'post_type', 'author_id', 'date', 'modified' ),
						'sortableAttributes'   => array( 'id', 'date', 'modified', 'title' ),
					),
					''
				)
			)
			->queue(
				new Response(
					200,
					array(
						'actions' => array( '*' ),
						'indexes' => array( '*' ),
					),
					''
				)
			);

		$this->tab()->after_save();

		$this->assertSame( 'error', $this->transients[ Notices::connect_result_key( 3 ) ]['type'] );
		$this->assertSame( 'GET /keys/stored-search-key', 'GET ' . substr( $this->transport->requests()[3]['url'], strlen( 'https://stored.example' ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constants_make_fields_read_only(): void {
		define( 'MEILISEARCH_HOST', 'https://constant.example' );
		define( 'MEILISEARCH_ADMIN_KEY', 'constant-admin-key' );
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->justReturn( '' );
		$tab = $this->tab();

		$this->assertSame( 'https://stored.example', $tab->sanitize_connection( array( 'host' => 'https://other.example' ) )['host'] );
		$this->assertSame( self::SECRET, $tab->sanitize_admin_key( 'typed-key' ) );

		ob_start();
		$tab->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'name="meilisearch_connection[host]"', $html );
		$this->assertStringNotContainsString( 'name="meilisearch_admin_key"', $html );
		$this->assertStringNotContainsString( 'constant-admin-key', $html );
		$this->assertStringContainsString( 'https://constant.example', $html );
	}
}
