<?php
/**
 * FilterBuilder tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Meilisearch\WordPress\Search\FilterBuilder;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * Filter expression builder.
 *
 * @covers \Meilisearch\WordPress\Search\FilterBuilder
 */
final class FilterBuilderTest extends TestCase {

	/**
	 * Review Focus #2: quotes and backslashes never reach a filter unescaped.
	 *
	 * @dataProvider escaping_cases
	 *
	 * @param string $raw      Raw value.
	 * @param string $expected Filter literal.
	 */
	public function test_value_escaping( string $raw, string $expected ): void {
		self::assertSame( $expected, FilterBuilder::value( $raw ) );
	}

	/**
	 * Raw value => expected literal. Backslashes are escaped first, then double quotes.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function escaping_cases(): array {
		return array(
			'plain'              => array( 'red', '"red"' ),
			'quote'              => array( 'say "hi"', '"say \\"hi\\""' ),
			'backslash'          => array( 'C:\\path', '"C:\\\\path"' ),
			'backslash-quote'    => array( 'a\\"b', '"a\\\\\\"b"' ),
			'trailing backslash' => array( 'end\\', '"end\\\\"' ),
			'injection attempt'  => array( 'x" OR post_type = "page', '"x\\" OR post_type = \\"page"' ),
			'empty'              => array( '', '""' ),
			'unicode'            => array( 'pa_couleur-é', '"pa_couleur-é"' ),
		);
	}

	/**
	 * Booleans and integers are emitted unquoted.
	 */
	public function test_scalar_values(): void {
		self::assertSame( 'true', FilterBuilder::value( true ) );
		self::assertSame( 'false', FilterBuilder::value( false ) );
		self::assertSame( '42', FilterBuilder::value( 42 ) );
		self::assertSame( '-7', FilterBuilder::value( -7 ) );
	}

	/**
	 * Floats keep a decimal point and never use exponent notation.
	 *
	 * @dataProvider float_cases
	 *
	 * @param float  $value    Value.
	 * @param string $expected Literal.
	 */
	public function test_float_formatting( float $value, string $expected ): void {
		self::assertSame( $expected, FilterBuilder::value( $value ) );
	}

	/**
	 * Float => literal.
	 *
	 * @return array<string, array{0: float, 1: string}>
	 */
	public static function float_cases(): array {
		return array(
			'integral keeps decimal' => array( 49.0, '49.0' ),
			'two decimals'           => array( 19.99, '19.99' ),
			'tenth'                  => array( 0.1, '0.1' ),
			'negative'               => array( -2.5, '-2.5' ),
			'negative zero'          => array( -0.0, '0.0' ),
			'huge, no exponent'      => array( 1.0E+25, '10000000000000000905969664.0' ),
			'small, no exponent'     => array( 1.5E-7, '0.00000015' ),
		);
	}

	/**
	 * A comma-decimal locale does not change the output.
	 */
	public function test_float_formatting_ignores_locale(): void {
		$previous = setlocale( LC_NUMERIC, '0' );
		if ( false === setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR' ) ) {
			self::markTestSkipped( 'No comma-decimal locale installed.' );
		}
		try {
			self::assertSame( '49.5', FilterBuilder::value( 49.5 ) );
		} finally {
			setlocale( LC_NUMERIC, false === $previous ? 'C' : $previous );
		}
	}

	/**
	 * INF cannot be expressed in a filter.
	 */
	public function test_non_finite_float_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		FilterBuilder::value( INF );
	}

	/**
	 * Valid names are returned unchanged.
	 *
	 * @dataProvider valid_fields
	 *
	 * @param string $name Field name.
	 */
	public function test_valid_field_names( string $name ): void {
		self::assertSame( $name, FilterBuilder::field( $name ) );
	}

	/**
	 * Valid field names.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function valid_fields(): array {
		return array(
			'simple'     => array( 'post_type' ),
			'nested'     => array( 'categories.lvl0' ),
			'normalized' => array( 'tax_pa_couleur_ids' ),
		);
	}

	/**
	 * Invalid names throw.
	 *
	 * @dataProvider invalid_fields
	 *
	 * @param string $name Field name.
	 */
	public function test_invalid_field_names_throw( string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		FilterBuilder::field( $name );
	}

	/**
	 * Invalid field names.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_fields(): array {
		return array(
			'empty'     => array( '' ),
			'space'     => array( 'post type' ),
			'dash'      => array( 'my-key' ),
			'quote'     => array( 'a"b' ),
			'operator'  => array( 'price>1' ),
			'non-ascii' => array( 'couleur_é' ),
			'keyword'   => array( 'AND' ),
		);
	}

	/**
	 * Every comparison operator formats field, operator and value.
	 */
	public function test_compare_operators(): void {
		self::assertSame( 'price = 10', FilterBuilder::compare( 'price', '=', 10 ) );
		self::assertSame( 'price != 10', FilterBuilder::compare( 'price', '!=', 10 ) );
		self::assertSame( 'price < 10.5', FilterBuilder::compare( 'price', '<', 10.5 ) );
		self::assertSame( 'price <= 49.0', FilterBuilder::compare( 'price', '<=', 49.0 ) );
		self::assertSame( 'date > 1704067200', FilterBuilder::compare( 'date', '>', 1704067200 ) );
		self::assertSame( 'date >= 1', FilterBuilder::compare( 'date', '>=', 1 ) );
		self::assertSame( 'in_stock = true', FilterBuilder::compare( 'in_stock', '=', true ) );
		self::assertSame( 'meta_color = "blue"', FilterBuilder::compare( 'meta_color', '=', 'blue' ) );
	}

	/**
	 * Unsupported operators are rejected.
	 */
	public function test_unknown_operator_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		FilterBuilder::compare( 'price', 'LIKE', 'x' );
	}

	/**
	 * Field names are validated by every builder.
	 */
	public function test_compare_rejects_invalid_field(): void {
		$this->expectException( \InvalidArgumentException::class );
		FilterBuilder::compare( 'price = 1 OR x', '=', 1 );
	}

	/**
	 * IN / NOT IN lists format each value.
	 */
	public function test_in_and_not_in(): void {
		self::assertSame( 'post_type IN ["post", "page"]', FilterBuilder::in( 'post_type', array( 'post', 'page' ) ) );
		self::assertSame( 'author_id NOT IN [3, 4]', FilterBuilder::not_in( 'author_id', array( 3, 4 ) ) );
		self::assertSame( 'price IN [1.5, 2]', FilterBuilder::in( 'price', array( 1.5, 2 ) ) );
	}

	/**
	 * An empty IN list is valid syntax (matches nothing).
	 */
	public function test_empty_in_list_is_valid(): void {
		self::assertSame( 'tax_category_ids IN []', FilterBuilder::in( 'tax_category_ids', array() ) );
		self::assertSame( 'tax_category_ids NOT IN []', FilterBuilder::not_in( 'tax_category_ids', array() ) );
	}

	/**
	 * Ranges use "field low TO high".
	 */
	public function test_between(): void {
		self::assertSame( 'price 10 TO 20.5', FilterBuilder::between( 'price', 10, 20.5 ) );
		self::assertSame( 'price -5.0 TO 0', FilterBuilder::between( 'price', -5.0, 0 ) );
	}

	/**
	 * Presence operators.
	 */
	public function test_exists_not_exists_and_is_empty(): void {
		self::assertSame( 'meta_price EXISTS', FilterBuilder::exists( 'meta_price' ) );
		self::assertSame( 'meta_price NOT EXISTS', FilterBuilder::not_exists( 'meta_price' ) );
		self::assertSame( 'tax_genre_ids IS EMPTY', FilterBuilder::is_empty( 'tax_genre_ids' ) );
	}

	/**
	 * Combinators drop empty parts and parenthesize multiple parts.
	 */
	public function test_all_and_any(): void {
		self::assertSame( '', FilterBuilder::all( array() ) );
		self::assertSame( '', FilterBuilder::any( array( '', '' ) ) );
		self::assertSame( 'a = 1', FilterBuilder::all( array( '', 'a = 1' ) ) );
		self::assertSame( '(a = 1) AND (b = 2)', FilterBuilder::all( array( 'a = 1', '', 'b = 2' ) ) );
		self::assertSame( '(a = 1) OR (b = 2)', FilterBuilder::any( array( 'a = 1', 'b = 2' ) ) );
		self::assertSame(
			'((a = 1) OR (b = 2)) AND (c = 3)',
			FilterBuilder::all( array( FilterBuilder::any( array( 'a = 1', 'b = 2' ) ), 'c = 3' ) )
		);
	}

	/**
	 * Negation wraps the expression.
	 */
	public function test_negate(): void {
		self::assertSame( 'NOT (a = 1)', FilterBuilder::negate( 'a = 1' ) );
		self::assertSame( 'NOT ((a = 1) OR (b = 2))', FilterBuilder::negate( '(a = 1) OR (b = 2)' ) );
	}

	/**
	 * Negating "no constraint" is a programming error.
	 */
	public function test_negate_empty_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		FilterBuilder::negate( '' );
	}
}
