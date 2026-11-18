<?php
/**
 * Whether visitors' browsers can reach the autocomplete host.
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Detects a Meilisearch URL that visitors' browsers cannot use: a loopback, private or link-local
 * address, or a name that only resolves inside a network, while the site itself is public (on a local
 * development site the browser runs on the same machine or network, so these work); or a plain-http
 * host on an https site, which browsers block as mixed content (loopback hosts are exempt).
 *
 * Options::is_local_host() serves another purpose (choosing a default scheme) and treats every IPv4
 * literal as local, so it is not used here.
 */
final class BrowserHost {

	public const UNREACHABLE   = 'unreachable';
	public const MIXED_CONTENT = 'mixed_content';

	/**
	 * Top-level names reserved for local or internal use (RFC 2606, RFC 6762, RFC 8375, ICANN .internal).
	 */
	private const LOCAL_SUFFIXES = array( 'localhost', 'local', 'test', 'internal', 'invalid', 'example', 'lan', 'localdomain', 'home.arpa', 'intranet' );

	/**
	 * Problem of a browser host for a site, or null when visitors can use it.
	 *
	 * @param string $browser_url Meilisearch URL the browser calls (after meilisearch_autocomplete_host).
	 * @param string $site_url    The site's front-end URL (home_url()).
	 * @return string|null self::UNREACHABLE, self::MIXED_CONTENT or null.
	 */
	public static function problem( string $browser_url, string $site_url ): ?string {
		$browser = self::host( $browser_url );
		if ( '' === $browser ) {
			return null;
		}
		$site = self::host( $site_url );

		if ( self::is_private( $browser ) && ( '' === $site || ! self::is_private( $site ) ) ) {
			return self::UNREACHABLE;
		}
		if ( 'http' === self::scheme( $browser_url ) && 'https' === self::scheme( $site_url ) && ! self::is_loopback( $browser ) ) {
			return self::MIXED_CONTENT;
		}

		return null;
	}

	/**
	 * Admin-facing explanation of the problem, or null when there is none.
	 *
	 * @param string $browser_url Meilisearch URL the browser calls.
	 * @param string $site_url    The site's front-end URL.
	 * @return string|null Plain text.
	 */
	public static function warning( string $browser_url, string $site_url ): ?string {
		switch ( self::problem( $browser_url, $site_url ) ) {
			case self::UNREACHABLE:
				return sprintf(
					/* translators: %s: Meilisearch URL. */
					__( 'Visitors\' browsers send autocomplete queries to %s, an address that only works from your own machine or network, so visitors never see suggestions. Return the public URL of your Meilisearch instance from the meilisearch_autocomplete_host filter.', 'meilisearch' ),
					$browser_url
				);
			case self::MIXED_CONTENT:
				return sprintf(
					/* translators: %s: Meilisearch URL. */
					__( 'Your site uses HTTPS but visitors\' browsers send autocomplete queries to %s over plain HTTP. Browsers block these requests as mixed content, so visitors never see suggestions. Serve Meilisearch over HTTPS, or return its https:// URL from the meilisearch_autocomplete_host filter.', 'meilisearch' ),
					$browser_url
				);
			default:
				return null;
		}
	}

	/**
	 * Loopback hosts: localhost, *.localhost, 127.0.0.0/8 and ::1 (browsers treat them as secure).
	 *
	 * @param string $host Lowercase host without brackets.
	 * @return bool
	 */
	public static function is_loopback( string $host ): bool {
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || '::1' === $host ) {
			return true;
		}

		return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && str_starts_with( $host, '127.' );
	}

	/**
	 * Hosts only reachable from the same machine or network: loopback, private, link-local, CGNAT and
	 * reserved addresses, single-label names (a container or intranet name) and reserved local TLDs.
	 *
	 * @param string $host Lowercase host without brackets.
	 * @return bool
	 */
	public static function is_private( string $host ): bool {
		if ( self::is_loopback( $host ) ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
			// Older PHP versions miss these ranges: IPv6 link-local fe80::/10 and IPv4 shared (CGNAT) 100.64.0.0/10.
			$packed = (string) inet_pton( $host );
			if ( 16 === strlen( $packed ) ) {
				return "\xfe" === $packed[0] && 0x80 === ( ord( $packed[1] ) & 0xc0 );
			}
			return 4 === strlen( $packed ) && 100 === ord( $packed[0] ) && 64 === ( ord( $packed[1] ) & 0xc0 );
		}
		if ( ! str_contains( $host, '.' ) ) {
			return true;
		}
		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, '.' . $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lowercase host of a URL, without IPv6 brackets or a trailing dot; '' when there is none.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? rtrim( strtolower( trim( $host, '[]' ) ), '.' ) : '';
	}

	/**
	 * Lowercase scheme of a URL; '' when there is none.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function scheme( string $url ): string {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return is_string( $scheme ) ? strtolower( $scheme ) : '';
	}
}
