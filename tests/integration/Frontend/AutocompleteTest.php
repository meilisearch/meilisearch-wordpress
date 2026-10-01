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

	public function test_not_enqueued_when_disabled(): void {
		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_script_is( Autocomplete::HANDLE, 'enqueued' ) );
	}

	public function test_products_index_and_store_currency_when_woocommerce_is_active(): void {
		$this->skip_without_woocommerce();
		$this->enable_products();

		$config = Plugin::instance()->get( 'autocomplete' )->config();

		$this->assertSame( Plugin::instance()->get( 'names' )->uid( 'products' ), $config['indexes']['products'] );
		$this->assertSame( get_woocommerce_currency(), $config['currency'] );
	}
}
