<?php
/**
 * Connection tab: host, admin key, prefix, search key fallback.
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Option group `meilisearch_connection` holds exactly two options:
 * Options::CONNECTION and Options::ADMIN_KEY.
 */
final class ConnectionTab implements Tab, Registrable {

	public const GROUP       = 'meilisearch_connection';
	public const PLACEHOLDER = '•••••••• (saved)';
	public const PREFIX_MAX  = 40;

	private const DEFAULTS = array(
		'host'                => '',
		'prefix'              => '',
		'search_key'          => '',
		'search_key_uid'      => '',
		'delete_on_uninstall' => false,
	);

	/**
	 * Constructor.
	 *
	 * @param Options      $options Plugin options.
	 * @param IndexManager $indexes Index manager.
	 * @param IndexNames   $names   Index names.
	 */
	public function __construct( private Options $options, private IndexManager $indexes, private IndexNames $names ) {}

	/**
	 * Attaches hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ) );
		add_action( 'load-' . Menu::HOOK_SUFFIX, array( $this, 'after_save' ) );
	}

	/**
	 * Tab slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'connection';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Connection', 'meilisearch' );
	}

	/**
	 * Super admins only on multisite (prevents SSRF from subsite admins).
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		return current_user_can( $this->required_capability() );
	}

	/**
	 * Capability needed to save the group (filter option_page_capability_meilisearch_connection).
	 *
	 * @param string $capability Default capability.
	 * @return string
	 */
	public function capability( string $capability ): string {
		return is_multisite() ? 'manage_network_options' : $capability;
	}

	/**
	 * Registers both options of the group.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		global $pagenow;
		if ( 'options.php' === $pagenow ) {
			$this->ensure_private_options();
		}

		register_setting(
			self::GROUP,
			Options::CONNECTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_connection' ),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			Options::ADMIN_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_admin_key' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitizes Options::CONNECTION.
	 *
	 * A key missing from the input keeps its stored value, except the
	 * delete_on_uninstall checkbox (missing = unchecked). `search_key` is taken
	 * from the form only in manual mode (and then needs a successful verification
	 * before it is served); both key fields are taken from the input only during
	 * Options::save_search_key(). Idempotent.
	 *
	 * @param mixed $input Submitted value.
	 * @return array{host: string, prefix: string, search_key: string, search_key_uid: string, delete_on_uninstall: bool}
	 */
	public function sanitize_connection( mixed $input ): array {
		$stored = get_option( Options::CONNECTION, array() );
		$stored = array_merge( self::DEFAULTS, array_intersect_key( is_array( $stored ) ? $stored : array(), self::DEFAULTS ) );
		$out    = array(
			'host'                => (string) $stored['host'],
			'prefix'              => (string) $stored['prefix'],
			'search_key'          => (string) $stored['search_key'],
			'search_key_uid'      => (string) $stored['search_key_uid'],
			'delete_on_uninstall' => (bool) $stored['delete_on_uninstall'],
		);
		if ( ! is_array( $input ) ) {
			return $out;
		}

		if ( ! $this->options->host_is_constant() && array_key_exists( 'host', $input ) && is_scalar( $input['host'] ) ) {
			$raw  = trim( (string) $input['host'] );
			$host = Options::normalize_host( $raw );
			if ( '' === $raw || '' !== $host ) {
				$out['host'] = $host;
			} else {
				add_settings_error( self::GROUP, 'meilisearch_invalid_host', __( 'The host must be an http:// or https:// URL. The previous value was kept.', 'meilisearch' ) );
			}
		}

		if ( array_key_exists( 'prefix', $input ) && is_scalar( $input['prefix'] ) ) {
			$out['prefix'] = self::sanitize_prefix( (string) $input['prefix'] );
		}

		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		if ( Options::is_internal_key_write() ) {
			$out['search_key']     = is_scalar( $input['search_key'] ?? '' ) ? sanitize_text_field( (string) ( $input['search_key'] ?? '' ) ) : '';
			$out['search_key_uid'] = is_scalar( $input['search_key_uid'] ?? '' ) ? sanitize_text_field( (string) ( $input['search_key_uid'] ?? '' ) ) : '';
		} elseif ( array_key_exists( 'search_key', $input ) && is_scalar( $input['search_key'] ) && $this->options->state( 'search_key_manual', false ) ) {
			$key = sanitize_text_field( (string) $input['search_key'] );
			if ( $key !== $out['search_key'] ) {
				$out['search_key']     = $key;
				$out['search_key_uid'] = '';
			}
		}

		return $out;
	}

	/**
	 * Sanitizes Options::ADMIN_KEY: an empty submission keeps the stored key.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public function sanitize_admin_key( mixed $input ): string {
		$stored = get_option( Options::ADMIN_KEY, '' );
		$stored = is_string( $stored ) ? $stored : '';
		if ( $this->options->admin_key_is_constant() || ! is_scalar( $input ) ) {
			return $stored;
		}
		$key = trim( sanitize_text_field( (string) $input ) );

		return '' === $key || self::PLACEHOLDER === $key ? $stored : $key;
	}

	/**
	 * Index prefix: lowercase [a-z0-9_], at most PREFIX_MAX characters.
	 *
	 * @param string $raw Raw prefix.
	 * @return string
	 */
	public static function sanitize_prefix( string $raw ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $raw ) ), 0, self::PREFIX_MAX );
	}

	/**
	 * Runs the connection flow after the Settings API redirect back to this tab
	 * (load-toplevel_page_meilisearch) and stores the result for Notices.
	 *
	 * @return void
	 */
	public function after_save(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- options.php verified the nonce before redirecting here; nothing is read from the request but these flags.
		$updated = isset( $_GET['settings-updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['settings-updated'] ) );
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $updated || $this->slug() !== $tab || ! $this->is_visible() ) {
			return;
		}

		if ( ! $this->options->is_configured() ) {
			$this->store_result( 'error', __( 'Enter the Meilisearch host URL and an admin API key.', 'meilisearch' ) );
			return;
		}

		try {
			$result = $this->indexes->connect();
		} catch ( \Throwable $error ) {
			$this->store_result(
				'error',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not connect to Meilisearch: %s', 'meilisearch' ),
					self::human_message( $error )
				)
			);
			return;
		}

		$this->options->set_state(
			'last_connect',
			array(
				'version' => $result['version'],
				'time'    => time(),
			)
		);

		/* translators: %s: Meilisearch version. */
		$message = sprintf( __( 'Connected to Meilisearch %s. Indexes are ready.', 'meilisearch' ), $result['version'] );
		$type    = 'success';
		if ( 'created' === $result['key'] ) {
			$message .= ' ' . __( 'A search-only key was created for autocomplete.', 'meilisearch' );
		} elseif ( 'manual' === $result['key'] || $this->options->state( 'search_key_manual', false ) ) {
			$search_key = $this->options->search_key();
			if ( '' === $search_key ) {
				$type     = 'warning';
				$message .= ' ' . __( 'Your admin key cannot create API keys: paste a search-only key below to enable autocomplete.', 'meilisearch' );
			} else {
				$verified = $this->indexes->verify_search_key( $search_key );
				if ( false === $verified ) {
					$this->options->clear_search_key_verified();
				} else {
					$this->options->mark_search_key_verified();
					// True, or null (unverifiable: served with the warning below).
				}
				if ( false === $verified ) {
					$type     = 'error';
					$message .= ' ' . __( 'The search key is not a search-only key for this site\'s indexes (it is the admin or master key, Meilisearch does not know it, or it allows more than "search"). It is not sent to visitors: replace it with a search-only key.', 'meilisearch' );
				} elseif ( null === $verified ) {
					$type     = 'warning';
					$message .= ' ' . __( 'The search key could not be verified. Make sure it only allows the "search" action.', 'meilisearch' );
				}
			}
		}//end if

		$this->store_result( $type, $message );
	}

	/**
	 * Prints the tab.
	 *
	 * @return void
	 */
	public function render(): void {
		$connection = get_option( Options::CONNECTION, array() );
		$connection = array_merge( self::DEFAULTS, is_array( $connection ) ? $connection : array() );
		$has_key    = '' !== $this->options->admin_key();
		$manual     = (bool) $this->options->state( 'search_key_manual', false );
		$last       = $this->options->state( 'last_connect' );

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="meilisearch-host">' . esc_html__( 'Host URL', 'meilisearch' ) . '</label></th><td>';
		if ( $this->options->host_is_constant() ) {
			echo '<code>' . esc_html( $this->options->host() ) . '</code> <p class="description">' . esc_html__( 'Defined by MEILISEARCH_HOST in wp-config.php.', 'meilisearch' ) . '</p>';
		} else {
			printf(
				'<input type="url" id="meilisearch-host" class="regular-text" name="%1$s[host]" value="%2$s" placeholder="https://ms-xxxx.meilisearch.io" />',
				esc_attr( Options::CONNECTION ),
				esc_attr( (string) $connection['host'] )
			);
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="meilisearch-admin-key">' . esc_html__( 'Admin API key', 'meilisearch' ) . '</label></th><td>';
		if ( $this->options->admin_key_is_constant() ) {
			echo '<p>' . esc_html__( 'Defined by MEILISEARCH_ADMIN_KEY in wp-config.php.', 'meilisearch' ) . '</p>';
		} else {
			printf(
				'<input type="password" id="meilisearch-admin-key" class="regular-text" name="%1$s" value="" autocomplete="new-password" placeholder="%2$s" />',
				esc_attr( Options::ADMIN_KEY ),
				esc_attr( $has_key ? self::PLACEHOLDER : '' )
			);
			echo '<p class="description">' . esc_html__( 'Leave empty to keep the saved key. The key is never displayed.', 'meilisearch' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="meilisearch-prefix">' . esc_html__( 'Index prefix', 'meilisearch' ) . '</label></th><td>';
		printf(
			'<input type="text" id="meilisearch-prefix" class="regular-text" name="%1$s[prefix]" value="%2$s" maxlength="%3$d" pattern="[a-z0-9_]*" />',
			esc_attr( Options::CONNECTION ),
			esc_attr( (string) $connection['prefix'] ),
			(int) self::PREFIX_MAX
		);
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: index prefix in use. */
				__( 'Lowercase letters, digits and underscores. Leave empty for the default. In use: %s. Changing it requires a full reindex.', 'meilisearch' ),
				$this->names->prefix()
			)
		) . '</p></td></tr>';

		if ( $manual ) {
			echo '<tr><th scope="row"><label for="meilisearch-search-key">' . esc_html__( 'Search key', 'meilisearch' ) . '</label></th><td>';
			printf(
				'<input type="text" id="meilisearch-search-key" class="regular-text" name="%1$s[search_key]" value="%2$s" autocomplete="off" />',
				esc_attr( Options::CONNECTION ),
				esc_attr( (string) $connection['search_key'] )
			);
			echo '<p class="description">' . esc_html__( 'A key allowed only the "search" action on this site\'s indexes. It is sent to visitors\' browsers for autocomplete.', 'meilisearch' ) . '</p></td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Uninstall', 'meilisearch' ) . '</th><td><label>';
		printf(
			'<input type="checkbox" name="%1$s[delete_on_uninstall]" value="1"%2$s /> ',
			esc_attr( Options::CONNECTION ),
			checked( (bool) $connection['delete_on_uninstall'], true, false )
		);
		echo esc_html__( 'Delete the indexes and the search key from Meilisearch when the plugin is uninstalled.', 'meilisearch' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Status', 'meilisearch' ) . '</th><td><p class="meilisearch-status">';
		if ( ! $this->options->is_configured() ) {
			echo esc_html__( 'Not connected.', 'meilisearch' );
		} elseif ( is_array( $last ) && isset( $last['version'] ) ) {
			echo esc_html(
				sprintf(
					/* translators: %s: Meilisearch version. */
					__( 'Connected to Meilisearch %s.', 'meilisearch' ),
					(string) $last['version']
				)
			);
			echo ' ' . esc_html( '' !== $this->options->search_key() ? __( 'Search key: OK.', 'meilisearch' ) : __( 'Search key: missing.', 'meilisearch' ) );
		} else {
			echo esc_html__( 'Saved but not verified yet. Save to test the connection.', 'meilisearch' );
		}
		echo '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save and connect', 'meilisearch' ) );
		echo '</form>';
	}

	/**
	 * Capability required for this tab.
	 *
	 * @return string
	 */
	private function required_capability(): string {
		return is_multisite() ? 'manage_network_options' : Menu::CAPABILITY;
	}

	/**
	 * Makes sure both options exist with autoload off before options.php saves them
	 * (update_option() on a missing option would add it with autoload "auto").
	 *
	 * @return void
	 */
	private function ensure_private_options(): void {
		if ( false === get_option( Options::ADMIN_KEY, false ) ) {
			add_option( Options::ADMIN_KEY, '', '', false );
		}
		if ( false === get_option( Options::CONNECTION, false ) ) {
			add_option( Options::CONNECTION, self::DEFAULTS, '', false );
		}
	}

	/**
	 * Stores the connection result for Notices (60 s, per user).
	 *
	 * @param string $type    'success' | 'warning' | 'error'.
	 * @param string $message Message.
	 * @return void
	 */
	private function store_result( string $type, string $message ): void {
		set_transient(
			Notices::connect_result_key( get_current_user_id() ),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Plain-text exception message, without the machine prefix of an unsupported version.
	 *
	 * Exception messages are raw data (IndexManager::check_connection() included): Notices escapes
	 * every message exactly once, at display.
	 *
	 * @param \Throwable $error Error.
	 * @return string
	 */
	private static function human_message( \Throwable $error ): string {
		$message = $error->getMessage();
		$prefix  = 'unsupported_version: ';
		if ( str_starts_with( $message, $prefix ) ) {
			return substr( $message, strlen( $prefix ) );
		}

		return $message;
	}
}
