<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\ContentDocumentBuilder;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class ContentDocumentBuilderTest extends TestCase {

	/**
	 * Meta values returned by get_post_meta(), keyed by meta key.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * Terms returned by get_the_terms(), keyed by taxonomy.
	 *
	 * @var array<string, mixed>
	 */
	private array $terms = array();

	protected function set_up(): void {
		parent::set_up();

		$this->meta  = array();
		$this->terms = array();

		$this->stub_options(
			array(
				Options::CONTENT => array(
					'post_types' => array( 'post' ),
					'taxonomies' => array( 'post' => array( 'category', 'pa_couleur-é', '---' ) ),
					'meta_keys'  => array( 'post' => array( 'price', 'my.key', 'my-key', 'zip', 'flag', 'list', 'missing', 'big', 'ratio' ) ),
				),
			)
		);
		Functions\when( 'wp_check_invalid_utf8' )->alias(
			static function ( $text, $strip = false ) {
				$text = (string) $text;
				if ( mb_check_encoding( $text, 'UTF-8' ) ) {
					return $text;
				}
				return $strip ? mb_scrub( $text, 'UTF-8' ) : '';
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $text ) => trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text ) ) )
		);
		Functions\when( 'strip_shortcodes' )->alias( static fn( $text ) => (string) preg_replace( '/\[[^\]]*\]/', '', (string) $text ) );
		Functions\when( 'do_blocks' )->returnArg();
		Functions\when( 'wp_trim_words' )->alias(
			static function ( $text, $num = 55, $more = null ) {
				$words = preg_split( '/\s+/', trim( (string) $text ) );
				return implode( ' ', array_slice( (array) $words, 0, (int) $num ) ) . ( count( (array) $words ) > $num ? (string) $more : '' );
			}
		);
		Functions\when( 'get_the_title' )->alias( static fn( $post ) => $post->post_title );
		Functions\when( 'get_permalink' )->alias( static fn( $post ) => 'https://example.test/?p=' . $post->ID );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Ada &amp; Co' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		Functions\when( 'get_the_terms' )->alias( fn( $post, $taxonomy ) => $this->terms[ $taxonomy ] ?? false );
		Functions\when( 'get_ancestors' )->alias(
			static fn( $id ) => 3 === $id ? array( 2, 1 ) : array()
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $key ] ?? '' );
	}

	private function post( array $props = array() ): \WP_Post {
		return new \WP_Post(
			array_merge(
				array(
					'ID'                => 42,
					'post_type'         => 'post',
					'post_status'       => 'publish',
					'post_title'        => 'Hello &#8220;World&#8221;',
					'post_content'      => '<!-- wp:paragraph --><p>First   <strong>paragraph</strong>.</p><!-- /wp:paragraph -->[gallery ids="1"]<script>alert(1)</script>',
					'post_excerpt'      => '',
					'post_date_gmt'     => '2024-01-02 03:04:05',
					'post_modified_gmt' => '0000-00-00 00:00:00',
					'post_author'       => '7',
				),
				$props
			)
		);
	}

	private function builder(): ContentDocumentBuilder {
		return new ContentDocumentBuilder( new Options() );
	}

	public function test_core_fields(): void {
		$doc = $this->builder()->core_fields( $this->post() );

		$this->assertSame( 42, $doc['id'] );
		$this->assertSame( 'post', $doc['post_type'] );
		$this->assertSame( "Hello \u{201C}World\u{201D}", $doc['title'] );
		$this->assertSame( 'First paragraph.', $doc['content'] );
		$this->assertSame( 'First paragraph.', $doc['excerpt'] );
		$this->assertSame( 'https://example.test/?p=42', $doc['permalink'] );
		$this->assertSame( 1704164645, $doc['date'] );
		$this->assertSame( 0, $doc['modified'] );
		$this->assertSame( 7, $doc['author_id'] );
		$this->assertSame( 'Ada & Co', $doc['author_name'] );
		$this->assertNull( $doc['thumbnail_url'] );
	}

	public function test_explicit_excerpt_wins_and_is_cleaned(): void {
		$doc = $this->builder()->core_fields( $this->post( array( 'post_excerpt' => " <em>Short</em>\n text " ) ) );

		$this->assertSame( 'Short text', $doc['excerpt'] );
	}

	public function test_excerpt_falls_back_to_first_55_words(): void {
		$words = implode( ' ', array_map( static fn( int $i ): string => 'w' . $i, range( 1, 80 ) ) );
		$doc   = $this->builder()->core_fields( $this->post( array( 'post_content' => $words ) ) );

		$this->assertSame( implode( ' ', array_map( static fn( int $i ): string => 'w' . $i, range( 1, 55 ) ) ), $doc['excerpt'] );
	}

	public function test_content_is_truncated_to_20000_characters(): void {
		$doc = $this->builder()->core_fields( $this->post( array( 'post_content' => str_repeat( 'é', 25000 ) ) ) );

		$this->assertSame( 20000, mb_strlen( $doc['content'] ) );
	}

	public function test_content_filter_output_is_cleaned(): void {
		$post = $this->post();
		Filters\expectApplied( 'meilisearch_document_content' )->once()->with( 'First paragraph.', $post )->andReturn( '<div>Rendered   by builder</div>' );

		$this->assertSame( 'Rendered by builder', $this->builder()->core_fields( $post )['content'] );
	}

	public function test_thumbnail_url_is_kept_when_present(): void {
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://example.test/t.jpg' );

		$this->assertSame( 'https://example.test/t.jpg', $this->builder()->core_fields( $this->post() )['thumbnail_url'] );
	}

	public function test_taxonomy_fields_include_ancestor_ids_and_normalized_names(): void {
		$this->terms = array(
			'category'     => array(
				(object) array(
					'term_id' => 3,
					'name'    => 'Child &amp; Co',
				),
				(object) array(
					'term_id' => 9,
					'name'    => 'Other',
				),
			),
			'pa_couleur-é' => new \WP_Error( 'invalid_taxonomy', 'nope' ),
		);

		$fields = $this->builder()->taxonomy_fields( $this->post(), array( 'category', 'pa_couleur-é', '---' ) );

		$this->assertSame(
			array(
				'tax_category'       => array( 'Child & Co', 'Other' ),
				'tax_category_ids'   => array( 1, 2, 3, 9 ),
				'tax_pa_couleur'     => array(),
				'tax_pa_couleur_ids' => array(),
			),
			$fields
		);
	}

	public function test_meta_fields_scalars_only_with_numeric_casts(): void {
		$this->meta = array(
			'price'  => '49.90',
			'my.key' => 'first',
			'my-key' => 'second (collides, skipped)',
			'zip'    => '01234',
			'flag'   => true,
			'list'   => array( 'a', 'b' ),
			'big'    => '99999999999999999999',
			'ratio'  => '-12',
		);

		$fields = $this->builder()->meta_fields( 42, array( 'price', 'my.key', 'my-key', 'zip', 'flag', 'list', 'missing', 'big', 'ratio', '...' ) );

		$this->assertSame(
			array(
				'meta_price'  => 49.9,
				'meta_my_key' => 'first',
				'meta_zip'    => '01234',
				'meta_flag'   => true,
				'meta_big'    => '99999999999999999999',
				'meta_ratio'  => -12,
			),
			$fields
		);
	}

	public function test_build_merges_everything_and_applies_document_filter(): void {
		$this->meta = array( 'price' => '10' );
		$post       = $this->post();
		Filters\expectApplied( 'meilisearch_document' )
			->once()
			->with( \Mockery::type( 'array' ), $post, 'content' )
			->andReturnUsing(
				static function ( array $doc ): array {
					$doc['extra'] = 'x';
					return $doc;
				}
			);

		$doc = $this->builder()->build( $post );

		$this->assertIsArray( $doc );
		$this->assertSame( 10, $doc['meta_price'] );
		$this->assertSame( array(), $doc['tax_category'] );
		$this->assertSame( 'x', $doc['extra'] );
		$this->assertArrayNotHasKey( 'tax_', $doc );
	}

	public function test_invalid_utf8_is_scrubbed(): void {
		// Real wp_json_encode() repairs invalid UTF-8 instead of failing, so the scrubbing must
		// already have happened in clean_text()/meta_value(): mimic the repairing encoder here.
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $value ) => json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE )
		);

		$this->meta  = array( 'price' => "12\xC3\x28" );
		$this->terms = array(
			'category' => array(
				(object) array(
					'term_id' => 9,
					'name'    => "Caf\xE9",
				),
			),
		);
		$post        = $this->post(
			array(
				'post_title'   => "Bad \xFF title",
				'post_content' => "Legacy caf\xE9 import \xC3\x28 bytes",
				'post_excerpt' => "Excerpt \x80",
			)
		);

		$doc = $this->builder()->build( $post );

		$this->assertIsArray( $doc );
		foreach ( array( 'title', 'content', 'excerpt', 'meta_price' ) as $field ) {
			$this->assertTrue( mb_check_encoding( (string) $doc[ $field ], 'UTF-8' ), $field . ' must be valid UTF-8' );
		}
		$this->assertTrue( mb_check_encoding( $doc['tax_category'][0], 'UTF-8' ) );
		$this->assertStringStartsWith( 'Legacy caf', $doc['content'] );
		$this->assertStringStartsWith( 'Bad ', $doc['title'] );
		$this->assertStringContainsString( 'Excerpt', $doc['excerpt'] );
		$this->assertStringStartsWith( '12', (string) $doc['meta_price'] );
		$this->assertStringContainsString( 'Caf', $doc['tax_category'][0] );
		// A strict (non-repairing) encoder must accept the document as is.
		$this->assertNotFalse( json_encode( $doc ), 'The document must be JSON-encodable without repair.' );
	}

	public function test_clean_text_scrubs_invalid_utf8_itself(): void {
		$this->assertTrue( mb_check_encoding( ContentDocumentBuilder::clean_text( "a\xFFb\xC3\x28<b>c</b>" ), 'UTF-8' ) );
		$this->assertSame( 'a & b', ContentDocumentBuilder::clean_text( " a &amp;\u{00A0}\n b " ) );
	}

	public function test_unencodable_document_returns_null(): void {
		Filters\expectApplied( 'meilisearch_document' )->once()->andReturnUsing(
			static function ( array $doc ): array {
				$doc['score'] = NAN;
				return $doc;
			}
		);

		$this->assertNull( $this->builder()->build( $this->post() ) );
	}

	public function test_filter_returning_non_array_returns_null(): void {
		Filters\expectApplied( 'meilisearch_document' )->once()->andReturn( false );

		$this->assertNull( $this->builder()->build( $this->post() ) );
	}
}
