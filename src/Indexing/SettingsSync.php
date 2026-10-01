<?php
/**
 * Applies Content and WooCommerce settings changes to Meilisearch right away.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Search\FilterBuilder;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;

/**
 * Content that stops being indexed must stop being reachable with the public search key without
 * waiting for a reindex, and new filterable/sortable fields must reach the index settings:
 *
 * - post types removed from the Content tab: their documents are deleted by filter;
 * - any Content change: IndexManager::ensure_all() pushes the required settings;
 * - products disabled: every product document is deleted (the index and its settings are kept)
 *   and the products index is marked unpopulated until a reindex fills it again.
 *
 * Runs inline after the option is saved. Errors are logged, never thrown: the settings save
 * always succeeds, and the next reindex finishes the job.
 */
final class SettingsSync implements Registrable {

	/**
	 * Constructor.
	 *
	 * @param ClientFactory $clients Client factory.
	 * @param IndexManager  $indexes Index manager.
	 * @param IndexNames    $names   Index names.
	 * @param Options       $options Options.
	 * @param ErrorLog      $log     Error log.
	 */
	public function __construct(
		private readonly ClientFactory $clients,
		private readonly IndexManager $indexes,
		private readonly IndexNames $names,
		private readonly Options $options,
		private readonly ErrorLog $log
	) {}

	/**
	 * Hooks the option updates (they fire only when the value changed).
	 */
	public function register(): void {
		add_action( 'update_option_' . Options::CONTENT, array( $this, 'on_content_update' ), 10, 2 );
		add_action( 'update_option_' . Options::WOOCOMMERCE, array( $this, 'on_woocommerce_update' ), 10, 2 );
	}

	/**
	 * Deletes the documents of removed post types, then pushes the required index settings.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 */
	public function on_content_update( mixed $old_value, mixed $value ): void {
		if ( ! is_array( $old_value ) || ! is_array( $value ) || ! $this->clients->is_configured() ) {
			return;
		}

		$removed = array_values( array_diff( self::post_types( $old_value ), self::post_types( $value ) ) );
		if ( array() !== $removed ) {
			try {
				$this->clients->client()->delete_documents_by_filter( $this->names->uid( 'content' ), FilterBuilder::in( 'post_type', $removed ) );
			} catch ( \Throwable $e ) {
				$this->log_unless_missing( $e, 'Removing the documents of post types no longer indexed failed: %s' );
			}
		}

		try {
			$this->indexes->ensure_all();
		} catch ( \Throwable $e ) {
			$this->log->add( 'settings', sprintf( 'Applying the index settings failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Empties the products index when product indexing is turned off.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 */
	public function on_woocommerce_update( mixed $old_value, mixed $value ): void {
		$was_enabled = is_array( $old_value ) && ! empty( $old_value['enabled'] );
		$is_enabled  = is_array( $value ) && ! empty( $value['enabled'] );
		if ( ! $was_enabled || $is_enabled || ! is_array( $value ) ) {
			return;
		}

		$this->options->set_populated( 'products', false );
		if ( ! $this->clients->is_configured() ) {
			return;
		}
		try {
			$this->clients->client()->delete_all_documents( $this->names->uid( 'products' ) );
		} catch ( \Throwable $e ) {
			$this->log_unless_missing( $e, 'Removing the product documents failed: %s' );
		}
	}

	/**
	 * Logs an error, except a missing index (there is nothing to delete).
	 *
	 * @param \Throwable $error  Error.
	 * @param string     $format sprintf() format with one %s for the message.
	 */
	private function log_unless_missing( \Throwable $error, string $format ): void {
		if ( $error instanceof ApiError && 'index_not_found' === $error->error_code ) {
			return;
		}
		$this->log->add( 'settings', sprintf( $format, $error->getMessage() ) );
	}

	/**
	 * Post type names of a Content option value.
	 *
	 * @param array<mixed> $value Option value.
	 * @return list<string>
	 */
	private static function post_types( array $value ): array {
		$types = isset( $value['post_types'] ) && is_array( $value['post_types'] ) ? $value['post_types'] : array();

		return array_values( array_unique( array_filter( $types, static fn ( $type ): bool => is_string( $type ) && '' !== $type ) ) );
	}
}
