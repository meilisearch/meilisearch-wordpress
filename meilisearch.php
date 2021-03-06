<?php

/**
 * MeiliSearch
 *
 * @package           meilisearch/meilisearch-wordpress
 * @author            MeiliSearch <bonjour@meilisearch.com>
 * @copyright         2021 MeiliSearch
 * @license           https://github.com/meilisearch/MeiliSearch/blob/master/LICENSE MIT
 * @link              https://wordpress.meilisearch.dev
 *
 * @wordpress-plugin
 * Plugin Name:       MeiliSearch
 * Plugin URI:        https://wordpress.meilisearch.dev
 * Description:       The best search experience in WordPress with MeiliSearch.
 * Version:           0.1.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            MeiliSearch
 * Author URI:        https://www.meilisearch.com
 * Text Domain:       meilisearch
 * License:           MIT
 * License URI:       https://github.com/meilisearch/MeiliSearch/blob/master/LICENSE
 */

declare(strict_types=1);

namespace MeiliSearch\WordPress;

use Amp\Injector\Injector;

use function add_action;
use function current_user_can;
use function deactivate_plugins;
use function esc_html;
use function esc_html__;
use function plugin_basename;
use function register_activation_hook;
use function register_deactivation_hook;
use function register_uninstall_hook;

// Prevent direct execution.
if (! defined('ABSPATH')) {
    exit;
}

// Load autoloader.
if (! class_exists(Config::class) && is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Define constant values.
Config::init(
    [
        'version' => '0.1.0',
        'filePath' => __FILE__,
        'baseName' => plugin_basename(__FILE__),
        'slug' => 'meilisearch',
        'injector' => new Injector(),
    ]
);

// Load translations.
add_action('init', __NAMESPACE__ . '\\loadTextDomain', 10, 0);

// Check requirements.
if (
    (new Requirements())
        ->php('7.2')
        ->wp('5.2')
        ->multisite(false)
        ->packages(['meilisearch/meilisearch-php'])
        ->met()
) {
    // Hook plugin activation functions.
    register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate');
    register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate');
    register_uninstall_hook(__FILE__, __NAMESPACE__ . '\\uninstall');
    add_action('plugins_loaded', __NAMESPACE__ . '\\boot', 10, 0);
} else {
    // Suppress "Plugin activated." notice.
    unset($_GET['activate']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    add_action('admin_notices', __NAMESPACE__ . '\\printRequirementsNotice', 0, 0);

    require_once \ABSPATH . 'wp-admin/includes/plugin.php';
    deactivate_plugins([Config::get('baseName')], true);
}
