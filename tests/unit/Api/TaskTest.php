<?php
/**
 * Tests for Task::wait().
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\Task;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class TaskTest extends TestCase {

	private FakeTransport $transport;

	private Task $task;

	protected function set_up(): void {
		parent::set_up();
		$this->transport = new FakeTransport();
		$this->task      = new Task( new Client( $this->transport, 'http://localhost:7700', 'key', 'UA' ), 5 );
	}

	private function state( string $status, array $extra = [] ): void {
		$this->transport->respond(
			200,
			array_merge(
				[
					'uid'    => 5,
					'status' => $status,
				],
				$extra
			)
		);
	}

	public function test_wait_polls_until_succeeded(): void {
		$this->state( 'enqueued' );
		$this->state( 'processing' );
		$this->state( 'succeeded', [ 'type' => 'indexCreation' ] );

		$task = $this->task->wait( 30.0, 0 );

		$this->assertSame( 'succeeded', $task['status'] );
		$this->assertCount( 3, $this->transport->requests );
		$this->assertSame( 'http://localhost:7700/tasks/5', $this->transport->last()['url'] );
	}

	public function test_failed_task_throws_with_the_task_error_message(): void {
		$this->state(
			'failed',
			[
				'error' => [
					'message' => 'Index `wp_abc_content` already exists.',
					'code'    => 'index_already_exists',
				],
			]
		);

		try {
			$this->task->wait( 30.0, 0 );
			$this->fail( 'ApiError was not thrown.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'task_failed', $error->error_code );
			$this->assertSame( 'Index `wp_abc_content` already exists.', $error->getMessage() );
		}
	}

	public function test_failed_task_without_error_uses_a_generic_message(): void {
		$this->state( 'failed' );

		$this->expectException( ApiError::class );
		$this->expectExceptionMessage( 'Meilisearch task 5 failed.' );

		$this->task->wait( 30.0, 0 );
	}

	public function test_canceled_task_throws(): void {
		$this->state( 'canceled' );

		try {
			$this->task->wait( 30.0, 0 );
			$this->fail( 'ApiError was not thrown.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'task_canceled', $error->error_code );
			$this->assertSame( 'Meilisearch task 5 was canceled.', $error->getMessage() );
		}
	}

	public function test_deadline_throws_task_timeout(): void {
		$this->state( 'processing' );

		try {
			$this->task->wait( 0.0, 0 );
			$this->fail( 'ApiError was not thrown.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'task_timeout', $error->error_code );
			$this->assertSame( 'Meilisearch task 5 did not finish within 0 seconds.', $error->getMessage() );
			$this->assertCount( 1, $this->transport->requests );
		}
	}

	public function test_get_task_errors_propagate(): void {
		$this->transport->respond(
			404,
			[
				'message' => 'Task `5` not found.',
				'code'    => 'task_not_found',
			]
		);

		$this->expectException( ApiError::class );
		$this->expectExceptionMessage( 'Task `5` not found.' );

		$this->task->wait( 30.0, 0 );
	}

	public function test_translated_messages_are_raw_data_not_html_escaped(): void {
		Functions\when( '__' )->justReturn( "La tâche %d n'a pas été annulée & \"stoppée\"" );
		Functions\when( 'esc_html__' )->alias( static fn(): string => htmlspecialchars( "La tâche %d n'a pas été annulée & \"stoppée\"", ENT_QUOTES ) );
		$this->state( 'canceled' );

		try {
			$this->task->wait( 1.0, 0 );
			$this->fail( 'Expected ApiError.' );
		} catch ( ApiError $error ) {
			$this->assertSame( "La tâche 5 n'a pas été annulée & \"stoppée\"", $error->getMessage() );
			$this->assertStringNotContainsString( '&#039;', $error->getMessage() );
			$this->assertStringNotContainsString( '&amp;', $error->getMessage() );
		}
	}
}
