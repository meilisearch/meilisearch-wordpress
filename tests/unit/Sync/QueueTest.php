<?php
/**
 * Tests for Queue.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Sync;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Queue maps 1:1 onto Action Scheduler calls.
 */
final class QueueTest extends TestCase {

	/**
	 * The payload is wrapped in a one-element args array so handlers receive it as their only argument.
	 */
	public function test_schedule_wraps_payload_and_uses_group(): void {
		$before = time();
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with(
				\Mockery::on( fn ( $timestamp ) => is_int( $timestamp ) && $timestamp >= $before + 30 && $timestamp <= time() + 30 ),
				Queue::SYNC_POSTS,
				array(
					array(
						'index'   => 'content',
						'ids'     => array( 1 ),
						'attempt' => 1,
					),
				),
				'meilisearch'
			)
			->andReturn( 42 );

		$id = ( new Queue() )->schedule(
			Queue::SYNC_POSTS,
			array(
				'index'   => 'content',
				'ids'     => array( 1 ),
				'attempt' => 1,
			),
			30
		);

		$this->assertSame( 42, $id );
	}

	/**
	 * A negative delay is treated as "now".
	 */
	public function test_negative_delay_is_clamped(): void {
		$before = time();
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with( \Mockery::on( fn ( $timestamp ) => $timestamp >= $before && $timestamp <= time() ), Queue::SYNC_TERM, \Mockery::type( 'array' ), 'meilisearch' )
			->andReturn( 0 );

		$this->assertSame( 0, ( new Queue() )->schedule( Queue::SYNC_TERM, array(), -10 ) );
	}

	/**
	 * Cancelling targets the whole group.
	 */
	public function test_cancel_all_unschedules_group(): void {
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( '', array(), 'meilisearch' );

		( new Queue() )->cancel_all();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Counting asks for IDs only, capped at 1000.
	 */
	public function test_count_queries_ids_with_cap(): void {
		Functions\expect( 'as_get_scheduled_actions' )
			->once()
			->with(
				array(
					'group'    => 'meilisearch',
					'status'   => 'failed',
					'per_page' => 1000,
					'orderby'  => 'none',
				),
				'ids'
			)
			->andReturn( array( 3, 4, 5 ) );

		$this->assertSame( 3, ( new Queue() )->count( 'failed' ) );
	}

	/**
	 * Only pending and failed can be counted.
	 */
	public function test_count_rejects_other_statuses(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new Queue() )->count( 'complete' );
	}
}
