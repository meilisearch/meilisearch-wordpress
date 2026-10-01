<?php
/**
 * Connection flow against a real Meilisearch.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;

final class ConnectionTest extends TestCase {

	/**
	 * Key uids to delete after the test.
	 *
	 * @var list<string>
	 */
	private array $keys = array();

	public function tear_down(): void {
		$uid = $this->options()->search_key_uid();
		if ( '' !== $uid ) {
			$this->keys[] = $uid;
		}
		foreach ( array_unique( $this->keys ) as $key_uid ) {
			try {
				$this->meili( 'DELETE', '/keys/' . $key_uid, null );
			} catch ( \Throwable $error ) {
				unset( $error ); // Already deleted.
			}
		}
		$this->keys = array();
		parent::tear_down();
	}

	private function manager(): IndexManager {
		return Plugin::instance()->get( 'index_manager' );
	}

	private function options(): Options {
		return Plugin::instance()->get( 'options' );
	}

	private function names(): IndexNames {
		return Plugin::instance()->get( 'names' );
	}

	public function test_invalid_admin_key_is_rejected(): void {
		if ( defined( 'MEILISEARCH_ADMIN_KEY' ) ) {
			$this->markTestSkipped( 'The admin key is defined as a constant.' );
		}
		$original = (string) get_option( Options::ADMIN_KEY, '' );
		update_option( Options::ADMIN_KEY, 'not-a-valid-key' );

		try {
			$this->manager()->check_connection();
			$this->fail( 'An invalid admin key must be rejected.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 403, $error->http_status );
			$this->assertSame( 'invalid_api_key', $error->error_code );
		} finally {
			update_option( Options::ADMIN_KEY, $original );
		}
	}

	public function test_supported_version_is_reported(): void {
		$version = $this->manager()->check_connection();

		$this->assertTrue( version_compare( $version, IndexManager::MIN_VERSION, '>=' ), $version );
	}

	public function test_connect_creates_scoped_search_key_and_indexes(): void {
		$content_uid = $this->names()->uid( 'content' );
		$this->meili( 'DELETE', '/indexes/' . $content_uid, null );
		$this->wait_for_tasks();

		$result = $this->manager()->connect();

		$this->assertSame( 'created', $result['key'] );
		$key = $this->meili( 'GET', '/keys/' . $this->options()->search_key_uid(), null );
		$this->assertSame( array( 'search' ), $key['actions'] );
		$expected = array( $content_uid, $this->names()->uid( 'products' ) );
		$actual   = $key['indexes'];
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
		$this->assertNull( $key['expiresAt'] );
		$this->assertSame( $this->options()->search_key(), $key['key'] );

		$settings = $this->meili( 'GET', '/indexes/' . $content_uid . '/settings', null );
		foreach ( array( 'id', 'post_type', 'author_id', 'date', 'modified' ) as $field ) {
			$this->assertContains( $field, $settings['filterableAttributes'] );
		}
		foreach ( array( 'id', 'date', 'modified', 'title' ) as $field ) {
			$this->assertContains( $field, $settings['sortableAttributes'] );
		}
		$this->assertSame( 'title', $settings['searchableAttributes'][0] );
		$this->assertTrue( $this->options()->needs_reindex( 'content' ) );
	}

	public function test_reconnect_keeps_key_when_nothing_changed(): void {
		$this->manager()->connect();
		$key = $this->options()->search_key();
		$uid = $this->options()->search_key_uid();

		$second = $this->manager()->connect();

		$this->assertSame( 'kept', $second['key'] );
		$this->assertSame( $key, $this->options()->search_key() );
		$this->assertSame( $uid, $this->meili( 'GET', '/keys/' . $uid, null )['uid'] );
	}

	public function test_admin_key_without_key_permissions_falls_back_to_manual(): void {
		if ( defined( 'MEILISEARCH_ADMIN_KEY' ) ) {
			$this->markTestSkipped( 'The admin key is defined as a constant.' );
		}
		$restricted   = $this->meili(
			'POST',
			'/keys',
			array(
				'name'      => 'wp-test restricted admin',
				'actions'   => array( 'version', 'indexes.*', 'settings.*', 'tasks.*' ),
				'indexes'   => array( '*' ),
				'expiresAt' => null,
			)
		);
		$this->keys[] = $restricted['uid'];
		$original     = (string) get_option( Options::ADMIN_KEY, '' );
		update_option( Options::ADMIN_KEY, $restricted['key'] );

		try {
			$result = $this->manager()->connect();
		} finally {
			update_option( Options::ADMIN_KEY, $original );
		}

		$this->assertSame( 'manual', $result['key'] );
		$this->assertTrue( $this->options()->state( 'search_key_manual', false ) );
	}

	/**
	 * Creates a Meilisearch key for the test and remembers it for tear_down().
	 *
	 * @param list<string> $actions Actions.
	 * @return array{key: string, uid: string}
	 */
	private function create_key( array $actions ): array {
		$created      = $this->meili(
			'POST',
			'/keys',
			array(
				'name'      => 'wp-test ' . implode( ' ', $actions ),
				'actions'   => $actions,
				'indexes'   => array( '*' ),
				'expiresAt' => null,
			)
		);
		$this->keys[] = (string) $created['uid'];

		return array(
			'key' => (string) $created['key'],
			'uid' => (string) $created['uid'],
		);
	}

	/**
	 * Runs verify_search_key() with another admin key.
	 *
	 * @param string $admin_key Admin key.
	 * @param string $candidate Candidate search key.
	 * @return bool|null
	 */
	private function verify_with_admin_key( string $admin_key, string $candidate ): ?bool {
		$original = (string) get_option( Options::ADMIN_KEY, '' );
		update_option( Options::ADMIN_KEY, $admin_key );
		try {
			return $this->manager()->verify_search_key( $candidate );
		} finally {
			update_option( Options::ADMIN_KEY, $original );
		}
	}

	public function test_master_key_pasted_as_search_key_is_rejected(): void {
		if ( defined( 'MEILISEARCH_ADMIN_KEY' ) ) {
			$this->markTestSkipped( 'The admin key is defined as a constant.' );
		}
		// An admin key that may read keys gets 404 for the master key.
		$full = $this->create_key( array( '*' ) );
		$this->assertFalse( $this->verify_with_admin_key( $full['key'], self::test_key() ) );

		// An admin key without keys.* gets 403; the self-probe on /version then succeeds.
		$restricted = $this->create_key( array( 'version', 'indexes.*', 'settings.*', 'tasks.*' ) );
		$this->assertFalse( $this->verify_with_admin_key( $restricted['key'], self::test_key() ) );
	}

	public function test_admin_key_pasted_as_search_key_is_rejected(): void {
		$this->assertFalse( $this->manager()->verify_search_key( self::test_key() ) );
	}

	public function test_search_only_key_unreadable_by_the_admin_key_is_unverifiable(): void {
		if ( defined( 'MEILISEARCH_ADMIN_KEY' ) ) {
			$this->markTestSkipped( 'The admin key is defined as a constant.' );
		}
		$restricted = $this->create_key( array( 'version', 'indexes.*', 'settings.*', 'tasks.*' ) );
		$search     = $this->create_key( array( 'search' ) );

		$this->assertNull( $this->verify_with_admin_key( $restricted['key'], $search['key'] ) );
	}
}
