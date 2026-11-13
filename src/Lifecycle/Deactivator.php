<?php
/**
 * Deactivation (spec § 11.1).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Lifecycle;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;

/**
 * Deactivation (spec § 11.1): cancel pending actions and ask for a reindex; settings and indexes are kept.
 */
final class Deactivator {

	/**
	 * Suspends sync on the current site, or on every site for a network deactivation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			Sites::for_each( array( self::class, 'suspend_sync' ), get_current_network_id() );
			return;
		}
		self::suspend_sync();
	}

	/**
	 * Cancels the pending actions of the current site and flags its populated indexes as needing a reindex.
	 *
	 * The cancelled jobs carried changes (removals of unpublished content included) that exist nowhere else,
	 * and nothing is tracked while the plugin is inactive. Only a full reindex, whose sweep removes stale
	 * documents, brings the index back in line, so the flag makes the reindex notice, the Status tab and
	 * Site Health ask for one after reactivation. An index that no reindex has filled yet is already flagged
	 * or not in use.
	 */
	public static function suspend_sync(): void {
		Sites::unschedule_actions();

		$options = new Options();
		foreach ( ( new IndexNames( $options ) )->active_logicals() as $logical ) {
			if ( $options->is_populated( $logical ) ) {
				$options->flag_reindex( $logical, true );
			}
		}
	}
}
