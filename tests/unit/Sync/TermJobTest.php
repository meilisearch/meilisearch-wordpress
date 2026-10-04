<?php
/**
 * Tests for TermJob.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Sync;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ChangeCollector;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Sync\TermJob;
use Meilisearch\WordPress\Tests\Unit\Support\SyncFixtures;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Term hooks and paging.
 */
final class TermJobTest extends TestCase {

	use SyncFixtures;

	/**
	 * Collector fed by the job.
	 *
	 * @var ChangeCollector
	 */
	private ChangeCollector $collector;

	/**
	 * Job under test.
	 *
	 * @var TermJob
	 */
	private TermJob $job;

	/**
	 * Recorded schedules: [hook, payload].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $scheduled = array();

	/**
	 * Wires the job over real collaborators.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_wordpress_state();
		$this->scheduled = array();
		$options         = new Options();
		$queue           = new Queue();
		$this->collector = new ChangeCollector( new Indexability( $options ), $queue, $options, new ErrorLog() );
		$this->job       = new TermJob( $this->collector, $queue, $options );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ): int {
				$this->scheduled[] = array( $hook, $args[0] );
				return count( $this->scheduled );
			}
		);
		Functions\when( 'get_term_children' )->justReturn( array() );
	}

	/**
	 * Only the SYNC_* payloads of a hook.
	 *
	 * @param string $hook Hook.
	 * @return list<array<string, mixed>>
	 */
	private function payloads( string $hook ): array {
		$payloads = array();
		foreach ( $this->scheduled as [ $scheduled_hook, $payload ] ) {
			if ( $hook === $scheduled_hook ) {
				$payloads[] = $payload;
			}
		}
		return $payloads;
	}

	/**
	 * Hooks are attached with the arguments their callbacks read; the AS handler takes exactly 1.
	 */
	public function test_register_attaches_hooks(): void {
		Actions\expectAdded( 'edited_term' )->once()->with( array( $this->job, 'on_edited_term' ), 10, 3 );
		Actions\expectAdded( 'delete_term' )->once()->with( array( $this->job, 'on_delete_term' ), 10, 5 );
		Actions\expectAdded( Queue::SYNC_TERM )->once()->with( array( $this->job, 'handle' ), 10, 1 );

		$this->job->register();
		$this->addToAssertionCount( 3 );
	}

	/**
	 * Editing a term of an indexed taxonomy schedules page 0 of a term sync.
	 */
	public function test_edited_term_of_indexed_taxonomy_schedules_sync(): void {
		$this->job->on_edited_term( 7, 70, 'category' );

		$this->assertSame(
			array(
				array(
					Queue::SYNC_TERM,
					array(
						'taxonomy' => 'category',
						'term_id'  => 7,
						'page'     => 0,
					),
				),
			),
			$this->scheduled
		);
	}

	/**
	 * Unindexed taxonomies, and product taxonomies while products are off, are ignored.
	 */
	public function test_untracked_taxonomies_are_ignored(): void {
		$this->job->on_edited_term( 7, 70, 'nav_menu' );
		$this->job->on_edited_term( 8, 80, 'product_cat' );
		$this->job->on_edited_term( 9, 90, 'pa_color' );
		$this->job->on_edited_term( 0, 0, 'category' );

		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * Deleting a term collects the objects that had it (string IDs from $wpdb are accepted).
	 */
	public function test_delete_term_collects_object_ids(): void {
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post( array( 'ID' => 11 ) );

		$this->job->on_delete_term( 7, 70, 'category', null, array( '10', '11' ) );
		$this->job->on_delete_term( 8, 80, 'nav_menu', null, array( '10' ) );

		$this->assertSame( array( 'content' => array( 10, 11 ) ), $this->collector->pending() );
	}

	/**
	 * A page feeds PAGE posts to the collector, flushes them and chains the next page.
	 */
	public function test_handle_pages_and_chains_next_page(): void {
		$ids = range( 1, 1200 );
		foreach ( $ids as $id ) {
			$this->add_post( array( 'ID' => $id ) );
		}
		Functions\expect( 'get_objects_in_term' )
			->once()
			->with( array( 7 ), 'category' )
			->andReturn( array_map( 'strval', $ids ) );

		$this->job->handle(
			array(
				'taxonomy' => 'category',
				'term_id'  => 7,
				'page'     => 0,
			)
		);

		$syncs = $this->payloads( Queue::SYNC_POSTS );
		$this->assertCount( 5, $syncs );
		$this->assertSame( 1, $syncs[0]['ids'][0] );
		$this->assertSame( 500, $syncs[4]['ids'][99] );
		$this->assertSame(
			array(
				array(
					'taxonomy' => 'category',
					'term_id'  => 7,
					'page'     => 1,
				),
			),
			$this->payloads( Queue::SYNC_TERM )
		);
		$this->assertSame( array(), $this->collector->pending() );
	}

	/**
	 * The last page does not chain.
	 */
	public function test_last_page_does_not_chain(): void {
		$ids = range( 1, 1200 );
		foreach ( array_slice( $ids, 1000 ) as $id ) {
			$this->add_post( array( 'ID' => $id ) );
		}
		Functions\when( 'get_objects_in_term' )->justReturn( $ids );

		$this->job->handle(
			array(
				'taxonomy' => 'category',
				'term_id'  => 7,
				'page'     => 2,
			)
		);

		$this->assertCount( 2, $this->payloads( Queue::SYNC_POSTS ) );
		$this->assertSame( array(), $this->payloads( Queue::SYNC_TERM ) );
	}

	/**
	 * Descendant terms are included (re-parenting changes their posts' ancestor IDs), duplicates removed.
	 */
	public function test_descendant_terms_are_included(): void {
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post( array( 'ID' => 11 ) );
		Functions\when( 'get_term_children' )->justReturn( array( 8, '9' ) );
		Functions\expect( 'get_objects_in_term' )->once()->with( array( 7, 8, 9 ), 'category' )->andReturn( array( '10', '11', '10' ) );

		$this->job->handle(
			array(
				'taxonomy' => 'category',
				'term_id'  => 7,
				'page'     => 0,
			)
		);

		$this->assertSame( array( 10, 11 ), $this->payloads( Queue::SYNC_POSTS )[0]['ids'] );
	}

	/**
	 * A removed taxonomy (WP_Error) or a malformed payload does nothing.
	 */
	public function test_error_and_invalid_payload_do_nothing(): void {
		Functions\when( 'get_objects_in_term' )->justReturn( new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ) );

		$this->job->handle(
			array(
				'taxonomy' => 'gone',
				'term_id'  => 7,
				'page'     => 0,
			)
		);
		$this->job->handle( array( 'term_id' => 7 ) );

		$this->assertSame( array(), $this->scheduled );
	}
}
