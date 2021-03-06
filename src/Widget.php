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

use WP_Widget;

use function plugin_dir_url;
use function wp_enqueue_style;
use function wp_register_style;

class Widget extends WP_Widget
{
    /** @var \MeiliSearch\WordPress\ArrayOption */
    protected $option;

    public function __construct(ArrayOption $option)
    {
        $this->option = $option;

        parent::__construct(
            'meilisearch_widget',
            'MeiliSearch Bar',
            ['customize_selective_refresh' => true]
        );
    }

    /**
     * The widget form for the backend.
     */
    public function form(array $instance): void
    {
        // Set widget defaults
        $defaults = [
            'title' => '',
            'text' => '',
        ];

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

    /**
     * Update widget settings.
     */
    public function update(array $new_instance, array $old_instance): array
    {
        $instance = $old_instance;
        $instance['title'] = isset( $new_instance['title'] ) ? wp_strip_all_tags( $new_instance['title'] ) : '';
        $instance['text'] = isset( $new_instance['text'] ) ? wp_strip_all_tags( $new_instance['text'] ) : '';

        return $instance;
    }

    /**
     * @hook wp_enqueue_scripts
     */
    public function enqueueStyle(): void
        wp_register_style(
            'meilisearch_widget',
            plugin_dir_url(Config::get('filePath')) . 'assets/css/meilisearch_widget.css'
        );
        wp_enqueue_style('meilisearch_widget');
    }

    /**
     * Display the widget.
     */
    public function widget(array $args, array $instance): void
    {
        // FIXME Use WP functions.
        extract( $args );

        // Check the widget options
        $title = isset( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';
        $text = isset( $instance['text'] ) ? $instance['text'] : '';

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
            $search_elt_id = 'meilisearchbox';
            $hits_elt_id = 'meilisearchhits';

            $meilisearch_url = $this->option->get('url_0');
            $meilisearch_search_url = $this->option->get('search_url_4');
            $meilisearch_public_key = $this->option->get('public_key_2');
            $meilisearch_index_name = $this->option->get('index_name');

            $search_url = $meilisearch_search_url === '' ? $meilisearch_url : $meilisearch_search_url;

            // echo '<form><input type="text" placeholder="' . $text . '"/></form>';
            echo '<div id="'.$search_elt_id.'" class="ais-SearchBox"></div>';
            echo '<div id="'.$hits_elt_id.'" class="ais-SearchBox"></div>';
            echo '<script src="'.'https://cdn.jsdelivr.net/npm/meilisearch/dist/bundles/meilisearch.browser.js'.'"></script>';
            echo '<script src="'.'https://cdn.jsdelivr.net/npm/instantsearch.js@4'.'"></script>';
            echo '<script src="https://cdn.jsdelivr.net/npm/@meilisearch/instant-meilisearch"></script>';
            echo '<script src="' . esc_url(plugin_dir_url(Config::get('filePath'))) . 'js/instant-meilisearch.js'.'"></script>';
            echo '<script>';
            echo 'wpInstantMeilisearch("'.$search_url.'","'.$meilisearch_public_key.'","'.$meilisearch_index_name.'","#'.$search_elt_id.'","#'.$hits_elt_id.'")';
            echo '</script>';
        }

        echo '</div>';

        // WordPress core after_widget hook (always include)
        echo $after_widget;
    }
}
