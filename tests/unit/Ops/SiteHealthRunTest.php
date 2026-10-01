<?php
/**
 * Tests for SiteHealth::run_all() with a fake transport: key redaction.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Ops;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\ContentSchema;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Ops\SiteHealth;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\Support\SyncFixtures;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class SiteHealthRunTest extends TestCase {

	use SyncFixtures;

	private const SEARCH_KEY = 'pasted-secret-key-0123456789';
	private const ADMIN_KEY  = 'test-admin-key';

	/**
	 * Fake transport.
	 *
	 * @var FakeTransport
	 */
	private FakeTransport $transport;

	protected function set_up(): void {
		parent::set_up();
		$this->stub_wordpress_state();
		$this->install_wpdb( array(), '0' );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		$this->option_store[ Options::CONNECTION ]['search_key'] = self::SEARCH_KEY;
		$this->transport = new FakeTransport();
	}

	protected function tear_down(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	private function health(): SiteHealth {
		$options = new Options();
		$names   = new IndexNames( $options );
		$clients = $this->make_clients( $options, $this->transport );
		$indexes = new IndexManager( $clients, $names, new SettingsBuilder( array( 'content' => new ContentSchema( $options ) ) ), $options );
		$queue   = new Queue();

		return new SiteHealth( $clients, $indexes, $names, $this->make_reindexer( $options, $clients, $queue, new ErrorLog(), array() ), $queue, $options, new Indexability( $options ) );
	}

	/**
	 * @param list<array<string, mixed>> $results Site Health results.
	 */
	private static function text( array $results ): string {
		return (string) json_encode( $results );
	}

	public function test_key_lookup_failure_never_echoes_the_server_message(): void {
		$this->transport->respond( 200, array( 'pkgVersion' => '1.53.1' ) );
		$this->transport->respond( 200, array( 'numberOfDocuments' => 0 ) );
		$this->transport->respond( 200, array() );
		$this->transport->respond(
			404,
			array(
				'message' => 'API key `' . self::SEARCH_KEY . '` not found.',
				'code'    => 'api_key_not_found',
				'type'    => 'auth',
				'link'    => 'https://docs.meilisearch.com/errors#api_key_not_found',
			)
		);

		$results = $this->health()->run_all();
		$by_test = array_column( $results, null, 'test' );

		// An unknown key (the master key answers 404 too) must never be served: critical.
		self::assertSame( 'critical', $by_test[ SiteHealth::TEST_SEARCH_KEY ]['status'] );
		self::assertStringContainsString( 'api_key_not_found', $by_test[ SiteHealth::TEST_SEARCH_KEY ]['description'] );
		self::assertStringNotContainsString( self::SEARCH_KEY, self::text( $results ) );
	}

	/**
	 * Queues the version, stats and settings answers that precede the key lookup.
	 */
	private function queue_until_key_lookup(): void {
		$this->transport->respond( 200, array( 'pkgVersion' => '1.53.1' ) );
		$this->transport->respond( 200, array( 'numberOfDocuments' => 0 ) );
		$this->transport->respond( 200, array() );
	}

	public function test_search_key_equal_to_the_admin_key_is_critical(): void {
		$this->option_store[ Options::CONNECTION ]['search_key'] = self::ADMIN_KEY;
		$this->queue_until_key_lookup();

		$by_test = array_column( $this->health()->run_all(), null, 'test' );

		self::assertSame( 'critical', $by_test[ SiteHealth::TEST_SEARCH_KEY ]['status'] );
		self::assertSame( 0, $this->transport->pending() );
	}

	public function test_search_key_that_can_call_version_is_critical(): void {
		$this->queue_until_key_lookup();
		$this->transport->respond( 403, array( 'code' => 'invalid_api_key' ) );
		$this->transport->respond( 200, array( 'pkgVersion' => '1.53.1' ) );

		$by_test = array_column( $this->health()->run_all(), null, 'test' );

		self::assertSame( 'critical', $by_test[ SiteHealth::TEST_SEARCH_KEY ]['status'] );
		self::assertSame( 'Bearer ' . self::SEARCH_KEY, $this->transport->last()['headers']['Authorization'] );
	}

	public function test_unreadable_search_key_refused_by_version_is_only_recommended(): void {
		$this->queue_until_key_lookup();
		$this->transport->respond( 403, array( 'code' => 'invalid_api_key' ) );
		$this->transport->respond( 403, array( 'code' => 'invalid_api_key' ) );

		$by_test = array_column( $this->health()->run_all(), null, 'test' );

		self::assertSame( 'recommended', $by_test[ SiteHealth::TEST_SEARCH_KEY ]['status'] );
		self::assertSame( 0, $this->transport->pending() );
	}

	public function test_unexpected_exceptions_are_redacted_in_not_checked_results(): void {
		$this->transport->respond( 200, array( 'pkgVersion' => '1.53.1' ) );
		$this->transport->fail( new \RuntimeException( 'boom with ' . self::ADMIN_KEY . ' and ' . self::SEARCH_KEY ) );
		$this->transport->respond( 200, array() );
		$this->transport->fail( new \RuntimeException( 'again ' . self::ADMIN_KEY ) );

		$results = $this->health()->run_all();
		$by_test = array_column( $results, null, 'test' );

		self::assertSame( 'recommended', $by_test[ SiteHealth::TEST_DOCUMENTS ]['status'] );
		self::assertStringContainsString( 'boom with …', $by_test[ SiteHealth::TEST_DOCUMENTS ]['description'] );
		self::assertStringNotContainsString( self::ADMIN_KEY, self::text( $results ) );
		self::assertStringNotContainsString( self::SEARCH_KEY, self::text( $results ) );
	}

	public function test_connection_error_is_redacted(): void {
		$this->transport->fail( new \RuntimeException( 'Invalid key ' . self::ADMIN_KEY ) );

		$results = $this->health()->run_all();

		self::assertSame( 'critical', $results[0]['status'] );
		self::assertStringNotContainsString( self::ADMIN_KEY, self::text( $results ) );
	}
}
