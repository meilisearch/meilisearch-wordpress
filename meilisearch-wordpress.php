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



    if ( is_admin() )
        $meilisearch = new MeiliSearch();

?>
