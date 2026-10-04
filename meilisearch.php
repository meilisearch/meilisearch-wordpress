<?php
/**
 * Plugin Name:       Meilisearch
 * Plugin URI:        https://github.com/meilisearch/meilisearch-wordpress
 * Description:       Fast, relevant, typo-tolerant search for WordPress and WooCommerce, powered by Meilisearch.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Meilisearch
 * Author URI:        https://www.meilisearch.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       meilisearch
 * Domain Path:       /languages
 *
 * @package Meilisearch
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'MEILISEARCH_VERSION', '1.0.0' );
define( 'MEILISEARCH_FILE', __FILE__ );
define( 'MEILISEARCH_DIR', plugin_dir_path( __FILE__ ) );

require_once MEILISEARCH_DIR . 'vendor/autoload.php';

// Loaded at plugin load time, never inside a hook, so Action Scheduler can negotiate versions with other copies.
require_once MEILISEARCH_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( MEILISEARCH_FILE, array( \Meilisearch\WordPress\Lifecycle\Activator::class, 'activate' ) );
register_deactivation_hook( MEILISEARCH_FILE, array( \Meilisearch\WordPress\Lifecycle\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Meilisearch\WordPress\Plugin::class, 'boot' ) );
