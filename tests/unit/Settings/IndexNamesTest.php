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

	public function test_prefix_uses_home_url_on_a_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example/' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\expect( 'network_home_url' )->never();

		$this->assertSame( 'wp_' . substr( md5( 'https://shop.example' ), 0, 6 ), $this->names()->prefix() );
	}

	public function test_prefix_uses_network_home_url_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'network_home_url' )->justReturn( 'https://network.example' );
		Functions\when( 'get_current_blog_id' )->justReturn( 4 );
		Functions\expect( 'home_url' )->never();

		$this->assertSame( 'wp_' . substr( md5( 'https://network.example' ), 0, 6 ) . '_4', $this->names()->prefix() );
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
