<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class IndexabilityTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->stub_options(
			array(
				Options::CONTENT => array(
					'post_types' => array( 'post', 'page' ),
					'taxonomies' => array(),
					'meta_keys'  => array(),
				),
			)
		);
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
	}

	private function post( array $props = array() ): \WP_Post {
		return new \WP_Post(
			array_merge(
				array(
					'ID'            => 10,
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_password' => '',
				),
				$props
			)
		);
	}

	public function test_index_for_type(): void {
		$without_products = new Indexability( new Options() );
		$with_products    = new Indexability( new Options(), static fn( \WP_Post $post ): bool => 'product' === $post->post_type );

		$this->assertSame( 'content', $without_products->index_for_type( 'post' ) );
		$this->assertSame( 'content', $without_products->index_for_type( 'page' ) );
		$this->assertNull( $without_products->index_for_type( 'attachment' ) );
		$this->assertNull( $without_products->index_for_type( 'product' ) );
		$this->assertSame( 'products', $with_products->index_for_type( 'product' ) );
		$this->assertNull( $with_products->index_for_type( 'product_variation' ) );
	}

	public function test_published_public_post_is_indexable(): void {
		$this->assertTrue( ( new Indexability( new Options() ) )->is_indexable( $this->post() ) );
	}

	/**
	 * @dataProvider hidden_posts
	 */
	public function test_non_public_posts_are_not_indexable( array $props ): void {
		$this->assertFalse( ( new Indexability( new Options() ) )->is_indexable( $this->post( $props ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function hidden_posts(): array {
		return array(
			'draft'         => array( array( 'post_status' => 'draft' ) ),
			'pending'       => array( array( 'post_status' => 'pending' ) ),
			'private'       => array( array( 'post_status' => 'private' ) ),
			'future'        => array( array( 'post_status' => 'future' ) ),
			'trash'         => array( array( 'post_status' => 'trash' ) ),
			'password'      => array( array( 'post_password' => 'secret' ) ),
			'disabled type' => array( array( 'post_type' => 'attachment' ) ),
			'product off'   => array( array( 'post_type' => 'product' ) ),
			'variation'     => array( array( 'post_type' => 'product_variation' ) ),
		);
	}

	public function test_revisions_and_autosaves_are_ignored(): void {
		$indexability = new Indexability( new Options() );

		Functions\when( 'wp_is_post_revision' )->justReturn( 5 );
		$this->assertNull( $indexability->index_for( $this->post() ) );
		$this->assertFalse( $indexability->is_indexable( $this->post() ) );

		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( 7 );
		$this->assertNull( $indexability->index_for( $this->post() ) );
	}

	public function test_product_rule_decides_for_products(): void {
		$seen         = array();
		$rule         = static function ( \WP_Post $post ) use ( &$seen ): bool {
			$seen[] = $post->ID;
			return 11 === $post->ID;
		};
		$indexability = new Indexability( new Options(), $rule );

		$this->assertTrue(
			$indexability->is_indexable(
				$this->post(
					array(
						'ID'        => 11,
						'post_type' => 'product',
					)
				)
			)
		);
		$this->assertFalse(
			$indexability->is_indexable(
				$this->post(
					array(
						'ID'        => 12,
						'post_type' => 'product',
					)
				)
			)
		);
		$this->assertFalse(
			$indexability->is_indexable(
				$this->post(
					array(
						'ID'          => 13,
						'post_type'   => 'product',
						'post_status' => 'draft',
					)
				)
			)
		);
		$this->assertSame( array( 11, 12 ), $seen, 'The product rule only runs for published, unprotected products.' );
	}

	public function test_filter_can_veto(): void {
		$post = $this->post();
		Filters\expectApplied( 'meilisearch_should_index_post' )->once()->with( true, $post )->andReturn( false );

		$this->assertFalse( ( new Indexability( new Options() ) )->is_indexable( $post ) );
	}

	public function test_filter_is_not_applied_to_hidden_posts(): void {
		Filters\expectApplied( 'meilisearch_should_index_post' )->never();

		$this->assertFalse( ( new Indexability( new Options() ) )->is_indexable( $this->post( array( 'post_status' => 'draft' ) ) ) );
	}

	public function test_non_callable_rule_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Indexability( new Options(), 'not a function name' );
	}

	public function test_unindexed_searchable_types(): void {
		Functions\expect( 'get_post_types' )
			->with( array( 'exclude_from_search' => false ) )
			->andReturn(
				array(
					'post'       => 'post',
					'page'       => 'page',
					'attachment' => 'attachment',
					'event'      => 'event',
					'product'    => 'product',
				)
			);

		$this->assertSame( array( 'event', 'product' ), ( new Indexability( new Options() ) )->unindexed_searchable_types() );
		$this->assertSame( array( 'event' ), ( new Indexability( new Options(), static fn(): bool => true ) )->unindexed_searchable_types() );
	}
}
