<?php
/**
 * Shared WooCommerce fixtures for integration tests.
 *
 * @package Meilisearch\WordPress\Tests
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\WooCommerce;

use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\Options;

trait CreatesProducts {

	private array $registered_attribute_taxonomies = array();

	protected function skip_without_woocommerce(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded (run with MEILISEARCH_TEST_WC=1).' );
		}
	}

	/**
	 * Enables product indexing, rebuilds the services (always: the previous test's hooks were restored away),
	 * and creates the products index (after attributes exist, so attr_* are filterable).
	 */
	protected function enable_products( array $woocommerce = array() ): void {
		\WC_Install::create_terms(); // product_visibility terms: deterministic whatever ran before.
		update_option(
			Options::WOOCOMMERCE,
			array_merge(
				array(
					'enabled'           => true,
					'attributes'        => null,
					'custom_attributes' => false,
					'variation_skus'    => true,
				),
				$woocommerce
			)
		);
		$this->reboot_plugin();
		Plugin::instance()->get( 'index_manager' )->ensure_index( 'products' );
		// Tests fill the products index through sync instead of a full reindex (which populates it).
		Plugin::instance()->get( 'options' )->set_populated( 'products', true );
	}

	/**
	 * Creates a global attribute taxonomy with terms.
	 *
	 * @return array{id: int, taxonomy: string, terms: array<string, int>}
	 */
	protected function create_attribute( string $slug, array $term_names ): array {
		$attribute_id = wc_create_attribute(
			array(
				'name' => ucfirst( $slug ),
				'slug' => $slug,
			)
		);
		$this->assertIsInt( $attribute_id, is_wp_error( $attribute_id ) ? $attribute_id->get_error_message() : '' );

		$taxonomy = wc_attribute_taxonomy_name( $slug );
		register_taxonomy(
			$taxonomy,
			array( 'product' ),
			array(
				'hierarchical' => false,
				'public'       => false,
				'query_var'    => true,
				'rewrite'      => false,
			)
		);
		$GLOBALS['wc_product_attributes'][ $taxonomy ] = wc_get_attribute_taxonomies()[ 'id:' . $attribute_id ];
		$this->registered_attribute_taxonomies[]       = $taxonomy;

		$terms = array();
		foreach ( $term_names as $name ) {
			$term = wp_insert_term( $name, $taxonomy );
			$this->assertIsArray( $term );
			$terms[ $name ] = (int) $term['term_id'];
		}
		return array(
			'id'       => (int) $attribute_id,
			'taxonomy' => $taxonomy,
			'terms'    => $terms,
		);
	}

	protected function remove_attributes(): void {
		foreach ( $this->registered_attribute_taxonomies as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
			unset( $GLOBALS['wc_product_attributes'][ $taxonomy ] );
		}
		$this->registered_attribute_taxonomies = array();
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
	}

	protected function product_attribute( array $attribute, array $term_names, bool $for_variations ): \WC_Product_Attribute {
		$object = new \WC_Product_Attribute();
		$object->set_id( $attribute['id'] );
		$object->set_name( $attribute['taxonomy'] );
		$object->set_options( array_values( array_intersect_key( $attribute['terms'], array_flip( $term_names ) ) ) );
		$object->set_visible( true );
		$object->set_variation( $for_variations );
		return $object;
	}

	protected function create_simple_product( string $name, string $price, array $props = array(), ?array $attribute = null, array $term_names = array() ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );
		foreach ( $props as $prop => $value ) {
			$product->{'set_' . $prop}( $value );
		}
		if ( null !== $attribute ) {
			$product->set_attributes( array( $this->product_attribute( $attribute, $term_names, false ) ) );
		}
		$product->save();
		return $product;
	}

	protected function create_variable_product( string $name, array $attribute, array $variations ): \WC_Product_Variable {
		$product = new \WC_Product_Variable();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_attributes( array( $this->product_attribute( $attribute, array_keys( $variations ), true ) ) );
		$product->save();

		foreach ( $variations as $term_name => $data ) {
			$term      = get_term( $attribute['terms'][ $term_name ], $attribute['taxonomy'] );
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( $attribute['taxonomy'] => $term->slug ) );
			$variation->set_regular_price( $data['price'] );
			$variation->set_sku( $data['sku'] );
			$variation->set_status( 'publish' );
			$variation->save();
		}
		\WC_Product_Variable::sync( $product->get_id() );

		return new \WC_Product_Variable( $product->get_id() );
	}

	protected function sync_products(): void {
		Plugin::instance()->get( 'collector' )->flush();
		$this->run_actions();
		$this->wait_for_tasks();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function product_document( int $id ): ?array {
		foreach ( $this->index_documents( Plugin::instance()->get( 'names' )->uid( 'products' ) ) as $document ) {
			if ( (int) $document['id'] === $id ) {
				return $document;
			}
		}
		return null;
	}
}
