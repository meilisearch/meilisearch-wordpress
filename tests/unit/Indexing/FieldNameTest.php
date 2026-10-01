<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Meilisearch\WordPress\Indexing\FieldName;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class FieldNameTest extends TestCase {

	/**
	 * @dataProvider names
	 */
	public function test_normalize( string $raw, ?string $expected ): void {
		$this->assertSame( $expected, FieldName::normalize( $raw ) );
	}

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function names(): array {
		return array(
			'plain'                  => array( 'category', 'category' ),
			'underscore kept'        => array( 'post_tag', 'post_tag' ),
			'dash'                   => array( 'my-key', 'my_key' ),
			'dot'                    => array( 'my.key', 'my_key' ),
			'uppercase kept'         => array( 'MyKey', 'MyKey' ),
			'non-ascii collapsed'    => array( 'couleur-é', 'couleur' ),
			'wc attribute non-ascii' => array( 'pa_couleur-é', 'pa_couleur' ),
			'inner non-ascii'        => array( 'prix_ttc_€_eur', 'prix_ttc_eur' ),
			'leading underscore'     => array( '_price', 'price' ),
			'runs collapse'          => array( 'a__b---c', 'a_b_c' ),
			'digits'                 => array( '2024-sales', '2024_sales' ),
			'invalid utf-8'          => array( "bad\xFFkey", 'bad_key' ),
			'empty'                  => array( '', null ),
			'only symbols'           => array( '---', null ),
			'only non-ascii'         => array( 'éé', null ),
			'only underscores'       => array( '__', null ),
		);
	}

	public function test_distinct_raw_names_can_collide(): void {
		// Documented behavior: builders keep the first key that maps to a field name,
		// ContentTab drops later duplicates when saving.
		$this->assertSame( FieldName::normalize( 'my-key' ), FieldName::normalize( 'my.key' ) );
		$this->assertSame( FieldName::normalize( '_price' ), FieldName::normalize( 'price' ) );
	}

	public function test_output_is_a_valid_filter_field(): void {
		foreach ( array( 'pa_couleur-é', 'my.key', '_edit_lock', 'a b c' ) as $raw ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_]+$/', (string) FieldName::normalize( $raw ) );
		}
	}
}
