<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\Support;

/**
 * Thrown by the WP_CLI test shim wherever WP-CLI would exit the process. The code is the exit status.
 */
final class CliExit extends \RuntimeException {
}
