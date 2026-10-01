<?php
/**
 * Real-time sync against WordPress and a real Meilisearch.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ChangeCollector;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Sync\SyncJob;

/**
 * Spec § 12.2 SyncTest.
 */
final class SyncTest extends TestCase {

	/**
	 * Live content index UID.
	 *
	 * @var string
	 */
	private string $uid;

	/**
	 * Active HTTP-failure filter.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $http_filter = null;

	/**
	 * Creates the live content index.
	 */
	public function set_up(): void {
		parent::set_up();
		$names = Plugin::instance()->get( 'names' );
		assert( $names instanceof IndexNames );
		$this->uid = $names->uid( 'content' );
		$indexes   = Plugin::instance()->get( 'index_manager' );
		assert( $indexes instanceof IndexManager );
		$indexes->ensure_index( 'content' );
	}

	/**
	 * Makes every HTTP request fail until the end of the test. The filter is removed before tear_down(),
	 * whose cleanup talks to the real Meilisearch.
	 */
	private function fail_http_requests(): void {
		$filter            = static fn () => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		add_filter( 'pre_http_request', $filter );
		$this->http_filter = $filter;
	}

	/**
	 * Restores HTTP before the base class cleans the test indexes.
	 */
	public function tear_down(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter );
			$this->http_filter = null;
		}
		parent::tear_down();
	}

	/**
	 * The collector service.
	 *
	 * @return ChangeCollector
	 */
	private function collector(): ChangeCollector {
		$collector = Plugin::instance()->get( 'collector' );
		assert( $collector instanceof ChangeCollector );
		return $collector;
	}

	/**
	 * Simulates the end of the request, runs the queued jobs and waits for Meilisearch.
	 */
	private function sync(): void {
		$this->collector()->flush();
		$this->run_actions();
		$this->wait_for_tasks();
	}

	/**
	 * IDs currently in the live index.
	 *
	 * @return list<int>
	 */
	private function indexed_ids(): array {
		$ids = array_map( static fn ( array $doc ): int => (int) $doc['id'], $this->index_documents( $this->uid ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Publishing a post indexes it.
	 */
	public function test_published_post_is_indexed(): void {
		$id = self::factory()->post->create( array( 'post_title' => 'Hello Meilisearch' ) );

		$this->sync();

		$this->assertSame( array( $id ), $this->indexed_ids() );
		$this->assertSame( 'Hello Meilisearch', $this->index_documents( $this->uid )[0]['title'] );
	}

	/**
	 * Statuses that leave "publish".
	 *
	 * @return array<string, array{0: string}>
	 */
	public function unpublished_statuses(): array {
		return array(
			'draft'   => array( 'draft' ),
			'private' => array( 'private' ),
			'pending' => array( 'pending' ),
		);
	}

	/**
	 * A post moved out of publish is removed.
	 *
	 * @dataProvider unpublished_statuses
	 *
	 * @param string $status New status.
	 */
	public function test_post_leaving_publish_is_removed( string $status ): void {
		$id = self::factory()->post->create();
		$this->sync();
		$this->assertSame( array( $id ), $this->indexed_ids() );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $status,
			)
		);
		$this->sync();

		$this->assertSame( array(), $this->indexed_ids() );
	}

	/**
	 * Adding a password removes the post.
	 */
	public function test_password_protected_post_is_removed(): void {
		$id = self::factory()->post->create();
		$this->sync();

		wp_update_post(
			array(
				'ID'            => $id,
				'post_password' => 'secret',
			)
		);
		$this->sync();

		$this->assertSame( array(), $this->indexed_ids() );
	}

	/**
	 * Trashing removes the post.
	 */
	public function test_trashed_post_is_removed(): void {
		$id = self::factory()->post->create();
		$this->sync();

		wp_trash_post( $id );
		$this->sync();

		$this->assertSame( array(), $this->indexed_ids() );
	}

	/**
	 * Deleting removes the post.
	 */
	public function test_deleted_post_is_removed(): void {
		$keep = self::factory()->post->create();
		$id   = self::factory()->post->create();
		$this->sync();

		wp_delete_post( $id, true );
		$this->sync();

		$this->assertSame( array( $keep ), $this->indexed_ids() );
	}

	/**
	 * Renaming a term of an indexed taxonomy updates the documents of its posts.
	 */
	public function test_term_rename_updates_documents(): void {
		$content               = (array) get_option( Options::CONTENT, array() );
		$content['taxonomies'] = array( 'post' => array( 'category' ) );
		update_option( Options::CONTENT, $content );
		$term_id = self::factory()->category->create( array( 'name' => 'Alpha' ) );
		self::factory()->post->create( array( 'post_category' => array( $term_id ) ) );
		$this->sync();
		$this->assertSame( array( 'Alpha' ), $this->index_documents( $this->uid )[0]['tax_category'] );

		wp_update_term( $term_id, 'category', array( 'name' => 'Beta' ) );
		$this->sync();

		$this->assertSame( array( 'Beta' ), $this->index_documents( $this->uid )[0]['tax_category'] );
	}

	/**
	 * Edit-lock heartbeats never enqueue a job.
	 */
	public function test_edit_lock_update_enqueues_nothing(): void {
		$id = self::factory()->post->create();
		$this->sync();

		update_post_meta( $id, '_edit_lock', time() . ':1' );
		update_post_meta( $id, '_edit_last', '1' );

		$this->assertSame( array(), $this->collector()->pending() );
		$this->collector()->flush();
		$queue = Plugin::instance()->get( 'queue' );
		assert( $queue instanceof Queue );
		$this->assertSame( 0, $queue->count( 'pending' ) );
	}

	/**
	 * A transport failure reschedules the job with attempt 2 after the first backoff delay.
	 */
	public function test_failure_reschedules_with_next_attempt(): void {
		$this->fail_http_requests();
		$job = Plugin::instance()->get( 'sync_job' );
		assert( $job instanceof SyncJob );

		$before = time();
		$job->handle(
			array(
				'index'   => 'content',
				'ids'     => array( 987654 ),
				'attempt' => 1,
			)
		);

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => Queue::SYNC_POSTS,
				'group'    => Queue::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 50,
			)
		);
		$retries = array_values(
			array_filter(
				$actions,
				static fn ( $action ): bool => array(
					'index'   => 'content',
					'ids'     => array( 987654 ),
					'attempt' => 2,
				) === $action->get_args()[0]
			)
		);
		$this->assertCount( 1, $retries );
		$this->assertGreaterThanOrEqual( $before + SyncJob::BACKOFF[0], $retries[0]->get_schedule()->get_date()->getTimestamp() );
	}

	/**
	 * When the last attempt fails, the job throws and Action Scheduler marks the action failed.
	 */
	public function test_last_attempt_marks_action_failed(): void {
		$this->fail_http_requests();
		$queue = Plugin::instance()->get( 'queue' );
		assert( $queue instanceof Queue );
		$action_id = $queue->schedule(
			Queue::SYNC_POSTS,
			array(
				'index'   => 'content',
				'ids'     => array( 987654 ),
				'attempt' => SyncJob::MAX_ATTEMPTS,
			)
		);

		\ActionScheduler::runner()->process_action( $action_id, 'SyncTest' );

		$this->assertSame( \ActionScheduler_Store::STATUS_FAILED, \ActionScheduler::store()->get_status( $action_id ) );
		$log = Plugin::instance()->get( 'error_log' );
		assert( $log instanceof ErrorLog );
		$this->assertStringContainsString( 'after 6 attempt(s)', $log->all()[0]['message'] );
	}
}
