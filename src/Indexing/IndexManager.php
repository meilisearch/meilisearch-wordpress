<?php
/**
 * Connection check, index creation, settings sync, drift and the scoped search key.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Api\Task;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Index lifecycle (spec § 5.4, § 7.3).
 */
final class IndexManager {

	public const MIN_VERSION  = '1.34.0';
	public const TASK_TIMEOUT = 30.0;

	/**
	 * Constructor.
	 *
	 * @param ClientFactory   $clients  Client factory.
	 * @param IndexNames      $names    Index names.
	 * @param SettingsBuilder $settings Settings builder.
	 * @param Options         $options  Plugin options.
	 */
	public function __construct(
		private ClientFactory $clients,
		private IndexNames $names,
		private SettingsBuilder $settings,
		private Options $options
	) {}

	/**
	 * Checks credentials and version with the authenticated GET /version.
	 *
	 * Transport and HTTP errors propagate as ApiError (403 invalid_api_key for a wrong key).
	 *
	 * @return string Meilisearch pkgVersion.
	 *
	 * @throws \RuntimeException Message starting with 'unsupported_version' when older than MIN_VERSION.
	 */
	public function check_connection(): string {
		$version = $this->clients->client()->version();
		$found   = isset( $version['pkgVersion'] ) && is_string( $version['pkgVersion'] ) ? $version['pkgVersion'] : '';

		if ( '' === $found || version_compare( $found, self::MIN_VERSION, '<' ) ) {
			throw new \RuntimeException(
				'unsupported_version: ' . sprintf(
					/* translators: 1: version found on the server, 2: minimum supported version. */
					__( 'Meilisearch %1$s is not supported. Version %2$s or newer is required.', 'meilisearch' ),
					'' === $found ? esc_html__( '(unknown)', 'meilisearch' ) : esc_html( $found ),
					esc_html( self::MIN_VERSION )
				)
			);
		}

		return $found;
	}

	/**
	 * Connection flow run after saving the Connection tab (spec § 7.3 steps 2–5).
	 *
	 * @return array{version: string, key: string} key is 'created', 'kept' or 'manual'.
	 *
	 * @throws ApiError          On Meilisearch errors.
	 * @throws \RuntimeException On unsupported versions or missing configuration.
	 */
	public function connect(): array {
		$version     = $this->check_connection();
		$fingerprint = md5( $this->options->host() . '|' . $this->options->admin_key() . '|' . $this->names->prefix() );
		$changed     = $fingerprint !== (string) $this->options->state( 'fingerprint', '' );

		$key = 'kept';
		if ( $changed || '' === $this->options->search_key() ) {
			$key = $this->rotate_search_key();
		}

		$this->ensure_all();

		if ( $changed ) {
			foreach ( $this->names->active_logicals() as $logical ) {
				$this->options->flag_reindex( $logical, true );
			}
			$this->options->set_state( 'fingerprint', $fingerprint );
		}

		return array(
			'version' => $version,
			'key'     => $key,
		);
	}

	/**
	 * Creates the index if missing (initial settings), otherwise merges required settings.
	 *
	 * Waits for every task (TASK_TIMEOUT each).
	 *
	 * @param string      $logical Logical index.
	 * @param string|null $uid     Index uid; defaults to the live uid.
	 * @return void
	 *
	 * @throws ApiError On Meilisearch errors or failed tasks.
	 */
	public function ensure_index( string $logical, ?string $uid = null ): void {
		$uid    = $uid ?? $this->names->uid( $logical );
		$client = $this->clients->client();

		if ( ! $client->index_exists( $uid ) ) {
			$client->create_index( $uid, 'id' )->wait( self::TASK_TIMEOUT );
			$client->update_settings( $uid, $this->settings->initial( $logical ) )->wait( self::TASK_TIMEOUT );
			return;
		}

		$task = $this->sync_settings( $logical, $uid );
		if ( null !== $task ) {
			$task->wait( self::TASK_TIMEOUT );
		}
	}

	/**
	 * Ensures every active logical index that has a schema.
	 *
	 * @return void
	 *
	 * @throws ApiError On Meilisearch errors or failed tasks.
	 */
	public function ensure_all(): void {
		foreach ( $this->names->active_logicals() as $logical ) {
			if ( $this->settings->has( $logical ) ) {
				$this->ensure_index( $logical );
			}
		}
	}

	/**
	 * PATCHes the missing required settings; does not wait.
	 *
	 * @param string $logical Logical index.
	 * @param string $uid     Index uid.
	 * @return Task|null Null when nothing had to change.
	 *
	 * @throws ApiError On Meilisearch errors.
	 */
	public function sync_settings( string $logical, string $uid ): ?Task {
		$client = $this->clients->client();
		$patch  = $this->settings->patch( $logical, $client->get_settings( $uid ) );

		return array() === $patch ? null : $client->update_settings( $uid, $patch );
	}

	/**
	 * Required settings problems on the live index: missing filterable/sortable
	 * attributes, plus "comparison disabled for X" when a filterable object rule
	 * shadows a range-filtered field with `features.filter.comparison` off.
	 *
	 * @param string $logical Logical index.
	 * @return string[]
	 *
	 * @throws ApiError On Meilisearch errors (index_not_found when the index does not exist).
	 */
	public function drift( string $logical ): array {
		$current = $this->clients->client()->get_settings( $this->names->uid( $logical ) );
		$drift   = $this->settings->missing( $logical, $current );
		foreach ( $this->settings->comparison_disabled( $logical, $current ) as $field ) {
			$drift[] = 'comparison disabled for ' . $field;
		}

		return $drift;
	}

	/**
	 * Creates a search-only key for both plugin indexes and deletes the previous plugin key.
	 *
	 * @return string 'created', or 'manual' when the admin key may not create keys.
	 *
	 * @throws ApiError On other Meilisearch errors.
	 */
	public function rotate_search_key(): string {
		$client  = $this->clients->client();
		$payload = array(
			'name'        => sprintf( 'WordPress search (%s)', home_url() ),
			'description' => 'Search-only key created by the Meilisearch WordPress plugin for autocomplete. Rotated when the connection changes.',
			'actions'     => array( 'search' ),
			'indexes'     => array( $this->names->uid( 'content' ), $this->names->uid( 'products' ) ),
			'expiresAt'   => null,
		);

		try {
			$key = $client->create_key( $payload );
		} catch ( ApiError $error ) {
			if ( 403 === $error->http_status || 'missing_master_key' === $error->error_code ) {
				$this->options->set_state( 'search_key_manual', true );
				return 'manual';
			}
			throw $error;
		}

		$new_key = (string) ( $key['key'] ?? '' );
		$new_uid = (string) ( $key['uid'] ?? '' );
		if ( '' === $new_key ) {
			throw new ApiError( __( 'Meilisearch did not return the search key.', 'meilisearch' ), 'invalid_response' );
		}

		$previous = $this->options->search_key_uid();
		$this->options->save_search_key( $new_key, $new_uid );
		$this->options->mark_search_key_verified();
		// Created by the plugin with the `search` action only.
		$this->options->set_state( 'search_key_manual', false );

		if ( '' !== $previous && $new_uid !== $previous ) {
			try {
				$client->delete_key( $previous );
			} catch ( ApiError $error ) {
				if ( 404 !== $error->http_status ) {
					throw $error;
				}
			}
		}

		return 'created';
	}

	/**
	 * Verifies a manually entered search key: only the `search` action, only this site's indexes.
	 *
	 * @param string $key Key value.
	 * @return bool|null True when safe, false when too broad, null when it could not be read.
	 */
	public function verify_search_key( string $key ): ?bool {
		try {
			$details = $this->clients->client()->get_key( $key );
		} catch ( ApiError $error ) {
			return null;
		}
		if ( ! isset( $details['actions'], $details['indexes'] ) || ! is_array( $details['actions'] ) || ! is_array( $details['indexes'] ) ) {
			return null;
		}
		$allowed = array( $this->names->uid( 'content' ), $this->names->uid( 'products' ) );

		return array( 'search' ) === array_values( $details['actions'] )
			&& array() === array_diff( $details['indexes'], $allowed );
	}
}
