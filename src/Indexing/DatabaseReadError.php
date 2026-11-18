<?php
/**
 * A SELECT of the reindex that the database did not answer.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * Raised instead of treating a failed query as "no rows": wpdb reports both as an empty result.
 */
final class DatabaseReadError extends \RuntimeException {}
