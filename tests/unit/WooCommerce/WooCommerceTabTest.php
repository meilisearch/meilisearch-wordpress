<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Meilisearch\WordPress\WooCommerce\WooCommerceTab;

final class WooCommerceTabTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->stub_options();
		Functions\when( 'wc_get_attribute_taxonomy_names' )->justReturn( array( 'pa_color', 'pa_size' ) );
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			}
		);
	}

	public function test_identity(): void {
		$tab = new WooCommerceTab( new Options() );

		self::assertSame( 'woocommerce', $tab->slug() );
		self::assertSame( 'WooCommerce', $tab->label() );
		self::assertFalse( $tab->is_visible() ); // WooCommerce is not loaded in unit tests.
	}

	public function test_register_settings_uses_its_own_group_and_sanitizer(): void {
		$calls = array();
		Functions\when( 'register_setting' )->alias(
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			}
		);

		( new WooCommerceTab( new Options() ) )->register_settings();

		self::assertCount( 1, $calls );
		self::assertSame( 'meilisearch_woocommerce', $calls[0][0] );
		self::assertSame( Options::WOOCOMMERCE, $calls[0][1] );
		self::assertSame( array( WooCommerceTab::class, 'sanitize' ), $calls[0][2]['sanitize_callback'] );
		self::assertSame( 'array', $calls[0][2]['type'] );
	}

	public function test_sanitize_non_array_gives_disabled_defaults(): void {
		self::assertSame(
			array(
				'enabled'           => false,
				'attributes'        => array(),
				'custom_attributes' => false,
				'variation_skus'    => false,
			),
			WooCommerceTab::sanitize( 'garbage' )
		);
	}

	public function test_sanitize_all_attributes_is_null(): void {
		$clean = WooCommerceTab::sanitize(
			array(
				'enabled'        => '1',
				'all_attributes' => '1',
				'attributes'     => array( 'pa_color' ),
			)
		);

		self::assertTrue( $clean['enabled'] );
		self::assertNull( $clean['attributes'] );
	}

	public function test_sanitize_keeps_only_known_attribute_taxonomies(): void {
		$clean = WooCommerceTab::sanitize(
			array(
				'attributes' => array( 'pa_size', 'pa_unknown', '<b>pa_color</b>', array( 'nested' ), 'pa_size' ),
			)
		);

		self::assertSame( array( 'pa_size', 'pa_color' ), $clean['attributes'] );
	}

	/**
	 * @dataProvider idempotency_inputs
	 */
	public function test_sanitize_is_idempotent( $input ): void {
		$once = WooCommerceTab::sanitize( $input );

		self::assertSame( $once, WooCommerceTab::sanitize( $once ) );
	}

	public function idempotency_inputs(): array {
		return array(
			'all'      => array(
				array(
					'enabled'        => '1',
					'all_attributes' => '1',
				),
			),
			'selected' => array(
				array(
					'attributes'     => array( 'pa_color' ),
					'variation_skus' => 'on',
				),
			),
			'none'     => array( array() ),
			'invalid'  => array( null ),
		);
	}

	public function test_changing_attributes_flags_products_reindex(): void {
		WooCommerceTab::sanitize(
			array(
				'enabled'    => '1',
				'attributes' => array( 'pa_color' ),
			)
		);

		self::assertTrue( ( new Options() )->needs_reindex( 'products' ) );
	}

	public function test_unchanged_settings_do_not_flag_reindex(): void {
		WooCommerceTab::sanitize(
			array(
				'enabled'        => '1',
				'all_attributes' => '1',
				'variation_skus' => '1',
			)
		);

		self::assertFalse( ( new Options() )->needs_reindex( 'products' ) );
	}

	public function test_disabling_products_does_not_flag_reindex(): void {
		WooCommerceTab::sanitize(
			array(
				'all_attributes' => '1',
				'variation_skus' => '1',
			)
		);

		self::assertFalse( ( new Options() )->needs_reindex( 'products' ) );
	}

	public function test_render_lists_attribute_checkboxes(): void {
		$this->stub_options( array( Options::WOOCOMMERCE => array( 'attributes' => array( 'pa_size' ) ) ) );
		Functions\when( 'wc_get_attribute_taxonomies' )->justReturn(
			array(
				'id:1' => (object) array(
					'attribute_name'  => 'color',
					'attribute_label' => 'Color',
				),
				'id:2' => (object) array(
					'attribute_name'  => 'size',
					'attribute_label' => 'Size',
				),
			)
		);
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static function ( $name ) {
				return 'pa_' . $name;
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, $display = true ) {
				$result = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
				if ( $display ) {
					echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				return $result;
			}
		);

		ob_start();
		( new WooCommerceTab( new Options() ) )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="meilisearch_woocommerce[attributes][]" value="pa_color"  />', $html );
		self::assertStringContainsString( 'name="meilisearch_woocommerce[attributes][]" value="pa_size"  checked=\'checked\' />', $html );
		self::assertStringContainsString( 'name="meilisearch_woocommerce[all_attributes]" value="1"  />', $html );
	}
}
