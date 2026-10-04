<?php
/**
 * Stand-in for WP-CLI's base command class (Action Scheduler's migration command extends it).
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile
 *
 * @package Meilisearch
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_CLI_Command', false ) ) {
	/**
	 * Empty base class.
	 */
	abstract class WP_CLI_Command {

		public function __construct() {}
	}
}
