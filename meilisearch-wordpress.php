<?php
    /*
    Plugin Name: MeiliSearch Wordpress
    Plugin URI: https://wordpress.meilisearch.dev
    description: The best search experience in wordpress with MeiliSearch
    Version: 0.1.0
    Author: MeiliSearch
    Author URI: https://meilisearch.com
    License: MIT
    */

    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/src/search_widget.php';
    require_once __DIR__ . '/src/admin/meilisearch_admin.php';
    require_once __DIR__ . '/src/admin/utils.php';

    function meilisearch_scripts() {
        wp_register_style( 'meilisearch_widget', plugin_dir_url( __FILE__ ).'src/css/meilisearch_widget.css' );
        wp_enqueue_style('meilisearch_widget');
    }

    add_action('wp_insert_post', 'index_post_after_update', 1000, 3);
    add_action('rest_after_insert_post', 'index_post_after_meta_update', 1000, 2);
    add_action('wp_trash_post', 'delete_post_from_index');
    add_action( 'wp_enqueue_scripts', 'meilisearch_scripts' );
    register_activation_hook( __FILE__, 'meilisearch_wordpress_activate' );

    if ( is_admin() )
        $meilisearch = new MeiliSearch();

?>
