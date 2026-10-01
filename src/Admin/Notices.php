<?php
/**
 * Admin notices.
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the plugin's admin notices on the Meilisearch screen.
 *
 * Each notice comes from one small private method returning a notice array or
 * null; notices() lists them in display order.
 */
final class Notices implements Registrable {

	public const CONFLICTING_PLUGINS = array( 'relevanssi/relevanssi.php', 'relevanssi-premium/relevanssi.php', 'searchwp/index.php', 'elasticpress/elasticpress.php', 'jetpack-search/jetpack-search.php' );

	/**
	 * Per-user transient prefix holding the last connection result.
	 */
	public const CONNECT_RESULT = 'meilisearch_connect_result_';

	/**
	 * Window for the "recent errors" count, in seconds.
	 */
	public const RECENT_WINDOW = 86400;

	/**
	 * Constructor.
	 *
	 * @param Options  $options Plugin options.
	 * @param ErrorLog $log     Error log.
	 */
	public function __construct( private Options $options, private ErrorLog $log ) {}

	/**
	 * Attaches hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Transient name holding a user's last connection result.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function connect_result_key( int $user_id ): string {
		return self::CONNECT_RESULT . $user_id;
	}

	/**
	 * Prints the notices (admin_notices).
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) || ! $this->on_plugin_screen() ) {
			return;
		}
		foreach ( $this->notices() as $notice ) {
			$link = '';
			if ( isset( $notice['url'], $notice['link'] ) ) {
				$link = sprintf( ' <a href="%1$s">%2$s</a>', esc_url( $notice['url'] ), esc_html( $notice['link'] ) );
			}
			printf(
				'<div class="notice notice-%1$s"><p>%2$s%3$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] ),
				$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}
	}

	/**
	 * Notices to show, in order.
	 *
	 * @return list<array{type: string, message: string, url?: string, link?: string}>
	 */
	public function notices(): array {
		return array_values(
			array_filter(
				array(
					$this->connect_result_notice(),
					$this->search_key_manual_notice(),
					$this->needs_reindex_notice(),
					$this->recent_errors_notice(),
				)
			)
		);
	}

	/**
	 * Whether the current screen is the plugin page.
	 *
	 * @return bool
	 */
	private function on_plugin_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return null !== $screen && Menu::HOOK_SUFFIX === $screen->id;
	}

	/**
	 * Result of the last connection attempt (shown once).
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function connect_result_notice(): ?array {
		$key    = self::connect_result_key( get_current_user_id() );
		$result = get_transient( $key );
		if ( ! is_array( $result ) || ! isset( $result['type'], $result['message'] ) ) {
			return null;
		}
		delete_transient( $key );
		$type = in_array( $result['type'], array( 'success', 'warning', 'error' ), true ) ? $result['type'] : 'info';

		return array(
			'type'    => $type,
			'message' => (string) $result['message'],
		);
	}

	/**
	 * The admin key cannot create keys and no search key was pasted.
	 *
	 * @return array{type: string, message: string, url: string, link: string}|null
	 */
	private function search_key_manual_notice(): ?array {
		if ( ! $this->options->state( 'search_key_manual', false ) || '' !== $this->options->search_key() ) {
			return null;
		}

		return array(
			'type'    => 'warning',
			'message' => __( 'Meilisearch could not create a search-only key with your admin key. Paste a search-only key in the Connection tab to enable autocomplete.', 'meilisearch' ),
			'url'     => Menu::url( 'connection' ),
			'link'    => __( 'Open the Connection tab', 'meilisearch' ),
		);
	}

	/**
	 * A logical index needs a full reindex.
	 *
	 * @return array{type: string, message: string, url: string, link: string}|null
	 */
	private function needs_reindex_notice(): ?array {
		if ( ! $this->options->is_configured() ) {
			return null;
		}
		$logicals = array( 'content' );
		if ( $this->options->products_enabled() ) {
			$logicals[] = 'products';
		}
		$flagged = array_values( array_filter( $logicals, array( $this->options, 'needs_reindex' ) ) );
		if ( array() === $flagged ) {
			return null;
		}
		$labels = array(
			'content'  => __( 'content', 'meilisearch' ),
			'products' => __( 'products', 'meilisearch' ),
		);

		return array(
			'type'    => 'warning',
			'message' => sprintf(
				/* translators: %s: comma-separated index names ("content", "products"). */
				__( 'Meilisearch: run a full reindex of %s so your latest settings take effect.', 'meilisearch' ),
				implode( ', ', array_map( static fn( string $logical ): string => $labels[ $logical ], $flagged ) )
			),
			'url'     => Menu::url( 'status' ),
			'link'    => __( 'Go to the Status tab', 'meilisearch' ),
		);
	}

	/**
	 * Errors logged in the last RECENT_WINDOW seconds.
	 *
	 * @return array{type: string, message: string, url: string, link: string}|null
	 */
	private function recent_errors_notice(): ?array {
		$since = time() - self::RECENT_WINDOW;
		$count = count( array_filter( $this->log->all(), static fn( array $entry ): bool => $entry['time'] >= $since ) );
		if ( 0 === $count ) {
			return null;
		}

		return array(
			'type'    => 'error',
			'message' => sprintf(
				/* translators: %d: number of errors. */
				_n( 'Meilisearch logged %d error in the last 24 hours.', 'Meilisearch logged %d errors in the last 24 hours.', $count, 'meilisearch' ),
				$count
			),
			'url'     => Menu::url( 'status' ),
			'link'    => __( 'View errors', 'meilisearch' ),
		);
	}
}
