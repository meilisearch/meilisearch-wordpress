<?php
/**
 * Computes the index settings the plugin owns (spec § 5.4).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * Initial settings, merge patches and drift for every logical index.
 *
 * Only `searchableAttributes`, `filterableAttributes` and `sortableAttributes`
 * are ever produced. Filterable and sortable are merged as a union with the
 * current values; searchable is append-only and left alone when it is ["*"].
 */
final class SettingsBuilder {

	/**
	 * Fields the plugin filters with ranges (besides every meta_* field).
	 */
	public const RANGE_FIELDS = array( 'id', 'date', 'modified', 'price', 'rating_average' );

	/**
	 * Logical index => schema.
	 *
	 * @var array<string, Schema>
	 */
	private array $schemas;

	/**
	 * Constructor.
	 *
	 * @param array<string, Schema> $schemas Logical index => schema.
	 */
	public function __construct( array $schemas ) {
		$this->schemas = $schemas;
	}

	/**
	 * Whether a schema exists for the logical index.
	 *
	 * @param string $logical Logical index.
	 * @return bool
	 */
	public function has( string $logical ): bool {
		return isset( $this->schemas[ $logical ] );
	}

	/**
	 * Settings applied to a newly created index.
	 *
	 * @param string $logical Logical index.
	 * @return array{searchableAttributes: list<string>, filterableAttributes: list<string>, sortableAttributes: list<string>}
	 */
	public function initial( string $logical ): array {
		return $this->required( $logical );
	}

	/**
	 * Minimal PATCH body that adds what is missing; [] when nothing changes.
	 *
	 * Existing filterable entries (strings and `{attributePatterns, features}`
	 * objects) are sent back untouched, in order, with missing names appended.
	 *
	 * @param string               $logical Logical index.
	 * @param array<string, mixed> $current Result of GET /indexes/{uid}/settings.
	 * @return array<string, array<int, mixed>>
	 */
	public function patch( string $logical, array $current ): array {
		$required = $this->required( $logical );
		$patch    = array();

		$filterable = self::list_of( $current['filterableAttributes'] ?? array() );
		$missing    = self::missing_filterable( $required['filterableAttributes'], $filterable );
		if ( array() !== $missing ) {
			$patch['filterableAttributes'] = array_merge( $filterable, $missing );
		}

		$sortable = self::strings( $current['sortableAttributes'] ?? array() );
		$missing  = array_values( array_diff( $required['sortableAttributes'], $sortable ) );
		if ( array() !== $missing ) {
			$patch['sortableAttributes'] = array_merge( $sortable, $missing );
		}

		$searchable = self::strings( $current['searchableAttributes'] ?? array( '*' ) );
		if ( array( '*' ) !== $searchable ) {
			$missing = array_values( array_diff( $required['searchableAttributes'], $searchable ) );
			if ( array() !== $missing ) {
				$patch['searchableAttributes'] = array_merge( $searchable, $missing );
			}
		}

		return $patch;
	}

	/**
	 * Required filterable/sortable attributes absent from the current settings.
	 *
	 * @param string               $logical Logical index.
	 * @param array<string, mixed> $current Result of GET /indexes/{uid}/settings.
	 * @return list<string>
	 */
	public function missing( string $logical, array $current ): array {
		$required   = $this->required( $logical );
		$filterable = self::missing_filterable( $required['filterableAttributes'], self::list_of( $current['filterableAttributes'] ?? array() ) );
		$sortable   = array_diff( $required['sortableAttributes'], self::strings( $current['sortableAttributes'] ?? array() ) );

		return array_values( array_unique( array_merge( $filterable, $sortable ) ) );
	}

	/**
	 * Required range-filtered attributes (id, date, modified, price,
	 * rating_average, meta_*) whose first matching filterable rule is an
	 * object rule with `features.filter.comparison` off (Meilisearch applies
	 * the first matching rule; string rules allow every operator).
	 *
	 * @param string               $logical Logical index.
	 * @param array<string, mixed> $current Result of GET /indexes/{uid}/settings.
	 * @return list<string>
	 */
	public function comparison_disabled( string $logical, array $current ): array {
		$rules    = self::list_of( $current['filterableAttributes'] ?? array() );
		$disabled = array();
		foreach ( $this->required( $logical )['filterableAttributes'] as $name ) {
			if ( ! in_array( $name, self::RANGE_FIELDS, true ) && ! str_starts_with( $name, 'meta_' ) ) {
				continue;
			}
			$rule = self::first_rule( $name, $rules );
			if ( is_array( $rule ) && empty( $rule['features']['filter']['comparison'] ) ) {
				$disabled[] = $name;
			}
		}

		return $disabled;
	}

	/**
	 * Required settings after the `meilisearch_index_settings` filter.
	 *
	 * @param string $logical Logical index.
	 * @return array{searchableAttributes: list<string>, filterableAttributes: list<string>, sortableAttributes: list<string>}
	 *
	 * @throws \InvalidArgumentException For an unknown logical index.
	 */
	private function required( string $logical ): array {
		if ( ! $this->has( $logical ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown logical index "%s".', esc_html( $logical ) ) );
		}
		$schema   = $this->schemas[ $logical ];
		$settings = array(
			'searchableAttributes' => $schema->searchable(),
			'filterableAttributes' => $schema->filterable(),
			'sortableAttributes'   => $schema->sortable(),
		);

		/**
		 * Filters the settings the plugin requires on an index.
		 *
		 * Only the three keys below are read back; every value must be a list of
		 * attribute names made of letters, digits, '_' and '.'.
		 *
		 * @param array{searchableAttributes: list<string>, filterableAttributes: list<string>, sortableAttributes: list<string>} $settings Settings.
		 * @param string                                                                                              $logical  Logical index.
		 */
		$filtered = apply_filters( 'meilisearch_index_settings', $settings, $logical );
		$filtered = is_array( $filtered ) ? $filtered : $settings;

		return array(
			'searchableAttributes' => self::names( $filtered['searchableAttributes'] ?? array() ),
			'filterableAttributes' => self::names( $filtered['filterableAttributes'] ?? array() ),
			'sortableAttributes'   => self::names( $filtered['sortableAttributes'] ?? array() ),
		);
	}

	/**
	 * Required filterable names not covered by an existing rule.
	 *
	 * @param string[]          $required Required names.
	 * @param array<int, mixed> $current  Current filterable rules.
	 * @return list<string>
	 */
	private static function missing_filterable( array $required, array $current ): array {
		$missing = array();
		foreach ( $required as $name ) {
			if ( ! self::is_covered( $name, $current ) ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}

	/**
	 * Whether a name is covered by a string rule (exact) or an object rule
	 * (any of its attributePatterns, with Meilisearch's `*` wildcard rules).
	 *
	 * @param string            $name  Attribute name.
	 * @param array<int, mixed> $rules Current filterable rules.
	 * @return bool
	 */
	private static function is_covered( string $name, array $rules ): bool {
		return null !== self::first_rule( $name, $rules );
	}

	/**
	 * First rule matching a name (Meilisearch uses the first match).
	 *
	 * @param string            $name  Attribute name.
	 * @param array<int, mixed> $rules Current filterable rules.
	 * @return string|array<string, mixed>|null
	 */
	private static function first_rule( string $name, array $rules ): string|array|null {
		foreach ( $rules as $rule ) {
			if ( is_string( $rule ) && $rule === $name ) {
				return $rule;
			}
			if ( is_array( $rule ) && isset( $rule['attributePatterns'] ) && is_array( $rule['attributePatterns'] ) ) {
				foreach ( $rule['attributePatterns'] as $pattern ) {
					if ( is_string( $pattern ) && self::pattern_matches( $pattern, $name ) ) {
						return $rule;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Meilisearch attribute pattern matching: `*`, `prefix*`, `*suffix`, `*infix*`, exact.
	 *
	 * @param string $pattern Pattern.
	 * @param string $name    Attribute name.
	 * @return bool
	 */
	private static function pattern_matches( string $pattern, string $name ): bool {
		if ( '*' === $pattern ) {
			return true;
		}
		$starts = str_starts_with( $pattern, '*' );
		$ends   = str_ends_with( $pattern, '*' );
		if ( $starts && $ends ) {
			return str_contains( $name, substr( $pattern, 1, -1 ) );
		}
		if ( $starts ) {
			return str_ends_with( $name, substr( $pattern, 1 ) );
		}
		if ( $ends ) {
			return str_starts_with( $name, substr( $pattern, 0, -1 ) );
		}

		return $pattern === $name;
	}

	/**
	 * Current value as a list (JSON arrays decode to lists).
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, mixed>
	 */
	private static function list_of( mixed $value ): array {
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * String entries of a current value.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return array_values( array_filter( self::list_of( $value ), 'is_string' ) );
	}

	/**
	 * Valid, unique attribute names from a filtered value.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	private static function names( mixed $value ): array {
		$names = array();
		foreach ( self::strings( $value ) as $name ) {
			if ( 1 === preg_match( '/^[A-Za-z0-9_.]+$/', $name ) ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}
}
