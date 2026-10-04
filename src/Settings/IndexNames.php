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
	 * @param string $home_url  network_home_url() on multisite, home_url() otherwise.
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
		return self::default_prefix( $multisite ? network_home_url() : home_url(), get_current_blog_id(), $multisite );
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
