<?php
/**
 * Front-end autocomplete (spec § 10).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Frontend;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;

/**
 * Enqueues the vanilla-JS autocomplete and passes it the scoped search key and index UIDs.
 * The key is the plugin-created browser key (`search` action on this site's indexes only),
 * never the admin key.
 */
final class Autocomplete implements Registrable {

	public const HANDLE           = 'meilisearch-autocomplete';
	public const OBJECT_NAME      = 'meilisearchAutocomplete';
	public const DEFAULT_SELECTOR = 'form[role=search] input[name=s], input[name=s]';
	public const LIMIT            = 5;
	public const MIN_CHARS        = 2;
	public const DEBOUNCE_MS      = 150;

	/**
	 * Constructor.
	 *
	 * @param Options    $options Plugin options.
	 * @param IndexNames $names   Index names.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly IndexNames $names
	) {}

	/**
	 * Attaches the enqueue callback.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Registers, configures and enqueues the script and style when autocomplete is usable.
	 */
	public function enqueue(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}
		wp_register_script(
			self::HANDLE,
			plugins_url( 'assets/js/' . $this->script_file(), MEILISEARCH_FILE ),
			array(),
			MEILISEARCH_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script( self::HANDLE, self::OBJECT_NAME, $this->config() );
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/css/autocomplete.css', MEILISEARCH_FILE ), array(), MEILISEARCH_VERSION );
	}

	/**
	 * Front end only, enabled in the Search tab, connected, a browser search key exists, and its hash is
	 * the one recorded as servable: by rotate_search_key() for a plugin-created key, or by the last
	 * successful verification of a manually pasted key.
	 */
	public function should_enqueue(): bool {
		return ! is_admin()
			&& $this->options->search()['autocomplete']
			&& '' !== $this->options->search_key()
			&& $this->options->is_configured()
			&& $this->options->search_key_is_verified();
	}

	/**
	 * `autocomplete.min.js` (built by `npm run build`, not committed) unless SCRIPT_DEBUG is on
	 * or the minified file is missing, in which case the readable source is served.
	 */
	public function script_file(): string {
		$minified = dirname( MEILISEARCH_FILE ) . '/assets/js/autocomplete.min.js';
		$debug    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		return ( ! $debug && file_exists( $minified ) ) ? 'autocomplete.min.js' : 'autocomplete.js';
	}

	/**
	 * Script configuration. Numbers live in nested arrays because wp_localize_script()
	 * casts top-level scalars to strings.
	 *
	 * @return array{host: string, key: string, indexes: array{content: string, products: ?string}, limits: array{content: int, products: int, minChars: int, debounce: int}, subtitles: array{content: ?string, products: ?string}, selector: string, currency: ?string, locale: string, home: string, i18n: array<string, string>}
	 */
	public function config(): array {
		$selector = apply_filters( 'meilisearch_autocomplete_selector', self::DEFAULT_SELECTOR );
		if ( ! is_string( $selector ) || '' === trim( $selector ) ) {
			$selector = self::DEFAULT_SELECTOR;
		}
		$products = $this->options->products_enabled();

		$subtitles = array();
		foreach ( array( 'content', 'products' ) as $logical ) {
			/**
			 * Filters the document field shown as a second line under each autocomplete suggestion.
			 *
			 * @param string|null $field   Field name (top-level, [A-Za-z0-9_.]), or null for none.
			 * @param string      $logical 'content' | 'products'.
			 */
			$field                 = apply_filters( 'meilisearch_autocomplete_subtitle_field', null, $logical );
			$subtitles[ $logical ] = is_string( $field ) && 1 === preg_match( '/^[A-Za-z0-9_.]+$/', $field ) ? $field : null;
		}

		return array(
			'host'      => $this->options->host(),
			'key'       => $this->options->search_key(),
			'indexes'   => array(
				'content'  => $this->names->uid( 'content' ),
				'products' => $products ? $this->names->uid( 'products' ) : null,
			),
			'limits'    => array(
				'content'  => self::LIMIT,
				'products' => self::LIMIT,
				'minChars' => self::MIN_CHARS,
				'debounce' => self::DEBOUNCE_MS,
			),
			'subtitles' => $subtitles,
			'selector'  => $selector,
			'currency'  => $products && function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : null,
			'locale'    => str_replace( '_', '-', determine_locale() ),
			'home'      => home_url( '/' ),
			'i18n'      => array(
				'products'    => __( 'Products', 'meilisearch' ),
				'posts'       => __( 'Posts', 'meilisearch' ),
				'listLabel'   => __( 'Search suggestions', 'meilisearch' ),
				'noResults'   => __( 'No suggestions found.', 'meilisearch' ),
				'oneResult'   => __( '1 suggestion available. Use the up and down arrow keys to browse.', 'meilisearch' ),
				/* translators: %d: number of suggestions. */
				'results'     => __( '%d suggestions available. Use the up and down arrow keys to browse.', 'meilisearch' ),
				/* translators: %s: the search terms. */
				'seeAll'      => __( 'See all results for “%s”', 'meilisearch' ),
				/* translators: 1: number of results, 2: the search terms. */
				'seeAllCount' => __( 'See all %1$d results for “%2$s”', 'meilisearch' ),
			),
		);
	}
}
