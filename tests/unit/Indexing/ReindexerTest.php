<?php
/**
 * Tests for Reindexer.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Indexing\DatabaseReadError;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\Support\SyncFixtures;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Upsert batches, keyset cursor, sweep, task checks, failure handling and run guards.
 */
final class ReindexerTest extends TestCase {

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
	 * Post ID => document override returned by the builder.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $documents = array();

	/**
	 * Stubs WordPress state.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_wordpress_state();
		$this->transport = new FakeTransport();
		$this->options   = new Options();
		$this->log       = new ErrorLog();
		$this->documents = array();
	}

	/**
	 * Removes the $wpdb double.
	 */
	protected function tear_down(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	/**
	 * The reindexer under test.
	 *
	 * @return Reindexer
	 */
	private function reindexer(): Reindexer {
		$builder = self::builder(
			fn ( \WP_Post $post ) => array_key_exists( (int) $post->ID, $this->documents )
				? $this->documents[ (int) $post->ID ]
				: array( 'id' => (int) $post->ID )
		);
		return $this->make_reindexer(
			$this->options,
			$this->make_clients( $this->options, $this->transport ),
			new Queue(),
			$this->log,
			array( 'content' => $builder )
		);
	}

	/**
	 * Seeds a running run.
	 *
	 * @param array<string, mixed> $overrides State fields.
	 */
	private function seed_run( array $overrides = array() ): void {
		$this->options->set_reindex_state(
			'content',
			array_merge(
				array(
					'run'        => 'r1',
					'phase'      => 'upsert',
					'last_id'    => 0,
					'sent'       => 0,
					'deleted'    => 0,
					'total'      => 2,
					'task_uids'  => array(),
					'started_at' => time(),
					'status'     => 'running',
					'error'      => '',
				),
				$overrides
			)
		);
	}

	/**
	 * Expects exactly one REINDEX_BATCH action for run r1, delayed by $delay seconds.
	 *
	 * @param int $delay Seconds.
	 */
	private function expect_next_step( int $delay ): void {
		$before = time();
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with(
				\Mockery::on( fn ( $timestamp ) => $timestamp >= $before + $delay && $timestamp <= time() + $delay ),
				Queue::REINDEX_BATCH,
				array(
					array(
						'logical' => 'content',
						'run'     => 'r1',
					),
				),
				Queue::GROUP
			)
			->andReturn( 9 );
	}

	/**
	 * Runs the batch handler for run r1.
	 */
	private function handle(): void {
		$this->reindexer()->handle_batch(
			array(
				'logical' => 'content',
				'run'     => 'r1',
			)
		);
	}

	/**
	 * Current stored state.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		return (array) $this->options->reindex_state( 'content' );
	}

	/**
	 * The handler is attached with accepted_args 1.
	 */
	public function test_register_attaches_batch_handler(): void {
		$reindexer = $this->reindexer();
		Actions\expectAdded( Queue::REINDEX_BATCH )->once()->with( array( $reindexer, 'handle_batch' ), 10, 1 );

		$reindexer->register();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * The last (short) upsert batch writes to the live index and switches to the sweep with the cursor reset.
	 */
	public function test_last_upsert_batch_switches_to_sweep(): void {
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post( array( 'ID' => 11 ) );
		$this->install_wpdb( array( array( '10', '11' ) ) );
		$this->transport->queue( self::task_response( 7 ) );
		$this->expect_next_step( 0 );

		$this->handle();

		$requests = $this->transport->requests();
		$this->assertCount( 1, $requests );
		$this->assertStringContainsString( '/indexes/wp_test_content/documents?', $requests[0]['url'] );
		$this->assertSame( array( array( 'id' => 10 ), array( 'id' => 11 ) ), $requests[0]['body'] );
		$state = $this->state();
		$this->assertSame( 'sweep', $state['phase'] );
		$this->assertSame( 0, $state['last_id'] );
		$this->assertSame( 2, $state['sent'] );
		$this->assertSame( array( 7 ), $state['task_uids'] );
		$this->assertSame( 'running', $state['status'] );
	}

	/**
	 * A full batch chains the next batch immediately; the batch size comes from the filter.
	 */
	public function test_full_batch_chains_next_batch(): void {
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post( array( 'ID' => 11 ) );
		$wpdb = $this->install_wpdb( array( array( 10, 11 ) ) );
		Filters\expectApplied( 'meilisearch_reindex_batch_size' )->once()->with( Reindexer::DEFAULT_BATCH )->andReturn( 2 );
		$this->transport->queue( self::task_response( 7 ) );
		$this->expect_next_step( 0 );

		$this->handle();

		$state = $this->state();
		$this->assertSame( 'upsert', $state['phase'] );
		$this->assertSame( 11, $state['last_id'] );
		$this->assertSame( array( 'post', 'page', 0, 2 ), $wpdb->prepared[0][1] );
		$this->assertStringContainsString( 'ID > %d ORDER BY ID ASC LIMIT %d', $wpdb->prepared[0][0] );
	}

	/**
	 * A page where every row is vetoed still advances the cursor and does not end the phase.
	 */
	public function test_filtered_page_advances_cursor_without_finishing(): void {
		$this->seed_run( array( 'last_id' => 5 ) );
		$this->add_post( array( 'ID' => 20 ) );
		$this->add_post( array( 'ID' => 21 ) );
		$this->install_wpdb( array( array( 20, 21 ) ) );
		Filters\expectApplied( 'meilisearch_reindex_batch_size' )->andReturn( 2 );
		Filters\expectApplied( 'meilisearch_should_index_post' )->andReturn( false );
		$this->expect_next_step( 0 );

		$this->handle();

		$this->assertSame( array(), $this->transport->requests() );
		$state = $this->state();
		$this->assertSame( 'upsert', $state['phase'] );
		$this->assertSame( 21, $state['last_id'] );
		$this->assertSame( 0, $state['sent'] );
	}

	/**
	 * An unencodable document is skipped and logged; the rest of the batch is sent.
	 */
	public function test_unencodable_document_is_skipped_during_reindex(): void {
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post( array( 'ID' => 11 ) );
		$this->documents[11] = array(
			'id'    => 11,
			'title' => "\xC3\x28",
		);
		$this->install_wpdb( array( array( 10, 11 ) ) );
		$this->transport->queue( self::task_response( 7 ) );
		$this->expect_next_step( 0 );

		$this->handle();

		$this->assertSame( array( array( 'id' => 10 ) ), $this->transport->requests()[0]['body'] );
		$this->assertSame( 1, $this->state()['sent'] );
		$this->assertStringContainsString( 'Post 11', $this->log->all()[0]['message'] );
	}

	/**
	 * A sweep page deletes documents whose post is missing or no longer indexable; the last page starts finalizing.
	 */
	public function test_sweep_deletes_stale_documents(): void {
		$this->seed_run( array( 'phase' => 'sweep' ) );
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post(
			array(
				'ID'          => 11,
				'post_status' => 'draft',
			)
		);
		$wpdb = $this->install_wpdb( array( array( '10', '11' ) ) );
		$this->transport
			->queue(
				self::json_response(
					200,
					array(
						'results' => array( array( 'id' => 10 ), array( 'id' => 11 ), array( 'id' => 999999 ) ),
						'offset'  => 0,
						'limit'   => 1000,
						'total'   => 3,
					)
				)
			)
			->queue( self::task_response( 8 ) );
		$this->expect_next_step( Reindexer::FINALIZE_POLL );

		$this->handle();

		$requests = $this->transport->requests();
		$this->assertStringContainsString( '/indexes/wp_test_content/documents/fetch', $requests[0]['url'] );
		$this->assertSame(
			array(
				'filter' => 'id > 0',
				'sort'   => array( 'id:asc' ),
				'fields' => array( 'id' ),
				'limit'  => 1000,
			),
			$requests[0]['body']
		);
		$this->assertStringContainsString( '/documents/delete-batch', $requests[1]['url'] );
		$this->assertSame( array( 11, 999999 ), $requests[1]['body'] );
		$this->assertStringContainsString( 'ID IN (%d, %d, %d)', $wpdb->prepared[0][0] );
		$state = $this->state();
		$this->assertSame( 'finalizing', $state['phase'] );
		$this->assertSame( 2, $state['deleted'] );
		$this->assertSame( 999999, $state['last_id'] );
		$this->assertSame( array( 8 ), $state['task_uids'] );
	}

	/**
	 * A full sweep page with nothing stale continues from the last ID.
	 */
	public function test_full_sweep_page_continues(): void {
		$this->seed_run(
			array(
				'phase'   => 'sweep',
				'last_id' => 500,
			)
		);
		$ids = range( 501, 1500 );
		foreach ( $ids as $id ) {
			$this->add_post( array( 'ID' => $id ) );
		}
		$this->install_wpdb( array( $ids ) );
		$this->transport->queue(
			self::json_response(
				200,
				array(
					'results' => array_map( static fn ( int $id ): array => array( 'id' => $id ), $ids ),
					'offset'  => 0,
					'limit'   => 1000,
					'total'   => 1000,
				)
			)
		);
		$this->expect_next_step( 0 );

		$this->handle();

		$this->assertCount( 1, $this->transport->requests() );
		$this->assertSame( 'id > 500', $this->transport->requests()[0]['body']['filter'] );
		$state = $this->state();
		$this->assertSame( 'sweep', $state['phase'] );
		$this->assertSame( 1500, $state['last_id'] );
		$this->assertSame( 0, $state['deleted'] );
	}

	/**
	 * Starting a run does not wait for (or fail on) a settings task Meilisearch is still processing: the task is recorded.
	 */
	public function test_start_records_a_settings_task_still_processing(): void {
		Functions\when( 'wp_generate_password' )->justReturn( 'abcd' );
		$this->install_wpdb( array(), '2' );
		$this->transport
			->respond(
				404,
				array(
					'code'    => 'index_not_found',
					'message' => 'missing',
				)
			)
			->queue( self::task_response( 1 ) )
			->respond(
				200,
				array(
					'uid'    => 1,
					'status' => 'processing',
				)
			)
			->queue( self::task_response( 2 ) )
			->respond(
				200,
				array(
					'uid'    => 2,
					'status' => 'enqueued',
				)
			);
		Functions\expect( 'as_schedule_single_action' )->once()->andReturn( 9 );

		$state = $this->reindexer()->start( 'content' );

		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 'upsert', $state['phase'] );
		$this->assertSame( 2, $state['settings_task'] );
		$this->assertCount( 5, $this->transport->requests(), 'One poll per task, no waiting.' );
		$this->assertSame( $this->options->content_signature(), $state['content_signature'], 'The content settings the run is built with.' );
	}

	/**
	 * The sweep reads the index with a filter and a sort on id: it waits until the settings task has been applied.
	 */
	public function test_sweep_waits_for_the_settings_task(): void {
		$this->seed_run(
			array(
				'phase'         => 'sweep',
				'settings_task' => 2,
			)
		);
		$this->transport->respond(
			200,
			array(
				'uid'    => 2,
				'status' => 'processing',
			)
		);
		$this->expect_next_step( Reindexer::FINALIZE_POLL );

		$this->handle();

		$requests = $this->transport->requests();
		$this->assertCount( 1, $requests );
		$this->assertStringEndsWith( '/tasks/2', $requests[0]['url'] );
		$state = $this->state();
		$this->assertSame( 'sweep', $state['phase'] );
		$this->assertSame( 2, $state['settings_task'] );
		$this->assertSame( 'running', $state['status'] );
	}

	/**
	 * Once the settings task has succeeded the sweep runs in the same step.
	 */
	public function test_sweep_runs_once_the_settings_task_succeeded(): void {
		$this->seed_run(
			array(
				'phase'         => 'sweep',
				'settings_task' => 2,
			)
		);
		$this->transport
			->queue( self::succeeded_task( 2 ) )
			->queue( self::json_response( 200, array( 'results' => array() ) ) );
		$this->expect_next_step( Reindexer::FINALIZE_POLL );

		$this->handle();

		$this->assertStringContainsString( '/documents/fetch', $this->transport->requests()[1]['url'] );
		$state = $this->state();
		$this->assertSame( 'finalizing', $state['phase'] );
		$this->assertArrayNotHasKey( 'settings_task', $state );
	}

	/**
	 * A settings task that failed fails the run with the engine's message before anything is swept.
	 */
	public function test_failed_settings_task_fails_the_run(): void {
		$this->seed_run(
			array(
				'phase'         => 'sweep',
				'settings_task' => 2,
			)
		);
		$this->transport->respond(
			200,
			array(
				'uid'    => 2,
				'status' => 'failed',
				'error'  => array( 'message' => 'Invalid filterable attribute.' ),
			)
		);
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->handle();

		$this->assertCount( 1, $this->transport->requests() );
		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 'Invalid filterable attribute.', $this->state()['error'] );
	}

	/**
	 * Actions of a superseded or finished run are ignored.
	 */
	public function test_stale_run_is_ignored(): void {
		$this->seed_run( array( 'run' => 'r2' ) );
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->handle();

		$this->assertSame( array(), $this->transport->requests() );
		$this->assertSame( 'r2', $this->state()['run'] );
	}

	/**
	 * While tasks are still processing, finalization polls again later and keeps only the pending UIDs.
	 */
	public function test_finalize_waits_for_processing_tasks(): void {
		$this->seed_run(
			array(
				'phase'     => 'finalizing',
				'task_uids' => array( 7, 8 ),
			)
		);
		$this->transport
			->queue(
				self::tasks_response(
					array(
						array(
							'uid'    => 8,
							'status' => 'processing',
						),
					)
				)
			)
			->queue( self::tasks_response( array() ) );
		$this->expect_next_step( Reindexer::FINALIZE_POLL );

		$this->handle();

		$requests = $this->transport->requests();
		$this->assertStringContainsString( 'uids=7%2C8', $requests[0]['url'] );
		$this->assertStringContainsString( 'statuses=enqueued%2Cprocessing', $requests[0]['url'] );
		$this->assertStringContainsString( 'uids=7', $requests[1]['url'] );
		$this->assertStringContainsString( 'statuses=failed%2Ccanceled', $requests[1]['url'] );
		$state = $this->state();
		$this->assertSame( 'finalizing', $state['phase'] );
		$this->assertSame( array( 8 ), $state['task_uids'] );
	}

	/**
	 * All tasks succeeded: the run is done and the flags are updated.
	 */
	public function test_finalize_marks_run_done(): void {
		$this->seed_run(
			array(
				'phase'     => 'finalizing',
				'task_uids' => array( 7 ),
			)
		);
		$this->options->flag_reindex( 'content', true ); // Raised after the run started.
		$this->transport->queue( self::tasks_response( array() ) )->queue( self::tasks_response( array() ) );
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->handle();

		$this->assertCount( 2, $this->transport->requests() );
		$this->assertSame( 'done', $this->state()['status'] );
		$this->assertTrue( $this->options->needs_reindex( 'content' ), 'A flag raised mid-run must survive finalization.' );
		$this->assertTrue( (bool) $this->options->state( 'first_reindex_done' ) );
		$this->assertTrue( $this->options->is_populated( 'content' ), 'A done run populates the index.' );
	}

	/**
	 * The content settings changed while the run was sending documents (a post type, taxonomy or meta key
	 * added mid-run): the posts sent before the change lack it, so the run does not mark the index populated.
	 */
	public function test_finalize_does_not_populate_when_the_content_settings_changed_mid_run(): void {
		$this->seed_run(
			array(
				'phase'             => 'finalizing',
				'task_uids'         => array( 7 ),
				'content_signature' => $this->options->content_signature(),
			)
		);
		$this->option_store[ Options::CONTENT ]['post_types'][] = 'book';
		$this->transport->queue( self::tasks_response( array() ) )->queue( self::tasks_response( array() ) );

		$this->handle();

		$this->assertSame( 'done', $this->state()['status'] );
		$this->assertFalse( $this->options->is_populated( 'content' ), 'Built with two configurations: not populated.' );
	}

	/**
	 * Settings unchanged since the run started: the index is populated.
	 */
	public function test_finalize_populates_when_the_content_settings_are_those_of_the_run(): void {
		$this->seed_run(
			array(
				'phase'             => 'finalizing',
				'task_uids'         => array( 7 ),
				'content_signature' => $this->options->content_signature(),
			)
		);
		$this->transport->queue( self::tasks_response( array() ) )->queue( self::tasks_response( array() ) );

		$this->handle();

		$this->assertTrue( $this->options->is_populated( 'content' ) );
	}

	/**
	 * A failed task fails the run with the Meilisearch error, logged.
	 */
	public function test_failed_task_fails_run(): void {
		$this->seed_run(
			array(
				'phase'     => 'finalizing',
				'task_uids' => array( 7 ),
			)
		);
		$this->transport
			->queue( self::tasks_response( array() ) )
			->queue(
				self::tasks_response(
					array(
						array(
							'uid'    => 7,
							'status' => 'failed',
							'error'  => array(
								'message' => 'Document identifier `"a b"` is invalid.',
								'code'    => 'invalid_document_id',
							),
						),
					)
				)
			);
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->handle();

		$state = $this->state();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertStringContainsString( 'invalid', $state['error'] );
		$this->assertSame( 'reindex', $this->log->all()[0]['context'] );
		$this->assertFalse( (bool) $this->options->state( 'first_reindex_done' ) );
		$this->assertTrue( $this->options->needs_reindex( 'content' ), 'A failed run leaves the index flagged.' );
	}

	/**
	 * Once TASK_PRUNE_AT UIDs accumulate, finished tasks are checked and dropped so the state stays small.
	 */
	public function test_task_uids_are_pruned(): void {
		$this->seed_run( array( 'task_uids' => range( 1, 19 ) ) );
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		Filters\expectApplied( 'meilisearch_reindex_batch_size' )->andReturn( 1 );
		$this->transport
			->queue( self::task_response( 20 ) )
			->queue(
				self::tasks_response(
					array(
						array(
							'uid'    => 20,
							'status' => 'enqueued',
						),
					)
				)
			)
			->queue( self::tasks_response( array() ) );
		$this->expect_next_step( 0 );

		$this->handle();

		$this->assertSame( array( 20 ), $this->state()['task_uids'] );
		$this->assertStringContainsString( 'statuses=failed%2Ccanceled', $this->transport->requests()[2]['url'] );
	}

	/**
	 * A failure found while pruning fails the run early.
	 */
	public function test_pruning_detects_failure_early(): void {
		$this->seed_run( array( 'task_uids' => range( 1, 19 ) ) );
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		Filters\expectApplied( 'meilisearch_reindex_batch_size' )->andReturn( 1 );
		$this->transport
			->queue( self::task_response( 20 ) )
			->queue( self::tasks_response( array() ) )
			->queue(
				self::tasks_response(
					array(
						array(
							'uid'    => 3,
							'status' => 'failed',
							'error'  => array( 'message' => 'boom' ),
						),
					)
				)
			);
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->handle();

		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 'boom', $this->state()['error'] );
	}

	/**
	 * Errors worth retrying: no response, request timeout, rate limit, server or proxy errors.
	 *
	 * @return array<string, array{0: ApiError}>
	 */
	public static function transient_errors(): array {
		return array(
			'transport' => array( ApiError::transport( 'cURL error 28' ) ),
			'408'       => array( new ApiError( 'Request timeout.', 'http_408', 408 ) ),
			'429'       => array( new ApiError( 'Too many requests.', 'too_many_requests', 429 ) ),
			'502'       => array( new ApiError( 'Bad gateway.', 'http_502', 502 ) ),
			'503'       => array( new ApiError( 'Service unavailable.', 'http_503', 503 ) ),
		);
	}

	/**
	 * A transient error keeps the run running and reschedules the same step with backoff; the counter lives in the state.
	 *
	 * @dataProvider transient_errors
	 *
	 * @param ApiError $error Error thrown by the upsert request.
	 */
	public function test_transient_batch_error_reschedules_the_step( ApiError $error ): void {
		$this->seed_run( array( 'updated_at' => 1 ) );
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( $error );
		$this->expect_next_step( Reindexer::RETRY_BACKOFF[0] );

		$this->handle();

		$state = $this->state();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 'upsert', $state['phase'] );
		$this->assertSame( 0, $state['last_id'], 'The failed batch is sent again.' );
		$this->assertSame( 1, $state['retries'] );
		$this->assertGreaterThan( time() - 5, $state['updated_at'] );
	}

	/**
	 * Each further transient error waits longer.
	 */
	public function test_retries_back_off(): void {
		$this->seed_run( array( 'retries' => 1 ) );
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( new ApiError( 'Bad gateway.', 'http_502', 502 ) );
		$this->expect_next_step( Reindexer::RETRY_BACKOFF[1] );

		$this->handle();

		$this->assertSame( 2, $this->state()['retries'] );
	}

	/**
	 * A successful step resets the counter: the limit is per step, not per run.
	 */
	public function test_successful_step_resets_the_retry_counter(): void {
		$this->seed_run( array( 'retries' => 2 ) );
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( self::task_response( 7 ) );
		$this->expect_next_step( 0 );

		$this->handle();

		$this->assertArrayNotHasKey( 'retries', $this->state() );
	}

	/**
	 * Errors that a retry cannot fix fail the run at once.
	 *
	 * @return array<string, array{0: ApiError}>
	 */
	public static function permanent_errors(): array {
		return array(
			'invalid key'     => array( new ApiError( 'The provided API key is invalid.', 'invalid_api_key', 403 ) ),
			'missing index'   => array( new ApiError( 'Index not found.', 'index_not_found', 404 ) ),
			'payload too big' => array( new ApiError( 'Payload too large.', 'payload_too_large', 413 ) ),
			'task timeout'    => array( new ApiError( 'Timed out.', 'task_timeout' ) ),
		);
	}

	/**
	 * A non-retryable error fails the run at once and is rethrown for Action Scheduler.
	 *
	 * @dataProvider permanent_errors
	 *
	 * @param ApiError $error Error thrown by the upsert request.
	 */
	public function test_permanent_batch_error_fails_at_once( ApiError $error ): void {
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( $error );
		Functions\expect( 'as_schedule_single_action' )->never();

		try {
			$this->handle();
			$this->fail( 'The error must be rethrown.' );
		} catch ( ApiError $e ) {
			$this->assertSame( $error->error_code, $e->error_code );
		}

		$this->assertSame( 'failed', $this->state()['status'] );
	}

	/**
	 * When the retry cannot be scheduled the run fails instead of hanging.
	 */
	public function test_unschedulable_retry_fails_the_run(): void {
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( ApiError::transport( 'cURL error 28' ) );
		Functions\expect( 'as_schedule_single_action' )->once()->andReturn( 0 );

		try {
			$this->handle();
			$this->fail( 'The error must be rethrown.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'transport_error', $e->error_code );
		}

		$this->assertSame( 'failed', $this->state()['status'] );
	}

	/**
	 * A transient error that outlasts every retry marks the run failed and is rethrown for Action Scheduler.
	 */
	public function test_batch_error_fails_run_and_rethrows(): void {
		$this->seed_run( array( 'retries' => count( Reindexer::RETRY_BACKOFF ) ) );
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( ApiError::transport( 'cURL error 28' ) );
		Functions\expect( 'as_schedule_single_action' )->never();

		try {
			$this->handle();
			$this->fail( 'The error must be rethrown.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'transport_error', $e->error_code );
		}

		$state = $this->state();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 'cURL error 28', $state['error'] );
	}

	/**
	 * A failed candidate query during the sweep is not "no rows": nothing is deleted and the run fails.
	 */
	public function test_failed_sweep_query_deletes_nothing_and_fails_run(): void {
		$this->seed_run( array( 'phase' => 'sweep' ) );
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post( array( 'ID' => 11 ) );
		$this->install_wpdb( array( false ) );
		$this->transport->queue(
			self::json_response(
				200,
				array(
					'results' => array( array( 'id' => 10 ), array( 'id' => 11 ) ),
					'offset'  => 0,
					'limit'   => 1000,
					'total'   => 2,
				)
			)
		);
		Functions\expect( 'as_schedule_single_action' )->never();

		try {
			$this->handle();
			$this->fail( 'The database error must be rethrown.' );
		} catch ( DatabaseReadError $e ) {
			$this->assertStringNotContainsString( 'wp_posts', $e->getMessage(), 'The SQL error is not exposed.' );
		}

		$this->assertCount( 1, $this->transport->requests(), 'No delete request may be sent.' );
		$state = $this->state();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 'sweep', $state['phase'] );
		$this->assertSame( 0, $state['deleted'] );
		$this->assertTrue( $this->options->needs_reindex( 'content' ) );
	}

	/**
	 * A failed scan query during the upsert phase is not "exhausted": the run fails instead of moving on to the sweep.
	 */
	public function test_failed_scan_query_fails_run_instead_of_ending_the_upsert_phase(): void {
		$this->seed_run( array( 'last_id' => 5 ) );
		$this->install_wpdb( array( false ) );
		Functions\expect( 'as_schedule_single_action' )->never();

		try {
			$this->handle();
			$this->fail( 'The database error must be rethrown.' );
		} catch ( DatabaseReadError $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( array(), $this->transport->requests() );
		$state = $this->state();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 'upsert', $state['phase'] );
		$this->assertSame( 5, $state['last_id'] );
		$this->assertFalse( $this->options->is_populated( 'content' ) );
	}

	/**
	 * The public helpers report a failed query instead of an empty answer.
	 */
	public function test_non_indexable_and_scan_throw_on_a_failed_query(): void {
		$this->install_wpdb( array( false, false ) );

		try {
			$this->reindexer()->non_indexable( 'content', array( 10 ) );
			$this->fail( 'Expected a database error.' );
		} catch ( DatabaseReadError $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->expectException( DatabaseReadError::class );
		$this->reindexer()->indexable_ids_after( 'content', 0, 10 );
	}

	/**
	 * A failure message that echoes a configured key is stored and logged redacted.
	 */
	public function test_failure_message_is_redacted(): void {
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( new ApiError( 'The provided API key `test-admin-key` is invalid.', 'invalid_api_key', 403 ) );

		try {
			$this->handle();
			$this->fail( 'The error must be rethrown.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'invalid_api_key', $e->error_code );
		}

		$this->assertSame( 'The provided API key `…` is invalid.', $this->state()['error'] );
		$this->assertStringNotContainsString( 'test-admin-key', $this->log->all()[0]['message'] );
	}

	/**
	 * A second start while a recent run is active is refused without touching Meilisearch.
	 */
	public function test_start_refuses_while_running(): void {
		$this->seed_run( array( 'phase' => 'finalizing' ) );

		try {
			$this->reindexer()->start( 'content' );
			$this->fail( 'Expected already_running.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'already_running', $e->getMessage() );
		}
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * After the host, admin key or prefix changed without the connection flow (here MEILISEARCH_HOST in
	 * wp-config.php), a reindex would fill another instance's index while the plugin still serves the
	 * previous connection's state: it is refused until the connection flow runs, without touching Meilisearch.
	 */
	public function test_start_refuses_when_the_connection_changed_since_connect(): void {
		$this->options->set_state( 'fingerprint', md5( 'http://meili.test|test-admin-key|wp_test' ) );
		$this->options = new Options(
			static fn ( string $name ): ?string => 'MEILISEARCH_HOST' === $name ? 'http://other.test' : null
		);

		try {
			$this->reindexer()->start( 'content' );
			$this->fail( 'Expected the reindex to be refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'wp meilisearch connect', $e->getMessage() );
		}
		$this->assertSame( array(), $this->transport->requests() );
		$this->assertNull( $this->options->reindex_state( 'content' ) );
	}

	/**
	 * Inactive logical indexes cannot be reindexed.
	 */
	public function test_start_rejects_inactive_index(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->reindexer()->start( 'products' );
	}

	/**
	 * Only IDs with a public post of an indexed type survive; the rest are reported.
	 */
	public function test_non_indexable_returns_missing_and_unpublished_ids(): void {
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post(
			array(
				'ID'          => 11,
				'post_status' => 'draft',
			)
		);
		$this->add_post( array( 'ID' => 12 ) );
		$wpdb = $this->install_wpdb( array( array( '10', '11', '12' ) ) );

		$this->assertSame( array( 11, 13 ), $this->reindexer()->non_indexable( 'content', array( 10, 11, 12, 13, 13, 0 ) ) );
		$this->assertStringContainsString( 'ID IN (%d, %d, %d, %d) AND post_type IN (%s, %s)', $wpdb->prepared[0][0] );
		$this->assertSame( array( 10, 11, 12, 13, 'post', 'page' ), $wpdb->prepared[0][1] );
		$this->assertSame( array( 5 ), $this->reindexer()->non_indexable( 'products', array( 5 ) ) );
		$this->assertSame( array(), $this->reindexer()->non_indexable( 'content', array() ) );
	}

	/**
	 * Candidate rows are prepared per post type and filtered by the Indexability rule.
	 */
	public function test_indexable_ids_after_filters_candidates(): void {
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post(
			array(
				'ID'          => 11,
				'post_status' => 'draft',
			)
		);
		$this->add_post( array( 'ID' => 12 ) );
		$wpdb = $this->install_wpdb( array( array( '10', '11', '12', '13' ) ) );

		$this->assertSame( array( 10, 12 ), $this->reindexer()->indexable_ids_after( 'content', 5, 4 ) );
		$this->assertStringContainsString( 'post_type IN (%s, %s)', $wpdb->prepared[0][0] );
		$this->assertSame( array( 'post', 'page', 5, 4 ), $wpdb->prepared[0][1] );
	}

	/**
	 * The count comes from SQL.
	 */
	public function test_count_indexable_uses_sql_count(): void {
		$wpdb = $this->install_wpdb( array(), '42' );

		$this->assertSame( 42, $this->reindexer()->count_indexable( 'content' ) );
		$this->assertStringContainsString( 'SELECT COUNT(ID)', $wpdb->prepared[0][0] );
		$this->assertSame( array( 'post', 'page' ), $wpdb->prepared[0][1] );
		$this->assertSame( 0, $this->reindexer()->count_indexable( 'products' ) );
	}

	/**
	 * A stored Content post type that is not registered as public is never selected: not counted, not
	 * scanned, and its documents are stale for the sweep.
	 */
	public function test_non_public_stored_type_is_not_selected(): void {
		$this->option_store[ Options::CONTENT ]['post_types'] = array( 'post', 'shop_coupon', 'page' );
		$this->add_post(
			array(
				'ID'        => 10,
				'post_type' => 'shop_coupon',
			)
		);
		$wpdb = $this->install_wpdb( array( array(), array( 10 ) ), '3' );

		$this->reindexer()->count_indexable( 'content' );
		$this->reindexer()->indexable_ids_after( 'content', 0, 10 );
		$stale = $this->reindexer()->non_indexable( 'content', array( 10 ) );

		$this->assertSame( array( 'post', 'page' ), $wpdb->prepared[0][1] );
		$this->assertSame( array( 'post', 'page', 0, 10 ), $wpdb->prepared[1][1] );
		$this->assertSame( array( 10, 'post', 'page' ), $wpdb->prepared[2][1] );
		$this->assertSame( array( 10 ), $stale );
	}

	/**
	 * Status exposes the stored state.
	 */
	public function test_status_returns_stored_state(): void {
		$this->assertNull( $this->reindexer()->status( 'content' ) );
		$this->seed_run( array( 'phase' => 'sweep' ) );
		$this->assertSame( 'sweep', $this->reindexer()->status( 'content' )['phase'] );
	}

	/**
	 * Emulates the per-process object cache of get_option(): the first read is cached until wp_cache_delete().
	 */
	private function stub_object_cache(): void {
		$cache = array();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) use ( &$cache ) {
				if ( ! array_key_exists( $name, $cache ) ) {
					$cache[ $name ] = array_key_exists( $name, $this->option_store ) ? $this->option_store[ $name ] : $default_value;
				}
				return $cache[ $name ];
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$cache ): bool {
				$this->option_store[ $name ] = $value;
				$cache[ $name ]              = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $name ) use ( &$cache ): bool {
				unset( $cache[ $name ] );
				return true;
			}
		);
	}

	/**
	 * Another process changes the state option while this one holds a cached copy: the next step keeps the change.
	 */
	public function test_step_preserves_changes_made_by_other_writers(): void {
		$this->stub_object_cache();
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( self::task_response( 7 ) );
		$this->expect_next_step( 0 );
		$reindexer = $this->reindexer();
		$reindexer->status( 'content' ); // Warms the cache.

		$other                                = $this->option_store[ Options::STATE ];
		$other['needs_reindex']               = array( 'content' );
		$other['search_key_manual']           = true;
		$other['reindex']['products']         = array(
			'run'    => 'p1',
			'status' => 'running',
		);
		$this->option_store[ Options::STATE ] = $other;

		$reindexer->handle_batch(
			array(
				'logical' => 'content',
				'run'     => 'r1',
			)
		);

		$stored = $this->option_store[ Options::STATE ];
		$this->assertSame( array( 'content' ), $stored['needs_reindex'] );
		$this->assertTrue( $stored['search_key_manual'] );
		$this->assertSame( 'p1', $stored['reindex']['products']['run'] );
		$this->assertSame( 'sweep', $stored['reindex']['content']['phase'] );
	}

	/**
	 * A run replaced by a newer one stops without writing (no request, no state change).
	 */
	public function test_superseded_run_stops_without_writing(): void {
		$this->stub_object_cache();
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( self::task_response( 7 ) );
		Functions\expect( 'as_schedule_single_action' )->never();
		$reindexer = $this->reindexer();
		$reindexer->status( 'content' );
		$newer                                = $this->option_store[ Options::STATE ];
		$newer['reindex']['content']['run']   = 'r2';
		$this->option_store[ Options::STATE ] = $newer;

		// The initial check already sees the new run.
		$reindexer->handle_batch(
			array(
				'logical' => 'content',
				'run'     => 'r1',
			)
		);

		$this->assertSame( array(), $this->transport->requests() );
		$this->assertSame( 'r2', $this->option_store[ Options::STATE ]['reindex']['content']['run'] );
		$this->assertSame( 'running', $this->option_store[ Options::STATE ]['reindex']['content']['status'] );
	}

	/**
	 * A run replaced while a step is in flight raises 'superseded' at the save and leaves the new run alone.
	 */
	public function test_run_replaced_mid_step_is_not_failed(): void {
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( self::task_response( 7 ) );
		Functions\expect( 'as_schedule_single_action' )->never();
		// The batch size filter runs just before the step: replace the run there.
		$replace = function (): void {
			$state                                = $this->option_store[ Options::STATE ];
			$state['reindex']['content']['run']   = 'r2';
			$this->option_store[ Options::STATE ] = $state;
		};
		Filters\expectApplied( 'meilisearch_reindex_batch_size' )->once()->andReturnUsing(
			static function ( $size ) use ( $replace ) {
				$replace();
				return $size;
			}
		);

		$this->handle(); // Must not throw and must not fail run r2.

		$this->assertCount( 1, $this->transport->requests() ); // The step ran; only its save was refused.
		$stored = $this->state();
		$this->assertSame( 'r2', $stored['run'] );
		$this->assertSame( 'running', $stored['status'] );
		$this->assertSame( '', $stored['error'] );
	}

	/**
	 * The exception path of a batch never fails a different current run.
	 */
	public function test_batch_error_does_not_fail_a_different_run(): void {
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		// Not retryable, so the failure path runs (a transient error would be retried instead).
		$this->transport->queue( new ApiError( 'Payload too large.', 'payload_too_large', 413 ) );
		$replace = function (): void {
			$state                                = $this->option_store[ Options::STATE ];
			$state['reindex']['content']['run']   = 'r2';
			$this->option_store[ Options::STATE ] = $state;
		};
		Filters\expectApplied( 'meilisearch_reindex_batch_size' )->once()->andReturnUsing(
			static function ( $size ) use ( $replace ) {
				$replace();
				return $size;
			}
		);

		try {
			$this->handle();
			$this->fail( 'The error must be rethrown.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'payload_too_large', $e->error_code );
		}

		$stored = $this->state();
		$this->assertSame( 'r2', $stored['run'] );
		$this->assertSame( 'running', $stored['status'] );
		$this->assertFalse( $this->options->needs_reindex( 'content' ) );
	}

	/**
	 * A transient error of a run replaced mid-step neither reschedules it nor touches the new run.
	 */
	public function test_transient_error_of_a_replaced_run_is_not_retried(): void {
		$this->seed_run();
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( ApiError::transport( 'cURL error 28' ) );
		$replace = function (): void {
			$state                                = $this->option_store[ Options::STATE ];
			$state['reindex']['content']['run']   = 'r2';
			$this->option_store[ Options::STATE ] = $state;
		};
		Filters\expectApplied( 'meilisearch_reindex_batch_size' )->once()->andReturnUsing(
			static function ( $size ) use ( $replace ) {
				$replace();
				return $size;
			}
		);
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->handle();

		$stored = $this->state();
		$this->assertSame( 'r2', $stored['run'] );
		$this->assertSame( 'running', $stored['status'] );
		$this->assertArrayNotHasKey( 'retries', $stored );
	}

	/**
	 * Starting a run clears the flag; every step stamps updated_at.
	 */
	public function test_steps_stamp_updated_at(): void {
		$this->seed_run( array( 'updated_at' => 1 ) );
		$this->add_post( array( 'ID' => 10 ) );
		$this->install_wpdb( array( array( 10 ) ) );
		$this->transport->queue( self::task_response( 7 ) );
		$this->expect_next_step( 0 );

		$this->handle();

		$this->assertGreaterThan( time() - 5, $this->state()['updated_at'] );
	}

	/**
	 * A run whose started_at is old but updated_at is recent is not abandoned (no schedule lookup, still running).
	 */
	public function test_recent_updated_at_keeps_run_alive(): void {
		$this->seed_run(
			array(
				'phase'      => 'finalizing',
				'started_at' => time() - 10 * Reindexer::STALE_AFTER,
				'updated_at' => time(),
			)
		);
		Functions\expect( 'as_has_scheduled_action' )->never();

		try {
			$this->reindexer()->start( 'content' );
			$this->fail( 'Expected already_running.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'already_running', $e->getMessage() );
		}
	}

	/**
	 * A run untouched for longer than STALE_AFTER with a pending action is still alive; the lookup is made.
	 */
	public function test_old_updated_at_checks_pending_action(): void {
		$this->seed_run(
			array(
				'phase'      => 'finalizing',
				'updated_at' => time() - 2 * Reindexer::STALE_AFTER,
			)
		);
		Functions\expect( 'as_has_scheduled_action' )->once()->andReturn( true );

		try {
			$this->reindexer()->start( 'content' );
			$this->fail( 'Expected already_running.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'already_running', $e->getMessage() );
		}
	}

	/**
	 * A running run untouched past STALE_AFTER with no pending batch is stalled; a recent one is not.
	 */
	public function test_is_stalled(): void {
		$this->seed_run( array( 'updated_at' => time() - 2 * Reindexer::STALE_AFTER ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		$this->assertTrue( $this->reindexer()->is_stalled( 'content' ) );

		$this->seed_run( array( 'updated_at' => time() ) );
		$this->assertFalse( $this->reindexer()->is_stalled( 'content' ) );
	}

	/**
	 * WP-CLI runs flush the runtime object cache after every step, so memory stays flat on large sites.
	 */
	public function test_run_sync_flushes_the_runtime_cache_after_each_step(): void {
		$flushes = 0;
		Functions\when( 'wp_cache_flush_runtime' )->alias(
			static function () use ( &$flushes ): bool {
				++$flushes;
				return true;
			}
		);
		Functions\when( 'wp_cache_supports' )->alias( static fn ( string $feature ): bool => 'flush_runtime' === $feature );
		Functions\when( 'wp_generate_password' )->justReturn( 'abcd' );
		$this->install_wpdb( array( array() ), '0' );
		$this->transport
			->respond(
				404,
				array(
					'code'    => 'index_not_found',
					'message' => 'missing',
				)
			)
			->queue( self::task_response( 1 ) )
			->respond(
				200,
				array(
					'uid'    => 1,
					'status' => 'succeeded',
				)
			)
			->queue( self::task_response( 2 ) )
			->respond(
				200,
				array(
					'uid'    => 2,
					'status' => 'succeeded',
				)
			)
			->respond( 200, array( 'results' => array() ) );

		$this->reindexer()->run_sync( 'content', 100, static function (): void {} );

		$this->assertSame( 'done', $this->state()['status'] );
		// Upsert, sweep, finalize.
		$this->assertSame( 3, $flushes );
	}

	/**
	 * Queues the answers of begin() for a missing index whose tasks succeed at once, then an empty scan.
	 */
	private function queue_cli_begin(): void {
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'abcd' );
		$this->install_wpdb( array( array() ), '0' );
		$this->transport
			->respond(
				404,
				array(
					'code'    => 'index_not_found',
					'message' => 'missing',
				)
			)
			->queue( self::task_response( 1 ) )
			->respond(
				200,
				array(
					'uid'    => 1,
					'status' => 'succeeded',
				)
			)
			->queue( self::task_response( 2 ) )
			->respond(
				200,
				array(
					'uid'    => 2,
					'status' => 'succeeded',
				)
			);
	}

	/**
	 * WP-CLI runs retry a step that hit a transient error in place instead of failing the whole run.
	 */
	public function test_run_sync_retries_a_transient_error(): void {
		$this->queue_cli_begin();
		$this->transport
			->fail( new ApiError( 'Service unavailable.', 'http_503', 503 ) )
			->respond( 200, array( 'results' => array() ) );

		$this->reindexer()->run_sync( 'content', 100, static function (): void {} );

		$this->assertSame( 'done', $this->state()['status'] );
		$this->assertSame( 0, $this->transport->pending() );
	}

	/**
	 * WP-CLI runs still fail at once on an error a retry cannot fix.
	 */
	public function test_run_sync_fails_at_once_on_a_permanent_error(): void {
		$this->queue_cli_begin();
		$this->transport
			->fail( new ApiError( 'The provided API key is invalid.', 'invalid_api_key', 403 ) )
			->respond( 200, array( 'results' => array() ) );

		try {
			$this->reindexer()->run_sync( 'content', 100, static function (): void {} );
			$this->fail( 'The error must be rethrown.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'invalid_api_key', $e->error_code );
		}

		$this->assertSame( 'failed', $this->state()['status'] );
		$this->assertSame( 1, $this->transport->pending(), 'Not retried.' );
	}
}
