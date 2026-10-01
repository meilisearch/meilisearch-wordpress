<?php
/**
 * Highlighter tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Search\Highlighter;
use Meilisearch\WordPress\Search\ResultMapper;
use Meilisearch\WordPress\Search\SearchResult;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Excerpt highlighting.
 *
 * @covers \Meilisearch\WordPress\Search\Highlighter
 */
final class HighlighterTest extends TestCase {

	use SearchStubs;

	/**
	 * Stubs wp_kses() with a <mark>-only strip.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_search_options( array( Options::SEARCH => array( 'highlight' => true ) ) );
		Functions\when( 'wp_kses' )->alias(
			static function ( $html ) {
				return strip_tags( $html, '<mark>' );
			}
		);
	}

	/**
	 * The filter is added when highlighting is enabled.
	 */
	public function test_register_when_enabled(): void {
		$highlighter = new Highlighter( new ResultMapper(), new Options() );
		$highlighter->register();

		self::assertSame( 20, has_filter( 'get_the_excerpt', array( $highlighter, 'filter_excerpt' ) ) );
	}

	/**
	 * Nothing is hooked when highlighting is disabled.
	 */
	public function test_no_hook_when_disabled(): void {
		$this->stub_search_options( array( Options::SEARCH => array( 'highlight' => false ) ) );
		$highlighter = new Highlighter( new ResultMapper(), new Options() );
		$highlighter->register();

		self::assertFalse( has_filter( 'get_the_excerpt', array( $highlighter, 'filter_excerpt' ) ) );
	}

	/**
	 * Intercepted posts get the cropped content with only <mark> kept.
	 */
	public function test_intercepted_post_gets_highlighted_crop(): void {
		$mapper = new ResultMapper();
		$mapper->apply(
			new \WP_Query( array() ),
			new SearchResult( array(), 1, 1, array( 5 => array( 'content' => '…the <mark>nebula</mark> <script>x</script>glows…' ) ) )
		);

		$excerpt = ( new Highlighter( $mapper, new Options() ) )->filter_excerpt( 'Original', new \WP_Post( array( 'ID' => 5 ) ) );

		self::assertSame( '…the <mark>nebula</mark> xglows…', $excerpt );
	}

	/**
	 * Other posts keep their excerpt.
	 */
	public function test_other_posts_keep_their_excerpt(): void {
		$mapper = new ResultMapper();
		$mapper->apply( new \WP_Query( array() ), new SearchResult( array(), 1, 1, array( 5 => array( 'title' => 'no content' ) ) ) );
		$highlighter = new Highlighter( $mapper, new Options() );

		self::assertSame( 'Original', $highlighter->filter_excerpt( 'Original', new \WP_Post( array( 'ID' => 5 ) ) ) );
		self::assertSame( 'Other', $highlighter->filter_excerpt( 'Other', new \WP_Post( array( 'ID' => 6 ) ) ) );
	}
}
