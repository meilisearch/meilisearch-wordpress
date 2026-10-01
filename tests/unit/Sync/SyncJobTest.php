<?php
/**
 * Tests for SyncJob.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Sync;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Sync\SyncJob;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\Support\SyncFixtures;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Reconciliation, retries and the unencodable-document rule.
 */
final class SyncJobTest extends TestCase {

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
	 * Stubs WordPress state and seeds posts.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_wordpress_state();
		$this->transport = new FakeTransport();
		$this->options   = new Options();
		$this->log       = new ErrorLog();
		$this->documents = array();

		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post( array( 'ID' => 11 ) );
	}

	/**
	 * The job under test.
	 *
	 * @return SyncJob
	 */
	private function job(): SyncJob {
		$builder = self::builder(
			fn ( \WP_Post $post ) => array_key_exists( (int) $post->ID, $this->documents )
				? $this->documents[ (int) $post->ID ]
				: array(
					'id'    => (int) $post->ID,
					'title' => (string) $post->post_title,
				)
		);
		return new SyncJob(
			$this->make_clients( $this->options, $this->transport ),
			new IndexNames( $this->options ),
			new Indexability( $this->options ),
			array( 'content' => $builder ),
			new Queue(),
			$this->log
		);
	}

	/**
	 * The handler is attached with accepted_args 1 so the payload (and its attempt counter) arrives intact.
	 */
	public function test_register_attaches_handler_with_one_argument(): void {
		$job = $this->job();
		Actions\expectAdded( Queue::SYNC_POSTS )->once()->with( array( $job, 'handle' ), 10, 1 );

		$job->register();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * An indexable post is upserted and a missing post is deleted, in one call each.
	 */
	public function test_indexable_post_is_upserted_and_missing_post_deleted(): void {
		$this->transport->queue( self::task_response( 1 ) )->queue( self::task_response( 2 ) );

		$result = $this->job()->reconcile( 'content', array( 10, 99 ) );

		$this->assertSame(
			array(
				'upserted' => 1,
				'deleted'  => 1,
				'skipped'  => 0,
			),
			$result
		);
		$requests = $this->transport->requests();
		$this->assertCount( 2, $requests );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertStringContainsString( '/indexes/wp_test_content/documents', $requests[0]['url'] );
		$this->assertSame(
			array(
				array(
					'id'    => 10,
					'title' => 'Post 10',
				),
			),
			$requests[0]['body']
		);
		$this->assertStringContainsString( '/indexes/wp_test_content/documents/delete-batch', $requests[1]['url'] );
		$this->assertSame( array( 99 ), $requests[1]['body'] );
	}

	/**
	 * Every non-public post is deleted, never upserted.
	 */
	public function test_non_public_posts_are_deleted(): void {
		$this->add_post(
			array(
				'ID'          => 20,
				'post_status' => 'draft',
			)
		);
		$this->add_post(
			array(
				'ID'          => 21,
				'post_status' => 'private',
			)
		);
		$this->add_post(
			array(
				'ID'          => 22,
				'post_status' => 'pending',
			)
		);
		$this->add_post(
			array(
				'ID'          => 23,
				'post_status' => 'trash',
			)
		);
		$this->add_post(
			array(
				'ID'            => 24,
				'post_password' => 'secret',
			)
		);
		$this->transport->queue( self::task_response( 1 ) );

		$result = $this->job()->reconcile( 'content', array( 20, 21, 22, 23, 24 ) );

		$this->assertSame( 5, $result['deleted'] );
		$this->assertSame( 0, $result['upserted'] );
		$requests = $this->transport->requests();
		$this->assertCount( 1, $requests );
		$this->assertStringContainsString( '/delete-batch', $requests[0]['url'] );
		$this->assertSame( array( 20, 21, 22, 23, 24 ), $requests[0]['body'] );
	}

	/**
	 * A post whose type now belongs to another index is removed from this one.
	 */
	public function test_post_of_other_type_is_deleted_from_this_index(): void {
		$this->add_post(
			array(
				'ID'        => 30,
				'post_type' => 'nav_menu_item',
			)
		);
		$this->transport->queue( self::task_response( 1 ) );

		$result = $this->job()->reconcile( 'content', array( 30 ) );

		$this->assertSame( 1, $result['deleted'] );
	}

	/**
	 * Review Focus 1: a document that cannot be JSON-encoded is skipped and logged, the rest of the batch is indexed,
	 * and the post is deleted so a stale copy does not linger.
	 */
	public function test_unencodable_document_is_skipped_and_logged(): void {
		$this->add_post( array( 'ID' => 12 ) );
		$this->documents[12] = array(
			'id'    => 12,
			'title' => "Legacy \xB1\x31 import",
		);
		$this->transport->queue( self::task_response( 1 ) )->queue( self::task_response( 2 ) );

		$result = $this->job()->reconcile( 'content', array( 10, 12, 11 ) );

		$this->assertSame(
			array(
				'upserted' => 2,
				'deleted'  => 1,
				'skipped'  => 1,
			),
			$result
		);
		$requests = $this->transport->requests();
		$this->assertSame( array( 10, 11 ), array_column( $requests[0]['body'], 'id' ) );
		$this->assertSame( array( 12 ), $requests[1]['body'] );
		$entries = $this->log->all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'sync', $entries[0]['context'] );
		$this->assertStringContainsString( 'Post 12', $entries[0]['message'] );
		$this->assertStringContainsString( 'JSON', $entries[0]['message'] );
	}

	/**
	 * A builder that returns null skips, logs and deletes the post.
	 */
	public function test_unbuildable_post_is_skipped_logged_and_deleted(): void {
		$this->documents[11] = null;
		$this->transport->queue( self::task_response( 1 ) )->queue( self::task_response( 2 ) );

		$result = $this->job()->reconcile( 'content', array( 10, 11 ) );

		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array( 11 ), $this->transport->requests()[1]['body'] );
		$this->assertStringContainsString( 'Post 11', $this->log->all()[0]['message'] );
	}

	/**
	 * The pending markers are gone before the first post is read.
	 */
	public function test_handle_clears_pending_markers_before_reading_posts(): void {
		$this->transient_store['meilisearch_pending_1_10'] = 1;
		$seen = array();
		Functions\when( 'get_post' )->alias(
			function ( $id ) use ( &$seen ) {
				$seen[] = array_key_exists( 'meilisearch_pending_1_10', $this->transient_store );
				return $this->posts[ (int) $id ] ?? null;
			}
		);
		$this->transport->queue( self::task_response( 1 ) );

		$this->job()->handle(
			array(
				'index'   => 'content',
				'ids'     => array( 10 ),
				'attempt' => 1,
			)
		);

		$this->assertSame( array( false ), $seen );
	}

	/**
	 * A failure reschedules the same payload with attempt + 1 after BACKOFF[attempt - 1].
	 */
	public function test_failure_reschedules_next_attempt_with_backoff(): void {
		$this->transport->queue( ApiError::transport( 'cURL error 7: Failed to connect' ) );
		$before = time();
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with(
				\Mockery::on( fn ( $timestamp ) => $timestamp >= $before + 300 && $timestamp <= time() + 300 ),
				Queue::SYNC_POSTS,
				array(
					array(
						'index'   => 'content',
						'ids'     => array( 10 ),
						'attempt' => 3,
					),
				),
				Queue::GROUP
			)
			->andReturn( 77 );

		$this->job()->handle(
			array(
				'index'   => 'content',
				'ids'     => array( 10 ),
				'attempt' => 2,
			)
		);

		$this->assertSame( array(), $this->log->all() );
	}

	/**
	 * Deletes are retried like upserts.
	 */
	public function test_failed_delete_is_retried(): void {
		$this->transport->queue( new ApiError( 'Service unavailable', 'http_error', 503 ) );
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with(
				\Mockery::type( 'int' ),
				Queue::SYNC_POSTS,
				array(
					array(
						'index'   => 'content',
						'ids'     => array( 99 ),
						'attempt' => 2,
					),
				),
				Queue::GROUP
			)
			->andReturn( 5 );

		$this->job()->handle(
			array(
				'index'   => 'content',
				'ids'     => array( 99 ),
				'attempt' => 1,
			)
		);
		$this->addToAssertionCount( 1 );
	}

	/**
	 * After the last attempt the error is logged and rethrown so Action Scheduler marks the action failed.
	 */
	public function test_last_attempt_logs_and_rethrows(): void {
		$this->transport->queue( ApiError::transport( 'timeout' ) );
		Functions\expect( 'as_schedule_single_action' )->never();

		try {
			$this->job()->handle(
				array(
					'index'   => 'content',
					'ids'     => array( 10 ),
					'attempt' => SyncJob::MAX_ATTEMPTS,
				)
			);
			$this->fail( 'The error must be rethrown.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'transport_error', $e->error_code );
		}

		$this->assertStringContainsString( 'after 6 attempt(s)', $this->log->all()[0]['message'] );
	}

	/**
	 * Attempt 5 failing schedules attempt 6 after the last delay (6 h).
	 */
	public function test_fifth_attempt_schedules_sixth_after_last_delay(): void {
		$this->transport->queue( ApiError::transport( 'timeout' ) );
		$before = time();
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with(
				\Mockery::on( fn ( $timestamp ) => $timestamp >= $before + 21600 && $timestamp <= time() + 21600 ),
				Queue::SYNC_POSTS,
				array(
					array(
						'index'   => 'content',
						'ids'     => array( 10 ),
						'attempt' => 6,
					),
				),
				Queue::GROUP
			)
			->andReturn( 3 );

		$this->job()->handle(
			array(
				'index'   => 'content',
				'ids'     => array( 10 ),
				'attempt' => 5,
			)
		);
		$this->assertSame( array(), $this->log->all() );
	}

	/**
	 * If the retry cannot be stored, the job fails loudly instead of dropping the change.
	 */
	public function test_unschedulable_retry_rethrows(): void {
		$this->transport->queue( ApiError::transport( 'timeout' ) );
		Functions\expect( 'as_schedule_single_action' )->once()->andReturn( 0 );

		$this->expectException( ApiError::class );
		$this->job()->handle(
			array(
				'index'   => 'content',
				'ids'     => array( 10 ),
				'attempt' => 1,
			)
		);
	}

	/**
	 * Without a connection there is nothing to sync: no request, no error, markers still cleared.
	 */
	public function test_unconfigured_connection_returns_silently(): void {
		$this->option_store[ Options::ADMIN_KEY ]          = '';
		$this->transient_store['meilisearch_pending_1_10'] = 1;

		$this->job()->handle(
			array(
				'index'   => 'content',
				'ids'     => array( 10 ),
				'attempt' => 1,
			)
		);

		$this->assertSame( array(), $this->transport->requests() );
		$this->assertSame( array(), $this->transient_store );
		$this->assertSame( array(), $this->log->all() );
	}

	/**
	 * A malformed payload is logged and ignored.
	 */
	public function test_invalid_payload_is_logged_and_ignored(): void {
		$this->job()->handle(
			array(
				'index' => 'products',
				'ids'   => array( 10 ),
			)
		);
		$this->job()->handle(
			array(
				'index' => 'content',
				'ids'   => array( 'x', -3 ),
			)
		);

		$this->assertSame( array(), $this->transport->requests() );
		$this->assertCount( 2, $this->log->all() );
	}
}
