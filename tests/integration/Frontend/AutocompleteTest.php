<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\Frontend;

use Meilisearch\WordPress\Frontend\Autocomplete;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Integration\TestCase;
use Meilisearch\WordPress\Tests\Integration\WooCommerce\CreatesProducts;

final class AutocompleteTest extends TestCase {

	use CreatesProducts;

	public function tear_down(): void {
		wp_dequeue_script( Autocomplete::HANDLE );
		wp_deregister_script( Autocomplete::HANDLE );
		wp_dequeue_style( Autocomplete::HANDLE );
		parent::tear_down();
	}

	private function enable_autocomplete(): void {
		update_option(
			Options::SEARCH,
			array(
				'replace'        => false,
				'highlight'      => false,
				'embedder'       => '',
				'semantic_ratio' => 0.5,
				'autocomplete'   => true,
			)
		);
		$options = Plugin::instance()->get( 'options' );
		$options->save_search_key( 'integration-search-key', 'integration-search-key-uid' );
		// As IndexManager::rotate_search_key() does for a plugin-created key.
		$options->mark_search_key_verified();
	}

	public function test_script_and_style_are_enqueued_on_the_front_end_with_config(): void {
		$this->enable_autocomplete();

		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( Autocomplete::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Autocomplete::HANDLE, 'enqueued' ) );
		$data = (string) wp_scripts()->get_data( Autocomplete::HANDLE, 'data' );
		$this->assertStringContainsString( 'var meilisearchAutocomplete = ', $data );
		$this->assertStringContainsString( '"key":"integration-search-key"', $data );
		$this->assertStringNotContainsString( Plugin::instance()->get( 'options' )->admin_key(), $data );
		$this->assertSame( 'defer', wp_scripts()->get_data( Autocomplete::HANDLE, 'strategy' ) );
	}

	/**
	 * The readiness check runs on every front-end page view and reads non-autoloaded options (connection,
	 * admin key, state): without a persistent object cache they are loaded together, in one query.
	 */
	public function test_enqueue_reads_the_plugin_options_in_a_single_query(): void {
		$this->enable_autocomplete();
		$options = Plugin::instance()->get( 'options' );
		$options->set_state( 'fingerprint', $options->connection_fingerprint( Plugin::instance()->get( 'names' )->prefix() ) );

		// A fresh request without a persistent object cache.
		wp_cache_flush();
		wp_load_alloptions();

		$queries = array();
		$log     = static function ( $query ) use ( &$queries ) {
			if ( str_contains( (string) $query, "'meilisearch_" ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $log );
		try {
			do_action( 'wp_enqueue_scripts' );
		} finally {
			remove_filter( 'query', $log );
		}

		$this->assertTrue( wp_script_is( Autocomplete::HANDLE, 'enqueued' ) );
		$this->assertCount( 1, $queries, implode( "\n", $queries ) );
	}

	public function test_not_enqueued_when_disabled(): void {
		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_script_is( Autocomplete::HANDLE, 'enqueued' ) );
	}

	/**
	 * A type that is indexed but registered with exclude_from_search is absent from the results page;
	 * the filter shipped to the browser keeps it out of the suggestions (and of their total) too.
	 */
	public function test_content_filter_leaves_out_indexed_types_excluded_from_the_site_search(): void {
		register_post_type(
			'meili_hidden',
			array(
				'public'              => true,
				'exclude_from_search' => true,
			)
		);
		try {
			update_option( Options::CONTENT, array_merge( (array) get_option( Options::CONTENT ), array( 'post_types' => array( 'post', 'page', 'meili_hidden' ) ) ) );
			Plugin::instance()->get( 'index_manager' )->ensure_index( 'content' );
			$post_id   = self::factory()->post->create( array( 'post_title' => 'Zyxquark field notes' ) );
			$hidden_id = self::factory()->post->create(
				array(
					'post_type'  => 'meili_hidden',
					'post_title' => 'Zyxquark internal record',
				)
			);
			Plugin::instance()->get( 'collector' )->flush();
			$this->run_actions();
			$this->wait_for_tasks();

			$config = Plugin::instance()->get( 'autocomplete' )->config();
			$this->assertSame( 'post_type IN ["post", "page"]', $config['filters']['content'] );

			// The query assets/js/autocomplete.js sends, with and without the filter.
			$query    = array(
				'indexUid' => $config['indexes']['content'],
				'q'        => 'zyxquark',
			);
			$filtered = $this->meili( 'POST', '/multi-search', array( 'queries' => array( $query + array( 'filter' => $config['filters']['content'] ) ) ) )['results'][0];
			$open     = $this->meili( 'POST', '/multi-search', array( 'queries' => array( $query ) ) )['results'][0];

			$this->assertEqualsCanonicalizing( array( $post_id, $hidden_id ), array_map( 'intval', array_column( $open['hits'], 'id' ) ), 'Both documents are indexed.' );
			$this->assertSame( array( $post_id ), array_map( 'intval', array_column( $filtered['hits'], 'id' ) ) );
			$this->assertSame( 1, $filtered['estimatedTotalHits'] );
		} finally {
			unregister_post_type( 'meili_hidden' );
		}
	}

	public function test_products_index_and_store_currency_when_woocommerce_is_active(): void {
		$this->skip_without_woocommerce();
		$this->enable_products();

		$config = Plugin::instance()->get( 'autocomplete' )->config();

		$this->assertSame( Plugin::instance()->get( 'names' )->uid( 'products' ), $config['indexes']['products'] );
		$this->assertSame( get_woocommerce_currency(), $config['currency'] );
	}

	public function test_host_warning_follows_the_filtered_host_and_the_site_url(): void {
		$this->enable_autocomplete();
		$options = Plugin::instance()->get( 'options' );
		$site    = static fn (): string => 'https://shop.example.com';
		add_filter( 'pre_option_home', $site );
		try {
			$public = static fn (): string => 'https://search.example.com';
			add_filter( 'meilisearch_autocomplete_host', $public );
			$this->assertNull( Autocomplete::host_warning( $options ), 'A public https URL works for visitors.' );
			remove_filter( 'meilisearch_autocomplete_host', $public );

			$loopback = static fn (): string => 'http://127.0.0.1:7700';
			add_filter( 'meilisearch_autocomplete_host', $loopback );
			$this->assertStringContainsString( 'http://127.0.0.1:7700', (string) Autocomplete::host_warning( $options ) );
			remove_filter( 'meilisearch_autocomplete_host', $loopback );

			$http = static fn (): string => 'http://search.example.com';
			add_filter( 'meilisearch_autocomplete_host', $http );
			$this->assertStringContainsString( 'mixed content', (string) Autocomplete::host_warning( $options ) );
			remove_filter( 'meilisearch_autocomplete_host', $http );
		} finally {
			remove_filter( 'pre_option_home', $site );
		}
	}
}
