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
use Meilisearch\WordPress\Search\FilterBuilder;
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
	 * Registers, configures and enqueues the script and style when autocomplete is usable and at
	 * least one index holds a post type of the site search (otherwise the script has nothing to query).
	 */
	public function enqueue(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}
		$config = $this->config();
		if ( null === $config['indexes']['content'] && null === $config['indexes']['products'] ) {
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
		wp_localize_script( self::HANDLE, self::OBJECT_NAME, $config );
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/css/autocomplete.css', MEILISEARCH_FILE ), array(), MEILISEARCH_VERSION );
	}

	/**
	 * Front end only, enabled in the Search tab, connected, a browser search key exists, and its hash is
	 * the one recorded as servable: by rotate_search_key() for a plugin-created key, or by the last
	 * successful verification of a manually pasted key.
	 *
	 * Runs on every front-end page view: once autocomplete is known to be on (an autoloaded option), the
	 * non-autoloaded options the other checks read are loaded in a single query.
	 */
	public function should_enqueue(): bool {
		if ( is_admin() || ! $this->options->search()['autocomplete'] ) {
			return false;
		}
		$this->options->prime_connection();

		return '' !== $this->options->search_key()
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
	 * Suggestions follow the default site search (QueryTranslator): only post types registered with
	 * exclude_from_search = false. A type can be indexed and still be left out of the search, and the
	 * results page the "See all results" link opens would not list it.
	 *
	 * @return array{host: string, key: string, indexes: array{content: ?string, products: ?string}, filters: array{content: ?string}, limits: array{content: int, products: int, minChars: int, debounce: int}, subtitles: array{content: ?string, products: ?string}, selector: string, currency: ?string, locale: string, home: string, i18n: array<string, string>}
	 */
	public function config(): array {
		$selector = apply_filters( 'meilisearch_autocomplete_selector', self::DEFAULT_SELECTOR );
		if ( ! is_string( $selector ) || '' === trim( $selector ) ) {
			$selector = self::DEFAULT_SELECTOR;
		}
		$searchable    = array_map( 'strval', array_values( get_post_types( array( 'exclude_from_search' => false ) ) ) );
		$content_types = array_values( array_intersect( $this->options->enabled_post_types(), $searchable ) );
		$products      = $this->options->products_enabled() && in_array( 'product', $searchable, true );

		$subtitles = array();
		foreach ( array( 'content', 'products' ) as $logical ) {
			$subtitles[ $logical ] = self::subtitle_field( $logical );
		}

		return array(
			'host'      => $this->browser_host(),
			'key'       => $this->options->search_key(),
			'indexes'   => array(
				'content'  => array() !== $content_types ? $this->names->uid( 'content' ) : null,
				'products' => $products ? $this->names->uid( 'products' ) : null,
			),
			'filters'   => array(
				'content' => array() !== $content_types ? FilterBuilder::in( 'post_type', $content_types ) : null,
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

	/**
	 * Why visitors' browsers cannot use the browser host (BrowserHost::warning()), or null when they can or
	 * autocomplete is off. The site's URL is home_url(), which visitors open.
	 *
	 * @param Options $options Plugin options.
	 * @return string|null Plain text.
	 */
	public static function host_warning( Options $options ): ?string {
		if ( ! $options->search()['autocomplete'] || ! $options->is_configured() ) {
			return null;
		}

		return BrowserHost::warning( self::browser_host_for( $options ), home_url( '/' ) );
	}

	/**
	 * The field shown under each suggestion of an index, or null for none. SettingsBuilder adds it to the
	 * displayed attributes of the indexes the plugin creates, so the browser key can read it.
	 *
	 * @param string $logical 'content' | 'products'.
	 * @return string|null
	 */
	public static function subtitle_field( string $logical ): ?string {
		/**
		 * Filters the document field shown as a second line under each autocomplete suggestion.
		 *
		 * @param string|null $field   Field name (top-level, [A-Za-z0-9_.]), or null for none.
		 * @param string      $logical 'content' | 'products'.
		 */
		$field = apply_filters( 'meilisearch_autocomplete_subtitle_field', null, $logical );
		return is_string( $field ) && 1 === preg_match( '/^[A-Za-z0-9_.]+$/', $field ) ? $field : null;
	}

	/**
	 * The Meilisearch URL visitors' browsers use. Defaults to the configured host; a site whose PHP reaches
	 * Meilisearch on a private address (loopback, internal network) returns the public URL here.
	 */
	public function browser_host(): string {
		return self::browser_host_for( $this->options );
	}

	/**
	 * The Meilisearch URL visitors' browsers use, for the given options (see browser_host()).
	 *
	 * @param Options $options Plugin options.
	 * @return string
	 */
	public static function browser_host_for( Options $options ): string {
		$host = $options->host();
		/**
		 * Filters the Meilisearch URL the autocomplete script calls from the browser.
		 *
		 * @param string $host Meilisearch URL (the configured host by default).
		 */
		$filtered = apply_filters( 'meilisearch_autocomplete_host', $host );
		$public   = is_string( $filtered ) ? Options::normalize_host( $filtered ) : '';
		return '' !== $public ? $public : $host;
	}
}
