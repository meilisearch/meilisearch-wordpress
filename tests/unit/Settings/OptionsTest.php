<?php
/**
 * Tests for Options.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Settings;

use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class OptionsTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->stub_options();
	}

	/**
	 * Builds Options with a fake constant table.
	 *
	 * @param array<string, mixed> $constants Constant name => value.
	 */
	private function options( array $constants = [] ): Options {
		return new Options(
			static function ( string $name ) use ( $constants ) {
				return $constants[ $name ] ?? null;
			}
		);
	}

	/**
	 * @dataProvider provide_hosts
	 */
	public function test_host_normalization( string $raw, string $expected ): void {
		$this->assertSame( $expected, Options::normalize_host( $raw ) );
	}

	public static function provide_hosts(): array {
		return [
			'trailing slash'            => [ 'https://example.com/', 'https://example.com' ],
			'no scheme, public host'    => [ 'example.com', 'https://example.com' ],
			'reverse-proxy path prefix' => [ 'https://example.com/meili/', 'https://example.com/meili' ],
			'uppercase scheme and host' => [ 'HTTPS://Example.COM', 'https://example.com' ],
			'path case is kept'         => [ 'https://Example.com/Meili/', 'https://example.com/Meili' ],
			'localhost with port'       => [ 'localhost:7700', 'http://localhost:7700' ],
			'loopback IPv4 with port'   => [ '127.0.0.1:7700', 'http://127.0.0.1:7700' ],
			'loopback IPv6 with port'   => [ '[::1]:7700', 'http://[::1]:7700' ],
			'private IPv4'              => [ '192.168.1.10:7700', 'http://192.168.1.10:7700' ],
			'.test domain'              => [ 'meili.test', 'http://meili.test' ],
			'.localhost domain'         => [ 'search.localhost:7700', 'http://search.localhost:7700' ],
			'.local domain'             => [ 'nas.local', 'http://nas.local' ],
			'explicit http is kept'     => [ 'http://example.com', 'http://example.com' ],
			'surrounding whitespace'    => [ "  https://ms-123.meilisearch.io \n", 'https://ms-123.meilisearch.io' ],
			'query and fragment'        => [ 'https://example.com:8443/meili/?x=1#top', 'https://example.com:8443/meili' ],
			'credentials are dropped'   => [ 'https://user:pass@example.com', 'https://example.com' ],
			'empty'                     => [ '', '' ],
			'whitespace only'           => [ '   ', '' ],
			'scheme only'               => [ 'http://', '' ],
			'unsupported scheme'        => [ 'ftp://example.com', '' ],
			'space in host'             => [ 'exa mple.com', '' ],
			'invalid port'              => [ 'https://example.com:99999', '' ],
		];
	}

	public function test_defaults_cover_every_option(): void {
		$defaults = Options::defaults();

		$this->assertSame(
			[ Options::CONNECTION, Options::ADMIN_KEY, Options::CONTENT, Options::WOOCOMMERCE, Options::SEARCH, Options::STATE, Options::LOG ],
			array_keys( $defaults )
		);
		$this->assertSame( '', $defaults[ Options::ADMIN_KEY ] );
		$this->assertSame( [ 'post', 'page' ], $defaults[ Options::CONTENT ]['post_types'] );
		$this->assertNull( $defaults[ Options::WOOCOMMERCE ]['attributes'] );
		$this->assertFalse( $defaults[ Options::SEARCH ]['replace'] );
		$this->assertSame( [], $defaults[ Options::LOG ] );
	}

	public function test_seed_defaults_adds_missing_options_with_the_right_autoload(): void {
		$this->stub_options( [ Options::CONTENT => [ 'post_types' => [ 'post' ] ] ] );

		$this->options()->seed_defaults();

		$this->assertSame( [ 'post_types' => [ 'post' ] ], $this->option_store[ Options::CONTENT ] );
		$this->assertSame( Options::defaults()[ Options::CONNECTION ], $this->option_store[ Options::CONNECTION ] );
		$this->assertSame(
			[
				Options::CONNECTION  => false,
				Options::ADMIN_KEY   => false,
				Options::WOOCOMMERCE => true,
				Options::SEARCH      => true,
				Options::STATE       => false,
				Options::LOG         => false,
			],
			$this->option_autoload
		);
	}

	public function test_host_and_admin_key_come_from_options(): void {
		$this->stub_options(
			[
				Options::CONNECTION => [ 'host' => 'Example.com/meili/' ],
				Options::ADMIN_KEY  => '  admin-key  ',
			]
		);
		$options = $this->options();

		$this->assertSame( 'https://example.com/meili', $options->host() );
		$this->assertSame( 'admin-key', $options->admin_key() );
		$this->assertFalse( $options->host_is_constant() );
		$this->assertFalse( $options->admin_key_is_constant() );
		$this->assertTrue( $options->is_configured() );
	}

	public function test_constants_override_options(): void {
		$this->stub_options(
			[
				Options::CONNECTION => [ 'host' => 'https://option.example.com' ],
				Options::ADMIN_KEY  => 'option-key',
			]
		);
		$options = $this->options(
			[
				'MEILISEARCH_HOST'      => 'HTTP://Const.test:7700/',
				'MEILISEARCH_ADMIN_KEY' => 'const-key',
			]
		);

		$this->assertSame( 'http://const.test:7700', $options->host() );
		$this->assertSame( 'const-key', $options->admin_key() );
		$this->assertTrue( $options->host_is_constant() );
		$this->assertTrue( $options->admin_key_is_constant() );
	}

	public function test_empty_or_non_string_constants_are_ignored(): void {
		$this->stub_options(
			[
				Options::CONNECTION => [ 'host' => 'https://option.example.com' ],
				Options::ADMIN_KEY  => 'option-key',
			]
		);
		$options = $this->options(
			[
				'MEILISEARCH_HOST'      => '',
				'MEILISEARCH_ADMIN_KEY' => 42,
			]
		);

		$this->assertSame( 'https://option.example.com', $options->host() );
		$this->assertSame( 'option-key', $options->admin_key() );
		$this->assertFalse( $options->host_is_constant() );
		$this->assertFalse( $options->admin_key_is_constant() );
	}

	public function test_is_configured_needs_a_valid_host_and_a_key(): void {
		$this->stub_options( [ Options::CONNECTION => [ 'host' => 'https://example.com' ] ] );
		$this->assertFalse( $this->options()->is_configured() );

		$this->stub_options(
			[
				Options::CONNECTION => [ 'host' => 'exa mple.com' ],
				Options::ADMIN_KEY  => 'key',
			]
		);
		$this->assertFalse( $this->options()->is_configured() );
		$this->assertSame( '', $this->options()->host() );
	}

	public function test_prefix_override_is_sanitized(): void {
		$this->assertSame( '', $this->options()->prefix_override() );

		$this->stub_options( [ Options::CONNECTION => [ 'prefix' => ' My-Site_01! ' ] ] );

		$this->assertSame( 'mysite_01', $this->options()->prefix_override() );
	}

	public function test_search_key_accessors_and_save(): void {
		$this->stub_options(
			[
				Options::CONNECTION => [
					'host'                => 'https://example.com',
					'delete_on_uninstall' => 1,
				],
			]
		);
		$options = $this->options();

		$options->save_search_key( 'search-key', 'key-uid' );

		$this->assertSame( 'search-key', $options->search_key() );
		$this->assertSame( 'key-uid', $options->search_key_uid() );
		$this->assertSame( 'https://example.com', $this->option_store[ Options::CONNECTION ]['host'] );
		$this->assertFalse( $this->option_autoload[ Options::CONNECTION ] );
		$this->assertTrue( $options->delete_on_uninstall() );
	}

	public function test_content_accessors(): void {
		$this->stub_options(
			[
				Options::CONTENT => [
					'post_types' => [ 'post', 'product', 'book', '', 42, 'post', 'product_variation' ],
					'taxonomies' => [
						'post' => [ 'category', 'post_tag' ],
						'book' => [ 'genre', 'category' ],
					],
					'meta_keys'  => [
						'book' => [ 'isbn', 'price' ],
						'post' => 'not-a-list',
					],
				],
			]
		);
		$options = $this->options();

		$this->assertSame( [ 'post', 'book' ], $options->enabled_post_types() );
		$this->assertSame( [ 'genre', 'category' ], $options->taxonomies_for( 'book' ) );
		$this->assertSame( [], $options->taxonomies_for( 'page' ) );
		$this->assertSame( [], $options->meta_keys_for( 'post' ) );
		$this->assertSame( [ 'isbn', 'price' ], $options->meta_keys_for( 'book' ) );
		$this->assertSame( [ 'category', 'post_tag', 'genre' ], $options->all_taxonomies() );
		$this->assertSame( [ 'isbn', 'price' ], $options->all_meta_keys() );
	}

	public function test_content_defaults_when_option_is_missing(): void {
		$options = $this->options();

		$this->assertSame( [ 'post', 'page' ], $options->enabled_post_types() );
		$this->assertSame( [ 'category', 'post_tag' ], $options->taxonomies_for( 'post' ) );
		$this->assertSame( [], $options->all_meta_keys() );
	}

	public function test_woocommerce_settings_are_typed(): void {
		$this->stub_options(
			[
				Options::WOOCOMMERCE => [
					'enabled'           => '1',
					'attributes'        => [ 'pa_color', 7, 'pa_size' ],
					'custom_attributes' => 1,
				],
			]
		);

		$this->assertSame(
			[
				'enabled'           => true,
				'attributes'        => [ 'pa_color', 'pa_size' ],
				'custom_attributes' => true,
				'variation_skus'    => true,
			],
			$this->options()->woocommerce()
		);
	}

	public function test_woocommerce_null_attributes_mean_all(): void {
		$this->assertNull( $this->options()->woocommerce()['attributes'] );
	}

	public function test_products_are_disabled_without_woocommerce(): void {
		$this->assertFalse( class_exists( 'WooCommerce' ) );
		$this->assertFalse( $this->options()->products_enabled() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_products_follow_the_setting_when_woocommerce_is_active(): void {
		require_once dirname( __DIR__ ) . '/Support/woocommerce-active.php';

		$this->assertTrue( $this->options()->products_enabled() );

		$this->stub_options( [ Options::WOOCOMMERCE => [ 'enabled' => false ] ] );
		$this->assertFalse( $this->options()->products_enabled() );
	}

	public function test_search_settings_are_typed_and_clamped(): void {
		$this->stub_options(
			[
				Options::SEARCH => [
					'replace'        => '1',
					'embedder'       => ' default ',
					'semantic_ratio' => '1.7',
				],
			]
		);

		$this->assertSame(
			[
				'replace'        => true,
				'highlight'      => false,
				'embedder'       => 'default',
				'semantic_ratio' => 1.0,
				'autocomplete'   => false,
			],
			$this->options()->search()
		);

		$this->stub_options( [ Options::SEARCH => [ 'semantic_ratio' => 'abc' ] ] );
		$this->assertSame( 0.5, $this->options()->search()['semantic_ratio'] );
	}

	public function test_state_round_trip(): void {
		$options = $this->options();

		$this->assertSame( '', $options->state( 'fingerprint' ) );
		$this->assertSame( 'fallback', $options->state( 'missing', 'fallback' ) );

		$options->set_state( 'fingerprint', 'abc' );

		$this->assertSame( 'abc', $options->state( 'fingerprint' ) );
		$this->assertFalse( $this->option_autoload[ Options::STATE ] );
	}

	public function test_reindex_flags(): void {
		$options = $this->options();

		$options->flag_reindex( 'content', true );
		$options->flag_reindex( 'content', true );
		$options->flag_reindex( 'products', true );
		$this->assertTrue( $options->needs_reindex( 'content' ) );
		$this->assertSame( [ 'content', 'products' ], $options->state( 'needs_reindex' ) );

		$options->flag_reindex( 'content', false );
		$this->assertFalse( $options->needs_reindex( 'content' ) );
		$this->assertSame( [ 'products' ], $options->state( 'needs_reindex' ) );
	}

	public function test_reindex_state(): void {
		$options = $this->options();
		$state   = [
			'run'        => '20261001',
			'phase'      => 'upsert',
			'last_id'    => 0,
			'sent'       => 0,
			'deleted'    => 0,
			'total'      => 10,
			'task_uids'  => [],
			'started_at' => 1790000000,
			'status'     => 'running',
			'error'      => '',
		];

		$this->assertNull( $options->reindex_state( 'content' ) );

		$options->set_reindex_state( 'content', $state );
		$this->assertSame( $state, $options->reindex_state( 'content' ) );
		$this->assertNull( $options->reindex_state( 'products' ) );

		$options->set_reindex_state( 'content', null );
		$this->assertNull( $options->reindex_state( 'content' ) );
		$this->assertSame( [], $options->state( 'reindex' ) );
	}

	public function test_populated_indexes(): void {
		$options = $this->options();

		$this->assertFalse( $options->is_populated( 'content' ), 'Fresh installs start unpopulated.' );

		$options->set_populated( 'content', true );
		$options->set_populated( 'content', true );
		$options->set_populated( 'products', true );
		$this->assertTrue( $options->is_populated( 'content' ) );
		$this->assertSame( [ 'content', 'products' ], $options->state( 'populated' ) );

		$options->set_populated( 'products', false );
		$this->assertFalse( $options->is_populated( 'products' ) );
		$this->assertSame( [ 'content' ], $options->state( 'populated' ) );

		$options->clear_populated();
		$this->assertFalse( $options->is_populated( 'content' ) );
		$this->assertSame( [], $options->state( 'populated' ) );
	}
}
