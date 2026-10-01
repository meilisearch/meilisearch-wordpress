<?php
/**
 * Uninstall routine (spec § 11.5). WordPress deactivates the plugin, then includes only this file.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

// Action Scheduler 4.2+ initializes itself when loaded this late (from uninstall.php) without scheduling
// anything (ActionScheduler::is_uninstalling()); if another plugin already loaded it, this is a no-op.
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

\Meilisearch\WordPress\Lifecycle\Uninstaller::run();
