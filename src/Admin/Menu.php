<?php
/**
 * Top-level "Meilisearch" admin page with tabs.
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

use Meilisearch\WordPress\Registrable;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, settings registration and asset loading.
 */
final class Menu implements Registrable {

	public const SLUG        = 'meilisearch';
	public const HOOK_SUFFIX = 'toplevel_page_meilisearch';
	public const CAPABILITY  = 'manage_options';

	/**
	 * Tabs in display order.
	 *
	 * @var list<Tab>
	 */
	private array $tabs;

	/**
	 * Constructor.
	 *
	 * @param Tab[] $tabs Tabs in display order.
	 *
	 * @throws \InvalidArgumentException When no tab is given.
	 */
	public function __construct( array $tabs ) {
		if ( array() === $tabs ) {
			throw new \InvalidArgumentException( 'At least one tab is required.' );
		}
		$this->tabs = array_values( $tabs );
	}

	/**
	 * Attaches hooks, including those of tabs that are Registrable.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		foreach ( $this->tabs as $tab ) {
			if ( $tab instanceof Registrable ) {
				$tab->register();
			}
		}
	}

	/**
	 * Adds the top-level menu page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Meilisearch', 'meilisearch' ),
			__( 'Meilisearch', 'meilisearch' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-search'
		);
	}

	/**
	 * Registers every tab's settings; each tab uses its own option group.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		foreach ( $this->tabs as $tab ) {
			$tab->register_settings();
		}
	}

	/**
	 * Loads the admin stylesheet on the plugin screen only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'meilisearch-admin', plugins_url( 'assets/css/admin.css', MEILISEARCH_FILE ), array(), MEILISEARCH_VERSION );
	}

	/**
	 * Tabs the current user may see.
	 *
	 * @return list<Tab>
	 */
	public function visible_tabs(): array {
		return array_values( array_filter( $this->tabs, static fn( Tab $tab ): bool => $tab->is_visible() ) );
	}

	/**
	 * Tab selected by `?tab=`, falling back to the first visible tab.
	 *
	 * @return Tab
	 */
	public function current_tab(): Tab {
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$visible   = $this->visible_tabs();
		foreach ( $visible as $tab ) {
			if ( $tab->slug() === $requested ) {
				return $tab;
			}
		}

		return $visible[0] ?? $this->tabs[0];
	}

	/**
	 * Prints the page: title, settings messages, tab navigation, current tab.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$current = $this->current_tab();

		echo '<div class="wrap meilisearch-admin">';
		echo '<h1>' . esc_html__( 'Meilisearch', 'meilisearch' ) . '</h1>';
		settings_errors();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Meilisearch settings', 'meilisearch' ) . '">';
		foreach ( $this->visible_tabs() as $tab ) {
			$active = $tab->slug() === $current->slug();
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $tab->slug() ) ),
				$active ? ' nav-tab-active' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $tab->label() )
			);
		}
		echo '</nav>';
		echo '<div class="meilisearch-tab meilisearch-tab-' . esc_attr( $current->slug() ) . '">';
		if ( $current->is_visible() ) {
			$current->render();
		}
		echo '</div></div>';
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}
}
