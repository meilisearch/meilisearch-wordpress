<?php
/**
 * Tests for AuthorJob.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Sync;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\AuthorJob;
use Meilisearch\WordPress\Sync\ChangeCollector;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Tests\Unit\Support\SyncFixtures;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * User hooks and paging.
 */
final class AuthorJobTest extends TestCase {

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
	 * @var AuthorJob
	 */
	private AuthorJob $job;

	/**
	 * Recorded schedules: [hook, payload].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $scheduled = array();

	/**
	 * Recorded get_posts() arguments.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = array();

	/**
	 * Author ID => published post IDs returned by get_posts().
	 *
	 * @var array<int, list<int>>
	 */
	private array $authored = array();

	/**
	 * User ID => display name returned by get_userdata().
	 *
	 * @var array<int, string>
	 */
	private array $users = array();

	/**
	 * Wires the job over real collaborators.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_wordpress_state();
		$this->scheduled = array();
		$this->queries   = array();
		$this->authored  = array();
		$this->users     = array();
		$options         = new Options();
		$queue           = new Queue();
		$this->collector = new ChangeCollector( new Indexability( $options ), $queue, $options, new ErrorLog() );
		$this->job       = new AuthorJob( $this->collector, $queue, $options );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ): int {
				$this->scheduled[] = array( $hook, $args[0] );
				return count( $this->scheduled );
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				$this->queries[] = $args;
				$ids             = $this->authored[ (int) $args['author'] ] ?? array();
				$limit           = (int) $args['posts_per_page'];
				return array_map( 'strval', array_slice( $ids, (int) $args['offset'], $limit < 0 ? null : $limit ) );
			}
		);
		Functions\when( 'get_userdata' )->alias(
			fn ( $user_id ) => isset( $this->users[ (int) $user_id ] ) ? (object) array( 'display_name' => $this->users[ (int) $user_id ] ) : false
		);
	}

	/**
	 * Only the payloads of a hook.
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
		Actions\expectAdded( 'delete_user' )->once()->with( array( $this->job, 'on_delete_user' ), 10, 2 );
		Actions\expectAdded( 'remove_user_from_blog' )->once()->with( array( $this->job, 'on_remove_user_from_blog' ), 10, 3 );
		Actions\expectAdded( 'profile_update' )->once()->with( array( $this->job, 'on_profile_update' ), 10, 2 );
		Actions\expectAdded( Queue::SYNC_AUTHOR )->once()->with( array( $this->job, 'handle' ), 10, 1 );

		$this->job->register();
		$this->addToAssertionCount( 4 );
	}

	/**
	 * Deleting a user whose posts go to another author collects that user's published posts of the
	 * indexed types, before WordPress moves them with a direct UPDATE.
	 */
	public function test_delete_user_with_reassign_collects_the_users_published_posts(): void {
		$this->add_post( array( 'ID' => 10 ) );
		$this->add_post( array( 'ID' => 11 ) );
		$this->authored[5] = array( 10, 11 );

		$this->job->on_delete_user( '5', 3 );

		$this->assertSame( array( 'content' => array( 10, 11 ) ), $this->collector->pending() );
		$this->assertCount( 1, $this->queries );
		$this->assertSame( array( 'post', 'page' ), $this->queries[0]['post_type'] );
		$this->assertSame( 'publish', $this->queries[0]['post_status'] );
		$this->assertSame( 'ids', $this->queries[0]['fields'] );
		$this->assertSame( -1, $this->queries[0]['posts_per_page'] );
	}

	/**
	 * Without a reassign target the posts are deleted one by one (before_delete_post covers them).
	 */
	public function test_delete_user_without_reassign_collects_nothing(): void {
		$this->add_post( array( 'ID' => 10 ) );
		$this->authored[5] = array( 10 );

		$this->job->on_delete_user( 5, null );
		$this->job->on_delete_user( 0, 3 );

		$this->assertSame( array(), $this->collector->pending() );
	}

	/**
	 * Removing a user from a site of a network with a reassign target collects their posts there.
	 */
	public function test_remove_user_from_blog_with_reassign_collects_the_users_posts(): void {
		$this->add_post( array( 'ID' => 10 ) );
		$this->authored[5] = array( 10 );

		$this->job->on_remove_user_from_blog( 5, 1, 0 );
		$this->assertSame( array(), $this->collector->pending() );

		$this->job->on_remove_user_from_blog( 5, 1, '3' );
		$this->assertSame( array( 'content' => array( 10 ) ), $this->collector->pending() );
	}

	/**
	 * A new display name schedules an author sync; any other profile change schedules nothing.
	 */
	public function test_profile_update_schedules_a_sync_only_when_the_display_name_changes(): void {
		$this->users[5] = 'After Name';
		$this->users[6] = 'Same Name';

		$this->job->on_profile_update( 5, (object) array( 'display_name' => 'Before Name' ) );
		$this->job->on_profile_update( 6, (object) array( 'display_name' => 'Same Name' ) );
		$this->job->on_profile_update( 7, (object) array( 'display_name' => 'Gone' ) );
		$this->job->on_profile_update( 5, null );
		$this->job->on_profile_update( 0, (object) array( 'display_name' => 'Before Name' ) );

		$this->assertSame(
			array(
				array(
					'author_id' => 5,
					'page'      => 0,
				),
			),
			$this->payloads( Queue::SYNC_AUTHOR )
		);
	}

	/**
	 * The handler feeds one page of the author's posts to the collector, flushes, and chains the next
	 * page while a page comes back full.
	 */
	public function test_handle_pages_through_the_authors_posts(): void {
		$ids = range( 1, AuthorJob::PAGE + 2 );
		foreach ( $ids as $id ) {
			$this->add_post( array( 'ID' => $id ) );
		}
		$this->authored[5] = $ids;

		$this->job->handle(
			array(
				'author_id' => 5,
				'page'      => 0,
			)
		);

		$synced = $this->payloads( Queue::SYNC_POSTS );
		$this->assertSame( range( 1, ChangeCollector::CHUNK ), $synced[0]['ids'] );
		$this->assertCount( AuthorJob::PAGE / ChangeCollector::CHUNK, $synced );
		$this->assertSame(
			array(
				array(
					'author_id' => 5,
					'page'      => 1,
				),
			),
			$this->payloads( Queue::SYNC_AUTHOR )
		);
		$this->assertSame( array(), $this->collector->pending() );

		$this->scheduled = array();
		$this->job->handle(
			array(
				'author_id' => 5,
				'page'      => 1,
			)
		);
		$this->assertSame( array( AuthorJob::PAGE + 1, AuthorJob::PAGE + 2 ), $this->payloads( Queue::SYNC_POSTS )[0]['ids'] );
		$this->assertSame( array(), $this->payloads( Queue::SYNC_AUTHOR ) );
	}

	/**
	 * A malformed payload does nothing.
	 */
	public function test_handle_ignores_an_invalid_payload(): void {
		$this->job->handle( array() );
		$this->job->handle( array( 'author_id' => 'x' ) );

		$this->assertSame( array(), $this->queries );
		$this->assertSame( array(), $this->scheduled );
	}
}
