<?php
/**
 * Site Health tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Ops;

use Meilisearch\WordPress\Ops\SiteHealth;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class SiteHealthTest extends TestCase {

	private const URL = 'https://example.test/wp-admin/admin.php?page=meilisearch&tab=connection';

	public function test_results_have_the_core_site_health_shape(): void {
		$result = SiteHealth::evaluate_connection( true, '1.53.1', '', self::URL );

		self::assertSame( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ), array_keys( $result ) );
		self::assertSame( array( 'label', 'color' ), array_keys( $result['badge'] ) );
		self::assertSame( 'meilisearch_connection', $result['test'] );
		self::assertStringStartsWith( '<p>', $result['description'] );
	}

	public function test_unconfigured_connection_is_recommended_with_a_settings_link(): void {
		$result = SiteHealth::evaluate_connection( false, '', '', self::URL );

		self::assertSame( 'recommended', $result['status'] );
		self::assertStringContainsString( 'tab=connection', $result['actions'] );
	}

	public function test_a_key_that_cannot_read_the_version_is_connected(): void {
		$result = SiteHealth::evaluate_connection( true, SiteHealth::VERSION_UNREADABLE, '', self::URL );

		self::assertSame( 'good', $result['status'] );
		self::assertStringContainsString( 'cannot read the Meilisearch version', $result['description'] );
	}

	public function test_unreachable_meilisearch_is_critical(): void {
		$result = SiteHealth::evaluate_connection( true, '', 'cURL error 7: Failed to connect', self::URL );

		self::assertSame( 'critical', $result['status'] );
		self::assertStringContainsString( 'Failed to connect', $result['description'] );
	}

	/**
	 * @dataProvider version_provider
	 */
	public function test_version_gate( string $version, string $expected ): void {
		self::assertSame( $expected, SiteHealth::evaluate_connection( true, $version, '', self::URL )['status'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function version_provider(): array {
		return array(
			'too old'         => array( '1.33.2', 'critical' ),
			'unknown'         => array( '', 'critical' ),
			'minimum'         => array( '1.34.0', 'good' ),
			'current release' => array( '1.53.1', 'good' ),
		);
	}

	/**
	 * @dataProvider drift_provider
	 */
	public function test_document_drift_threshold( int $indexable, int $documents, string $expected ): void {
		$counts = array(
			'content' => array(
				'uid'       => 'wp_abc123_content',
				'documents' => $documents,
				'indexable' => $indexable,
			),
		);

		self::assertSame( $expected, SiteHealth::evaluate_documents( $counts, self::URL )['status'] );
	}

	/**
	 * @return array<string, array{int, int, string}>
	 */
	public static function drift_provider(): array {
		return array(
			'equal'        => array( 100, 100, 'good' ),
			'2 % missing'  => array( 100, 98, 'good' ),
			'3 % missing'  => array( 100, 97, 'recommended' ),
			'2 % extra'    => array( 100, 102, 'good' ),
			'3 % extra'    => array( 100, 103, 'recommended' ),
			'empty site'   => array( 0, 0, 'good' ),
			'orphans only' => array( 0, 3, 'recommended' ),
		);
	}

	/**
	 * Candidates the last completed reindex excluded (hidden products, vetoed posts) are not drift.
	 *
	 * @dataProvider excluded_provider
	 */
	public function test_excluded_candidates_are_not_drift( int $indexable, int $excluded, int $documents, string $expected ): void {
		$counts = array(
			'content' => array(
				'uid'       => 'wp_abc123_content',
				'documents' => $documents,
				'indexable' => $indexable,
				'excluded'  => $excluded,
			),
		);

		self::assertSame( $expected, SiteHealth::evaluate_documents( $counts, self::URL )['status'] );
	}

	/**
	 * @return array<string, array{int, int, int, string}>
	 */
	public static function excluded_provider(): array {
		return array(
			'all excluded accounted for' => array( 1000, 100, 900, 'good' ),
			'some excluded back in'      => array( 1000, 100, 950, 'good' ),
			'2 % below the expected'     => array( 1000, 100, 882, 'good' ),
			'3 % below the expected'     => array( 1000, 100, 873, 'recommended' ),
			'3 % above the upper bound'  => array( 1000, 100, 1030, 'recommended' ),
			'everything excluded'        => array( 10, 10, 0, 'good' ),
			'more excluded than exist'   => array( 5, 10, 0, 'good' ),
		);
	}

	public function test_drift_below_the_expected_count_reports_the_expected_count(): void {
		$counts = array(
			'content' => array(
				'uid'       => 'wp_abc123_content',
				'documents' => 500,
				'indexable' => 1000,
				'excluded'  => 100,
			),
		);

		self::assertStringContainsString( 'holds 500 documents but WordPress has 900 searchable items', SiteHealth::evaluate_documents( $counts, self::URL )['description'] );
	}

	public function test_missing_index_is_recommended(): void {
		$counts = array(
			'content' => array(
				'uid'       => 'wp_abc123_content',
				'documents' => null,
				'indexable' => 10,
			),
		);
		$result = SiteHealth::evaluate_documents( $counts, self::URL );

		self::assertSame( 'recommended', $result['status'] );
		self::assertStringContainsString( 'wp_abc123_content', $result['description'] );
	}

	public function test_drift_ratio(): void {
		self::assertSame( 0.03, SiteHealth::drift_ratio( 97, 100 ) );
		self::assertSame( 3.0, SiteHealth::drift_ratio( 3, 0 ) );
	}

	public function test_missing_settings_are_listed(): void {
		$result = SiteHealth::evaluate_settings( array( 'wp_abc123_content' => array( 'tax_category_ids', 'date' ) ), self::URL );

		self::assertSame( 'recommended', $result['status'] );
		self::assertStringContainsString( 'tax_category_ids, date', $result['description'] );
		self::assertSame( 'good', SiteHealth::evaluate_settings( array( 'wp_abc123_content' => array() ), self::URL )['status'] );
	}

	/**
	 * @dataProvider queue_provider
	 */
	public function test_queue_thresholds( int $pending, int $failed, string $expected ): void {
		self::assertSame( $expected, SiteHealth::evaluate_queue( $pending, $failed, self::URL )['status'] );
	}

	/**
	 * @return array<string, array{int, int, string}>
	 */
	public static function queue_provider(): array {
		return array(
			'idle'         => array( 0, 0, 'good' ),
			'at threshold' => array( 500, 0, 'good' ),
			'backlog'      => array( 501, 0, 'recommended' ),
			'one failure'  => array( 0, 1, 'recommended' ),
		);
	}

	/**
	 * @dataProvider search_key_provider
	 *
	 * @param array<string, mixed>|null $details
	 */
	public function test_search_key_scope( bool $autocomplete, string $key, ?array $details, string $expected ): void {
		$allowed = array( 'wp_abc123_content', 'wp_abc123_products' );

		self::assertSame( $expected, SiteHealth::evaluate_search_key( $autocomplete, $key, $details, $allowed, 'forbidden', self::URL )['status'] );
	}

	public function test_unsafe_search_key_without_details_is_critical(): void {
		$result = SiteHealth::evaluate_search_key( true, 'k', null, array( 'wp_abc123_content' ), 'api_key_not_found', self::URL, true );

		self::assertSame( 'critical', $result['status'] );
		self::assertStringContainsString( 'api_key_not_found', $result['description'] );
	}

	/**
	 * @return array<string, array{bool, string, array<string, mixed>|null, string}>
	 */
	public static function search_key_provider(): array {
		return array(
			'no key, autocomplete off' => array( false, '', null, 'good' ),
			'no key, autocomplete on'  => array( true, '', null, 'recommended' ),
			'cannot verify'            => array( true, 'k', null, 'recommended' ),
			'restricted to both'       => array(
				true,
				'k',
				array(
					'actions' => array( 'search' ),
					'indexes' => array( 'wp_abc123_content', 'wp_abc123_products' ),
				),
				'good',
			),
			'restricted to one'        => array(
				true,
				'k',
				array(
					'actions' => array( 'search' ),
					'indexes' => array( 'wp_abc123_content' ),
				),
				'good',
			),
			'extra action'             => array(
				true,
				'k',
				array(
					'actions' => array( 'search', 'documents.add' ),
					'indexes' => array( 'wp_abc123_content' ),
				),
				'critical',
			),
			'wildcard action'          => array(
				true,
				'k',
				array(
					'actions' => array( '*' ),
					'indexes' => array( 'wp_abc123_content' ),
				),
				'critical',
			),
			'all indexes'              => array(
				true,
				'k',
				array(
					'actions' => array( 'search' ),
					'indexes' => array( '*' ),
				),
				'critical',
			),
			'pattern'                  => array(
				true,
				'k',
				array(
					'actions' => array( 'search' ),
					'indexes' => array( 'wp_*' ),
				),
				'critical',
			),
			'no index'                 => array(
				true,
				'k',
				array(
					'actions' => array( 'search' ),
					'indexes' => array(),
				),
				'critical',
			),
		);
	}

	public function test_reindex_flags_and_failures(): void {
		self::assertSame( 'good', SiteHealth::evaluate_reindex( array( 'content' => false ), array(), self::URL )['status'] );
		self::assertSame( 'recommended', SiteHealth::evaluate_reindex( array( 'content' => true ), array(), self::URL )['status'] );

		$failed = SiteHealth::evaluate_reindex( array( 'content' => false ), array( 'products' => 'task 42 failed' ), self::URL );
		self::assertSame( 'recommended', $failed['status'] );
		self::assertStringContainsString( 'task 42 failed', $failed['description'] );
	}

	public function test_stalled_run_has_its_own_wording(): void {
		$result = SiteHealth::evaluate_reindex( array( 'content' => false ), array(), self::URL, array( 'content' ) );

		self::assertSame( 'recommended', $result['status'] );
		self::assertStringContainsString( 'stopped making progress', $result['description'] );
		self::assertStringNotContainsString( 'failed', $result['description'] );
	}

	public function test_not_checked_is_recommended(): void {
		$result = SiteHealth::not_checked( SiteHealth::TEST_DOCUMENTS, 'Meilisearch is unreachable.' );

		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( 'meilisearch_documents', $result['test'] );
	}

	public function test_search_coverage(): void {
		self::assertSame( 'good', SiteHealth::evaluate_coverage( false, array( 'event' ), self::URL )['status'] );
		self::assertSame( 'good', SiteHealth::evaluate_coverage( true, array(), self::URL )['status'] );

		$result = SiteHealth::evaluate_coverage( true, array( 'event', 'product' ), self::URL );
		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( SiteHealth::TEST_COVERAGE, $result['test'] );
		self::assertStringContainsString( 'event, product', $result['description'] );
	}

	public function test_index_displaying_every_attribute_is_recommended_when_autocomplete_is_on(): void {
		$exposed = array( 'wp_test_products' => array( 'id', 'title', 'price' ) );

		self::assertSame( 'good', SiteHealth::evaluate_displayed( true, array() )['status'] );
		self::assertSame( 'good', SiteHealth::evaluate_displayed( false, $exposed )['status'], 'No key reaches visitors without autocomplete.' );

		$result = SiteHealth::evaluate_displayed( true, $exposed );
		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( SiteHealth::TEST_DISPLAYED, $result['test'] );
		self::assertStringContainsString( 'wp_test_products: id, title, price.', $result['description'] );
		self::assertStringContainsString( 'full reindex', $result['description'] );
	}

	public function test_sql_filter_callbacks_are_recommended_only_when_search_is_replaced(): void {
		$callbacks = array( 'posts_where: Groups_Post_Access::posts_where (plugin groups)' );

		self::assertSame( 'good', SiteHealth::evaluate_sql_filters( false, $callbacks, self::URL )['status'] );
		self::assertSame( 'good', SiteHealth::evaluate_sql_filters( true, array(), self::URL )['status'] );

		$result = SiteHealth::evaluate_sql_filters( true, $callbacks, self::URL );
		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( SiteHealth::TEST_SQL, $result['test'] );
		self::assertStringContainsString( 'Groups_Post_Access::posts_where (plugin groups)', $result['description'] );
		self::assertStringContainsString( 'meilisearch_should_intercept', $result['description'] );
		self::assertStringContainsString( 'meilisearch_should_index_post', $result['description'] );
	}

	public function test_long_sql_filter_lists_are_truncated(): void {
		$callbacks = array_map( static fn ( int $i ): string => 'posts_where: cb' . $i, range( 1, 13 ) );

		$description = SiteHealth::evaluate_sql_filters( true, $callbacks, self::URL )['description'];

		self::assertStringContainsString( 'cb10; 3 more', $description );
		self::assertStringNotContainsString( 'cb11', $description );
	}

	public function test_browser_host_warning_is_recommended(): void {
		self::assertSame( 'good', SiteHealth::evaluate_browser_host( null, self::URL )['status'] );

		$result = SiteHealth::evaluate_browser_host( 'Browsers block http://x.', self::URL );
		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( SiteHealth::TEST_BROWSER, $result['test'] );
		self::assertSame( '<p>Browsers block http://x.</p>', $result['description'] );
	}
}
