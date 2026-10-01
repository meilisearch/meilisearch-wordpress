<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\ContentTab;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class ContentTabTest extends TestCase {

	/**
	 * In-memory options table.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = array();

	protected function set_up(): void {
		parent::set_up();
		$this->store = array(
			Options::CONTENT => array(
				'post_types' => array( 'post' ),
				'taxonomies' => array( 'post' => array( 'category' ) ),
				'meta_keys'  => array( 'post' => array( 'price' ) ),
			),
			Options::STATE   => array(),
		);
		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->store[ $name ] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) ) );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	public function test_identity_and_hooks(): void {
		$tab = new ContentTab( new Options() );
		$tab->register();

		$this->assertSame( 'content', $tab->slug() );
		$this->assertSame( 'Content', $tab->label() );
		$this->assertTrue( $tab->is_visible() );
		$this->assertNotFalse( has_action( 'update_option_meilisearch_content', array( $tab, 'on_update' ) ) );
		$this->assertNotFalse( has_action( 'add_option_meilisearch_content', array( $tab, 'on_add' ) ) );
	}

	public function test_register_settings_uses_its_own_group(): void {
		$registered = array();
		Functions\when( 'register_setting' )->alias(
			static function ( $group, $name, $args ) use ( &$registered ) {
				$registered[] = array( $group, $name, $args['sanitize_callback'] );
			}
		);

		( new ContentTab( new Options() ) )->register_settings();

		$this->assertSame( array( array( 'meilisearch_content', Options::CONTENT, array( ContentTab::class, 'sanitize' ) ) ), $registered );
	}

	public function test_sanitize_raw_form_shape(): void {
		$out = ContentTab::sanitize(
			array(
				'post_types' => array( 'post', 'Page', 'attachment', 'product', 'product_variation', 'post', '<b>', array( 'x' ) ),
				'taxonomies' => array(
					'post'     => array( 'category', 'Post_Tag', 'category', '' ),
					'page'     => 'not-a-list',
					'unlisted' => array( 'category' ),
				),
				'meta_keys'  => array(
					'post' => "price\r\n  my.key \n\nmy-key\n---\n<b>bold</b>\n_price",
					'page' => array( 'subtitle' ),
				),
			)
		);

		$this->assertSame(
			array(
				'post_types' => array( 'post', 'page', 'b' ),
				'taxonomies' => array(
					'post' => array( 'category', 'post_tag' ),
					'page' => array(),
					'b'    => array(),
				),
				'meta_keys'  => array(
					'post' => array( 'price', 'my.key', 'bold' ),
					'page' => array( 'subtitle' ),
					'b'    => array(),
				),
			),
			$out
		);
	}

	public function test_sanitize_is_idempotent(): void {
		$raw  = array(
			'post_types' => array( 'post', 'page' ),
			'taxonomies' => array( 'post' => array( 'category' ) ),
			'meta_keys'  => array( 'post' => "price\nrating" ),
		);
		$once = ContentTab::sanitize( $raw );

		$this->assertSame( $once, ContentTab::sanitize( $once ) );
		$this->assertSame( array( 'price', 'rating' ), $once['meta_keys']['post'] );
	}

	/**
	 * @dataProvider empty_inputs
	 *
	 * @param mixed $input Submitted value.
	 */
	public function test_sanitize_empty_inputs( $input ): void {
		$this->assertSame(
			array(
				'post_types' => array(),
				'taxonomies' => array(),
				'meta_keys'  => array(),
			),
			ContentTab::sanitize( $input )
		);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function empty_inputs(): array {
		return array(
			'null'            => array( null ),
			'string'          => array( 'garbage' ),
			'no post types'   => array( array( 'taxonomies' => array( 'post' => array( 'category' ) ) ) ),
			'post types null' => array( array( 'post_types' => null ) ),
		);
	}

	public function test_changed_field_set_flags_reindex(): void {
		$tab = new ContentTab( new Options() );
		$old = $this->store[ Options::CONTENT ];
		$new = $old;

		$new['meta_keys']['post'][] = 'rating';
		$tab->on_update( $old, $new );

		$this->assertTrue( ( new Options() )->needs_reindex( 'content' ) );
	}

	public function test_same_field_set_in_other_order_does_not_flag(): void {
		$tab = new ContentTab( new Options() );
		$old = array(
			'post_types' => array( 'post', 'page' ),
			'taxonomies' => array( 'post' => array( 'category', 'post_tag' ) ),
			'meta_keys'  => array(),
		);
		$new = array(
			'post_types' => array( 'page', 'post' ),
			'taxonomies' => array( 'post' => array( 'post_tag', 'category' ) ),
			'meta_keys'  => array(),
		);

		$tab->on_update( $old, $new );

		$this->assertFalse( ( new Options() )->needs_reindex( 'content' ) );
	}

	public function test_first_save_flags_reindex(): void {
		$tab = new ContentTab( new Options() );

		$tab->on_add( Options::CONTENT, array( 'post_types' => array() ) );
		$this->assertFalse( ( new Options() )->needs_reindex( 'content' ) );

		$tab->on_add( Options::CONTENT, array( 'post_types' => array( 'post' ) ) );
		$this->assertTrue( ( new Options() )->needs_reindex( 'content' ) );
	}

	public function test_render_lists_public_types_without_excluded_ones(): void {
		Functions\when( 'settings_fields' )->alias(
			static function ( $group ) {
				echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
			}
		);
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->alias( static fn( $a, $b ) => $a === $b ? ' checked="checked"' : '' );
		Functions\when( 'esc_textarea' )->alias( static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\expect( 'get_post_types' )
			->once()
			->with( array( 'public' => true ), 'objects' )
			->andReturn(
				array(
					'post'       => (object) array(
						'name'  => 'post',
						'label' => 'Posts',
					),
					'page'       => (object) array(
						'name'  => 'page',
						'label' => 'Pages',
					),
					'attachment' => (object) array(
						'name'  => 'attachment',
						'label' => 'Media',
					),
					'product'    => (object) array(
						'name'  => 'product',
						'label' => 'Products',
					),
				)
			);
		Functions\when( 'get_object_taxonomies' )->alias(
			static fn( $post_type ) => 'post' === $post_type
				? array(
					'category'    => (object) array(
						'name'    => 'category',
						'label'   => 'Categories',
						'public'  => true,
						'show_ui' => true,
					),
					'post_format' => (object) array(
						'name'    => 'post_format',
						'label'   => 'Format',
						'public'  => false,
						'show_ui' => false,
					),
				)
				: array()
		);

		ob_start();
		( new ContentTab( new Options() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="meilisearch_content"', $html );
		$this->assertStringContainsString( 'name="meilisearch_content[post_types][]" value="post" checked="checked"', $html );
		$this->assertStringContainsString( 'name="meilisearch_content[post_types][]" value="page" />', $html );
		$this->assertStringNotContainsString( 'value="attachment"', $html );
		$this->assertStringNotContainsString( 'value="product"', $html );
		$this->assertStringContainsString( 'name="meilisearch_content[taxonomies][post][]" value="category" checked="checked"', $html );
		$this->assertStringNotContainsString( 'post_format', $html );
		$this->assertStringContainsString( 'name="meilisearch_content[meta_keys][post]">price</textarea>', $html );
	}
}
