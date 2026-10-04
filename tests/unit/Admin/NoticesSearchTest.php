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
	 * User ID => meta key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $user_meta = array();

	/**
	 * Current user.
	 *
	 * @var int
	 */
	private int $user_id = 7;

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
		$this->user_meta = array();
		Functions\when( 'get_current_user_id' )->alias( fn () => $this->user_id );
		Functions\when( 'get_user_meta' )->alias(
			fn ( $user_id, $key = '', $single = false ) => $this->user_meta[ $user_id ][ $key ] ?? ( $single ? '' : array() )
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_url' )->alias( static fn ( string $url, string $action ): string => $url . '&_wpnonce=nonce-' . $action );
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

	/**
	 * Both search notices carry a nonce-protected dismiss link.
	 */
	public function test_prompt_and_conflict_notice_have_a_dismiss_link(): void {
		self::assertStringContainsString( 'admin-post.php?action=meilisearch_dismiss_notice&notice=enable_search&_wpnonce=nonce-meilisearch_dismiss_notice_enable_search', $this->render() );

		$this->active_plugins = array( 'searchwp/index.php' );
		self::assertStringContainsString( 'notice=conflict&_wpnonce=nonce-meilisearch_dismiss_notice_conflict', $this->render() );
	}

	/**
	 * A dismissed notice is hidden for that user only.
	 */
	public function test_dismissed_notice_is_hidden_for_that_user_only(): void {
		$this->user_meta[7][ Notices::DISMISSED_META ] = array( 'enable_search' );
		self::assertSame( '', $this->render() );

		$this->user_id = 8;
		self::assertStringContainsString( 'Enable Meilisearch search', $this->render() );

		$this->user_id        = 7;
		$this->active_plugins = array( 'searchwp/index.php' );
		self::assertStringContainsString( 'searchwp/index.php', $this->render(), 'Dismissing the prompt keeps the conflict notice.' );
		$this->user_meta[7][ Notices::DISMISSED_META ][] = 'conflict';
		self::assertSame( '', $this->render() );
	}

	/**
	 * The dismiss handler checks capability and nonce, then records the notice for the current user.
	 */
	public function test_dismiss_records_the_notice_for_the_current_user(): void {
		$_GET['notice'] = 'conflict';
		Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( (string) $key ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'check_admin_referer' )->once()->with( 'meilisearch_dismiss_notice_conflict' )->andReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( 'http://example.test/wp-admin/index.php' );

		$url = ( new Notices( new Options(), new ErrorLog() ) )->process_dismiss();
		unset( $_GET['notice'] );

		self::assertSame( 'http://example.test/wp-admin/index.php', $url );
		self::assertSame( array( 'conflict' ), $this->user_meta[7][ Notices::DISMISSED_META ] );
		self::assertArrayNotHasKey( 8, $this->user_meta );
	}

	/**
	 * A bad nonce stops the handler before anything is stored.
	 */
	public function test_dismiss_rejects_a_bad_nonce(): void {
		$_GET['notice'] = 'enable_search';
		Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( (string) $key ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'check_admin_referer' )->alias(
			static function () {
				throw new \RuntimeException( 'bad nonce' );
			}
		);

		try {
			( new Notices( new Options(), new ErrorLog() ) )->process_dismiss();
			self::fail( 'Expected the nonce check to stop the request.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'bad nonce', $e->getMessage() );
		} finally {
			unset( $_GET['notice'] );
		}
		self::assertSame( array(), $this->user_meta );
	}

	/**
	 * Users without manage_options, and unknown notice ids, are rejected.
	 *
	 * @dataProvider rejected_dismissals
	 */
	public function test_dismiss_rejects_missing_capability_or_unknown_notice( bool $can, string $notice ): void {
		$_GET['notice'] = $notice;
		Functions\when( 'current_user_can' )->justReturn( $can );
		Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( (string) $key ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'check_admin_referer' )->never();
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		try {
			( new Notices( new Options(), new ErrorLog() ) )->process_dismiss();
			self::fail( 'Expected wp_die().' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'wp_die', $e->getMessage() );
		} finally {
			unset( $_GET['notice'] );
		}
		self::assertSame( array(), $this->user_meta );
	}

	/**
	 * @return array<string, array{bool, string}>
	 */
	public static function rejected_dismissals(): array {
		return array(
			'no capability'  => array( false, 'conflict' ),
			'unknown notice' => array( true, 'reindex_failed' ),
		);
	}
}
