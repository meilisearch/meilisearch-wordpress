<?php

/**
 * Widget.php
 *
 * @package meilisearch/meilisearch-wordpress
 * @author MeiliSearch <bonjour@meilisearch.com>
 * @copyright 2021 MeiliSearch
 * @license https://github.com/meilisearch/MeiliSearch/blob/master/LICENSE MIT
 * @link https://wordpress.meilisearch.dev
 */

declare(strict_types=1);

namespace MeiliSearch\WordPress;

use MeiliSearch\WordPress\Tools\HookAnnotation;

class Admin
{
    use HookAnnotation;

    /** @var \MeiliSearch\WordPress\Index */
    protected $index;

    /** @var \MeiliSearch\WordPress\ArrayOption */
    protected $option;

    public function __construct(Index $index, ArrayOption $option)
    {
        $this->index = $index;
        $this->option = $option;
        $this->hookMethods();
    }

    /**
     * @hook admin_menu
     */
    public function add_plugin_page(): void
    {
        add_menu_page(
            'MeiliSearch', // page_title
            'MeiliSearch', // menu_title
            'manage_options', // capability
            'meilisearch', // menu_slug
            [$this, 'create_admin_page'], // function
            'dashicons-admin-generic', // icon_url
        );

        add_submenu_page(
            'meilisearch', // parent_slug
            'MeiliSearch Settings', // page_title
            'Index content', // menu_title
            'manage_options', // capability
            'meilisearch_index_content', // menu_slug
            [$this, 'create_admin_page_index_content'], // function
        );
    }

    /**
     * @hook admin_init
     */
    public function page_init(): void
    {
        register_setting(
            'meilisearch_option_group',
            ArrayOption::OPTION_NAME,
            [$this, 'sanitize']
        );

        add_settings_section(
            'meilisearch_setting_section', // id
            'Settings', // title
            [$this, 'section_info'], // callback
            'meilisearch-admin-page' // page
        );

        add_settings_field(
            'url_0', // id
            'MeiliSearch URL', // title
            [$this, 'url_0_callback'], // callback
            'meilisearch-admin-page', // page
            'meilisearch_setting_section' // section
        );

        add_settings_field(
            'search_url_4', // id
            'MeiliSearch Search URL (if different)', // title
            [$this, 'search_url_4_callback'], // callback
            'meilisearch-admin-page', // page
            'meilisearch_setting_section' // section
        );

        add_settings_field(
            'private_key_1', // id
            'MeiliSearch Private Key', // title
            [$this, 'private_key_1_callback'], // callback
            'meilisearch-admin-page', // page
            'meilisearch_setting_section' // section
        );

        add_settings_field(
            'public_key_2', // id
            'MeiliSearch Public Key', // title
            [$this, 'public_key_2_callback'], // callback
            'meilisearch-admin-page', // page
            'meilisearch_setting_section' // section
        );

        add_settings_field(
            'index_name', // id
            'MeiliSearch Index Name', // title
            [$this, 'index_name_callback'], // callback
            'meilisearch-admin-page', // page
            'meilisearch_setting_section' // section
        );
    }

    public function create_admin_page(): void
    {
        ?>
        <div class="wrap">
            <h2>MeiliSearch</h2>
            <p>Set up MeiliSearch for your WordPress installation</p>
            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                    settings_fields( 'meilisearch_option_group' );
                    do_settings_sections( 'meilisearch-admin-page' );
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function create_admin_page_index_content(): void
    {
        if (isset($_GET['deleteIndex']) && $_GET['deleteIndex'] === '1') {
            $this->index->deleteIndex();
        }

        if (isset($_GET['indexAll']) && $_GET['indexAll'] === '1') {
            $this->index->indexAllPostsSync();
        }

        ?>
        <div class="wrap">
            <h2>Index your existing content to MeiliSearch</h2>
            <p>In this page you can index all of your currently existing content in your Wordpress site</p>
            <?php settings_errors(); ?>

            <p>Indexed documents: <?php echo $this->index->getCount(); ?></p>

            <form method="post" action="admin.php?page=meilisearch_index_content&deleteIndex=1">
                <span id="index-all-button">
                    <input id="index-all" type="submit" value="Delete Index" class="button" >
                </span>
            </form>
            <form method="post" action="admin.php?page=meilisearch_index_content&indexAll=1">
                <span id="delete-index-button">
                    <input id="delete-index" type="submit" value="Index my site content" class="button" >
                </span>
            </form>
        </div>
        <?php
    }

    public function sanitize(array $input): array
    {
        $sanitary_values = array();
        if ( isset( $input['url_0'] ) ) {
            $sanitary_values['url_0'] = sanitize_text_field( $input['url_0'] );
        }

        if ( isset( $input['search_url_4'] ) ) {
            $sanitary_values['search_url_4'] = sanitize_text_field( $input['search_url_4'] );
        }

        if ( isset( $input['private_key_1'] ) ) {
            $sanitary_values['private_key_1'] = sanitize_text_field( $input['private_key_1'] );
        }

        if ( isset( $input['public_key_2'] ) ) {
            $sanitary_values['public_key_2'] = sanitize_text_field( $input['public_key_2'] );
        }

        if ( isset( $input['index_name'] ) ) {
            $sanitary_values['index_name'] = sanitize_text_field( $input['index_name'] );
        }

        return $sanitary_values;
    }

    public function section_info(): void
    {
        echo 'function section_info'; // @TODO
    }

    public function url_0_callback(): void
    {
        printf(
            '<input class="regular-text" type="text" name="%s[url_0]" id="meilisearch_url_0" value="%s">',
            ArrayOption::OPTION_NAME,
            esc_attr($this->option->get('url_0') ?? '')
        );
    }

    public function search_url_4_callback(): void
    {
        printf(
            '<input class="regular-text" type="text" name="%s[search_url_4]" id="meilisearch_search_url_4" value="%s">',
            ArrayOption::OPTION_NAME,
            esc_attr($this->option->get('search_url_4') ?? '')
        );
    }

    public function private_key_1_callback(): void
    {
        printf(
            '<input class="regular-text" type="text" name="%s[private_key_1]" id="meilisearch_private_key_1" value="%s">',
            ArrayOption::OPTION_NAME,
            esc_attr($this->option->get('private_key_1') ?? '')
        );
    }

    public function public_key_2_callback(): void
    {
        printf(
            '<input class="regular-text" type="text" name="%s[public_key_2]" id="meilisearch_public_key_2" value="%s">',
            ArrayOption::OPTION_NAME,
            esc_attr($this->option->get('public_key_2') ?? '')
        );
    }

    public function index_name_callback(): void
    {
        printf(
            '<input class="regular-text" type="text" name="%s[index_name]" id="meilisearch_index_name" value="%s">',
            ArrayOption::OPTION_NAME,
            esc_attr($this->option->get('index_name') ?? '')
        );
    }
}
