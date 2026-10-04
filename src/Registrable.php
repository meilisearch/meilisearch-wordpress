<?php
/**
 * Contract for services that attach WordPress hooks.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * A service whose actions and filters are attached in register().
 */
interface Registrable {

	/**
	 * Attaches the service's actions and filters.
	 */
	public function register(): void;
}
