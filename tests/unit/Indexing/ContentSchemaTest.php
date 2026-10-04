<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Meilisearch\WordPress\Indexing\ContentSchema;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class ContentSchemaTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->stub_options(
			array(
				Options::CONTENT => array(
					'post_types' => array( 'post', 'page' ),
					'taxonomies' => array(
						'post' => array( 'category', 'post_tag' ),
						'page' => array( 'category' ),
					),
					'meta_keys'  => array(
						'post' => array( 'price' ),
						'page' => array( 'price', 'rating' ),
					),
				),
			)
		);
	}

	public function test_filterable(): void {
		$this->assertSame(
			array( 'id', 'post_type', 'author_id', 'date', 'modified', 'tax_category', 'tax_category_ids', 'tax_post_tag', 'tax_post_tag_ids', 'meta_price', 'meta_rating' ),
			( new ContentSchema( new Options() ) )->filterable()
		);
	}

	public function test_sortable(): void {
		$this->assertSame(
			array( 'id', 'date', 'modified', 'title', 'meta_price', 'meta_rating' ),
			( new ContentSchema( new Options() ) )->sortable()
		);
	}

	public function test_searchable_order(): void {
		$this->assertSame(
			array( 'title', 'tax_category', 'tax_post_tag', 'excerpt', 'content', 'meta_price', 'meta_rating' ),
			( new ContentSchema( new Options() ) )->searchable()
		);
	}
}
