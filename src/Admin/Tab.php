<?php
/**
 * Admin tab contract.
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One tab of the Meilisearch admin page. A tab that needs hooks also implements
 * Registrable; Menu::register() calls its register().
 */
interface Tab {

	/**
	 * Tab slug used in `?tab=`.
	 *
	 * @return string 'connection' | 'content' | 'woocommerce' | 'search' | 'status'
	 */
	public function slug(): string;

	/**
	 * Translated tab label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the current user may see the tab.
	 *
	 * @return bool
	 */
	public function is_visible(): bool;

	/**
	 * Registers the tab's settings (called on admin_init).
	 *
	 * @return void
	 */
	public function register_settings(): void;

	/**
	 * Prints the tab body inside the page wrapper.
	 *
	 * @return void
	 */
	public function render(): void;
}
