<?php
/**
 * Tests for BrowserHost.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Frontend;

use Meilisearch\WordPress\Frontend\BrowserHost;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class BrowserHostTest extends TestCase {

	/**
	 * @dataProvider cases
	 */
	public function test_problem( string $browser, string $site, ?string $expected ): void {
		self::assertSame( $expected, BrowserHost::problem( $browser, $site ) );
	}

	/**
	 * @return array<string, array{string, string, ?string}>
	 */
	public static function cases(): array {
		return array(
			'public https host'                       => array( 'https://ms-123.meilisearch.io', 'https://shop.example.com/', null ),
			'loopback on a public site'               => array( 'http://127.0.0.1:7700', 'https://shop.example.com/', BrowserHost::UNREACHABLE ),
			'localhost on a public site'              => array( 'http://localhost:7700', 'http://shop.example.com/', BrowserHost::UNREACHABLE ),
			'IPv6 loopback on a public site'          => array( 'http://[::1]:7700', 'https://shop.example.com/', BrowserHost::UNREACHABLE ),
			'RFC 1918 address on a public site'       => array( 'http://192.168.1.20:7700', 'http://shop.example.com/', BrowserHost::UNREACHABLE ),
			'10/8 address on a public site'           => array( 'https://10.0.0.5', 'https://shop.example.com/', BrowserHost::UNREACHABLE ),
			'172.16/12 address on a public site'      => array( 'https://172.20.0.3', 'https://shop.example.com/', BrowserHost::UNREACHABLE ),
			'link-local address on a public site'     => array( 'https://169.254.10.1', 'https://shop.example.com/', BrowserHost::UNREACHABLE ),
			'IPv6 unique local on a public site'      => array( 'https://[fd00::12]', 'https://shop.example.com/', BrowserHost::UNREACHABLE ),
			'container name on a public site'         => array( 'http://meilisearch:7700', 'https://shop.example.com/', BrowserHost::UNREACHABLE ),
			'.internal name on a public site'         => array( 'https://search.internal', 'https://shop.example.com/', BrowserHost::UNREACHABLE ),
			'.local name on a public site'            => array( 'https://search.local', 'https://shop.example.com/', BrowserHost::UNREACHABLE ),
			'public IP literal over https'            => array( 'https://203.0.113.9:7700', 'https://shop.example.com/', null ),
			'public IP literal over http, http site'  => array( 'http://8.8.8.8:7700', 'http://shop.example.com/', null ),
			'http public host on an https site'       => array( 'http://search.example.com', 'https://shop.example.com/', BrowserHost::MIXED_CONTENT ),
			'http public host on an http site'        => array( 'http://search.example.com', 'http://shop.example.com/', null ),
			'local dev site with loopback'            => array( 'http://127.0.0.1:7700', 'http://localhost:8080/', null ),
			'.test dev site with localhost'           => array( 'http://localhost:7700', 'https://shop.test/', null ),
			'.local dev site with container name'     => array( 'http://meilisearch:7700', 'http://wordpress.local/', null ),
			'https dev site with an http .test host'  => array( 'http://search.test', 'https://shop.test/', BrowserHost::MIXED_CONTENT ),
			'https dev site with http loopback is ok' => array( 'http://127.0.0.1:7700', 'https://shop.test/', null ),
			'https dev site with http .localhost'     => array( 'http://meili.localhost', 'https://shop.test/', null ),
			'intranet site on a private IP'           => array( 'http://10.0.0.6:7700', 'http://10.0.0.5/', null ),
			'uppercase and trailing dot'              => array( 'http://LOCALHOST.:7700', 'https://Shop.Example.com/', BrowserHost::UNREACHABLE ),
		);
	}

	public function test_warning_names_the_host_and_the_filter(): void {
		$unreachable = (string) BrowserHost::warning( 'http://127.0.0.1:7700', 'https://shop.example.com/' );
		$mixed       = (string) BrowserHost::warning( 'http://search.example.com', 'https://shop.example.com/' );

		self::assertStringContainsString( 'http://127.0.0.1:7700', $unreachable );
		self::assertStringContainsString( 'meilisearch_autocomplete_host', $unreachable );
		self::assertStringContainsString( 'http://search.example.com', $mixed );
		self::assertStringContainsString( 'mixed content', $mixed );
		self::assertNull( BrowserHost::warning( 'https://search.example.com', 'https://shop.example.com/' ) );
	}
}
