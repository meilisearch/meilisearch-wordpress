<?php
   /*
   Plugin Name: MeiliSearch Wordpress
   Plugin URI: https://wordpress.meilisearch.dev
   description: The best search experience in wordpress with MeiliSearch
   Version: 1.0
   Author: MeiliSearch
   Author URI: https://meilisearch.com
   License: MIT
   */

   require_once __DIR__ . '/vendor/autoload.php';

   use MeiliSearch\Client;

   function get_meilisearch_index(){
       $meilisearch_options = get_option( 'meilisearch_option_name' );
       $client = new Client($meilisearch_options['meilisearch_url_0'], $meilisearch_options['meilisearch_private_key_1']);
       $index = $client->getOrCreateIndex('wordpress');
       return $index;
    }
    function index_post_after_update($post_ID, $post, $update){
        if ($post->post_status == 'publish') {
            index_post($post);
        }
    }

    function index_post_after_meta_update($post, $request){
        if ($post->post_status == 'publish') {
            index_post($post);
        }
    }

    function index_post($post){
        $index = get_meilisearch_index();
        $categories = [];
        foreach ($post->post_category as $category){
            array_push($categories, get_cat_name($category));
        }
        $document = [
            [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => strip_tags($post->post_content),
                'url' => get_the_permalink($post),
                'tags' => $post->tags_input,
                'categories' => $categories,
            ],
        ];
        $index->addDocuments($document);
    }

    function delete_post_from_index($post_id){
        $index = get_meilisearch_index();
        $index->deleteDocument($post_id);
    }
    add_action('wp_insert_post', 'index_post_after_update', 1000, 3);
    add_action('rest_after_insert_post', 'index_post_after_meta_update', 1000, 2);
    add_action('wp_trash_post', 'delete_post_from_index');

    function meilisearch_wordpress_activate(){

    }

    function index_all_posts(){
        $index = get_meilisearch_index();
        $posts = get_posts(array('numberposts' => -1));
        foreach ($posts as $post){
            index_post($post);
        }
    }

    register_activation_hook( __FILE__, 'meilisearch_wordpress_activate' );

    if ( is_admin() )
        $meilisearch = new MeiliSearch();

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




    // The widget class
    class MeiliSearch_Widget extends WP_Widget {

        // Main constructor
        public function __construct() {
            parent::__construct(
                'meilisearch_widget',
                'MeiliSearch Bar',
                array(
                    'customize_selective_refresh' => true,
                )
            );
        }

        // The widget form (for the backend )
    public function form( $instance ) {

        // Set widget defaults
        $defaults = array(
            'title'    => '',
            'text'     => '',
        );

        // Parse current settings with defaults
        extract( wp_parse_args( ( array ) $instance, $defaults ) ); ?>

        <?php // Widget Title ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php _e( 'Widget Title', 'text_domain' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
        </p>

        <?php // Text Field ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'text' ) ); ?>"><?php _e( 'Text:', 'text_domain' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'text' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'text' ) ); ?>" type="text" value="<?php echo esc_attr( $text ); ?>" />
        </p>

        <?php
        }

        // Update widget settings
        public function update( $new_instance, $old_instance ) {
            $instance = $old_instance;
            $instance['title']    = isset( $new_instance['title'] ) ? wp_strip_all_tags( $new_instance['title'] ) : '';
            $instance['text']     = isset( $new_instance['text'] ) ? wp_strip_all_tags( $new_instance['text'] ) : '';
            return $instance;
        }

        // Display the widget
        public function widget( $args, $instance ) {

            extract( $args );

            // Check the widget options
            $title    = isset( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';
            $text     = isset( $instance['text'] ) ? $instance['text'] : '';

            // WordPress core before_widget hook (always include )
            echo $before_widget;

            // Display the widget
            echo '<div class="widget-text wp_widget_plugin_box">';

            // Display widget title if defined
            if ( $title ) {
                echo $before_title . $title . $after_title;
            }

            // Display text field
            if ( $text ) {
                // echo '<form><input type="text" placeholder="' . $text . '"/></form>';
                echo '<div id="searchbox" class="ais-SearchBox"></div>';
                echo '<div id="hits" class="ais-SearchBox"></div>';
                echo '<script src="'.'https://cdn.jsdelivr.net/npm/meilisearch/dist/bundles/meilisearch.browser.js'.'"></script>';
                echo '<script src="'.'https://cdn.jsdelivr.net/npm/instantsearch.js@4'.'"></script>';
                echo '<script src="https://cdn.jsdelivr.net/npm/@meilisearch/instant-meilisearch"></script>';
                echo '<script>';
                $meilisearch_options = get_option( 'meilisearch_option_name' );
                echo 'let $meilisearchUrl = "'.$meilisearch_options['meilisearch_url_0'].'";';
                echo 'let $meilisearchSearchUrl = "'.$meilisearch_options['meilisearch_search_url_4'].'";';
                echo 'let $meilisearchPublicKey = "'.$meilisearch_options['meilisearch_public_key_2'].'";';
                echo '</script>';
                echo '<script src="'.plugin_dir_url( __FILE__ ) . 'src/js/instant-meilisearch.js'.'"></script>';
            }

            echo '</div>';

            // WordPress core after_widget hook (always include )
            echo $after_widget;

        }

    }

    // Register the widget
    function my_register_custom_widget() {
        register_widget( 'MeiliSearch_Widget' );
    }
    add_action( 'widgets_init', 'my_register_custom_widget' );
?>
