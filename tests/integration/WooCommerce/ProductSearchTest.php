<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\WooCommerce;

use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Integration\TestCase;

require_once __DIR__ . '/CreatesProducts.php';

/**
 * Real main-query product searches. The query "shirtt" contains a typo that MySQL LIKE
 * cannot match, so any result proves Meilisearch answered the query.
 */
final class ProductSearchTest extends TestCase {

	use CreatesProducts;

	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$this->skip_without_woocommerce();

		// A plain ?s=&post_type=product search is not a product archive for WooCommerce 11.1, so it does not run
		// product_query() itself. Shop contexts that do (product taxonomy archives, the shop page) are simulated.
		add_action( 'pre_get_posts', array( $this, 'run_product_query' ), 10 );

		$color = $this->create_attribute( 'color', array( 'Red', 'Blue' ) );
		$this->enable_products();
		update_option(
			Options::SEARCH,
			array(
				'replace'        => true,
				'highlight'      => false,
				'embedder'       => '',
				'semantic_ratio' => 0.5,
				'autocomplete'   => false,
			)
		);

		$tees      = wp_insert_term( 'Tees', 'product_cat', array( 'slug' => 'tees' ) );
		$in_tees   = array( 'category_ids' => array( (int) $tees['term_id'] ) );
		$this->ids = array(
			'alpha'   => $this->create_simple_product( 'Meili Shirt Alpha', '30', $in_tees, $color, array( 'Red' ) )->get_id(),
			'bravo'   => $this->create_simple_product( 'Meili Shirt Bravo', '10', array(), $color, array( 'Blue' ) )->get_id(),
			'charlie' => $this->create_simple_product( 'Meili Shirt Charlie', '20', $in_tees, $color, array( 'Red' ) )->get_id(),
			'hidden'  => $this->create_simple_product( 'Meili Shirt Hidden', '5', array( 'catalog_visibility' => 'hidden' ), $color, array( 'Red' ) )->get_id(),
		);
		$this->sync_products();
	}

	public function tear_down(): void {
		remove_action( 'pre_get_posts', array( $this, 'run_product_query' ), 10 );
		if ( class_exists( 'WooCommerce' ) ) {
			$this->remove_attributes();
			\WC_Query::reset_chosen_attributes();
		}
		parent::tear_down();
	}

	/**
	 * Applies WooCommerce's product query (catalog ordering, visibility, layered nav) to the main query.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function run_product_query( \WP_Query $query ): void {
		if ( $query->is_main_query() ) {
			WC()->query->product_query( $query );
		}
	}

	/**
	 * Runs a front-end main query and returns the result IDs in order.
	 *
	 * @return list<int>
	 */
	private function search( array $args ): array {
		\WC_Query::reset_chosen_attributes();
		$defaults = array(
			's'         => 'shirtt',
			'post_type' => 'product',
		);
		$this->go_to( add_query_arg( array_merge( $defaults, $args ), home_url( '/' ) ) );
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	public function test_orderby_price_returns_products_in_price_order(): void {
		$ids = $this->search( array( 'orderby' => 'price' ) );

		$this->assertSame( array( $this->ids['bravo'], $this->ids['charlie'], $this->ids['alpha'] ), $ids );
		$this->assertSame( 3, (int) $GLOBALS['wp_query']->found_posts );
	}

	public function test_orderby_price_desc(): void {
		$this->assertSame( array( $this->ids['alpha'], $this->ids['charlie'], $this->ids['bravo'] ), $this->search( array( 'orderby' => 'price-desc' ) ) );
	}

	public function test_min_and_max_price_filter(): void {
		$ids = $this->search(
			array(
				'orderby'   => 'price',
				'min_price' => '15',
				'max_price' => '35',
			)
		);

		$this->assertSame( array( $this->ids['charlie'], $this->ids['alpha'] ), $ids );
	}

	public function test_layered_nav_attribute_filter(): void {
		$ids = $this->search(
			array(
				'orderby'      => 'price',
				'filter_color' => 'red',
			)
		);

		$this->assertSame( array( $this->ids['charlie'], $this->ids['alpha'] ), $ids );
	}

	public function test_product_category_clause_is_translated_by_the_query_translator(): void {
		$ids = $this->search(
			array(
				'orderby'     => 'price',
				'product_cat' => 'tees',
			)
		);

		$this->assertSame( array( $this->ids['charlie'], $this->ids['alpha'] ), $ids );
	}

	public function test_hidden_product_never_appears(): void {
		$this->assertNotContains( $this->ids['hidden'], $this->search( array() ) );
		$this->assertNotContains( $this->ids['hidden'], $this->search( array( 's' => 'meili shirtt hidden' ) ) );
	}
}
