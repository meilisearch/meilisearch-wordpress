<?php
/**
 * Tests for IndexNames.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class IndexNamesTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->stub_options();
	}

	private function names(): IndexNames {
		return new IndexNames( new Options() );
	}

	public function test_default_prefix_on_a_single_site(): void {
		$expected = 'wp_' . substr( md5( 'https://example.com' ), 0, 6 );

		$this->assertSame( $expected, IndexNames::default_prefix( 'https://example.com', 1, false ) );
		$this->assertSame( $expected, IndexNames::default_prefix( 'https://example.com/', 1, false ) );
	}

	public function test_default_prefix_on_multisite_appends_the_blog_id(): void {
		$this->assertSame(
			'wp_' . substr( md5( 'https://network.example' ), 0, 6 ) . '_3',
			IndexNames::default_prefix( 'https://network.example/', 3, true )
		);
	}

	public function test_default_prefix_only_uses_uid_safe_characters(): void {
		$this->assertMatchesRegularExpression( '/^wp_[a-f0-9]{6}_12$/', IndexNames::default_prefix( 'https://ÉXAMPLE.com/blog', 12, true ) );
	}

	/**
	 * Stubs a multisite network whose main site (blog 1) stores $main_home as its `home` option.
	 */
	private function stub_network( string $domain, string $path, string $main_home, int $blog_id ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( $blog_id );
		Functions\when( 'get_site' )->alias(
			static function ( $id = null ) use ( $blog_id, $main_home ) {
				$id = null === $id ? $blog_id : (int) $id;
				return (object) [
					'id'         => (string) $id,
					'network_id' => '1',
					'home'       => 1 === $id ? $main_home : 'http://child.example',
				];
			}
		);
		Functions\when( 'get_network' )->justReturn(
			(object) [
				'id'      => 1,
				'domain'  => $domain,
				'path'    => $path,
				'site_id' => '1',
			]
		);
	}

	public function test_prefix_uses_the_stored_home_option_on_a_single_site(): void {
		$this->stub_options( [ 'home' => 'https://shop.example' ] );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		// Their scheme follows is_ssl() of the current request: web and WP-CLI/cron would disagree.
		Functions\expect( 'home_url' )->never();
		Functions\expect( 'network_home_url' )->never();

		// Pinned: the uid an HTTPS site with an https:// home option has always had, md5( home_url() ).
		$this->assertSame( 'wp_b15a97', $this->names()->prefix() );
		$this->assertSame( 'wp_b15a97_content', $this->names()->uid( 'content' ) );
		$this->assertSame( IndexNames::default_prefix( 'https://shop.example/', 1, false ), $this->names()->prefix() );
	}

	public function test_prefix_uses_the_network_address_with_the_main_site_scheme_on_multisite(): void {
		$this->stub_network( 'network.example', '/', 'https://network.example', 4 );
		Functions\expect( 'home_url' )->never();
		Functions\expect( 'network_home_url' )->never();

		// Pinned: the uid an HTTPS network has always had on its HTTPS requests, md5( network_home_url() ).
		$this->assertSame( 'wp_d0090a_4', $this->names()->prefix() );
		$this->assertSame( 'wp_d0090a_4_content', $this->names()->uid( 'content' ) );
		$this->assertSame( IndexNames::default_prefix( 'https://network.example/', 4, true ), $this->names()->prefix() );
	}

	public function test_prefix_keeps_the_http_scheme_of_a_network_stored_as_http(): void {
		$this->stub_network( 'network.example', '/', 'http://network.example', 1 );

		$this->assertSame( 'wp_b18b15_1', $this->names()->prefix() );
	}

	public function test_prefix_includes_the_network_path_on_multisite(): void {
		$this->stub_network( 'network.example', '/wp/', 'https://network.example/wp', 2 );

		$this->assertSame( 'wp_c77257_2', $this->names()->prefix() );
	}

	public function test_prefix_override_wins(): void {
		$this->stub_options( [ Options::CONNECTION => [ 'prefix' => 'staging' ] ] );
		Functions\expect( 'home_url' )->never();
		$names = $this->names();

		$this->assertSame( 'staging', $names->prefix() );
		$this->assertSame( 'staging_content', $names->uid( 'content' ) );
		$this->assertSame( 'staging_products', $names->uid( 'products' ) );
	}

	public function test_active_logicals_without_woocommerce(): void {
		$this->assertSame( [ 'content' ], $this->names()->active_logicals() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_active_logicals_with_woocommerce(): void {
		require_once dirname( __DIR__ ) . '/Support/woocommerce-active.php';

		$this->assertSame( [ 'content', 'products' ], $this->names()->active_logicals() );
	}
}
