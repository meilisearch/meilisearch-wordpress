<?php
/**
 * Declares an empty WooCommerce class; required only by tests that run in a separate process.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}
