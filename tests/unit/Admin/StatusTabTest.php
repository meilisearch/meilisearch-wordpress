<?php
/**
 * Tests for StatusTab.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\StatusTab;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\Support\SyncFixtures;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Rendering (escaping, counts, failure states) and the clear-log action.
 */
final class StatusTabTest extends TestCase {

	use SyncFixtures;

	/**
	 * Fake HTTP transport.
	 *
	 * @var FakeTransport
	 */
	private FakeTransport $transport;

	/**
	 * Options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Error log.
	 *
	 * @var ErrorLog
	 */
	private ErrorLog $log;

	/**
	 * Stubs WordPress state and rendering helpers.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_wordpress_state();
		$this->transport = new FakeTransport();
		$this->options   = new Options();
		$this->log       = new ErrorLog();
		$this->install_wpdb( array(), '3' );
		Functions\when( 'number_format_i18n' )->alias( fn ( $number ) => number_format( (float) $number ) );
		Functions\when( 'wp_date' )->alias( fn ( $format, $timestamp ) => gmdate( $format, $timestamp ) );
		Functions\when( 'admin_url' )->alias( fn ( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
	}

	/**
	 * Removes the $wpdb double.
	 */
	protected function tear_down(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	/**
	 * The tab under test.
	 *
	 * @return StatusTab
	 */
	private function tab(): StatusTab {
		$clients = $this->make_clients( $this->options, $this->transport );
		return new StatusTab(
			$this->options,
			new IndexNames( $this->options ),
			$this->make_reindexer( $this->options, $clients, new Queue(), $this->log, array( 'content' => self::builder( fn () => null ) ) ),
			$this->log,
			$clients
		);
	}

	/**
	 * Captured render() output.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->tab()->render();
		return (string) ob_get_clean();
	}

	/**
	 * Counts, reindex controls, errors and failed tasks are shown, and every dynamic value is escaped.
	 */
	public function test_render_shows_counts_and_escapes_values(): void {
		$this->log->add( 'sync', '<script>alert(1)</script>' );
		$this->transport
			->queue(
				self::json_response(
					200,
					array(
						'numberOfDocuments' => 2,
						'isIndexing'        => false,
					)
				)
			)
			->queue(
				self::tasks_response(
					array(
						array(
							'uid'        => 5,
							'indexUid'   => 'wp_test_content',
							'status'     => 'failed',
							'type'       => 'documentAdditionOrUpdate',
							'error'      => array(
								'message' => 'Bad <b>document</b>',
								'code'    => 'invalid_document_id',
							),
							'enqueuedAt' => '2026-10-01T00:00:00Z',
						),
					)
				)
			);

		$html = $this->render();

		$this->assertStringContainsString( '<td>3</td>', $html );
		$this->assertStringContainsString( '<td>2</td>', $html );
		$this->assertStringContainsString( 'data-meilisearch-action="reindex" data-meilisearch-index="content"', $html );
		$this->assertStringContainsString( 'data-meilisearch-progress="content"', $html );
		$this->assertStringContainsString( 'data-meilisearch-action="test-connection"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Bad &lt;b&gt;document&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'name="action" value="meilisearch_clear_log"', $html );

		$requests = $this->transport->requests();
		$this->assertStringContainsString( '/indexes/wp_test_content/stats', $requests[0]['url'] );
		$this->assertStringContainsString( 'statuses=failed', $requests[1]['url'] );
		$this->assertStringContainsString( 'indexUids=wp_test_content', $requests[1]['url'] );
		$this->assertStringContainsString( 'limit=10', $requests[1]['url'] );
	}

	/**
	 * Meilisearch errors degrade to short messages, never to a broken page.
	 */
	public function test_render_survives_meilisearch_errors(): void {
		$this->transport
			->queue( new ApiError( 'Index `wp_test_content` not found.', 'index_not_found', 404 ) )
			->queue( ApiError::transport( 'cURL error 7' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Index not created yet', $html );
		$this->assertStringContainsString( 'Failed tasks could not be loaded.', $html );
		$this->assertStringContainsString( 'No errors recorded.', $html );
	}

	/**
	 * A running reindex disables the button and shows its progress.
	 */
	public function test_render_shows_running_progress(): void {
		$this->options->set_reindex_state(
			'content',
			array(
				'run'        => 'r1',
				'phase'      => 'upsert',
				'last_id'    => 50,
				'sent'       => 50,
				'deleted'    => 0,
				'total'      => 200,
				'task_uids'  => array(),
				'started_at' => time(),
				'updated_at' => time(),
				'status'     => 'running',
				'error'      => '',
			)
		);
		$this->transport->queue( self::json_response( 200, array( 'numberOfDocuments' => 0 ) ) )->queue( self::tasks_response( array() ) );

		$html = $this->render();

		$this->assertStringContainsString( 'data-meilisearch-index="content" disabled', $html );
		$this->assertStringContainsString( '<progress max="100" value="25">', $html );
		$this->assertStringContainsString( 'Indexing: 50 of 200 posts sent.', $html );
	}

	/**
	 * The sweep phase shows the number of stale documents removed; a finished run shows both counters.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function phase_states(): array {
		$base = array(
			'run'        => 'r1',
			'last_id'    => 0,
			'sent'       => 1200,
			'deleted'    => 7,
			'total'      => 1200,
			'task_uids'  => array(),
			'started_at' => 0,
			'updated_at' => 0,
			'error'      => '',
		);
		return array(
			'sweep'      => array(
				array_merge( $base, array( 'phase' => 'sweep' ), array( 'status' => 'running' ) ),
				'Removing stale documents: 7 removed so far.',
			),
			'finalizing' => array(
				array_merge( $base, array( 'phase' => 'finalizing' ), array( 'status' => 'running' ) ),
				'Finalizing: waiting for Meilisearch to process the changes.',
			),
			'done'       => array(
				array_merge( $base, array( 'phase' => 'finalizing' ), array( 'status' => 'done' ) ),
				'Last reindex completed: 1,200 posts sent, 7 stale documents removed.',
			),
			'failed'     => array(
				array_merge( $base, array( 'status' => 'failed' ), array( 'error' => 'Index <b>gone</b>' ) ),
				'Last reindex failed: Index &lt;b&gt;gone&lt;/b&gt;',
			),
		);
	}

	/**
	 * Each phase is described in the progress text.
	 *
	 * @dataProvider phase_states
	 *
	 * @param array<string, mixed> $state    Run state.
	 * @param string               $expected Expected text.
	 */
	public function test_render_describes_phase( array $state, string $expected ): void {
		$state['started_at'] = time();
		$state['updated_at'] = time();
		$this->options->set_reindex_state( 'content', $state );
		$this->transport->queue( self::json_response( 200, array( 'numberOfDocuments' => 0 ) ) )->queue( self::tasks_response( array() ) );

		$this->assertStringContainsString( $expected, $this->render() );
	}

	/**
	 * An abandoned run enables the button, hides the bar and says so.
	 */
	public function test_render_abandoned_run_can_be_restarted(): void {
		$this->options->set_reindex_state(
			'content',
			array(
				'run'        => 'r1',
				'phase'      => 'upsert',
				'last_id'    => 50,
				'sent'       => 50,
				'deleted'    => 0,
				'total'      => 200,
				'task_uids'  => array(),
				'started_at' => time() - 10000,
				'updated_at' => time() - 10000,
				'status'     => 'running',
				'error'      => '',
			)
		);
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		$this->transport->queue( self::json_response( 200, array( 'numberOfDocuments' => 0 ) ) )->queue( self::tasks_response( array() ) );

		$html = $this->render();

		$this->assertStringNotContainsString( 'data-meilisearch-index="content" disabled', $html );
		$this->assertStringContainsString( 'data-meilisearch-index="content">', $html );
		$this->assertStringContainsString( '<progress max="100" value="25" hidden>', $html );
		$this->assertStringContainsString( 'The previous reindex stopped responding. Start a new one.', $html );
	}

	/**
	 * Without a connection nothing remote is called and the reindex button is disabled.
	 */
	public function test_render_without_connection_makes_no_requests(): void {
		$this->option_store[ Options::ADMIN_KEY ] = '';

		$html = $this->render();

		$this->assertSame( array(), $this->transport->requests() );
		$this->assertStringContainsString( 'Connect to Meilisearch on the Connection tab', $html );
		$this->assertStringContainsString( 'data-meilisearch-index="content" disabled', $html );
	}

	/**
	 * The clear-log handler is attached to admin-post.
	 */
	public function test_register_attaches_clear_log_handler(): void {
		$tab = $this->tab();
		Actions\expectAdded( 'admin_post_meilisearch_clear_log' )->once()->with( array( $tab, 'handle_clear_log' ), 10, 0 );

		$tab->register();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Clearing checks the nonce, empties the log and returns to the Status tab.
	 */
	public function test_clear_log_checks_nonce_and_clears(): void {
		$this->log->add( 'sync', 'boom' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'check_admin_referer' )->once()->with( 'meilisearch_clear_log' )->andReturn( 1 );
		Functions\when( 'add_query_arg' )->alias( fn ( $args, $url ) => $url . '?' . http_build_query( $args ) );

		$url = $this->tab()->process_clear_log();

		$this->assertSame( array(), $this->log->all() );
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=meilisearch&tab=status&meilisearch-log-cleared=1', $url );
	}

	/**
	 * Users without manage_options cannot clear the log.
	 */
	public function test_clear_log_requires_capability(): void {
		$this->log->add( 'sync', 'boom' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_die' )->once()->andReturnUsing(
			function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		try {
			$this->tab()->process_clear_log();
			$this->fail( 'wp_die() must stop the request.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertCount( 1, $this->log->all() );
	}
}
