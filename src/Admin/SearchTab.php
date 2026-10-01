<?php
/**
 * Search tab: replacement, highlighting, hybrid search, autocomplete.
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Option group `meilisearch_search` holds exactly Options::SEARCH.
 */
final class SearchTab implements Tab {

	public const GROUP    = 'meilisearch_search';
	public const DOCS_URL = 'https://www.meilisearch.com/docs/learn/ai_powered_search/getting_started_with_ai_search';

	/**
	 * Constructor.
	 *
	 * @param Options      $options      Plugin options.
	 * @param Indexability $indexability Indexability rule (which post types are indexed).
	 */
	public function __construct( private Options $options, private Indexability $indexability ) {}

	/**
	 * Tab slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'search';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Search', 'meilisearch' );
	}

	/**
	 * Visible to everyone who can open the page.
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		return current_user_can( Menu::CAPABILITY );
	}

	/**
	 * Registers Options::SEARCH in its own group.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Options::SEARCH,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitizes Options::SEARCH: checkboxes (missing = off), embedder name,
	 * semantic ratio clamped to 0..1 (0 = hybrid off). Idempotent.
	 *
	 * @param mixed $input Submitted value.
	 * @return array{replace: bool, highlight: bool, embedder: string, semantic_ratio: float, autocomplete: bool}
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		$ratio = $input['semantic_ratio'] ?? 0;
		$ratio = is_scalar( $ratio ) ? str_replace( ',', '.', trim( (string) $ratio ) ) : '0';
		$ratio = is_numeric( $ratio ) ? (float) $ratio : 0.0;
		$ratio = is_finite( $ratio ) ? max( 0.0, min( 1.0, $ratio ) ) : 0.0;

		return array(
			'replace'        => ! empty( $input['replace'] ),
			'highlight'      => ! empty( $input['highlight'] ),
			'embedder'       => is_scalar( $input['embedder'] ?? '' ) ? sanitize_text_field( (string) ( $input['embedder'] ?? '' ) ) : '',
			'semantic_ratio' => $ratio,
			'autocomplete'   => ! empty( $input['autocomplete'] ),
		);
	}

	/**
	 * Prints the tab.
	 *
	 * @return void
	 */
	public function render(): void {
		$search = $this->options->search();

		$unindexed = $search['replace'] ? $this->indexability->unindexed_searchable_types() : array();
		if ( array() !== $unindexed ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: comma-separated post type names. */
					__( 'These post types are included in site searches but are not indexed: %s. Searches that include them, such as the default theme search, keep using the WordPress database. Index them in the Content tab (products in the WooCommerce tab) for Meilisearch to answer those searches.', 'meilisearch' ),
					implode( ', ', $unindexed )
				)
			) . '</p></div>';
		}

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox_row( 'replace', __( 'Site search', 'meilisearch' ), __( 'Answer theme and WooCommerce searches with Meilisearch (falls back to WordPress search when Meilisearch is unavailable).', 'meilisearch' ), $search['replace'] );
		$this->checkbox_row( 'highlight', __( 'Highlighting', 'meilisearch' ), __( 'Show matching words highlighted in search result excerpts.', 'meilisearch' ), $search['highlight'] );
		$this->checkbox_row( 'autocomplete', __( 'Autocomplete', 'meilisearch' ), __( 'Show results as visitors type in search forms. Queries are sent from the visitor\'s browser to your Meilisearch host.', 'meilisearch' ), $search['autocomplete'] );

		echo '<tr><th scope="row"><label for="meilisearch-embedder">' . esc_html__( 'Embedder', 'meilisearch' ) . '</label></th><td>';
		printf(
			'<input type="text" id="meilisearch-embedder" class="regular-text" name="%1$s[embedder]" value="%2$s" />',
			esc_attr( Options::SEARCH ),
			esc_attr( $search['embedder'] )
		);
		echo '<p class="description">' . esc_html__( 'Hybrid search needs an embedder configured on your indexes in Meilisearch Cloud or on your instance. Enter its name here.', 'meilisearch' );
		printf( ' <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( self::DOCS_URL ), esc_html__( 'Learn how to configure an embedder', 'meilisearch' ) );
		echo '</p></td></tr>';

		echo '<tr><th scope="row"><label for="meilisearch-semantic-ratio">' . esc_html__( 'Semantic ratio', 'meilisearch' ) . '</label></th><td>';
		printf(
			'<input type="number" id="meilisearch-semantic-ratio" class="small-text" name="%1$s[semantic_ratio]" value="%2$s" min="0" max="1" step="0.05" />',
			esc_attr( Options::SEARCH ),
			esc_attr( (string) $search['semantic_ratio'] )
		);
		echo '<p class="description">' . esc_html__( '0 turns hybrid search off; 1 uses only semantic search. 0.5 is a good start.', 'meilisearch' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * One checkbox row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Checkbox text.
	 * @param bool   $checked     Current value.
	 * @return void
	 */
	private function checkbox_row( string $key, string $label, string $description, bool $checked ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[%3$s]" value="1"%4$s /> %5$s</label></td></tr>',
			esc_html( $label ),
			esc_attr( Options::SEARCH ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_html( $description )
		);
	}
}
