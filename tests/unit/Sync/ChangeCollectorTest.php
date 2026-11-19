<?php
/**
 * Tests for ChangeCollector.
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
use Meilisearch\WordPress\Tests\Unit\Support\SyncFixtures;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Hook paths, filtering, dedupe and chunking.
 */
final class ChangeCollectorTest extends TestCase {

	use SyncFixtures;

	/**
	 * Collector under test.
	 *
	 * @var ChangeCollector
	 */
	private ChangeCollector $collector;

	/**
	 * Recorded as_schedule_single_action calls: [timestamp, hook, payload].
	 *
	 * @var list<array{0: int, 1: string, 2: array<string, mixed>}>
	 */
	private array $scheduled = array();

	/**
	 * Builds the collector over real Options/Indexability/Queue with stubbed WordPress state.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_wordpress_state();
		$this->scheduled = array();
		$options         = new Options();
		$this->collector = new ChangeCollector( new Indexability( $options ), new Queue(), $options, new ErrorLog() );

		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post( array( 'ID' => 11 ) );
		$this->add_post(
			array(
				'ID'            => 12,
				'post_status'   => 'draft',
				'post_date_gmt' => '2026-10-01 08:00:00',
			)
		);
		$this->add_post(
			array(
				'ID'        => 20,
				'post_type' => 'nav_menu_item',
			)
		);
		$this->add_post(
			array(
				'ID'        => 21,
				'post_type' => 'revision',
			)
		);
	}

	/**
	 * Records scheduled actions, returning incrementing IDs (or the given fixed value).
	 *
	 * @param int|null $fixed_id Value to return instead of an incrementing ID.
	 */
	private function record_schedules( ?int $fixed_id = null ): void {
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) use ( $fixed_id ): int {
				$this->scheduled[] = array( $timestamp, $hook, $args[0] );
				return $fixed_id ?? count( $this->scheduled );
			}
		);
	}

	/**
	 * Every hook is attached with the number of arguments its callback reads.
	 */
	public function test_register_attaches_hooks(): void {
		Actions\expectAdded( 'save_post' )->once()->with( array( $this->collector, 'on_save_post' ), 10, 1 );
		Actions\expectAdded( 'transition_post_status' )->once()->with( array( $this->collector, 'on_transition_post_status' ), 10, 3 );
		Actions\expectAdded( 'before_delete_post' )->once()->with( array( $this->collector, 'on_before_delete_post' ), 10, 1 );
		Actions\expectAdded( 'set_object_terms' )->once()->with( array( $this->collector, 'on_set_object_terms' ), 10, 1 );
		Actions\expectAdded( 'deleted_term_relationships' )->once()->with( array( $this->collector, 'on_deleted_term_relationships' ), 10, 1 );
		Actions\expectAdded( 'added_post_meta' )->once()->with( array( $this->collector, 'on_meta_change' ), 10, 3 );
		Actions\expectAdded( 'updated_post_meta' )->once()->with( array( $this->collector, 'on_meta_change' ), 10, 3 );
		Actions\expectAdded( 'deleted_post_meta' )->once()->with( array( $this->collector, 'on_meta_change' ), 10, 3 );
		Actions\expectAdded( 'shutdown' )->once()->with( array( $this->collector, 'flush' ), 10, 0 );

		$this->collector->register();
		$this->addToAssertionCount( 9 );
	}

	/**
	 * The save_post hook collects the post in its logical index, whatever its status (12 is a draft
	 * that was published before: its document may still have to be deleted).
	 */
	public function test_save_post_collects_id(): void {
		$this->collector->on_save_post( 10 );
		$this->collector->on_save_post( '12' );

		$this->assertSame( array( 'content' => array( 10, 12 ) ), $this->collector->pending() );
	}

	/**
	 * The transition_post_status hook collects the post from the post object.
	 */
	public function test_transition_post_status_collects_id(): void {
		$this->collector->on_transition_post_status( 'draft', 'publish', $this->posts[10] );
		$this->collector->on_transition_post_status( 'publish', 'draft', 'not-a-post' );

		$this->assertSame( array( 'content' => array( 10 ) ), $this->collector->pending() );
	}

	/**
	 * The before_delete_post hook collects the post while it still exists.
	 */
	public function test_before_delete_post_collects_id(): void {
		$this->collector->on_before_delete_post( 11 );

		$this->assertSame( array( 'content' => array( 11 ) ), $this->collector->pending() );
	}

	/**
	 * The set_object_terms hook collects the object.
	 */
	public function test_set_object_terms_collects_id(): void {
		$this->collector->on_set_object_terms( 10 );

		$this->assertSame( array( 'content' => array( 10 ) ), $this->collector->pending() );
	}

	/**
	 * The deleted_term_relationships hook (wp_remove_object_terms()) collects the object.
	 */
	public function test_deleted_term_relationships_collects_id(): void {
		$this->collector->on_deleted_term_relationships( '11' );

		$this->assertSame( array( 'content' => array( 11 ) ), $this->collector->pending() );
	}

	/**
	 * Drafts and pending posts that were never published (floating date: post_date_gmt is zero) have no
	 * document, so the post hooks skip them: each block-editor autosave of a draft saves the post itself.
	 * Deleting one and direct additions (product and term jobs) still collect it.
	 */
	public function test_never_published_posts_are_not_collected_by_the_post_hooks(): void {
		$this->add_post(
			array(
				'ID'          => 13,
				'post_status' => 'draft',
			)
		);
		$this->add_post(
			array(
				'ID'            => 14,
				'post_status'   => 'auto-draft',
				'post_date_gmt' => '',
			)
		);
		$this->add_post(
			array(
				'ID'          => 15,
				'post_status' => 'pending',
			)
		);

		foreach ( array( 13, 14, 15 ) as $id ) {
			$this->collector->on_transition_post_status( $this->posts[ $id ]->post_status, 'draft', $this->posts[ $id ] );
			$this->collector->on_save_post( $id );
			$this->collector->on_set_object_terms( $id );
			$this->collector->on_deleted_term_relationships( $id );
			$this->collector->on_meta_change( 5, $id, 'price' );
		}
		$this->assertSame( array(), $this->collector->pending() );

		$this->collector->on_before_delete_post( 13 );
		$this->collector->add( 14 );
		$this->assertSame( array( 'content' => array( 13, 14 ) ), $this->collector->pending() );
	}

	/**
	 * A post leaving publish is collected even if its date was cleared.
	 */
	public function test_transition_out_of_publish_is_always_collected(): void {
		$this->add_post(
			array(
				'ID'          => 13,
				'post_status' => 'draft',
			)
		);

		$this->collector->on_transition_post_status( 'draft', 'publish', $this->posts[13] );

		$this->assertSame( array( 'content' => array( 13 ) ), $this->collector->pending() );
	}

	/**
	 * An hour of draft autosaves (one request a minute) schedules no job.
	 */
	public function test_draft_autosaves_schedule_nothing(): void {
		$this->record_schedules();
		$this->add_post(
			array(
				'ID'          => 13,
				'post_status' => 'draft',
			)
		);
		for ( $i = 0; $i < 60; $i++ ) {
			$this->collector->on_transition_post_status( 'draft', 'draft', $this->posts[13] );
			$this->collector->on_save_post( 13 );
			$this->collector->flush();
			ChangeCollector::clear_pending( array( 13 ) );
		}

		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * Without a host and an admin key there is nowhere to sync to: the flush schedules nothing,
	 * sets no marker and still empties the set.
	 */
	public function test_flush_schedules_nothing_when_not_configured(): void {
		$this->record_schedules();
		unset( $this->option_store[ Options::ADMIN_KEY ] );
		$this->collector->on_save_post( 10 );

		$this->collector->flush();

		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( array(), $this->transient_store );
		$this->assertSame( array(), $this->collector->pending() );
	}

	/**
	 * Revisions, types without an index, missing posts and invalid IDs are ignored.
	 */
	public function test_unindexed_types_and_missing_posts_are_ignored(): void {
		$this->collector->on_save_post( 20 );
		$this->collector->on_save_post( 21 );
		$this->collector->on_save_post( 999 );
		$this->collector->on_save_post( 0 );
		$this->collector->on_save_post( 'abc' );

		$this->assertSame( array(), $this->collector->pending() );
	}

	/**
	 * Indexed meta keys collect on add, update and delete (deleted_post_meta passes an array of meta IDs).
	 */
	public function test_indexed_meta_key_collects_id(): void {
		$this->collector->on_meta_change( 5, 10, 'price' );
		$this->collector->on_meta_change( 6, 11, 'price' );
		$this->collector->on_meta_change( array( '7', '8' ), 12, 'price' );

		$this->assertSame( array( 'content' => array( 10, 11, 12 ) ), $this->collector->pending() );
	}

	/**
	 * _edit_lock heartbeats and other unindexed meta keys enqueue nothing.
	 */
	public function test_edit_lock_meta_update_enqueues_nothing(): void {
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->collector->on_meta_change( 5, 10, '_edit_lock' );
		$this->collector->on_meta_change( 6, 10, '_edit_last' );
		$this->collector->on_meta_change( array( 7 ), 10, '_wp_old_slug' );
		$this->collector->flush();

		$this->assertSame( array(), $this->collector->pending() );
		$this->assertSame( array(), $this->transient_store );
	}

	/**
	 * The same post seen through several hooks is scheduled once, with a pending marker.
	 */
	public function test_flush_dedupes_and_marks_pending(): void {
		$this->record_schedules();
		$this->collector->on_save_post( 10 );
		$this->collector->on_transition_post_status( 'publish', 'publish', $this->posts[10] );
		$this->collector->on_meta_change( 5, 10, 'price' );

		$before = time();
		$this->collector->flush();

		$this->assertCount( 1, $this->scheduled );
		[ $timestamp, $hook, $payload ] = $this->scheduled[0];
		$this->assertSame( Queue::SYNC_POSTS, $hook );
		$this->assertSame(
			array(
				'index'   => 'content',
				'ids'     => array( 10 ),
				'attempt' => 1,
			),
			$payload
		);
		$this->assertGreaterThanOrEqual( $before + ChangeCollector::DELAY, $timestamp );
		$this->assertArrayHasKey( 'meilisearch_pending_1_10', $this->transient_store );
		$this->assertSame( array(), $this->collector->pending() );
	}

	/**
	 * IDs with a job already waiting are not scheduled again.
	 */
	public function test_flush_skips_ids_with_pending_marker(): void {
		$this->record_schedules();
		$this->transient_store['meilisearch_pending_1_11'] = 1;
		$this->collector->add_many( array( 10, 11 ) );

		$this->collector->flush();

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( array( 10 ), $this->scheduled[0][2]['ids'] );
	}

	/**
	 * Nothing is scheduled when every ID already has a waiting job.
	 */
	public function test_flush_schedules_nothing_when_all_pending(): void {
		Functions\expect( 'as_schedule_single_action' )->never();
		$this->transient_store['meilisearch_pending_1_10'] = 1;
		$this->collector->add( 10 );

		$this->collector->flush();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Large sets are split into CHUNK-sized jobs.
	 */
	public function test_flush_chunks_ids(): void {
		$this->record_schedules();
		$ids = range( 1000, 1249 );
		foreach ( $ids as $id ) {
			$this->add_post( array( 'ID' => $id ) );
		}
		$this->collector->add_many( $ids );

		$this->collector->flush();

		$this->assertCount( 3, $this->scheduled );
		$this->assertCount( 100, $this->scheduled[0][2]['ids'] );
		$this->assertCount( 100, $this->scheduled[1][2]['ids'] );
		$this->assertCount( 50, $this->scheduled[2][2]['ids'] );
		$this->assertSame( 1000, $this->scheduled[0][2]['ids'][0] );
		$this->assertSame( 1249, $this->scheduled[2][2]['ids'][49] );
	}

	/**
	 * When Action Scheduler stores nothing, no marker is left behind.
	 */
	public function test_failed_schedule_leaves_no_marker(): void {
		$this->record_schedules( 0 );
		$this->collector->add( 10 );

		$this->collector->flush();

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( array(), $this->transient_store );
	}

	/**
	 * A chunk that cannot be scheduled (Action Scheduler throws) is logged; the others still go out.
	 */
	public function test_schedule_failure_is_logged_and_the_flush_continues(): void {
		$calls = 0;
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) use ( &$calls ): int {
				++$calls;
				if ( 1 === $calls ) {
					throw new \RuntimeException( 'Table wp_actionscheduler_actions is marked as crashed' );
				}
				$this->scheduled[] = array( $timestamp, $hook, $args[0] );
				return $calls;
			}
		);
		$ids = range( 1000, 1149 );
		foreach ( $ids as $id ) {
			$this->add_post( array( 'ID' => $id ) );
		}
		$this->collector->add_many( $ids );

		$this->collector->flush();

		$this->assertSame( 2, $calls );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( range( 1100, 1149 ), $this->scheduled[0][2]['ids'] );
		$this->assertArrayNotHasKey( 'meilisearch_pending_1_1000', $this->transient_store );
		$this->assertArrayHasKey( 'meilisearch_pending_1_1100', $this->transient_store );
		$entries = ( new ErrorLog() )->all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'sync', $entries[0]['context'] );
		$this->assertStringContainsString( 'marked as crashed', $entries[0]['message'] );
	}

	/**
	 * Method clear_pending() removes the markers of the given posts only.
	 */
	public function test_clear_pending_deletes_markers(): void {
		$this->transient_store['meilisearch_pending_1_10'] = 1;
		$this->transient_store['meilisearch_pending_1_11'] = 1;

		ChangeCollector::clear_pending( array( 10, '11' ) );

		$this->assertSame( array(), $this->transient_store );
	}

	/**
	 * IDs collected while another blog was current are scheduled on that blog, with its own markers.
	 */
	public function test_flush_schedules_each_blog_while_it_is_current(): void {
		$current = 1;
		$stack   = array();
		$log     = array();
		Functions\when( 'get_current_blog_id' )->alias(
			function () use ( &$current ) {
				return $current;
			}
		);
		Functions\when( 'switch_to_blog' )->alias(
			function ( $blog_id ) use ( &$current, &$stack ): bool {
				$stack[] = $current;
				$current = (int) $blog_id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function () use ( &$current, &$stack ): bool {
				$current = (int) array_pop( $stack );
				return true;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) use ( &$current, &$log ): int {
				$log[] = array( $current, $args[0]['ids'] );
				return count( $log );
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name ) use ( &$current ): bool {
				$this->transient_store[ $name ] = $current;
				return true;
			}
		);

		$this->collector->add( 10 );
		$current = 2;
		$this->collector->add( 11 );
		$current = 1;

		$this->collector->flush();

		$this->assertSame( array( array( 1, array( 10 ) ), array( 2, array( 11 ) ) ), $log );
		$this->assertSame( 1, $current );
		$this->assertSame(
			array(
				'meilisearch_pending_1_10' => 1,
				'meilisearch_pending_2_11' => 2,
			),
			$this->transient_store
		);
	}

	/**
	 * Single-site flushes never switch blogs.
	 */
	public function test_single_site_never_switches_blog(): void {
		$this->record_schedules();
		Functions\expect( 'switch_to_blog' )->never();
		Functions\expect( 'restore_current_blog' )->never();
		$this->collector->add( 10 );

		$this->collector->flush();

		$this->assertCount( 1, $this->scheduled );
	}
}
