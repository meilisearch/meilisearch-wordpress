<?php

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
                $search_elt_id = 'meilisearchbox';
                $hits_elt_id = 'meilisearchhits';

                // echo '<form><input type="text" placeholder="' . $text . '"/></form>';
                echo '<div id="'.$search_elt_id.'" class="ais-SearchBox"></div>';
                echo '<div id="'.$hits_elt_id.'" class="ais-SearchBox"></div>';
                echo '<script src="'.'https://cdn.jsdelivr.net/npm/meilisearch/dist/bundles/meilisearch.browser.js'.'"></script>';
                echo '<script src="'.'https://cdn.jsdelivr.net/npm/instantsearch.js@4'.'"></script>';
                echo '<script src="https://cdn.jsdelivr.net/npm/@meilisearch/instant-meilisearch"></script>';
                echo '<script src="'.plugin_dir_url( __FILE__ ) . 'js/instant-meilisearch.js'.'"></script>';
                echo '<script>';

                $meilisearch_options = get_option( 'meilisearch_option_name' );
                $meilisearch_url = $meilisearch_options['meilisearch_url_0'];
                $meilisearch_search_url = $meilisearch_options['meilisearch_search_url_4'];
                $meilisearch_public_key = $meilisearch_options['meilisearch_public_key_2'];
                $meilisearch_index_name = $meilisearch_options['meilisearch_index_name'];
                $search_url = $meilisearch_search_url === "" ? $meilisearch_url : $meilisearch_search_url;

                echo 'wpInstantMeilisearch("'.$search_url.'","'.$meilisearch_public_key.'","'.$meilisearch_index_name.'","#'.$search_elt_id.'","#'.$hits_elt_id.'")';
                echo '</script>';
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
