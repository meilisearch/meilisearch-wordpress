<?php

/**
 * functions.php - Procedural part of MeiliSearch.
 *
 * @package company/smallproject
 * @author Your Name <username@example.com>
 * @copyright 2019 Your Name or Company Name
 * @license GPL-2.0-or-later http://www.gnu.org/licenses/gpl-2.0.txt
 * @link https://example.com/plugin-name
 */

declare(strict_types=1);

namespace MeiliSearch\WordPress;

use function add_action;
use function current_user_can;
use function esc_html__;
use function esc_url;
use function load_plugin_textdomain;
use function plugin_dir_url;
use function register_widget;

/**
 * @return void
 */
function loadTextDomain()
{
    load_plugin_textdomain('plugin-slug', false, dirname(Config::get('baseName')) . '/languages');
}

/**
 * @return void
 */
function activate()
{
    // Run database migrations, initialize WordPress options etc.
}

/**
 * @return void
 */
function deactivate()
{
    // Do something related to deactivation.
}

/**
 * @return void
 */
function uninstall()
{
    // Remove custom database tables, WordPress options etc.
}

/**
 * @return void
 */
function printRequirementsNotice()
{
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    error_log('Plugin Name requirements are not met. Please read the Installation instructions.');

    if (! current_user_can('activate_plugins')) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p>%1$s <a href="%2$s" target="_blank">%3$s</a> %4$s</p></div>',
        esc_html__('Plugin Name activation failed! Please read', 'plugin-slug'),
        esc_url('https://github.com/szepeviktor/small-project#installation'),
        esc_html__('the Installation instructions', 'plugin-slug'),
        esc_html__('for list of requirements.', 'plugin-slug')
    );
}

function registerWidget(): void
{
    register_widget(Widget::class);
}

/**
 * Start!
 */
function boot(): void
{
    new Index();
    add_action('widgets_init', __NAMESPACE__ . '\\registerWidget');
}

function bootAdmin(): void
{
    new Admin();
}
