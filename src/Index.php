<?php

/**
 * Index.php
 *
 * @package meilisearch/meilisearch-wordpress
 * @author MeiliSearch <bonjour@meilisearch.com>
 * @copyright 2021 MeiliSearch
 * @license https://github.com/meilisearch/MeiliSearch/blob/master/LICENSE MIT
 * @link https://wordpress.meilisearch.dev
 */

declare(strict_types=1);

namespace MeiliSearch\WordPress;

use MeiliSearch\Client;
use MeiliSearch\Endpoints\Indexes;
use MeiliSearch\WordPress\Tools\HookAnnotation;
use WP_Post;
use WP_REST_Request;

use function get_cat_name;
use function get_posts;
use function get_the_permalink;
use function get_the_post_thumbnail_url;

/**
 * Handle MeiliSearch updates.
 */
final class Index
{
    use HookAnnotation;

    /** @var \MeiliSearch\Endpoints\Indexes */
    protected $index;

    /** @var \MeiliSearch\WordPress\ArrayOption */
    protected $option;

    public function __construct(ArrayOption $option)
    {
        $this->option = $option;
        $this->hookMethods();
    }

    /**
     * @hook wp_insert_post 1000
     */
    public function onInsertPost(int $postId, WP_Post $post, bool $update): void
    {
        if ($post->post_status !== 'publish') {
            return;
        }

        $this->indexPost($post);
    }

    /**
     * @hook rest_after_insert_post 1000
     */
    public function onRestInsertPost(WP_Post $post, WP_REST_Request $request): void
    {
        if ($post->post_status !== 'publish') {
            return;
        }

        $this->indexPost($post);
    }

    /**
     * @hook wp_trash_post 10
     */
    public function deletePostFromIndex(int $postId): void
    {
        $this->getIndex()->deleteDocument($postId);
    }

    public function indexAllPostsAsync(): array
    {
        $documents = [];
        foreach (get_posts(['numberposts' => -1]) as $post) {
            // FIXME index_post($post);

            $documents[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                // FIXME post_content will not be filtered this (raw) way.
                'content' => strip_tags($post->post_content),
                'img' => get_the_post_thumbnail_url($post, [100, 100]),
                'url' => get_the_permalink($post),
                'tags' => $post->tags_input,
                'categories' => array_map(
                    static function (int $category): string {
                        return get_cat_name($category);
                    },
                    $post->post_category
                ),
            ];
        }

        return $this->getIndex()->addDocuments($documents);
    }

    public function indexAllPostsSync(): void
    {
        $update = $this->indexAllPostsAsync();
        $this->getIndex()->waitForPendingUpdate($update['updateId']);
    }

    public function deleteIndex(): void
    {
        $this->getIndex()->delete();
    }

    public function getCount(): int
    {
        $stats = $this->getIndex()->stats();

        return $stats['numberOfDocuments'];
    }

    protected function indexPost(WP_Post $post): void
    {
        $this->getIndex()->addDocuments([[
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => strip_tags($post->post_content),
            'img' => get_the_post_thumbnail_url($post, [100, 100]),
            'url' => get_the_permalink($post),
            'tags' => $post->tags_input,
            'categories' => array_map(
                static function (int $category): string {
                    return get_cat_name($category);
                },
                $post->post_category
            ),
        ]]);
    }

    protected function getIndex(): Indexes
    {
        // Cache index instance.
        if (isset($this->index)) {
            return $this->index;
        }

        $client = new Client($this->option->get('url_0'), $this->option->get('private_key_1'));
        $this->index = $client->getOrCreateIndex($this->option->get('index_name'));

        return $this->index;
    }
}
