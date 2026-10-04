<?php
/**
 * Required index attributes for one logical index.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * Schema contract.
 */
interface Schema {

	/**
	 * Attributes the plugin filters on.
	 *
	 * @return list<string>
	 */
	public function filterable(): array;

	/**
	 * Attributes the plugin sorts on.
	 *
	 * @return list<string>
	 */
	public function sortable(): array;

	/**
	 * Searchable attributes in ranking order.
	 *
	 * @return list<string>
	 */
	public function searchable(): array;
}
