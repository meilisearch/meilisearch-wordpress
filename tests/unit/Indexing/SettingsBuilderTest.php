<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Brain\Monkey\Filters;
use Meilisearch\WordPress\Indexing\ContentSchema;
use Meilisearch\WordPress\Indexing\Schema;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class SettingsBuilderTest extends TestCase {

	private function schema(): Schema {
		return new class() implements Schema {
			public function filterable(): array {
				return array( 'post_type', 'date', 'tax_category_ids' );
			}
			public function sortable(): array {
				return array( 'date', 'title' );
			}
			public function searchable(): array {
				return array( 'title', 'tax_category', 'content' );
			}
		};
	}

	private function builder(): SettingsBuilder {
		return new SettingsBuilder( array( 'content' => $this->schema() ) );
	}

	public function test_has(): void {
		$this->assertTrue( $this->builder()->has( 'content' ) );
		$this->assertFalse( $this->builder()->has( 'products' ) );
	}

	public function test_initial(): void {
		$this->assertSame(
			array(
				'searchableAttributes' => array( 'title', 'tax_category', 'content' ),
				'filterableAttributes' => array( 'post_type', 'date', 'tax_category_ids' ),
				'sortableAttributes'   => array( 'date', 'title' ),
			),
			$this->builder()->initial( 'content' )
		);
	}

	public function test_unknown_logical_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->builder()->initial( 'products' );
	}

	public function test_filter_adjusts_required_settings_and_invalid_names_are_dropped(): void {
		Filters\expectApplied( 'meilisearch_index_settings' )
			->with( \Mockery::type( 'array' ), 'content' )
			->andReturnUsing(
				static function ( array $settings ): array {
					$settings['filterableAttributes'][] = 'categories.lvl0';
					$settings['filterableAttributes'][] = 'bad name"';
					$settings['sortableAttributes']     = array( 'date' );
					$settings['rankingRules']           = array( 'words' );
					return $settings;
				}
			);

		$initial = $this->builder()->initial( 'content' );

		$this->assertSame( array( 'post_type', 'date', 'tax_category_ids', 'categories.lvl0' ), $initial['filterableAttributes'] );
		$this->assertSame( array( 'date' ), $initial['sortableAttributes'] );
		$this->assertArrayNotHasKey( 'rankingRules', $initial );
	}

	public function test_patch_is_empty_when_everything_is_present(): void {
		$current = array(
			'searchableAttributes' => array( 'title', 'tax_category', 'content', 'extra' ),
			'filterableAttributes' => array( 'date', 'post_type', 'tax_category_ids', 'cloud_added' ),
			'sortableAttributes'   => array( 'date', 'title', 'price' ),
			'rankingRules'         => array( 'words' ),
		);

		$this->assertSame( array(), $this->builder()->patch( 'content', $current ) );
	}

	public function test_patch_unions_and_preserves_existing_values(): void {
		$current = array(
			'searchableAttributes' => array( 'content', 'cloud_field' ),
			'filterableAttributes' => array( 'cloud_added', 'date' ),
			'sortableAttributes'   => array( 'price' ),
		);

		$this->assertSame(
			array(
				'filterableAttributes' => array( 'cloud_added', 'date', 'post_type', 'tax_category_ids' ),
				'sortableAttributes'   => array( 'price', 'date', 'title' ),
				'searchableAttributes' => array( 'content', 'cloud_field', 'title', 'tax_category' ),
			),
			$this->builder()->patch( 'content', $current )
		);
	}

	public function test_patch_never_touches_wildcard_searchable(): void {
		$patch = $this->builder()->patch(
			'content',
			array(
				'searchableAttributes' => array( '*' ),
				'filterableAttributes' => array( 'post_type', 'date', 'tax_category_ids' ),
				'sortableAttributes'   => array( 'date', 'title' ),
			)
		);

		$this->assertSame( array(), $patch );
	}

	public function test_patch_keeps_object_rules_untouched_and_treats_their_patterns_as_present(): void {
		$object_rule = array(
			'attributePatterns' => array( 'tax_*', 'dat*' ),
			'features'          => array(
				'facetSearch' => true,
				'filter'      => array(
					'equality'   => true,
					'comparison' => true,
				),
			),
		);
		$current     = array(
			'searchableAttributes' => array( '*' ),
			'filterableAttributes' => array( 'genre', $object_rule ),
			'sortableAttributes'   => array( 'date', 'title' ),
		);

		$patch = $this->builder()->patch( 'content', $current );

		$this->assertSame( array( 'filterableAttributes' => array( 'genre', $object_rule, 'post_type' ) ), $patch );
		$this->assertSame(
			'["genre",{"attributePatterns":["tax_*","dat*"],"features":{"facetSearch":true,"filter":{"equality":true,"comparison":true}}},"post_type"]',
			json_encode( $patch['filterableAttributes'] )
		);
	}

	/**
	 * @dataProvider patterns
	 */
	public function test_pattern_coverage( string $pattern, bool $covers_date ): void {
		$current = array(
			'filterableAttributes' => array( array( 'attributePatterns' => array( $pattern ) ), 'post_type', 'tax_category_ids' ),
			'sortableAttributes'   => array( 'date', 'title' ),
		);

		$this->assertSame( $covers_date ? array() : array( 'date' ), $this->builder()->missing( 'content', $current ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function patterns(): array {
		return array(
			'wildcard' => array( '*', true ),
			'prefix'   => array( 'da*', true ),
			'suffix'   => array( '*te', true ),
			'infix'    => array( '*at*', true ),
			'exact'    => array( 'date', true ),
			'other'    => array( 'dates', false ),
			'nomatch'  => array( 'x*', false ),
		);
	}

	public function test_comparison_disabled_lists_shadowed_range_fields_only(): void {
		$no_comparison = array(
			'attributePatterns' => array( 'post_*', 'date' ),
			'features'          => array(
				'facetSearch' => false,
				'filter'      => array(
					'equality'   => true,
					'comparison' => false,
				),
			),
		);
		$comparison    = array(
			'attributePatterns' => array( 'date' ),
			'features'          => array( 'filter' => array( 'comparison' => true ) ),
		);

		$this->assertSame( array( 'date' ), $this->builder()->comparison_disabled( 'content', array( 'filterableAttributes' => array( $no_comparison, 'tax_category_ids' ) ) ) );
		$this->assertSame( array(), $this->builder()->comparison_disabled( 'content', array( 'filterableAttributes' => array( 'date', $no_comparison ) ) ), 'The first matching rule wins; string rules allow ranges.' );
		$this->assertSame( array(), $this->builder()->comparison_disabled( 'content', array( 'filterableAttributes' => array( $comparison, $no_comparison ) ) ) );
		$this->assertSame( array(), $this->builder()->comparison_disabled( 'content', array() ), 'Missing fields are reported by missing(), not here.' );
	}

	public function test_missing_lists_required_filterable_and_sortable(): void {
		$this->assertSame(
			array( 'post_type', 'tax_category_ids', 'title' ),
			$this->builder()->missing(
				'content',
				array(
					'filterableAttributes' => array( 'date' ),
					'sortableAttributes'   => array( 'date' ),
				)
			)
		);
	}

	public function test_field_names_are_normalized(): void {
		$this->stub_options(
			array(
				Options::CONTENT => array(
					'post_types' => array( 'post' ),
					'taxonomies' => array( 'post' => array( 'pa_couleur-é', '---' ) ),
					'meta_keys'  => array( 'post' => array( 'my.key', 'my-key', '...' ) ),
				),
			)
		);
		$initial = ( new SettingsBuilder( array( 'content' => new ContentSchema( new Options() ) ) ) )->initial( 'content' );

		$this->assertSame(
			array( 'id', 'post_type', 'author_id', 'date', 'modified', 'tax_pa_couleur', 'tax_pa_couleur_ids', 'meta_my_key' ),
			$initial['filterableAttributes']
		);
		$this->assertSame( array( 'id', 'date', 'modified', 'title', 'meta_my_key' ), $initial['sortableAttributes'] );
		$this->assertSame( array( 'title', 'tax_pa_couleur', 'excerpt', 'content', 'meta_my_key' ), $initial['searchableAttributes'] );
	}
}
