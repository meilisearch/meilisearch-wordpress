<?php
/**
 * What the browser search key can read from indexes the plugin creates.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\Frontend;

use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Search\SearchRequest;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Integration\TestCase;
use Meilisearch\WordPress\Tests\Integration\WooCommerce\CreatesProducts;

require_once dirname( __DIR__ ) . '/WooCommerce/CreatesProducts.php';

final class BrowserKeyTest extends TestCase {

	use CreatesProducts;

	/**
	 * Fields assets/js/autocomplete.js retrieves (CONTENT_FIELDS, PRODUCT_FIELDS).
	 */
	private const AUTOCOMPLETE_FIELDS = array( 'id', 'title', 'permalink', 'post_type', 'thumbnail_url' );

	/**
	 * Creates the plugin search key and returns it as the page receives it.
	 */
	private function browser_key(): string {
		$this->assertSame( 'created', Plugin::instance()->get( 'index_manager' )->rotate_search_key() );
		$key = Plugin::instance()->get( 'autocomplete' )->config()['key'];
		$this->assertNotSame( '', $key );
		return $key;
	}

	/**
	 * POST /indexes/{uid}/search with the browser key.
	 *
	 * @param string               $key  Browser search key.
	 * @param string               $uid  Index uid.
	 * @param array<string, mixed> $body Search body.
	 * @return array<string, mixed>
	 */
	private function search_as_visitor( string $key, string $uid, array $body ): array {
		$response = wp_remote_post(
			self::test_host() . '/indexes/' . rawurlencode( $uid ) . '/search',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ) );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->assertIsArray( $decoded );
		return $decoded;
	}

	public function test_browser_key_cannot_read_private_content_fields_of_a_new_index(): void {
		update_option(
			Options::CONTENT,
			array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array( 'post' => array( 'category' ) ),
				'meta_keys'  => array( 'post' => array( 'internal_code' ) ),
			)
		);
		$category = self::factory()->category->create( array( 'name' => 'Quasar topics' ) );
		$post_id  = self::factory()->post->create(
			array(
				'post_title'   => 'Quasar observation log',
				'post_content' => 'Notes about quasar jets and radio lobes.',
			)
		);
		wp_set_post_categories( $post_id, array( $category ) );
		update_post_meta( $post_id, 'internal_code', 'ACME-SECRET-77' );

		$plugin = Plugin::instance();
		$uid    = $plugin->get( 'names' )->uid( 'content' );
		$plugin->get( 'index_manager' )->ensure_index( 'content' );
		$plugin->get( 'reindexer' )->run_sync( 'content', 200, static function (): void {} );
		$this->wait_for_tasks();
		$key = $this->browser_key();

		$hit = $this->search_as_visitor(
			$key,
			$uid,
			array(
				'q'                    => 'quasar',
				'attributesToRetrieve' => array( '*' ),
			)
		)['hits'][0] ?? array();

		$this->assertSame( $post_id, (int) ( $hit['id'] ?? 0 ) );
		$this->assertArrayNotHasKey( 'author_id', $hit, 'author_id is readable with the browser key.' );
		$this->assertArrayNotHasKey( 'meta_internal_code', $hit, 'Custom field values are readable with the browser key.' );
		foreach ( array( 'title', 'content', 'excerpt', 'permalink', 'post_type', 'author_name', 'tax_category' ) as $field ) {
			$this->assertArrayHasKey( $field, $hit, "Public field {$field} is displayed." );
		}

		// The query autocomplete.js sends still gets its fields and the highlighted title.
		$suggestion = $this->search_as_visitor(
			$key,
			$uid,
			array(
				'q'                     => 'quasar',
				'attributesToRetrieve'  => self::AUTOCOMPLETE_FIELDS,
				'attributesToHighlight' => array( 'title' ),
			)
		)['hits'][0] ?? array();
		foreach ( self::AUTOCOMPLETE_FIELDS as $field ) {
			$this->assertArrayHasKey( $field, $suggestion );
		}
		$this->assertStringContainsString( '<em>Quasar</em>', (string) ( $suggestion['_formatted']['title'] ?? '' ) );

		// Server-side search (Searcher) still reads the IDs and the highlighted crop.
		$result = $plugin->get( 'searcher' )->execute( new SearchRequest( array( 'content' ), 'quasar', 1, 10, array(), array(), null, true ) );
		$this->assertSame( array( $post_id ), $result->ids );
		$this->assertStringContainsString( '<mark>quasar</mark>', (string) ( $result->formatted[ $post_id ]['content'] ?? '' ) );

		// The reindex sweep (fetch_documents with fields [id]) still finds and deletes orphans.
		$this->meili(
			'POST',
			'/indexes/' . $uid . '/documents',
			array(
				array(
					'id'    => 999999,
					'title' => 'Orphan',
				),
			)
		);
		$this->wait_for_tasks();
		$plugin->get( 'reindexer' )->run_sync( 'content', 200, static function (): void {} );
		$this->wait_for_tasks();
		$ids = array_map( static fn ( array $document ): int => (int) $document['id'], $this->index_documents( $uid ) );
		$this->assertSame( array( $post_id ), $ids );
	}

	public function test_browser_key_cannot_read_total_sales_or_stock_of_a_new_products_index(): void {
		$this->skip_without_woocommerce();
		$this->enable_products();
		update_option( 'woocommerce_stock_format', 'no_amount' );
		$product = $this->create_simple_product(
			'Meili Secret Seller',
			'30',
			array(
				'total_sales'    => 4321,
				'manage_stock'   => true,
				'stock_quantity' => 17,
				'sku'            => 'MSS-1',
			)
		);
		$this->sync_products();

		$key = $this->browser_key();
		$uid = Plugin::instance()->get( 'names' )->uid( 'products' );
		$hit = $this->search_as_visitor(
			$key,
			$uid,
			array(
				'q'                    => '',
				'limit'                => 1000,
				'sort'                 => array( 'total_sales:desc' ),
				'attributesToRetrieve' => array( '*' ),
			)
		)['hits'][0] ?? array();

		$this->assertSame( $product->get_id(), (int) ( $hit['id'] ?? 0 ) );
		$this->assertArrayNotHasKey( 'total_sales', $hit, 'total_sales is readable with the browser key.' );
		$this->assertArrayNotHasKey( 'stock_quantity', $hit, 'stock_quantity is readable with the browser key.' );
		$this->assertArrayNotHasKey( 'author_id', $hit, 'author_id is readable with the browser key.' );
		foreach ( array_merge( self::AUTOCOMPLETE_FIELDS, array( 'price', 'regular_price', 'sku', 'in_stock', 'stock_status', 'tax_product_cat' ) ) as $field ) {
			$this->assertArrayHasKey( $field, $hit, "Public field {$field} is displayed." );
		}
		$this->assertSame( 30.0, (float) $hit['price'] );

		// Sorting on a hidden field keeps working (the products sort by popularity uses it).
		$this->assertSame( 4321, $this->product_document( $product->get_id() )['total_sales'] ?? null, 'The field is still indexed.' );
	}

	public function test_subtitle_field_and_filter_extend_the_displayed_attributes(): void {
		update_option(
			Options::CONTENT,
			array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array( 'post' => array() ),
				'meta_keys'  => array( 'post' => array( 'subtitle', 'isbn' ) ),
			)
		);
		$subtitle = static fn ( $field, $logical ) => 'content' === $logical ? 'meta_subtitle' : $field;
		$extra    = static fn ( array $fields ): array => array_merge( $fields, array( 'meta_isbn' ) );
		add_filter( 'meilisearch_autocomplete_subtitle_field', $subtitle, 10, 2 );
		add_filter( 'meilisearch_displayed_attributes', $extra );

		$plugin = Plugin::instance();
		$plugin->get( 'index_manager' )->ensure_index( 'content' );
		$displayed = $this->meili( 'GET', '/indexes/' . $plugin->get( 'names' )->uid( 'content' ) . '/settings' )['displayedAttributes'] ?? array();

		$this->assertSame( 'id', $displayed[0] ?? null );
		$this->assertContains( 'meta_subtitle', $displayed );
		$this->assertContains( 'meta_isbn', $displayed );
		$this->assertNotContains( 'author_id', $displayed );
	}

	/**
	 * The plugin never rewrites displayedAttributes of an index that already exists with ["*"].
	 */
	public function test_existing_index_displaying_every_attribute_is_left_alone(): void {
		$plugin = Plugin::instance();
		$uid    = $plugin->get( 'names' )->uid( 'content' );
		$this->meili(
			'POST',
			'/indexes',
			array(
				'uid'        => $uid,
				'primaryKey' => 'id',
			)
		);
		$this->wait_for_tasks();

		$plugin->get( 'index_manager' )->ensure_index( 'content' );
		$this->wait_for_tasks();

		$this->assertSame( array( '*' ), $this->meili( 'GET', '/indexes/' . $uid . '/settings' )['displayedAttributes'] ?? null );
	}
}
