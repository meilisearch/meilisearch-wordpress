<?php
/**
 * Tests for SqlFilterAudit.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Ops;

use Meilisearch\WordPress\Ops\SqlFilterAudit;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class SqlFilterAuditTest extends TestCase {

	/**
	 * A WP_Hook-like object.
	 *
	 * @param list<mixed> $functions Callbacks at priority 10.
	 */
	private static function hook( array $functions ): object {
		$callbacks = array();
		foreach ( $functions as $i => $function ) {
			$callbacks[ 'cb' . $i ] = array(
				'function'      => $function,
				'accepted_args' => 1,
			);
		}
		return (object) array( 'callbacks' => array( 10 => $callbacks ) );
	}

	public static function restrict( string $where ): string {
		return $where;
	}

	public function test_lists_callbacks_on_the_sql_filters_with_their_hook(): void {
		$invokable = new class() {
			public function __invoke( string $where ): string {
				return $where;
			}
		};
		$filters   = array(
			'posts_where'           => self::hook( array( static fn ( $where ) => $where, array( self::class, 'restrict' ) ) ),
			'posts_clauses_request' => self::hook( array( self::class . '::restrict', $invokable ) ),
			'posts_search'          => self::hook( array( 'trim' ) ),
			'the_posts'             => self::hook( array( 'trim' ) ),
		);

		$found = ( new SqlFilterAudit( array( '/nowhere' ) ) )->foreign_callbacks( $filters );

		self::assertSame(
			array(
				'posts_search: trim',
				'posts_where: closure',
				'posts_where: ' . self::class . '::restrict',
				'posts_clauses_request: ' . self::class . '::restrict',
				'posts_clauses_request: ' . get_class( $invokable ),
			),
			$found
		);
	}

	public function test_callbacks_defined_in_ignored_directories_are_left_out(): void {
		$filters = array( 'posts_join' => self::hook( array( static fn ( $join ) => $join, array( self::class, 'restrict' ) ) ) );

		self::assertSame( array(), ( new SqlFilterAudit( array( __DIR__ ) ) )->foreign_callbacks( $filters ) );
		self::assertSame( array(), ( new SqlFilterAudit( array( __DIR__ . '/' ) ) )->foreign_callbacks( $filters ), 'Trailing slash.' );
		self::assertCount( 2, ( new SqlFilterAudit( array( __DIR__ . '-other' ) ) )->foreign_callbacks( $filters ), 'A sibling directory with the same prefix is not ignored.' );
	}

	public function test_default_ignores_this_plugin_and_core(): void {
		$filters = array( 'posts_where' => self::hook( array( array( SqlFilterAudit::class, 'foreign_callbacks' ) ) ) );

		self::assertSame( array(), ( new SqlFilterAudit() )->foreign_callbacks( $filters ) );
		self::assertSame( array(), ( new SqlFilterAudit() )->foreign_callbacks( array() ) );
	}
}
