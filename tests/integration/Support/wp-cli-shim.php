<?php
/**
 * Loads the WP-CLI test shim. Safe to require several times.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

require_once __DIR__ . '/CliExit.php';
require_once __DIR__ . '/ShimProgressBar.php';
require_once __DIR__ . '/class-wp-cli-command.php';
require_once __DIR__ . '/class-wp-cli.php';
require_once __DIR__ . '/wp-cli-utils.php';
