<?php
/**
 * Unit test bootstrap: Composer autoloader, plugin constants and WordPress class doubles.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

$meilisearch_root = dirname( __DIR__, 2 );

require_once $meilisearch_root . '/vendor/autoload.php';

// Plugin files exit unless ABSPATH is defined; unit tests never load WordPress itself.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $meilisearch_root . '/wordpress/' );
}

define( 'MEILISEARCH_VERSION', '1.0.0' );
define( 'MEILISEARCH_FILE', $meilisearch_root . '/meilisearch.php' );
define( 'MEILISEARCH_DIR', $meilisearch_root . '/' );

require_once __DIR__ . '/Support/wp-doubles.php';
