<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\SearchTab;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class SearchTabTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
	}

	private static function tab(): SearchTab {
		$options = new Options();
		return new SearchTab( $options, new Indexability( $options ) );
	}

	public function test_identity(): void {
		$tab = self::tab();

		$this->assertSame( 'search', $tab->slug() );
		$this->assertSame( 'Search', $tab->label() );
		$this->assertTrue( $tab->is_visible() );
	}

	public function test_register_settings_uses_its_own_group(): void {
		$registered = array();
		Functions\when( 'register_setting' )->alias(
			static function ( $group, $name, $args ) use ( &$registered ) {
				$registered[] = array( $group, $name, $args['sanitize_callback'] );
			}
		);

		( self::tab() )->register_settings();

		$this->assertSame( array( array( 'meilisearch_search', Options::SEARCH, array( SearchTab::class, 'sanitize' ) ) ), $registered );
	}

	public function test_sanitize_form_submission(): void {
		$this->assertSame(
			array(
				'replace'        => true,
				'highlight'      => false,
				'embedder'       => 'default',
				'semantic_ratio' => 0.5,
				'autocomplete'   => true,
			),
			SearchTab::sanitize(
				array(
					'replace'        => '1',
					'embedder'       => ' <b>default</b> ',
					'semantic_ratio' => '0,5',
					'autocomplete'   => '1',
				)
			)
		);
	}

	/**
	 * @dataProvider ratios
	 *
	 * @param mixed $raw Submitted ratio.
	 */
	public function test_semantic_ratio_is_clamped( $raw, float $expected ): void {
		$this->assertSame( $expected, SearchTab::sanitize( array( 'semantic_ratio' => $raw ) )['semantic_ratio'] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: float}>
	 */
	public static function ratios(): array {
		return array(
			'zero means off' => array( '0', 0.0 ),
			'decimal'        => array( '0.25', 0.25 ),
			'float'          => array( 0.75, 0.75 ),
			'above one'      => array( '3', 1.0 ),
			'negative'       => array( '-1', 0.0 ),
			'not a number'   => array( 'abc', 0.0 ),
			'empty'          => array( '', 0.0 ),
			'array'          => array( array( 1 ), 0.0 ),
			'infinite'       => array( '1e999', 0.0 ),
		);
	}

	public function test_sanitize_is_idempotent(): void {
		$once = SearchTab::sanitize(
			array(
				'highlight'      => 'on',
				'embedder'       => 'openai',
				'semantic_ratio' => '0.8',
			)
		);

		$this->assertSame( $once, SearchTab::sanitize( $once ) );
	}

	public function test_sanitize_non_array_gives_defaults(): void {
		$this->assertSame(
			array(
				'replace'        => false,
				'highlight'      => false,
				'embedder'       => '',
				'semantic_ratio' => 0.0,
				'autocomplete'   => false,
			),
			SearchTab::sanitize( 'garbage' )
		);
	}

	public function test_render_shows_values_and_embedder_note(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name ) => Options::SEARCH === $name
				? array(
					'replace'        => true,
					'highlight'      => false,
					'embedder'       => 'default',
					'semantic_ratio' => 0.5,
					'autocomplete'   => false,
				)
				: false
		);
		Functions\when( 'settings_fields' )->alias(
			static function ( $group ) {
				echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
			}
		);
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->alias( static fn( $a, $b ) => $a === $b ? ' checked="checked"' : '' );

		ob_start();
		( self::tab() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="meilisearch_search"', $html );
		$this->assertStringContainsString( 'name="meilisearch_search[replace]" value="1" checked="checked"', $html );
		$this->assertStringContainsString( 'name="meilisearch_search[highlight]" value="1" />', $html );
		$this->assertStringContainsString( 'name="meilisearch_search[embedder]" value="default"', $html );
		$this->assertStringContainsString( 'value="0.5"', $html );
		$this->assertStringContainsString( SearchTab::DOCS_URL, $html );
	}

	/**
	 * @return string Rendered tab with the given search settings and searchable post types.
	 */
	private function render_with( bool $replace, array $searchable ): string {
		Functions\when( 'get_option' )->alias(
			static fn( $name ) => Options::SEARCH === $name
				? array( 'replace' => $replace )
				: ( Options::CONTENT === $name ? array( 'post_types' => array( 'post', 'page' ) ) : false )
		);
		Functions\when( 'get_post_types' )->justReturn( array_combine( $searchable, $searchable ) );
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->justReturn( '' );

		ob_start();
		self::tab()->render();
		return (string) ob_get_clean();
	}

	public function test_replace_warns_about_searchable_types_that_are_not_indexed(): void {
		$html = $this->render_with( true, array( 'post', 'page', 'attachment', 'event<b>' ) );

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'event&lt;b&gt;', $html );
		$this->assertStringNotContainsString( 'event<b>', $html );
		$this->assertStringNotContainsString( 'attachment', $html );
	}

	public function test_no_warning_when_every_searchable_type_is_indexed_or_replace_is_off(): void {
		$this->assertStringNotContainsString( 'notice-warning', $this->render_with( true, array( 'post', 'page', 'attachment' ) ) );
		$this->assertStringNotContainsString( 'notice-warning', $this->render_with( false, array( 'post', 'event' ) ) );
	}
}
