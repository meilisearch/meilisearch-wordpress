<?php
/**
 * Contract of a tab that acts after its settings were saved.
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A tab that runs something when the admin page loads after a Settings API save.
 *
 * Menu calls after_save() on the tab it is about to render, so the tab never has
 * to work out from the URL whether it is the current one: the menu link has no
 * `?tab=` and options.php redirects back to whatever URL the form was shown on.
 */
interface HandlesSave {

	/**
	 * Called on `load-toplevel_page_meilisearch` when this tab is the current one
	 * and visible to the user. The tab checks `settings-updated` itself.
	 *
	 * @return void
	 */
	public function after_save(): void;
}
