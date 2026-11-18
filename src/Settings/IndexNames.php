<?php
/**
 * Index uid computation.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the index prefix and the uid of each logical index (spec § 5.1).
 */
final class IndexNames {

	/**
	 * Creates the helper.
	 *
	 * @param Options $options Plugin options.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Default prefix: wp_{first 6 hex chars of md5(url without trailing slash)}[_{blog_id} on multisite].
	 *
	 * @param string $home_url  Network address on multisite, site address otherwise (see stored_home_url()).
	 * @param int    $blog_id   Current blog id.
	 * @param bool   $multisite Whether the install is multisite.
	 * @return string Only [a-z0-9_].
	 */
	public static function default_prefix( string $home_url, int $blog_id, bool $multisite ): string {
		// Same as untrailingslashit(), without needing WordPress loaded.
		$prefix = 'wp_' . substr( md5( rtrim( $home_url, '/\\' ) ), 0, 6 );
		return $multisite ? $prefix . '_' . $blog_id : $prefix;
	}

	/**
	 * The admin override, or the default prefix for this site.
	 *
	 * @return string
	 */
	public function prefix(): string {
		$override = $this->options->prefix_override();
		if ( '' !== $override ) {
			return $override;
		}
		$multisite = is_multisite();
		return self::default_prefix( self::stored_home_url( $multisite ), get_current_blog_id(), $multisite );
	}

	/**
	 * The address the default prefix is derived from, as stored in the database.
	 *
	 * Deliberately not home_url() / network_home_url(): their scheme follows is_ssl() of the current
	 * request, so an HTTPS web request and WP-CLI or a system cron (no HTTPS in $_SERVER) would resolve
	 * different index uids for the same site. The stored scheme gives the value those functions return
	 * on a request made with the scheme the site is configured for, whatever the current request is.
	 *
	 * @param bool $multisite Whether the install is multisite.
	 * @return string Single site: the `home` option. Multisite: the address of the network the current
	 *                site belongs to, with the scheme of its main site's `home` option.
	 */
	private static function stored_home_url( bool $multisite ): string {
		if ( ! $multisite ) {
			return (string) get_option( 'home' );
		}

		// The network of the current site rather than of the request, so that a switched-to site of
		// another network resolves the same uids as its own requests do.
		$site    = get_site();
		$network = get_network( $site ? (int) $site->network_id : null );
		if ( ! $network ) {
			return (string) get_option( 'home' );
		}

		// Read through the site details cache: no blog switch on every call.
		$main_id   = (int) $network->site_id;
		$main_site = $main_id > 0 ? get_site( $main_id ) : null;
		$scheme    = $main_site ? wp_parse_url( (string) $main_site->home, PHP_URL_SCHEME ) : null;

		return ( 'https' === $scheme ? 'https' : 'http' ) . '://' . $network->domain . $network->path;
	}

	/**
	 * Live index uid.
	 *
	 * @param string $logical 'content' | 'products'.
	 * @return string "{prefix}_{logical}"
	 */
	public function uid( string $logical ): string {
		return $this->prefix() . '_' . $logical;
	}

	/**
	 * Logical indexes in use on this site.
	 *
	 * @return list<string> 'content', plus 'products' when products are indexed.
	 */
	public function active_logicals(): array {
		$logicals = array( 'content' );
		if ( $this->options->products_enabled() ) {
			$logicals[] = 'products';
		}
		return $logicals;
	}
}
