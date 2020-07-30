<?php

class MeiliSearch {
    private $meilisearch_options;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'meilisearch_add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'meilisearch_page_init' ) );
    }

    public function meilisearch_add_plugin_page() {
        add_menu_page(
            'MeiliSearch', // page_title
            'MeiliSearch', // menu_title
            'manage_options', // capability
            'meilisearch', // menu_slug
            array( $this, 'meilisearch_create_admin_page' ), // function
            'dashicons-admin-generic', // icon_url
            3 // position
        );
    }

    public function meilisearch_create_admin_page() {

        if ($_GET['indexAll'] == 1) {
            index_all_posts();
        }

        $this->meilisearch_options = get_option( 'meilisearch_option_name' ); ?>

        <div class="wrap">
            <h2>MeiliSearch</h2>
            <p>Set up MeiliSearch for your Wordpress</p>
            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                    settings_fields( 'meilisearch_option_group' );
                    do_settings_sections( 'meilisearch-admin' );
                    submit_button();
                ?>
            </form>
            <form method="post" name="test-button" action="admin.php?page=meilisearch&indexAll=1">
                <span id="test-button">
                    <input id="index-all" type="submit" value="Index site content" class="button" >
                </span>
            </form>
        </div>
    <?php }

    public function meilisearch_page_init() {

        register_setting(
            'meilisearch_option_group', // option_group
            'meilisearch_option_name', // option_name
            array( $this, 'meilisearch_sanitize' ) // sanitize_callback
        );

        add_settings_section(
            'meilisearch_setting_section', // id
            'Settings', // title
            array( $this, 'meilisearch_section_info' ), // callback
            'meilisearch-admin' // page
        );

        add_settings_field(
            'meilisearch_url_0', // id
            'MeiliSearch URL', // title
            array( $this, 'meilisearch_url_0_callback' ), // callback
            'meilisearch-admin', // page
            'meilisearch_setting_section' // section
        );

        add_settings_field(
            'meilisearch_search_url_4', // id
            'MeiliSearch Search URL (if different)', // title
            array( $this, 'meilisearch_search_url_4_callback' ), // callback
            'meilisearch-admin', // page
            'meilisearch_setting_section' // section
        );

        add_settings_field(
            'meilisearch_private_key_1', // id
            'MeiliSearch Private Key', // title
            array( $this, 'meilisearch_private_key_1_callback' ), // callback
            'meilisearch-admin', // page
            'meilisearch_setting_section' // section
        );

        add_settings_field(
            'meilisearch_public_key_2', // id
            'MeiliSearch Public Key', // title
            array( $this, 'meilisearch_public_key_2_callback' ), // callback
            'meilisearch-admin', // page
            'meilisearch_setting_section' // section
        );
    }

    public function meilisearch_sanitize($input) {
        $sanitary_values = array();
        if ( isset( $input['meilisearch_url_0'] ) ) {
            $sanitary_values['meilisearch_url_0'] = sanitize_text_field( $input['meilisearch_url_0'] );
        }

        if ( isset( $input['meilisearch_search_url_4'] ) ) {
            $sanitary_values['meilisearch_search_url_4'] = sanitize_text_field( $input['meilisearch_search_url_4'] );
        }

        if ( isset( $input['meilisearch_private_key_1'] ) ) {
            $sanitary_values['meilisearch_private_key_1'] = sanitize_text_field( $input['meilisearch_private_key_1'] );
        }

        if ( isset( $input['meilisearch_public_key_2'] ) ) {
            $sanitary_values['meilisearch_public_key_2'] = sanitize_text_field( $input['meilisearch_public_key_2'] );
        }

        return $sanitary_values;
    }

    public function meilisearch_section_info() {

    }

    public function meilisearch_url_0_callback() {
        printf(
            '<input class="regular-text" type="text" name="meilisearch_option_name[meilisearch_url_0]" id="meilisearch_url_0" value="%s">',
            isset( $this->meilisearch_options['meilisearch_url_0'] ) ? esc_attr( $this->meilisearch_options['meilisearch_url_0']) : ''
        );
    }

    public function meilisearch_search_url_4_callback() {
        printf(
            '<input class="regular-text" type="text" name="meilisearch_option_name[meilisearch_search_url_4]" id="meilisearch_search_url_4" value="%s">',
            isset( $this->meilisearch_options['meilisearch_search_url_4'] ) ? esc_attr( $this->meilisearch_options['meilisearch_search_url_4']) : ''
        );
    }

    public function meilisearch_private_key_1_callback() {
        printf(
            '<input class="regular-text" type="text" name="meilisearch_option_name[meilisearch_private_key_1]" id="meilisearch_private_key_1" value="%s">',
            isset( $this->meilisearch_options['meilisearch_private_key_1'] ) ? esc_attr( $this->meilisearch_options['meilisearch_private_key_1']) : ''
        );
    }

    public function meilisearch_public_key_2_callback() {
        printf(
            '<input class="regular-text" type="text" name="meilisearch_option_name[meilisearch_public_key_2]" id="meilisearch_public_key_2" value="%s">',
            isset( $this->meilisearch_options['meilisearch_public_key_2'] ) ? esc_attr( $this->meilisearch_options['meilisearch_public_key_2']) : ''
        );
    }

}

?>
