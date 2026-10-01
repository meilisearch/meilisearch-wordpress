<?php
/**
 * Capped error log stored in an option.
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync;

use Meilisearch\WordPress\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last MAX errors (newest first) in `meilisearch_log` (autoload off).
 */
final class ErrorLog {

	public const MAX = 50;

	/**
	 * Constructor.
	 *
	 * @param Options $options Options (the keys to redact from messages).
	 */
	public function __construct( private readonly Options $options = new Options() ) {}

	/**
	 * Records an error. The configured keys are redacted from the message first.
	 *
	 * @param string $context Short origin such as 'sync', 'reindex' or 'search'.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	public function add( string $context, string $message ): void {
		$message = $this->options->redact( $message );
		$entries = $this->all();
		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'context' => $context,
				'message' => $message,
			)
		);
		update_option( Options::LOG, array_slice( $entries, 0, self::MAX ), false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[meilisearch] %s: %s', $context, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only when WP_DEBUG is on.
		}
	}

	/**
	 * All entries, newest first.
	 *
	 * @return list<array{time: int, context: string, message: string}>
	 */
	public function all(): array {
		$stored  = get_option( Options::LOG, array() );
		$entries = array();
		foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['time'], $entry['context'], $entry['message'] ) ) {
				$entries[] = array(
					'time'    => (int) $entry['time'],
					'context' => (string) $entry['context'],
					'message' => (string) $entry['message'],
				);
			}
		}

		return $entries;
	}

	/**
	 * Deletes every entry.
	 *
	 * @return void
	 */
	public function clear(): void {
		update_option( Options::LOG, array(), false );
	}
}
