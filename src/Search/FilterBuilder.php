<?php
/**
 * Meilisearch filter expression builder.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Builds Meilisearch filter expressions (spec § 9.3).
 *
 * Every value goes through value(): strings are escaped (`\` first, then `"`) and wrapped in
 * double quotes, integers are emitted as-is, floats in a locale-independent fixed-point form that
 * always keeps a decimal point, booleans as true/false. Field names are validated. Combinators
 * wrap each part in parentheses, so any part may itself be a combined expression.
 */
final class FilterBuilder {

	/**
	 * Comparison operators accepted by compare().
	 */
	private const OPERATORS = array( '=', '!=', '<', '<=', '>', '>=' );

	/**
	 * Words the filter parser treats as keywords; never valid as a bare field name.
	 */
	private const KEYWORDS = array( 'AND', 'OR', 'IN', 'NOT', 'TO', 'EXISTS', 'IS', 'NULL', 'EMPTY', 'CONTAINS', 'STARTS', 'WITH', '_geoRadius', '_geoBoundingBox', '_geoPolygon' );

	/**
	 * Validates a field name.
	 *
	 * @param string $name Field name.
	 * @return string The same name.
	 * @throws \InvalidArgumentException When the name contains anything but [A-Za-z0-9_.] or is a keyword.
	 */
	public static function field( string $name ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_.]+$/', $name ) || in_array( $name, self::KEYWORDS, true ) ) {
			throw new \InvalidArgumentException( 'Invalid filter field name.' );
		}
		return $name;
	}

	/**
	 * Formats a value for a filter expression.
	 *
	 * @param string|int|float|bool $value Value.
	 * @return string
	 * @throws \InvalidArgumentException For INF and NAN.
	 */
	public static function value( string|int|float|bool $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			return self::format_float( $value );
		}
		return '"' . str_replace( '"', '\\"', str_replace( '\\', '\\\\', $value ) ) . '"';
	}

	/**
	 * "field op value".
	 *
	 * @param string                $field    Field name.
	 * @param string                $operator One of = != < <= > >=.
	 * @param string|int|float|bool $value    Value.
	 * @return string
	 * @throws \InvalidArgumentException For an unknown operator or invalid field.
	 */
	public static function compare( string $field, string $operator, string|int|float|bool $value ): string {
		if ( ! in_array( $operator, self::OPERATORS, true ) ) {
			throw new \InvalidArgumentException( 'Invalid filter operator.' );
		}
		return self::field( $field ) . ' ' . $operator . ' ' . self::value( $value );
	}

	/**
	 * "field IN [a, b]". An empty list is valid and matches no document.
	 *
	 * @param string                       $field  Field name.
	 * @param array<int, string|int|float> $values Values.
	 * @return string
	 */
	public static function in( string $field, array $values ): string {
		return self::field( $field ) . ' IN [' . self::value_list( $values ) . ']';
	}

	/**
	 * "field NOT IN [a, b]". An empty list is valid and matches every document.
	 *
	 * @param string                       $field  Field name.
	 * @param array<int, string|int|float> $values Values.
	 * @return string
	 */
	public static function not_in( string $field, array $values ): string {
		return self::field( $field ) . ' NOT IN [' . self::value_list( $values ) . ']';
	}

	/**
	 * "field low TO high" (inclusive range).
	 *
	 * @param string    $field Field name.
	 * @param int|float $low   Lower bound.
	 * @param int|float $high  Upper bound.
	 * @return string
	 */
	public static function between( string $field, int|float $low, int|float $high ): string {
		return self::field( $field ) . ' ' . self::value( $low ) . ' TO ' . self::value( $high );
	}

	/**
	 * "field EXISTS".
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	public static function exists( string $field ): string {
		return self::field( $field ) . ' EXISTS';
	}

	/**
	 * "field NOT EXISTS".
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	public static function not_exists( string $field ): string {
		return self::field( $field ) . ' NOT EXISTS';
	}

	/**
	 * "field IS EMPTY" (matches "", [] and {}).
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	public static function is_empty( string $field ): string {
		return self::field( $field ) . ' IS EMPTY';
	}

	/**
	 * Joins parts with AND. Empty parts are dropped; '' when nothing is left; a single part is returned unchanged.
	 *
	 * @param string[] $parts Expressions.
	 * @return string
	 */
	public static function all( array $parts ): string {
		return self::join( $parts, 'AND' );
	}

	/**
	 * Joins parts with OR. Empty parts are dropped; '' when nothing is left; a single part is returned unchanged.
	 *
	 * @param string[] $parts Expressions.
	 * @return string
	 */
	public static function any( array $parts ): string {
		return self::join( $parts, 'OR' );
	}

	/**
	 * "NOT (expression)".
	 *
	 * @param string $expression Expression.
	 * @return string
	 * @throws \InvalidArgumentException For an empty expression.
	 */
	public static function negate( string $expression ): string {
		if ( '' === $expression ) {
			throw new \InvalidArgumentException( 'Cannot negate an empty filter.' );
		}
		return 'NOT (' . $expression . ')';
	}

	/**
	 * Joins non-empty parts, parenthesizing each when there is more than one.
	 *
	 * @param string[] $parts Expressions.
	 * @param string   $glue  AND | OR.
	 * @return string
	 */
	private static function join( array $parts, string $glue ): string {
		$parts = array_values(
			array_filter(
				$parts,
				static function ( string $part ): bool {
					return '' !== $part;
				}
			)
		);
		if ( array() === $parts ) {
			return '';
		}
		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}
		return '(' . implode( ') ' . $glue . ' (', $parts ) . ')';
	}

	/**
	 * Comma-separated formatted values.
	 *
	 * @param array<int, string|int|float> $values Values.
	 * @return string
	 */
	private static function value_list( array $values ): string {
		$formatted = array();
		foreach ( $values as $value ) {
			$formatted[] = self::value( $value );
		}
		return implode( ', ', $formatted );
	}

	/**
	 * Fixed-point, locale-independent float formatting that keeps at least one decimal
	 * (49.0 → "49.0", 19.99 → "19.99") and never uses exponent notation (the filter parser
	 * rejects the "+" of "1.0e+25").
	 *
	 * @param float $value Value.
	 * @return string
	 * @throws \InvalidArgumentException For INF and NAN.
	 */
	private static function format_float( float $value ): string {
		if ( ! is_finite( $value ) ) {
			throw new \InvalidArgumentException( 'Filter numbers must be finite.' );
		}
		if ( 0.0 === $value ) {
			// Also normalizes -0.0.
			return '0.0';
		}
		$formatted = rtrim( sprintf( '%.14F', $value ), '0' );
		if ( str_ends_with( $formatted, '.' ) ) {
			$formatted .= '0';
		}
		return $formatted;
	}
}
