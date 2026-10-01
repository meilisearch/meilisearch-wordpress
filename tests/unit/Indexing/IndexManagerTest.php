<?php
/**
 * Tests for IndexManager.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Api\Response;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Schema;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class IndexManagerTest extends TestCase {

	private const HOST = 'http://meili.test';

	private FakeTransport $transport;

	private Options $options;

	protected function set_up(): void {
		parent::set_up();

		$this->stub_options(
			array(
				Options::CONNECTION => array(
					'host'                => self::HOST,
					'prefix'              => 'wp_test',
					'search_key'          => '',
					'search_key_uid'      => '',
					'delete_on_uninstall' => false,
				),
				Options::ADMIN_KEY  => 'admin-key',
				Options::STATE      => array(),
			)
		);
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.2' );

		$this->transport = new FakeTransport();
		$this->options   = new Options();
	}

	private function manager(): IndexManager {
		$schema = new class() implements Schema {
			public function filterable(): array {
				return array( 'post_type', 'date' );
			}
			public function sortable(): array {
				return array( 'date' );
			}
			public function searchable(): array {
				return array( 'title', 'content' );
			}
		};

		return new IndexManager(
			new ClientFactory( $this->options, $this->transport ),
			new IndexNames( $this->options ),
			new SettingsBuilder( array( 'content' => $schema ) ),
			$this->options
		);
	}

	/**
	 * @param array<string, mixed> $body Response body.
	 */
	private static function json( array $body, int $status = 200 ): Response {
		return new Response( $status, $body, (string) json_encode( $body ) );
	}

	private static function task( int $uid ): Response {
		return self::json(
			array(
				'taskUid' => $uid,
				'status'  => 'enqueued',
				'type'    => 'settingsUpdate',
			),
			202
		);
	}

	private static function done( int $uid ): Response {
		return self::json(
			array(
				'uid'    => $uid,
				'status' => 'succeeded',
			)
		);
	}

	private static function error( int $status, string $code ): Response {
		return self::json(
			array(
				'message' => $code,
				'code'    => $code,
				'type'    => 'invalid_request',
			),
			$status
		);
	}

	/**
	 * @return list<string> "METHOD path" for every request sent.
	 */
	private function calls(): array {
		return array_map(
			static fn( array $request ): string => $request['method'] . ' ' . substr( $request['url'], strlen( self::HOST ) ),
			$this->transport->requests()
		);
	}

	/**
	 * Queues: index exists, settings already complete.
	 */
	private function queue_index_up_to_date(): void {
		$this->transport
			->queue( self::json( array( 'uid' => 'wp_test_content' ) ) )
			->queue(
				self::json(
					array(
						'searchableAttributes' => array( '*' ),
						'filterableAttributes' => array( 'post_type', 'date' ),
						'sortableAttributes'   => array( 'date' ),
					)
				)
			);
	}

	public function test_check_connection_returns_version(): void {
		$this->transport->queue( self::json( array( 'pkgVersion' => '1.34.0' ) ) );

		$this->assertSame( '1.34.0', $this->manager()->check_connection() );
		$this->assertSame( array( 'GET /version' ), $this->calls() );
		$this->assertSame( 'Bearer admin-key', $this->transport->requests()[0]['headers']['Authorization'] );
	}

	public function test_check_connection_rejects_old_versions(): void {
		$this->transport->queue( self::json( array( 'pkgVersion' => '1.33.2' ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/^unsupported_version: .*1\.33\.2.*1\.34\.0/' );
		$this->manager()->check_connection();
	}

	public function test_check_connection_accepts_prereleases_of_supported_versions(): void {
		$this->transport->queue( self::json( array( 'pkgVersion' => '1.35.0-rc.1' ) ) );

		$this->assertSame( '1.35.0-rc.1', $this->manager()->check_connection() );
	}

	public function test_check_connection_propagates_invalid_key(): void {
		$this->transport->queue( self::error( 403, 'invalid_api_key' ) );

		try {
			$this->manager()->check_connection();
			$this->fail( 'Expected ApiError.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 403, $error->http_status );
			$this->assertSame( 'invalid_api_key', $error->error_code );
		}
	}

	public function test_ensure_index_creates_missing_index_with_initial_settings(): void {
		$this->transport
			->queue( self::error( 404, 'index_not_found' ) )
			->queue( self::task( 1 ) )
			->queue( self::done( 1 ) )
			->queue( self::task( 2 ) )
			->queue( self::done( 2 ) );

		$this->manager()->ensure_index( 'content' );

		$this->assertSame(
			array( 'GET /indexes/wp_test_content', 'POST /indexes', 'GET /tasks/1', 'PATCH /indexes/wp_test_content/settings', 'GET /tasks/2' ),
			$this->calls()
		);
		$requests = $this->transport->requests();
		$this->assertSame(
			array(
				'uid'        => 'wp_test_content',
				'primaryKey' => 'id',
			),
			$requests[1]['body']
		);
		$this->assertSame(
			array(
				'searchableAttributes' => array( 'title', 'content' ),
				'filterableAttributes' => array( 'post_type', 'date' ),
				'sortableAttributes'   => array( 'date' ),
			),
			$requests[3]['body']
		);
	}

	public function test_ensure_index_patches_only_missing_settings_of_existing_index(): void {
		$this->transport
			->queue( self::json( array( 'uid' => 'wp_test_content' ) ) )
			->queue(
				self::json(
					array(
						'searchableAttributes' => array( '*' ),
						'filterableAttributes' => array( 'cloud' ),
						'sortableAttributes'   => array( 'date' ),
						'rankingRules'         => array( 'words' ),
					)
				)
			)
			->queue( self::task( 3 ) )
			->queue( self::done( 3 ) );

		$this->manager()->ensure_index( 'content' );

		$this->assertSame(
			array( 'GET /indexes/wp_test_content', 'GET /indexes/wp_test_content/settings', 'PATCH /indexes/wp_test_content/settings', 'GET /tasks/3' ),
			$this->calls()
		);
		$this->assertSame( array( 'filterableAttributes' => array( 'cloud', 'post_type', 'date' ) ), $this->transport->requests()[2]['body'] );
	}

	public function test_ensure_index_sends_nothing_when_settings_are_complete(): void {
		$this->queue_index_up_to_date();

		$this->manager()->ensure_index( 'content' );

		$this->assertSame( array( 'GET /indexes/wp_test_content', 'GET /indexes/wp_test_content/settings' ), $this->calls() );
	}

	public function test_ensure_index_accepts_explicit_uid(): void {
		$this->transport
			->queue( self::error( 404, 'index_not_found' ) )
			->queue( self::task( 1 ) )
			->queue( self::done( 1 ) )
			->queue( self::task( 2 ) )
			->queue( self::done( 2 ) );

		$this->manager()->ensure_index( 'content', 'wp_other_content' );

		$this->assertSame( 'GET /indexes/wp_other_content', $this->calls()[0] );
	}

	public function test_sync_settings_returns_null_without_changes(): void {
		$this->transport->queue(
			self::json(
				array(
					'searchableAttributes' => array( 'title', 'content' ),
					'filterableAttributes' => array( 'post_type', 'date' ),
					'sortableAttributes'   => array( 'date' ),
				)
			)
		);

		$this->assertNull( $this->manager()->sync_settings( 'content', 'wp_test_content' ) );
	}

	public function test_drift_lists_missing_required_attributes(): void {
		$this->transport->queue(
			self::json(
				array(
					'filterableAttributes' => array( 'post_type' ),
					'sortableAttributes'   => array(),
				)
			)
		);

		$this->assertSame( array( 'date' ), $this->manager()->drift( 'content' ) );
		$this->assertSame( array( 'GET /indexes/wp_test_content/settings' ), $this->calls() );
	}

	public function test_drift_reports_object_rules_without_comparison_on_range_fields(): void {
		$this->transport->queue(
			self::json(
				array(
					'filterableAttributes' => array(
						array(
							'attributePatterns' => array( 'post_*', 'dat*' ),
							'features'          => array(
								'facetSearch' => false,
								'filter'      => array(
									'equality'   => true,
									'comparison' => false,
								),
							),
						),
					),
					'sortableAttributes'   => array( 'date' ),
				)
			)
		);

		$this->assertSame( array( 'comparison disabled for date' ), $this->manager()->drift( 'content' ) );
	}

	public function test_rotate_search_key_creates_scoped_key_and_deletes_previous(): void {
		$this->option_store[ Options::CONNECTION ]['search_key']     = 'old-key';
		$this->option_store[ Options::CONNECTION ]['search_key_uid'] = 'old-uid';
		$this->transport
			->queue(
				self::json(
					array(
						'key' => 'new-key',
						'uid' => 'new-uid',
					),
					201
				)
			)
			->queue( self::json( array(), 204 ) );

		$this->assertSame( 'created', $this->manager()->rotate_search_key() );

		$this->assertSame( array( 'POST /keys', 'DELETE /keys/old-uid' ), $this->calls() );
		$this->assertSame(
			array(
				'name'        => 'WordPress search (https://shop.example)',
				'description' => 'Search-only key created by the Meilisearch WordPress plugin for autocomplete. Rotated when the connection changes.',
				'actions'     => array( 'search' ),
				'indexes'     => array( 'wp_test_content', 'wp_test_products' ),
				'expiresAt'   => null,
			),
			$this->transport->requests()[0]['body']
		);
		$this->assertSame( 'new-key', $this->options->search_key() );
		$this->assertSame( 'new-uid', $this->options->search_key_uid() );
		$this->assertFalse( $this->options->state( 'search_key_manual', false ) );
	}

	public function test_rotate_search_key_ignores_missing_previous_key(): void {
		$this->option_store[ Options::CONNECTION ]['search_key_uid'] = 'gone';
		$this->transport
			->queue(
				self::json(
					array(
						'key' => 'k',
						'uid' => 'u',
					),
					201
				)
			)
			->queue( self::error( 404, 'api_key_not_found' ) );

		$this->assertSame( 'created', $this->manager()->rotate_search_key() );
		$this->assertSame( 'k', $this->options->search_key() );
	}

	/**
	 * @dataProvider manual_errors
	 */
	public function test_rotate_search_key_falls_back_to_manual( int $status, string $code ): void {
		$this->option_store[ Options::CONNECTION ]['search_key'] = 'pasted-key';
		$this->transport->queue( self::error( $status, $code ) );

		$this->assertSame( 'manual', $this->manager()->rotate_search_key() );
		$this->assertTrue( $this->options->state( 'search_key_manual', false ) );
		$this->assertSame( 'pasted-key', $this->options->search_key(), 'A pasted key is never overwritten.' );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function manual_errors(): array {
		return array(
			'forbidden'          => array( 403, 'invalid_api_key' ),
			'missing master key' => array( 401, 'missing_master_key' ),
		);
	}

	public function test_rotate_search_key_rethrows_other_errors(): void {
		$this->transport->queue( self::error( 500, 'internal' ) );

		$this->expectException( ApiError::class );
		$this->manager()->rotate_search_key();
	}

	public function test_rotate_search_key_rejects_a_response_without_key(): void {
		$this->transport->queue( self::json( array( 'uid' => 'u' ), 201 ) );

		try {
			$this->manager()->rotate_search_key();
			$this->fail( 'Expected ApiError.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'invalid_response', $error->error_code );
			$this->assertSame( '', $this->options->search_key() );
			$this->assertSame( array( 'POST /keys' ), $this->calls() );
		}
	}

	public function test_connect_first_time_creates_key_indexes_and_flags_reindex(): void {
		$this->transport
			->queue( self::json( array( 'pkgVersion' => '1.53.1' ) ) )
			->queue(
				self::json(
					array(
						'key' => 'search-key',
						'uid' => 'search-uid',
					),
					201
				)
			)
			->queue( self::error( 404, 'index_not_found' ) )
			->queue( self::task( 1 ) )
			->queue( self::done( 1 ) )
			->queue( self::task( 2 ) )
			->queue( self::done( 2 ) );

		$result = $this->manager()->connect();

		$this->assertSame(
			array(
				'version' => '1.53.1',
				'key'     => 'created',
			),
			$result
		);
		$this->assertSame( md5( self::HOST . '|admin-key|wp_test' ), $this->options->state( 'fingerprint' ) );
		$this->assertTrue( $this->options->needs_reindex( 'content' ) );
		$this->assertSame( 'search-key', $this->options->search_key() );
	}

	public function test_connect_with_unchanged_fingerprint_keeps_key_and_flags(): void {
		$this->option_store[ Options::CONNECTION ]['search_key']     = 'existing';
		$this->option_store[ Options::CONNECTION ]['search_key_uid'] = 'existing-uid';
		$this->options->set_state( 'fingerprint', md5( self::HOST . '|admin-key|wp_test' ) );
		$this->transport->queue( self::json( array( 'pkgVersion' => '1.53.1' ) ) );
		$this->queue_index_up_to_date();

		$result = $this->manager()->connect();

		$this->assertSame( 'kept', $result['key'] );
		$this->assertNotContains( 'POST /keys', $this->calls() );
		$this->assertFalse( $this->options->needs_reindex( 'content' ) );
		$this->assertSame( 'existing', $this->options->search_key() );
	}

	public function test_connect_rotates_when_prefix_changes(): void {
		$this->option_store[ Options::CONNECTION ]['search_key']     = 'existing';
		$this->option_store[ Options::CONNECTION ]['search_key_uid'] = 'existing-uid';
		$this->options->set_state( 'fingerprint', md5( self::HOST . '|admin-key|wp_old' ) );
		$this->transport
			->queue( self::json( array( 'pkgVersion' => '1.53.1' ) ) )
			->queue(
				self::json(
					array(
						'key' => 'rotated',
						'uid' => 'rotated-uid',
					),
					201
				)
			)
			->queue( self::json( array(), 204 ) );
		$this->queue_index_up_to_date();

		$this->assertSame( 'created', $this->manager()->connect()['key'] );
		$this->assertContains( 'DELETE /keys/existing-uid', $this->calls() );
		$this->assertTrue( $this->options->needs_reindex( 'content' ) );
	}

	public function test_connect_failure_does_not_store_fingerprint(): void {
		$this->transport->queue( self::json( array( 'pkgVersion' => '1.10.0' ) ) );

		try {
			$this->manager()->connect();
			$this->fail( 'Expected RuntimeException.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( '', $this->options->state( 'fingerprint' ) );
		}
	}

	/**
	 * @dataProvider key_details
	 *
	 * @param Response|ApiError $response Response to GET /keys/{key}.
	 */
	public function test_verify_search_key( $response, ?bool $expected ): void {
		$this->transport->queue( $response );

		$this->assertSame( $expected, $this->manager()->verify_search_key( 'pasted' ) );
	}

	/**
	 * @return array<string, array{0: Response|ApiError, 1: ?bool}>
	 */
	public static function key_details(): array {
		return array(
			'search only on our indexes' => array(
				self::json(
					array(
						'actions' => array( 'search' ),
						'indexes' => array( 'wp_test_content' ),
					)
				),
				true,
			),
			'extra action'               => array(
				self::json(
					array(
						'actions' => array( 'search', 'documents.add' ),
						'indexes' => array( 'wp_test_content' ),
					)
				),
				false,
			),
			'all indexes'                => array(
				self::json(
					array(
						'actions' => array( 'search' ),
						'indexes' => array( '*' ),
					)
				),
				false,
			),
			'unreadable'                 => array( self::error( 403, 'invalid_api_key' ), null ),
			'transport error'            => array( ApiError::transport( 'timeout' ), null ),
		);
	}
}
