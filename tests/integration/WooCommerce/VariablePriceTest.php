<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\WooCommerce;

use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Search\CircuitBreaker;
use Meilisearch\WordPress\Search\Interceptor;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Integration\TestCase;

require_once __DIR__ . '/CreatesProducts.php';

/**
 * Price filter and price ordering of a variable product (variations at 10 and 50), compared with
 * WooCommerce's own database query: the filter matches on price-range overlap and "price: high to
 * low" orders by the highest price (wc_product_meta_lookup min_price / max_price).
 */
final class VariablePriceTest extends TestCase {

	use CreatesProducts;

	/** @var array<string, int> */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$this->skip_without_woocommerce();
		$color = $this->create_attribute( 'color', array( 'Red', 'Blue' ) );
		$this->enable_products();
		$this->replace( true );
		$this->ids = array(
			'alpha'   => $this->create_simple_product( 'Meili Shirt Alpha', '30', array(), $color, array( 'Red' ) )->get_id(),
			'charlie' => $this->create_simple_product( 'Meili Shirt Charlie', '20', array(), $color, array( 'Red' ) )->get_id(),
			'var'     => $this->create_variable_product(
				'Meili Shirt Variable',
				$color,
				array(
					'Red'  => array(
						'price' => '10',
						'sku'   => 'VP-R',
					),
					'Blue' => array(
						'price' => '50',
						'sku'   => 'VP-B',
					),
				)
			)->get_id(),
		);
		$this->collector_flush();
	}

	public function tear_down(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->remove_attributes();
			\WC_Query::reset_chosen_attributes();
		}
		delete_transient( CircuitBreaker::TRANSIENT );
		parent::tear_down();
	}

	/**
	 * Drops the queued real-time sync of the fixtures: the tests fill the index themselves.
	 */
	private function collector_flush(): void {
		Plugin::instance()->get( 'collector' )->flush();
		$this->run_actions();
		$this->wait_for_tasks();
	}

	private function reindex(): void {
		Plugin::instance()->get( 'reindexer' )->run_sync( 'products', 200, static function (): void {} );
		$this->wait_for_tasks();
	}

	private function replace( bool $on ): void {
		update_option(
			Options::SEARCH,
			array(
				'replace'        => $on,
				'highlight'      => false,
				'embedder'       => '',
				'semantic_ratio' => 0.5,
				'autocomplete'   => false,
			)
		);
	}

	/**
	 * @return array{0: bool, 1: list<string>}
	 */
	private function search( array $args ): array {
		\WC_Query::reset_chosen_attributes();
		$defaults = array(
			's'         => 'shirt',
			'post_type' => 'product',
		);
		$this->go_to( add_query_arg( array_merge( $defaults, $args ), home_url( '/' ) ) );
		$names = array_flip( $this->ids );
		return array(
			(bool) $GLOBALS['wp_query']->get( Interceptor::QUERY_FLAG ),
			array_map( static fn( $id ) => $names[ (int) $id ] ?? (string) $id, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ),
		);
	}

	/**
	 * @return array{0: bool, 1: list<string>}
	 */
	private function database_search( array $args ): array {
		add_filter( 'meilisearch_should_intercept', '__return_false' );
		try {
			return $this->search( $args );
		} finally {
			remove_filter( 'meilisearch_should_intercept', '__return_false' );
		}
	}

	public static function cases(): array {
		return array(
			'range 20-30' => array(
				array(
					'orderby'   => 'price',
					'min_price' => '20',
					'max_price' => '30',
				),
				array( 'var', 'charlie', 'alpha' ),
			),
			'min 40'      => array( array( 'min_price' => '40' ), array( 'var' ) ),
			'max 15'      => array( array( 'max_price' => '15' ), array( 'var' ) ),
			'price-desc'  => array( array( 'orderby' => 'price-desc' ), array( 'var', 'alpha', 'charlie' ) ),
		);
	}

	/**
	 * @dataProvider cases
	 */
	public function test_matches_woocommerce_after_a_products_reindex( array $args, array $expected ): void {
		$this->reindex();

		list( $intercepted, $database ) = $this->database_search( $args );
		$this->assertFalse( $intercepted );
		$this->assertSame( $expected, $database, 'WooCommerce database query.' );

		list( $intercepted, $meili ) = $this->search( $args );
		$this->assertTrue( $intercepted, 'Answered by Meilisearch.' );
		$this->assertSame( $database, $meili );
	}

	public function test_every_product_document_carries_price_max(): void {
		$this->reindex();

		$this->assertSame( 30.0, (float) $this->product_document( $this->ids['alpha'] )['price_max'] );
		$this->assertSame( 10.0, (float) $this->product_document( $this->ids['var'] )['price'] );
		$this->assertSame( 50.0, (float) $this->product_document( $this->ids['var'] )['price_max'] );
	}

	/**
	 * A grouped product spans its children's prices in WooCommerce's lookup table (20 to 30 here); an
	 * external product, like any other non-variable type, has a single price.
	 */
	public function test_grouped_and_external_products_match_woocommerce(): void {
		$grouped = new \WC_Product_Grouped();
		$grouped->set_name( 'Meili Shirt Group' );
		$grouped->set_status( 'publish' );
		$grouped->set_children( array( $this->ids['alpha'], $this->ids['charlie'] ) );
		$grouped->save();
		$this->ids['group'] = $grouped->get_id();

		$external = new \WC_Product_External();
		$external->set_name( 'Meili Shirt External' );
		$external->set_status( 'publish' );
		$external->set_regular_price( '40' );
		$external->set_product_url( 'https://example.com/shirt' );
		$external->save();
		$this->ids['external'] = $external->get_id();
		$this->collector_flush();
		$this->reindex();

		$this->assertSame( 30.0, (float) $this->product_document( $this->ids['group'] )['price_max'] );
		$this->assertSame( 40.0, (float) $this->product_document( $this->ids['external'] )['price_max'] );

		$cases = array(
			array( 'min_price' => '25' ),
			array(
				'min_price' => '35',
				'max_price' => '45',
			),
			array( 'max_price' => '20' ),
			array( 'orderby' => 'price-desc' ),
		);
		foreach ( $cases as $args ) {
			list( , $database )          = $this->database_search( $args );
			list( $intercepted, $meili ) = $this->search( $args );
			$this->assertTrue( $intercepted, 'Answered by Meilisearch.' );
			if ( 'price-desc' === ( $args['orderby'] ?? '' ) ) {
				// The group ties with its dearest child (30): WooCommerce breaks ties by ID, the plugin by relevance.
				$highest = array(
					'var'      => 50,
					'external' => 40,
					'group'    => 30,
					'alpha'    => 30,
					'charlie'  => 20,
				);
				$by_max  = static fn( array $names ): array => array_map( static fn( $name ) => $highest[ $name ], $names );
				$this->assertSame( array( 50, 40, 30, 30, 20 ), $by_max( $database ), 'WooCommerce orders by the highest price.' );
				$this->assertSame( $by_max( $database ), $by_max( $meili ), 'price-desc' );
			}
			sort( $database );
			sort( $meili );
			$this->assertSame( $database, $meili, (string) wp_json_encode( $args ) );
		}
	}

	/**
	 * An index filled before documents carried price_max (documents synced one by one, no products reindex
	 * since): a price_max filter or sort would drop or sink those documents, so the minimum-price translation
	 * is kept until a products reindex has rewritten every document.
	 */
	public function test_an_index_not_reindexed_since_price_max_keeps_the_minimum_price_translation(): void {
		// The last completed products reindex predates price_max: it recorded no build.
		Plugin::instance()->get( 'options' )->set_state( 'products_build', null );
		$this->sync_products();
		// What documents written before price_max existed look like.
		$uid = Plugin::instance()->get( 'names' )->uid( 'products' );
		$old = array();
		foreach ( $this->index_documents( $uid ) as $document ) {
			unset( $document['price_max'] );
			if ( $this->ids['var'] === (int) $document['id'] ) {
				$document['price_max'] = 50.0;
			}
			$old[] = $document;
		}
		$this->meili( 'POST', '/indexes/' . $uid . '/documents', $old );
		$this->wait_for_tasks();

		list( $intercepted, $meili ) = $this->search( array( 'min_price' => '15' ) );
		$this->assertTrue( $intercepted, 'Answered by Meilisearch.' );
		sort( $meili );
		$this->assertSame( array( 'alpha', 'charlie' ), $meili, 'Simple products are not dropped.' );

		list( , $meili ) = $this->search( array( 'orderby' => 'price-desc' ) );
		$this->assertSame( array( 'alpha', 'charlie', 'var' ), $meili, 'Simple products are not sunk.' );
		$this->assertFalse( get_transient( CircuitBreaker::TRANSIENT ), 'No search error.' );
	}
}
