<?php
/**
 * ResultMapper tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Search\ResultMapper;
use Meilisearch\WordPress\Search\SearchResult;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Hits → WP_Post[] and pagination totals.
 *
 * @covers \Meilisearch\WordPress\Search\ResultMapper
 */
final class ResultMapperTest extends TestCase {

	/**
	 * Posts returned by the get_post() stub.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * Stubs get_post().
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->posts = array(
			7  => new \WP_Post( array( 'ID' => 7 ) ),
			12 => new \WP_Post( array( 'ID' => 12 ) ),
		);
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return $this->posts[ $id ] ?? null;
			}
		);
	}

	/**
	 * Posts keep the ranking order; found_posts / max_num_pages come from the result.
	 */
	public function test_apply_returns_posts_in_hit_order_and_sets_totals(): void {
		Functions\expect( '_prime_post_caches' )->once()->with( array( 12, 7 ) );
		$query = new \WP_Query( array( 's' => 'x' ) );

		$posts = ( new ResultMapper() )->apply( $query, new SearchResult( array( 12, 7 ), 25, 3, array() ) );

		self::assertSame( array( $this->posts[12], $this->posts[7] ), $posts );
		self::assertSame( 25, $query->found_posts );
		self::assertSame( 3, $query->max_num_pages );
	}

	/**
	 * A hit whose post no longer loads is dropped; totals are unchanged.
	 */
	public function test_missing_posts_are_dropped(): void {
		Functions\when( '_prime_post_caches' )->justReturn( null );
		$query = new \WP_Query( array() );

		$posts = ( new ResultMapper() )->apply( $query, new SearchResult( array( 12, 99, 7 ), 3, 1, array() ) );

		self::assertSame( array( $this->posts[12], $this->posts[7] ), $posts );
		self::assertSame( 3, $query->found_posts );
	}

	/**
	 * Stale hits for trashed, private, draft and password-protected posts are dropped; totals unchanged.
	 */
	public function test_non_public_posts_are_dropped(): void {
		Functions\when( '_prime_post_caches' )->justReturn( null );
		$this->posts[20] = new \WP_Post(
			array(
				'ID'          => 20,
				'post_status' => 'trash',
			)
		);
		$this->posts[21] = new \WP_Post(
			array(
				'ID'          => 21,
				'post_status' => 'private',
			)
		);
		$this->posts[22] = new \WP_Post(
			array(
				'ID'          => 22,
				'post_status' => 'draft',
			)
		);
		$this->posts[23] = new \WP_Post(
			array(
				'ID'            => 23,
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$query           = new \WP_Query( array() );

		$posts = ( new ResultMapper() )->apply( $query, new SearchResult( array( 12, 20, 21, 22, 23, 7 ), 6, 1, array() ) );

		self::assertSame( array( $this->posts[12], $this->posts[7] ), $posts );
		self::assertSame( 6, $query->found_posts );
		self::assertSame( 1, $query->max_num_pages );
	}

	/**
	 * Review Focus #3: paged past the end → no posts, real totals, no cache priming.
	 */
	public function test_page_beyond_last_returns_empty_but_keeps_totals(): void {
		Functions\expect( '_prime_post_caches' )->never();
		$query = new \WP_Query( array( 'paged' => 999 ) );

		$posts = ( new ResultMapper() )->apply( $query, new SearchResult( array(), 25, 3, array() ) );

		self::assertSame( array(), $posts );
		self::assertSame( 25, $query->found_posts );
		self::assertSame( 3, $query->max_num_pages );
	}

	/**
	 * Formatted fields accumulate across intercepted queries until reset().
	 */
	public function test_formatted_is_kept_until_reset(): void {
		$mapper = new ResultMapper();
		$mapper->apply( new \WP_Query( array() ), new SearchResult( array(), 1, 1, array( 7 => array( 'content' => 'a' ) ) ) );
		$mapper->apply( new \WP_Query( array() ), new SearchResult( array(), 1, 1, array( 12 => array( 'content' => 'b' ) ) ) );

		self::assertSame( array( 'content' => 'a' ), $mapper->formatted( 7 ) );
		self::assertSame( array( 'content' => 'b' ), $mapper->formatted( 12 ) );
		self::assertNull( $mapper->formatted( 3 ) );

		$mapper->reset();

		self::assertNull( $mapper->formatted( 7 ) );
	}
}
