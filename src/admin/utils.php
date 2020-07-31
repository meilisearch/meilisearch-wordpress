<?php

use MeiliSearch\Client;

function get_meilisearch_index(){
    $meilisearch_options = get_option( 'meilisearch_option_name' );
    $client = new Client($meilisearch_options['meilisearch_url_0'], $meilisearch_options['meilisearch_private_key_1']);
    $index = $client->getOrCreateIndex($meilisearch_options['meilisearch_index_name']);
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
            'img' => get_the_post_thumbnail_url($post, array(100,100)),
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


function meilisearch_wordpress_activate(){

}

function index_all_posts($sync = false){
    $index = get_meilisearch_index();
    $documents = [];
    $posts = get_posts(array('numberposts' => -1));
    foreach ($posts as $post){
        // index_post($post);
        $categories = [];
        foreach ($post->post_category as $category){
            array_push($categories, get_cat_name($category));
        }
        $document = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => strip_tags($post->post_content),
                'img' => get_the_post_thumbnail_url($post, array(100,100)),
                'url' => get_the_permalink($post),
                'tags' => $post->tags_input,
                'categories' => $categories,
        ];
        array_push($documents, $document);
    }
    $update = $index->addDocuments($documents);
    if ($sync) {
        $index->waitForPendingUpdate($update['updateId']);
    }
}

function delete_index(){
    $index = get_meilisearch_index();
    $index->delete();
}

function get_all_indexed(){
    $index = get_meilisearch_index();
    $indexed = $index->getDocuments();
    return $indexed;
}

?>
