<?php
/**
 * The default index prefix must not depend on the scheme of the current request.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;

final class IndexPrefixSchemeTest extends TestCase {

	/**
	 * $_SERVER['HTTPS'] before the test, null when it was not set.
	 *
	 * @var string|null
	 */
	private ?string $saved_https = null;

	public function set_up(): void {
		parent::set_up();
		$this->saved_https = isset( $_SERVER['HTTPS'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ) : null;

		// No admin override: the default prefix is under test.
		$connection           = (array) get_option( Options::CONNECTION );
		$connection['prefix'] = '';
		update_option( Options::CONNECTION, $connection );
	}

	public function tear_down(): void {
		if ( null === $this->saved_https ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->saved_https;
		}
		// The parent cleans up by prefix: give it the test prefix back (nothing was created under the default one).
		$connection           = (array) get_option( Options::CONNECTION );
		$connection['prefix'] = $this->prefix;
		update_option( Options::CONNECTION, $connection );
		parent::tear_down();
	}

	/**
	 * The uid resolved by a web request over HTTPS, then by WP-CLI / system cron (no HTTPS in $_SERVER).
	 *
	 * @return array{web: string, cli: string}
	 */
	private function uids(): array {
		$names = new IndexNames( new Options() );

		$_SERVER['HTTPS'] = 'on';
		$web              = $names->uid( 'content' );
		unset( $_SERVER['HTTPS'] );
		$cli = $names->uid( 'content' );

		return array(
			'web' => $web,
			'cli' => $cli,
		);
	}

	/**
	 * What the uid has always been: built on the md5 of the request-dependent URL.
	 */
	private function legacy_uid(): string {
		$url = is_multisite() ? network_home_url() : home_url();
		return IndexNames::default_prefix( $url, get_current_blog_id(), is_multisite() ) . '_content';
	}

	public function test_default_prefix_is_the_same_for_https_web_and_cli_requests(): void {
		$uids = $this->uids();

		$this->assertSame( $uids['web'], $uids['cli'] );
	}

	public function test_site_stored_as_http_keeps_the_uid_of_its_http_requests(): void {
		$this->assertSame( 'http', wp_parse_url( (string) get_option( 'home' ), PHP_URL_SCHEME ) );
		unset( $_SERVER['HTTPS'] );
		$legacy = $this->legacy_uid();

		$this->assertSame( is_multisite() ? 'wp_dab521_1_content' : 'wp_dab521_content', $legacy, 'md5( http://example.org )' );
		$this->assertSame(
			array(
				'web' => $legacy,
				'cli' => $legacy,
			),
			$this->uids()
		);
	}

	public function test_site_stored_as_https_keeps_the_uid_of_its_https_requests(): void {
		update_option( 'home', 'https://example.org' );
		$_SERVER['HTTPS'] = 'on';
		$legacy           = $this->legacy_uid();

		$this->assertSame( is_multisite() ? 'wp_5564fd_1_content' : 'wp_5564fd_content', $legacy, 'md5( https://example.org )' );
		$this->assertSame(
			array(
				'web' => $legacy,
				'cli' => $legacy,
			),
			$this->uids()
		);
	}

	public function test_subsite_uses_the_scheme_of_the_main_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		update_option( 'home', 'https://example.org' );
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		try {
			$_SERVER['HTTPS'] = 'on';
			$legacy           = $this->legacy_uid();
			$uids             = $this->uids();
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 'wp_5564fd_' . $site_id . '_content', $legacy );
		$this->assertSame(
			array(
				'web' => $legacy,
				'cli' => $legacy,
			),
			$uids
		);
	}

	public function test_switched_to_site_of_another_network_uses_its_own_network_address(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$network_id = self::factory()->network->create(
			array(
				'domain' => 'second.example',
				'path'   => '/',
			)
		);
		$site_id    = self::factory()->blog->create(
			array(
				'domain'     => 'second.example',
				'path'       => '/',
				'network_id' => $network_id,
			)
		);

		switch_to_blog( $site_id );
		try {
			$uids = $this->uids();
		} finally {
			restore_current_blog();
		}

		// The hash of http://second.example, which a request served by that network resolves.
		$this->assertSame(
			array(
				'web' => 'wp_bf3523_' . $site_id . '_content',
				'cli' => 'wp_bf3523_' . $site_id . '_content',
			),
			$uids
		);
	}
}
