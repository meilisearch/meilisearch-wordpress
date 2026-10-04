<?php
/**
 * Minimal doubles of WordPress and WooCommerce classes for unit tests.
 *
 * Each class is declared only when the real one is not loaded. The doubles hold state that tests
 * set and mirror the real public API that plugin code calls; they never query a database.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Post' ) ) {
	#[\AllowDynamicProperties]
	class WP_Post {
		public $ID                    = 0;
		public $post_author           = '0';
		public $post_date             = '0000-00-00 00:00:00';
		public $post_date_gmt         = '0000-00-00 00:00:00';
		public $post_content          = '';
		public $post_title            = '';
		public $post_excerpt          = '';
		public $post_status           = 'publish';
		public $comment_status        = 'open';
		public $ping_status           = 'open';
		public $post_password         = '';
		public $post_name             = '';
		public $to_ping               = '';
		public $pinged                = '';
		public $post_modified         = '0000-00-00 00:00:00';
		public $post_modified_gmt     = '0000-00-00 00:00:00';
		public $post_content_filtered = '';
		public $post_parent           = 0;
		public $guid                  = '';
		public $menu_order            = 0;
		public $post_type             = 'post';
		public $post_mime_type        = '';
		public $comment_count         = '0';
		public $filter                = 'raw';

		public function __construct( $fields = [] ) {
			foreach ( (array) $fields as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Tax_Query' ) ) {
	class WP_Tax_Query {
		public $queries       = [];
		public $relation      = 'AND';
		public $queried_terms = [];

		public function __construct( $tax_query ) {
			$this->relation = isset( $tax_query['relation'] ) ? $this->sanitize_relation( $tax_query['relation'] ) : 'AND';
			$this->queries  = $this->sanitize_query( $tax_query );
		}

		public function sanitize_query( $queries ) {
			$cleaned  = [];
			$defaults = [
				'taxonomy'         => '',
				'terms'            => [],
				'field'            => 'term_id',
				'operator'         => 'IN',
				'include_children' => true,
			];
			foreach ( $queries as $key => $query ) {
				if ( 'relation' === $key ) {
					$cleaned['relation'] = $this->sanitize_relation( $query );
				} elseif ( self::is_first_order_clause( $query ) ) {
					$clause          = array_merge( $defaults, $query );
					$clause['terms'] = (array) $clause['terms'];
					$cleaned[]       = $clause;
					if ( '' !== $clause['taxonomy'] && 'NOT IN' !== $clause['operator'] && ! isset( $this->queried_terms[ $clause['taxonomy'] ] ) ) {
						$this->queried_terms[ $clause['taxonomy'] ] = [
							'terms' => $clause['terms'],
							'field' => $clause['field'],
						];
					}
				} elseif ( is_array( $query ) ) {
					$sub = $this->sanitize_query( $query );
					if ( [] !== $sub ) {
						if ( ! isset( $sub['relation'] ) ) {
							$sub['relation'] = 'AND';
						}
						$cleaned[] = $sub;
					}
				}
			}
			return $cleaned;
		}

		public function sanitize_relation( $relation ) {
			return 'OR' === strtoupper( (string) $relation ) ? 'OR' : 'AND';
		}

		protected static function is_first_order_clause( $query ) {
			return is_array( $query ) && (
				[] === $query
				|| array_key_exists( 'terms', $query )
				|| array_key_exists( 'taxonomy', $query )
				|| array_key_exists( 'include_children', $query )
				|| array_key_exists( 'field', $query )
				|| array_key_exists( 'operator', $query )
			);
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	#[\AllowDynamicProperties]
	class WP_Query {
		public $query         = [];
		public $query_vars    = [];
		public $tax_query     = null;
		public $meta_query    = false;
		public $date_query    = false;
		public $posts         = null;
		public $post_count    = 0;
		public $found_posts   = 0;
		public $max_num_pages = 0;
		// Test-only flags returned by is_main_query() and is_search().
		public $main_query = false;
		public $search     = false;

		public function __construct( $query_vars = [] ) {
			$this->query      = $query_vars;
			$this->query_vars = $query_vars;
		}

		public function get( $query_var, $default_value = '' ) {
			if ( isset( $this->query_vars[ $query_var ] ) ) {
				return $this->query_vars[ $query_var ];
			}
			return $default_value;
		}

		public function set( $query_var, $value ) {
			$this->query_vars[ $query_var ] = $value;
		}

		public function is_main_query() {
			return (bool) $this->main_query;
		}

		public function is_search() {
			return (bool) $this->search;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors     = [];
		public $error_data = [];

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}
			$this->add( $code, $message, $data );
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		public function get_error_code() {
			$codes = $this->get_error_codes();
			return [] === $codes ? '' : $codes[0];
		}

		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return $this->error_data[ $code ] ?? null;
		}

		public function has_errors() {
			return [] !== $this->errors;
		}
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		protected $props = [];

		public function __construct( $props = [] ) {
			$this->set_props( $props );
		}

		public function set_props( $props ) {
			foreach ( $props as $key => $value ) {
				$this->props[ $key ] = $value;
			}
		}

		protected function prop( $key, $default_value ) {
			return array_key_exists( $key, $this->props ) ? $this->props[ $key ] : $default_value;
		}

		public function get_id() {
			return (int) $this->prop( 'id', 0 );
		}

		public function get_type() {
			return $this->prop( 'type', 'simple' );
		}

		public function is_type( $type ) {
			return in_array( $this->get_type(), (array) $type, true );
		}

		public function get_name() {
			return $this->prop( 'name', '' );
		}

		public function get_sku() {
			return $this->prop( 'sku', '' );
		}

		public function get_parent_id() {
			return (int) $this->prop( 'parent_id', 0 );
		}

		public function get_status() {
			return $this->prop( 'status', 'publish' );
		}

		public function is_featured() {
			return (bool) $this->prop( 'featured', false );
		}

		public function get_catalog_visibility() {
			return $this->prop( 'catalog_visibility', 'visible' );
		}

		public function get_price() {
			return $this->prop( 'price', '' );
		}

		public function get_regular_price() {
			return $this->prop( 'regular_price', '' );
		}

		public function get_sale_price() {
			return $this->prop( 'sale_price', '' );
		}

		public function is_on_sale() {
			return (bool) $this->prop( 'on_sale', false );
		}

		public function get_stock_status() {
			return $this->prop( 'stock_status', 'instock' );
		}

		public function is_in_stock() {
			return 'outofstock' !== $this->get_stock_status();
		}

		public function get_stock_quantity() {
			return $this->prop( 'stock_quantity', null );
		}

		public function get_average_rating() {
			return $this->prop( 'average_rating', 0.0 );
		}

		public function get_rating_count() {
			return (int) $this->prop( 'rating_count', 0 );
		}

		public function get_total_sales() {
			return (int) $this->prop( 'total_sales', 0 );
		}

		public function get_children() {
			return $this->prop( 'children', [] );
		}

		public function get_attributes() {
			return $this->prop( 'attributes', [] );
		}

		public function get_category_ids() {
			return $this->prop( 'category_ids', [] );
		}

		public function get_tag_ids() {
			return $this->prop( 'tag_ids', [] );
		}

		public function get_meta( $key = '', $single = true ) {
			$meta = $this->prop( 'meta', [] );
			if ( array_key_exists( $key, $meta ) ) {
				return $meta[ $key ];
			}
			return $single ? '' : [];
		}
	}
}

if ( ! class_exists( 'WC_Product_Simple' ) ) {
	class WC_Product_Simple extends WC_Product {
	}
}

if ( ! class_exists( 'WC_Product_Variable' ) ) {
	class WC_Product_Variable extends WC_Product {
		public function get_type() {
			return $this->prop( 'type', 'variable' );
		}

		public function get_variation_price( $min_or_max = 'min', $for_display = false ) {
			$prices = $this->prop( 'variation_prices', [] );
			return $prices[ $min_or_max ] ?? '';
		}

		public function get_variation_regular_price( $min_or_max = 'min', $for_display = false ) {
			$prices = $this->prop( 'variation_regular_prices', [] );
			return $prices[ $min_or_max ] ?? '';
		}

		public function get_variation_sale_price( $min_or_max = 'min', $for_display = false ) {
			$prices = $this->prop( 'variation_sale_prices', [] );
			return $prices[ $min_or_max ] ?? '';
		}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	// Like WooCommerce core, a variation is a simple product.
	class WC_Product_Variation extends WC_Product_Simple {
		public function get_type() {
			return $this->prop( 'type', 'variation' );
		}

		// Core falls back to the parent SKU in 'view' context; prop parent_sku stands for it.
		public function get_sku( $context = 'view' ) {
			$sku = $this->prop( 'sku', '' );
			return ( 'view' === $context && '' === $sku ) ? $this->prop( 'parent_sku', '' ) : $sku;
		}

		public function get_variation_attributes( $with_prefix = true ) {
			$attributes = (array) $this->prop( 'attributes', [] );
			if ( ! $with_prefix ) {
				return $attributes;
			}
			$prefixed = [];
			foreach ( $attributes as $slug => $value ) {
				$prefixed[ 'attribute_' . $slug ] = $value;
			}
			return $prefixed;
		}
	}
}

if ( ! class_exists( 'WC_Product_Attribute' ) ) {
	class WC_Product_Attribute {
		protected $data = [
			'id'        => 0,
			'name'      => '',
			'options'   => [],
			'position'  => 0,
			'visible'   => false,
			'variation' => false,
		];

		public function set_id( $value ) {
			$this->data['id'] = (int) $value;
		}

		public function set_name( $value ) {
			$this->data['name'] = (string) $value;
		}

		public function set_options( $value ) {
			$this->data['options'] = (array) $value;
		}

		public function set_position( $value ) {
			$this->data['position'] = (int) $value;
		}

		public function set_visible( $value ) {
			$this->data['visible'] = (bool) $value;
		}

		public function set_variation( $value ) {
			$this->data['variation'] = (bool) $value;
		}

		public function get_id() {
			return $this->data['id'];
		}

		public function get_name() {
			return $this->data['name'];
		}

		public function get_options() {
			return $this->data['options'];
		}

		public function get_position() {
			return $this->data['position'];
		}

		public function get_visible() {
			return $this->data['visible'];
		}

		public function get_variation() {
			return $this->data['variation'];
		}

		public function is_taxonomy() {
			return 0 < $this->get_id();
		}
	}
}

if ( ! class_exists( 'WC_Product_Grouped' ) ) {
	class WC_Product_Grouped extends WC_Product {
		public function get_type() {
			return $this->prop( 'type', 'grouped' );
		}
	}
}

if ( ! class_exists( 'WC_Product_External' ) ) {
	class WC_Product_External extends WC_Product {
		public function get_type() {
			return $this->prop( 'type', 'external' );
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id          = 0;
		public $name             = '';
		public $slug             = '';
		public $term_group       = 0;
		public $term_taxonomy_id = 0;
		public $taxonomy         = '';
		public $description      = '';
		public $parent           = 0;
		public $count            = 0;
		public $filter           = 'raw';

		public function __construct( $term = [] ) {
			foreach ( (array) $term as $key => $value ) {
				if ( property_exists( $this, (string) $key ) ) {
					$this->{$key} = $value;
				}
			}
		}
	}
}
