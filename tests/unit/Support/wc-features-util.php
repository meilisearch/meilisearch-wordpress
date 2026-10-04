<?php
/**
 * Double of WooCommerce's FeaturesUtil (namespaced, so it cannot live in wp-doubles.php).
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( FeaturesUtil::class ) ) {
	final class FeaturesUtil {
		/**
		 * Recorded calls: [ feature id, plugin file, positive compatibility ].
		 *
		 * @var list<array{0: string, 1: string, 2: bool}>
		 */
		public static $declared = [];

		public static function declare_compatibility( string $feature_id, string $plugin_file, bool $positive_compatibility = true ): bool {
			self::$declared[] = [ $feature_id, $plugin_file, $positive_compatibility ];
			return true;
		}
	}
}
