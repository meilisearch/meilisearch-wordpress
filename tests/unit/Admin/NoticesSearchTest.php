<?php
/**
 * Search notices tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\Notices;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Tests\Unit\Search\SearchStubs;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Enable-search prompt, conflict notice and the admin-post handler.
 *
 * @covers \Meilisearch\WordPress\Admin\Notices
 */
final class NoticesSearchTest extends TestCase {

	use SearchStubs;

	/**
	 * Active plugin files seen by is_plugin_active().
	 *
	 * @var string[]
	 */
	private array $active_plugins = array();

	/**
	 * Admin with a configured, reindexed site and replace off.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_search_options( array( Options::SEARCH => array( 'replace' => false ) ) );
		$this->active_plugins = array();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_plugin_active' )->alias(
			function ( $plugin ) {
				return in_array( $plugin, $this->active_plugins, true );
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action ) {
				echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
	}

	/**
	 * Output of render_search_notices().
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		( new Notices( new Options(), new ErrorLog() ) )->render_search_notices();
		return (string) ob_get_clean();
	}

	/**
	 * After the first reindex the prompt posts to admin-post.php with a nonce.
	 */
	public function test_prompt_after_first_reindex(): void {
		$html = $this->render();

		self::assertStringContainsString( 'action="http://example.test/wp-admin/admin-post.php"', $html );
		self::assertStringContainsString( 'name="action" value="meilisearch_enable_search"', $html );
		self::assertStringContainsString( 'nonce-meilisearch_enable_search', $html );
		self::assertStringContainsString( 'Enable Meilisearch search', $html );
	}

	/**
	 * No prompt before the first reindex, once enabled, or for non-admins.
	 */
	public function test_no_prompt_when_not_applicable(): void {
		$this->stub_search_options(
			array(
				Options::SEARCH => array( 'replace' => false ),
				Options::STATE  => array( 'first_reindex_done' => false ),
			)
		);
		self::assertSame( '', $this->render() );

		$this->stub_search_options( array( Options::SEARCH => array( 'replace' => true ) ) );
		self::assertSame( '', $this->render() );

		$this->stub_search_options( array( Options::SEARCH => array( 'replace' => false ) ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		self::assertSame( '', $this->render() );
	}

	/**
	 * A conflicting search plugin replaces the prompt with a warning.
	 */
	public function test_conflict_notice_suppresses_prompt(): void {
		$this->active_plugins = array( 'searchwp/index.php' );

		$html = $this->render();

		self::assertStringContainsString( 'notice-warning', $html );
		self::assertStringContainsString( 'searchwp/index.php', $html );
		self::assertStringNotContainsString( 'meilisearch_enable_search', $html );
	}

	/**
	 * The first active conflicting plugin is reported.
	 */
	public function test_conflicting_plugin(): void {
		$notices = new Notices( new Options(), new ErrorLog() );
		self::assertNull( $notices->conflicting_plugin() );

		$this->active_plugins = array( 'elasticpress/elasticpress.php', 'relevanssi/relevanssi.php' );
		self::assertSame( 'relevanssi/relevanssi.php', $notices->conflicting_plugin() );
	}

	/**
	 * The handler checks the nonce, enables replace and redirects back.
	 */
	public function test_enable_search_handler(): void {
		Functions\expect( 'check_admin_referer' )->once()->with( 'meilisearch_enable_search' )->andReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( 'http://example.test/wp-admin/index.php' );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $location ) {
				throw new \RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stub.
			}
		);

		try {
			( new Notices( new Options(), new ErrorLog() ) )->handle_enable_search();
			self::fail( 'Expected a redirect.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirect:http://example.test/wp-admin/index.php', $e->getMessage() );
		}
		self::assertTrue( $this->option_store[ Options::SEARCH ]['replace'] );
	}

	/**
	 * Users without manage_options are stopped before anything changes.
	 */
	public function test_enable_search_requires_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'check_admin_referer' )->never();
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		try {
			( new Notices( new Options(), new ErrorLog() ) )->handle_enable_search();
			self::fail( 'Expected wp_die().' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'wp_die', $e->getMessage() );
		}
		self::assertFalse( $this->option_store[ Options::SEARCH ]['replace'] );
	}
}
