<?php
/**
 * Content index schema (spec § 5.4).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

use Meilisearch\WordPress\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Required attributes of `{prefix}_content`.
 */
final class ContentSchema implements Schema {

	/**
	 * Constructor.
	 *
	 * @param Options $options Plugin options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * Filterable attributes (`id` serves the reindex orphan sweep: `id > X`).
	 *
	 * @return list<string>
	 */
	public function filterable(): array {
		$fields = array( 'id', 'post_type', 'author_id', 'date', 'modified' );
		foreach ( $this->taxonomy_fields() as $field ) {
			$fields[] = $field;
			$fields[] = $field . '_ids';
		}

		return self::unique( array_merge( $fields, $this->meta_fields() ) );
	}

	/**
	 * Sortable attributes. `id` serves the reindex orphan sweep (`id:asc`); every
	 * meta field is sortable (numbers sort numerically, strings lexicographically).
	 *
	 * @return list<string>
	 */
	public function sortable(): array {
		return self::unique( array_merge( array( 'id', 'date', 'modified', 'title' ), $this->meta_fields() ) );
	}

	/**
	 * Searchable attributes in ranking order.
	 *
	 * @return list<string>
	 */
	public function searchable(): array {
		return self::unique( array_merge( array( 'title' ), $this->taxonomy_fields(), array( 'excerpt', 'content' ), $this->meta_fields() ) );
	}

	/**
	 * `tax_{t}` names for every enabled taxonomy whose name normalizes.
	 *
	 * @return list<string>
	 */
	private function taxonomy_fields(): array {
		$fields = array();
		foreach ( $this->options->all_taxonomies() as $taxonomy ) {
			$name = FieldName::normalize( (string) $taxonomy );
			if ( null !== $name ) {
				$fields[] = 'tax_' . $name;
			}
		}

		return $fields;
	}

	/**
	 * `meta_{k}` names for every indexed meta key whose name normalizes.
	 *
	 * @return list<string>
	 */
	private function meta_fields(): array {
		$fields = array();
		foreach ( $this->options->all_meta_keys() as $key ) {
			$name = FieldName::normalize( (string) $key );
			if ( null !== $name ) {
				$fields[] = 'meta_' . $name;
			}
		}

		return $fields;
	}

	/**
	 * De-duplicates while keeping the first occurrence's position.
	 *
	 * @param string[] $fields Field names.
	 * @return list<string>
	 */
	private static function unique( array $fields ): array {
		return array_values( array_unique( $fields ) );
	}
}
