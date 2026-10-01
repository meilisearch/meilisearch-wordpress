<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\Menu;
use Meilisearch\WordPress\Admin\Tab;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class MenuTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		if ( ! defined( 'MEILISEARCH_FILE' ) ) {
			define( 'MEILISEARCH_FILE', '/plugins/meilisearch/meilisearch.php' );
		}
		if ( ! defined( 'MEILISEARCH_VERSION' ) ) {
			define( 'MEILISEARCH_VERSION', '1.0.0' );
		}
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ) => $url . '?' . http_build_query( $args ) );
		unset( $_GET['tab'] );
	}

	protected function tear_down(): void {
		unset( $_GET['tab'] );
		parent::tear_down();
	}

	private function tab( string $slug, bool $visible = true ): Tab {
		return new class( $slug, $visible ) implements Tab {
			public int $registered = 0;
			public int $rendered   = 0;
			public function __construct( private string $slug, private bool $visible ) {}
			public function slug(): string {
				return $this->slug;
			}
			public function label(): string {
				return ucfirst( $this->slug );
			}
			public function is_visible(): bool {
				return $this->visible;
			}
			public function register_settings(): void {
				++$this->registered;
			}
			public function render(): void {
				++$this->rendered;
				echo '[' . esc_html( $this->slug ) . ' body]';
			}
		};
	}

	public function test_requires_tabs(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Menu( array() );
	}

	public function test_register_adds_hooks_and_registers_registrable_tabs(): void {
		$registrable = new class() implements Tab, Registrable {
			public int $hooks = 0;
			public function slug(): string {
				return 'content';
			}
			public function label(): string {
				return 'Content';
			}
			public function is_visible(): bool {
				return true;
			}
			public function register_settings(): void {}
			public function render(): void {}
			public function register(): void {
				++$this->hooks;
			}
		};
		$menu        = new Menu( array( $this->tab( 'connection' ), $registrable ) );

		$menu->register();

		$this->assertNotFalse( has_action( 'admin_menu', array( $menu, 'add_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $menu, 'register_settings' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $menu, 'enqueue_assets' ) ) );
		$this->assertSame( 1, $registrable->hooks );
	}

	public function test_add_menu(): void {
		$menu  = new Menu( array( $this->tab( 'connection' ) ) );
		$calls = array();
		Functions\when( 'add_menu_page' )->alias(
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
				return Menu::HOOK_SUFFIX;
			}
		);

		$menu->add_menu();

		$this->assertSame(
			array( array( 'Meilisearch', 'Meilisearch', 'manage_options', 'meilisearch', array( $menu, 'render_page' ), 'dashicons-search' ) ),
			$calls
		);
	}

	public function test_register_settings_calls_every_tab(): void {
		$a = $this->tab( 'connection' );
		$b = $this->tab( 'content', false );

		( new Menu( array( $a, $b ) ) )->register_settings();

		$this->assertSame( 1, $a->registered );
		$this->assertSame( 1, $b->registered );
	}

	public function test_assets_load_only_on_plugin_screen(): void {
		$menu = new Menu( array( $this->tab( 'connection' ) ) );
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://example.test/wp-content/plugins/meilisearch/' . $path );
		$calls = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			}
		);

		$menu->enqueue_assets( 'index.php' );
		$menu->enqueue_assets( 'settings_page_other' );
		$menu->enqueue_assets( Menu::HOOK_SUFFIX );

		$this->assertSame(
			array( array( 'meilisearch-admin', 'https://example.test/wp-content/plugins/meilisearch/assets/css/admin.css', array(), MEILISEARCH_VERSION ) ),
			$calls
		);
	}

	public function test_current_tab_resolution(): void {
		$menu = new Menu( array( $this->tab( 'connection', false ), $this->tab( 'content' ), $this->tab( 'search' ) ) );

		$this->assertSame( 'content', $menu->current_tab()->slug(), 'Defaults to the first visible tab.' );

		$_GET['tab'] = 'search';
		$this->assertSame( 'search', $menu->current_tab()->slug() );

		$_GET['tab'] = 'connection';
		$this->assertSame( 'content', $menu->current_tab()->slug(), 'Hidden tabs cannot be selected.' );

		$_GET['tab'] = 'no-such-tab';
		$this->assertSame( 'content', $menu->current_tab()->slug() );

		$_GET['tab'] = 'SEARCH<script>';
		$this->assertSame( 'content', $menu->current_tab()->slug() );
	}

	public function test_render_page_shows_visible_tabs_only(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'settings_errors' )->once();
		$connection  = $this->tab( 'connection', false );
		$content     = $this->tab( 'content' );
		$search      = $this->tab( 'search' );
		$_GET['tab'] = 'search';

		ob_start();
		( new Menu( array( $connection, $content, $search ) ) )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'tab=connection', $html );
		$this->assertStringContainsString( 'tab=content', $html );
		$this->assertStringContainsString( 'nav-tab nav-tab-active" aria-current="page">Search', $html );
		$this->assertStringContainsString( '[search body]', $html );
		$this->assertSame( 0, $content->rendered );
	}

	public function test_render_page_requires_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		ob_start();
		( new Menu( array( $this->tab( 'content' ) ) ) )->render_page();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_url(): void {
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=meilisearch&tab=status', Menu::url( 'status' ) );
	}
}
