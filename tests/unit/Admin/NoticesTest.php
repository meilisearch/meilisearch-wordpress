<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\Menu;
use Meilisearch\WordPress\Admin\Notices;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class NoticesTest extends TestCase {

	/**
	 * Transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	protected function set_up(): void {
		parent::set_up();
		$this->stub_options(
			array(
				Options::CONNECTION => array( 'host' => 'https://meili.example' ),
				Options::ADMIN_KEY  => 'key',
				Options::STATE      => array(),
				Options::LOG        => array(),
			)
		);
		$this->transients = array();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => Menu::HOOK_SUFFIX ) );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ) => $url . '?' . http_build_query( $args ) );
	}

	private function notices(): Notices {
		return new Notices( new Options(), new ErrorLog() );
	}

	private function render(): string {
		ob_start();
		$this->notices()->render();
		return (string) ob_get_clean();
	}

	public function test_register(): void {
		$notices = $this->notices();
		$notices->register();

		$this->assertNotFalse( has_action( 'admin_notices', array( $notices, 'render' ) ) );
	}

	public function test_nothing_when_all_is_well(): void {
		$this->assertSame( '', $this->render() );
	}

	public function test_only_on_plugin_screen(): void {
		$this->option_store[ Options::STATE ] = array( 'needs_reindex' => array( 'content' ) );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'dashboard' ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_requires_capability(): void {
		$this->option_store[ Options::STATE ] = array( 'needs_reindex' => array( 'content' ) );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '', $this->render() );
	}

	public function test_connect_result_is_shown_once_and_escaped(): void {
		$this->transients[ Notices::connect_result_key( 3 ) ] = array(
			'type'    => 'error',
			'message' => 'Could not connect: <b>bad</b>',
		);

		$html = $this->render();

		$this->assertStringContainsString( 'notice notice-error', $html );
		$this->assertStringContainsString( 'Could not connect: &lt;b&gt;bad&lt;/b&gt;', $html );
		$this->assertSame( '', $this->render(), 'The result is deleted after display.' );
	}

	public function test_needs_reindex_links_to_status_tab(): void {
		$this->option_store[ Options::STATE ] = array( 'needs_reindex' => array( 'content' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'notice notice-warning', $html );
		$this->assertStringContainsString( 'run a full reindex of content', $html );
		$this->assertStringContainsString( 'tab=status', $html );
	}

	public function test_needs_reindex_hidden_when_not_configured(): void {
		$this->option_store[ Options::STATE ]     = array( 'needs_reindex' => array( 'content' ) );
		$this->option_store[ Options::ADMIN_KEY ] = '';

		$this->assertSame( '', $this->render() );
	}

	public function test_manual_search_key_warning(): void {
		$this->option_store[ Options::STATE ] = array( 'search_key_manual' => true );

		$html = $this->render();

		$this->assertStringContainsString( 'could not create a search-only key', $html );
		$this->assertStringContainsString( 'tab=connection', $html );
	}

	public function test_manual_search_key_warning_hidden_once_a_key_is_pasted(): void {
		$this->option_store[ Options::STATE ]      = array( 'search_key_manual' => true );
		$this->option_store[ Options::CONNECTION ] = array(
			'host'       => 'https://meili.example',
			'search_key' => 'pasted',
		);

		$this->assertSame( '', $this->render() );
	}

	public function test_recent_errors_are_counted(): void {
		$now                                = time();
		$this->option_store[ Options::LOG ] = array(
			array(
				'time'    => $now - 60,
				'context' => 'sync',
				'message' => 'a',
			),
			array(
				'time'    => $now - 120,
				'context' => 'sync',
				'message' => 'b',
			),
			array(
				'time'    => $now - Notices::RECENT_WINDOW - 10,
				'context' => 'sync',
				'message' => 'old',
			),
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Meilisearch logged 2 errors in the last 24 hours.', $html );
		$this->assertStringContainsString( 'tab=status', $html );
	}

	public function test_notices_are_ordered(): void {
		$this->option_store[ Options::STATE ]                 = array(
			'needs_reindex'     => array( 'content' ),
			'search_key_manual' => true,
		);
		$this->transients[ Notices::connect_result_key( 3 ) ] = array(
			'type'    => 'success',
			'message' => 'Connected.',
		);

		$this->assertSame( array( 'success', 'warning', 'warning' ), array_column( $this->notices()->notices(), 'type' ) );
	}
}
