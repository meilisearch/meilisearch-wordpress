<?php
/**
 * WooCommerce settings tab (spec § 7.1).
 *
 * @package Meilisearch\WordPress
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Admin\Tab;
use Meilisearch\WordPress\Settings\Options;

/**
 * "WooCommerce" tab: index products, attributes to index, custom attributes, variation SKUs.
 * Its own Settings API group, so saving it never touches another tab's option.
 */
final class WooCommerceTab implements Tab {

	public const GROUP = 'meilisearch_woocommerce';

	/**
	 * Constructor.
	 *
	 * @param Options $options Plugin options.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Tab slug.
	 */
	public function slug(): string {
		return 'woocommerce';
	}

	/**
	 * Tab label.
	 */
	public function label(): string {
		return __( 'WooCommerce', 'meilisearch' );
	}

	/**
	 * Only shown when WooCommerce is active.
	 */
	public function is_visible(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Registers the option with its sanitize callback (called on admin_init by Menu).
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Options::WOOCOMMERCE,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => Options::defaults()[ Options::WOOCOMMERCE ],
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Renders the form.
	 */
	public function render(): void {
		$settings   = $this->options->woocommerce();
		$attributes = wc_get_attribute_taxonomies();
		$name       = Options::WOOCOMMERCE;
		$all        = null === $settings['attributes'];
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Index products', 'meilisearch' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
							<?php esc_html_e( 'Index WooCommerce products in a dedicated products index', 'meilisearch' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Attributes', 'meilisearch' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Global attributes to index', 'meilisearch' ); ?></legend>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[all_attributes]" value="1" <?php checked( $all ); ?> />
								<?php esc_html_e( 'All global attributes, including attributes created later', 'meilisearch' ); ?>
							</label>
							<br />
							<?php foreach ( $attributes as $attribute ) : ?>
								<?php $taxonomy = wc_attribute_taxonomy_name( (string) $attribute->attribute_name ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[attributes][]" value="<?php echo esc_attr( $taxonomy ); ?>" <?php checked( $all || in_array( $taxonomy, (array) $settings['attributes'], true ) ); ?> />
									<?php echo esc_html( (string) $attribute->attribute_label ); ?>
									<code><?php echo esc_html( $taxonomy ); ?></code>
								</label>
								<br />
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Selected attributes are searchable and can be used by layered navigation filters on search results. Individual choices apply only when "All global attributes" is unchecked.', 'meilisearch' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Custom attributes', 'meilisearch' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[custom_attributes]" value="1" <?php checked( $settings['custom_attributes'] ); ?> />
							<?php esc_html_e( 'Store custom (per-product) attribute values in documents', 'meilisearch' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Custom attribute values are stored for display only; they are not searchable or filterable in this version.', 'meilisearch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Variation SKUs', 'meilisearch' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[variation_skus]" value="1" <?php checked( $settings['variation_skus'] ); ?> />
							<?php esc_html_e( 'Make variation SKUs searchable', 'meilisearch' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Sanitizes the option. Idempotent: its own output sanitizes to itself (WordPress may call it twice).
	 * Flags a products reindex when indexing is enabled or what gets indexed changes.
	 *
	 * @param mixed $input Raw input.
	 * @return array{enabled: bool, attributes: ?list<string>, custom_attributes: bool, variation_skus: bool}
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		$all   = ! empty( $input['all_attributes'] ) || ( array_key_exists( 'attributes', $input ) && null === $input['attributes'] );

		$attributes = null;
		if ( ! $all ) {
			$known      = wc_get_attribute_taxonomy_names();
			$attributes = array();
			foreach ( (array) ( $input['attributes'] ?? array() ) as $taxonomy ) {
				$taxonomy = sanitize_text_field( is_scalar( $taxonomy ) ? (string) $taxonomy : '' );
				if ( in_array( $taxonomy, $known, true ) ) {
					$attributes[] = $taxonomy;
				}
			}
			$attributes = array_values( array_unique( $attributes ) );
		}

		$clean = array(
			'enabled'           => ! empty( $input['enabled'] ),
			'attributes'        => $attributes,
			'custom_attributes' => ! empty( $input['custom_attributes'] ),
			'variation_skus'    => ! empty( $input['variation_skus'] ),
		);

		$options = new Options();
		$current = $options->woocommerce();
		if ( ( $clean['enabled'] && ! $current['enabled'] )
			|| $clean['attributes'] !== $current['attributes']
			|| $clean['custom_attributes'] !== $current['custom_attributes']
			|| $clean['variation_skus'] !== $current['variation_skus'] ) {
			$options->flag_reindex( 'products', true );
		}

		return $clean;
	}
}
