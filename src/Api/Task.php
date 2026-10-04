<?php
/**
 * Meilisearch asynchronous task handle.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps an enqueued task uid and waits for it (admin-synchronous flows only).
 */
final class Task {

	/**
	 * Creates the handle.
	 *
	 * @param Client $client Client used to poll the task.
	 * @param int    $uid    Task uid.
	 */
	public function __construct( private Client $client, public readonly int $uid ) {}

	/**
	 * Polls the task until it finishes.
	 *
	 * @param float $timeout Maximum seconds to wait.
	 * @param int   $poll_ms Milliseconds to sleep between polls; 0 polls without sleeping.
	 * @return array<string, mixed> The succeeded task.
	 * @throws ApiError Code 'task_failed' (message from the task error), 'task_canceled' or 'task_timeout'; or any polling error.
	 */
	public function wait( float $timeout = 30.0, int $poll_ms = 250 ): array {
		$deadline = microtime( true ) + $timeout;

		while ( true ) {
			$task = $this->client->get_task( $this->uid );
			if ( $this->succeeded( $task ) ) {
				return $task;
			}

			if ( microtime( true ) >= $deadline ) {
				throw $this->timeout_error( $timeout ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is data; escaped where displayed.
			}

			if ( $poll_ms > 0 ) {
				usleep( $poll_ms * 1000 );
			}
		}
	}

	/**
	 * Builds the error thrown when the deadline passes.
	 *
	 * @param float $timeout Seconds waited.
	 * @return ApiError Code 'task_timeout'.
	 */
	private function timeout_error( float $timeout ): ApiError {
		return new ApiError(
			sprintf(
				/* translators: 1: Meilisearch task uid, 2: number of seconds. */
				__( 'Meilisearch task %1$d did not finish within %2$d seconds.', 'meilisearch' ),
				$this->uid,
				(int) ceil( $timeout )
			),
			'task_timeout'
		);
	}

	/**
	 * Whether a polled task succeeded; throws when it failed or was canceled.
	 *
	 * @param array<string, mixed> $task Task object from GET /tasks/{uid}.
	 * @return bool False while the task is enqueued or processing.
	 * @throws ApiError Code 'task_failed' or 'task_canceled'.
	 */
	private function succeeded( array $task ): bool {
		$status = isset( $task['status'] ) && is_string( $task['status'] ) ? $task['status'] : '';

		if ( 'failed' === $status ) {
			$error   = isset( $task['error'] ) && is_array( $task['error'] ) ? $task['error'] : array();
			$message = isset( $error['message'] ) && is_string( $error['message'] ) && '' !== $error['message']
				? $error['message']
				/* translators: %d: Meilisearch task uid. */
				: sprintf( __( 'Meilisearch task %d failed.', 'meilisearch' ), $this->uid );
			throw new ApiError( $message, 'task_failed' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is data; escaped where displayed.
		}

		if ( 'canceled' === $status ) {
			/* translators: %d: Meilisearch task uid. */
			throw new ApiError( sprintf( __( 'Meilisearch task %d was canceled.', 'meilisearch' ), $this->uid ), 'task_canceled' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is data; escaped where displayed.
		}

		return 'succeeded' === $status;
	}
}
