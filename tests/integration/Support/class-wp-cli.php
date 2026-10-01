<?php
/**
 * Minimal WP_CLI stand-in for integration tests: records output instead of printing,
 * throws CliExit instead of exiting.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.NamingConventions.NoReservedKeywordParameterNames
 *
 * @package Meilisearch
 */

declare(strict_types=1);

use Meilisearch\WordPress\Tests\Integration\Support\CliExit;
use Meilisearch\WordPress\Tests\Integration\Support\ShimProgressBar;

if ( ! class_exists( 'WP_CLI', false ) ) {
	/**
	 * Recording WP_CLI.
	 */
	final class WP_CLI {

		/**
		 * Output calls: [ type, message ] with type log|success|warning|error|confirm.
		 *
		 * @var list<array{0: string, 1: string}>
		 */
		public static array $calls = array();

		/**
		 * Registered commands (kept across reset()).
		 *
		 * @var array<string, list<mixed>>
		 */
		public static array $commands = array();

		/**
		 * format_items() calls.
		 *
		 * @var list<array{format: string, items: array<int|string, mixed>, fields: array<int, string>|string}>
		 */
		public static array $items = array();

		/**
		 * Progress bars created by make_progress_bar().
		 *
		 * @var list<ShimProgressBar>
		 */
		public static array $bars = array();

		/**
		 * Answer given to confirm() when --yes is absent.
		 *
		 * @var bool
		 */
		public static bool $confirm = true;

		public static function reset(): void {
			self::$calls   = array();
			self::$items   = array();
			self::$bars    = array();
			self::$confirm = true;
		}

		/**
		 * @param array<string, mixed> $args
		 */
		public static function add_command( string $name, mixed $callable, array $args = array() ): bool {
			self::$commands[ $name ][] = $callable;

			return true;
		}

		public static function log( string $message ): void {
			self::$calls[] = array( 'log', $message );
		}

		public static function line( string $message = '' ): void {
			self::$calls[] = array( 'log', $message );
		}

		public static function success( string $message ): void {
			self::$calls[] = array( 'success', $message );
		}

		public static function warning( mixed $message ): void {
			self::$calls[] = array( 'warning', self::text( $message ) );
		}

		public static function debug( mixed $message, mixed $group = false ): void {
		}

		public static function error( mixed $message, mixed $exit = true ): void {
			self::$calls[] = array( 'error', self::text( $message ) );
			if ( true === $exit || ( is_int( $exit ) && $exit > 0 ) ) {
				throw new CliExit( self::text( $message ), is_int( $exit ) ? $exit : 1 );
			}
		}

		public static function halt( int $return_code ): void {
			throw new CliExit( 'halt', $return_code );
		}

		/**
		 * @param array<string, mixed> $assoc_args
		 */
		public static function confirm( string $question, array $assoc_args = array() ): void {
			self::$calls[] = array( 'confirm', $question );
			if ( empty( $assoc_args['yes'] ) && ! self::$confirm ) {
				throw new CliExit( 'aborted', 0 );
			}
		}

		private static function text( mixed $message ): string {
			if ( $message instanceof \Throwable ) {
				return $message->getMessage();
			}

			return is_string( $message ) ? $message : (string) wp_json_encode( $message );
		}
	}
}
