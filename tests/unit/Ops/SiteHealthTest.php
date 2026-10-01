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

	public function test_not_checked_is_recommended(): void {
		$result = SiteHealth::not_checked( SiteHealth::TEST_DOCUMENTS, 'Meilisearch is unreachable.' );

		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( 'meilisearch_documents', $result['test'] );
	}
}
