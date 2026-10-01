<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Lifecycle\Activator;
use Meilisearch\WordPress\Lifecycle\Deactivator;
use Meilisearch\WordPress\Lifecycle\SiteSeeder;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\Queue;

final class LifecycleTest extends TestCase {

	private const OPTIONS = array(
		Options::CONNECTION,
		Options::ADMIN_KEY,
		Options::CONTENT,
		Options::WOOCOMMERCE,
		Options::SEARCH,
		Options::STATE,
		Options::LOG,
	);

	public function test_activation_and_deactivation_hooks_are_registered(): void {
		$basename = plugin_basename( MEILISEARCH_FILE );

		self::assertNotFalse( has_action( 'activate_' . $basename, array( Activator::class, 'activate' ) ) );
		self::assertNotFalse( has_action( 'deactivate_' . $basename, array( Deactivator::class, 'deactivate' ) ) );
	}

	public function test_activation_seeds_missing_options_without_overwriting_existing_ones(): void {
		foreach ( self::OPTIONS as $name ) {
			delete_option( $name );
		}
		update_option( Options::SEARCH, array_merge( Options::defaults()[ Options::SEARCH ], array( 'highlight' => true ) ) );

		Activator::activate( false );

		foreach ( self::OPTIONS as $name ) {
			self::assertNotFalse( get_option( $name ), $name );
		}
		self::assertTrue( get_option( Options::SEARCH )['highlight'] );
	}

	public function test_admin_init_seeds_a_site_lazily_once(): void {
		delete_option( Options::CONTENT );
		$plugin = Plugin::instance();
		self::assertNotNull( $plugin );
		$seeder = $plugin->get( 'site_seeder' );
		self::assertInstanceOf( SiteSeeder::class, $seeder );

		$seeder->seed_current_site();
		self::assertNotFalse( get_option( Options::CONTENT ) );

		$custom = array_merge( Options::defaults()[ Options::CONTENT ], array( 'post_types' => array( 'page' ) ) );
		update_option( Options::CONTENT, $custom );
		$seeder->seed_current_site();
		self::assertSame( $custom, get_option( Options::CONTENT ) );
	}

	public function test_deactivation_cancels_only_the_plugin_group(): void {
		as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			Queue::SYNC_POSTS,
			array(
				array(
					'index'   => 'content',
					'ids'     => array( 1 ),
					'attempt' => 1,
				),
			),
			Queue::GROUP
		);
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'meilisearch_test_other_group', array(), 'meilisearch-test-other' );

		Deactivator::deactivate( false );

		self::assertFalse( as_has_scheduled_action( Queue::SYNC_POSTS, null, Queue::GROUP ) );
		self::assertTrue( as_has_scheduled_action( 'meilisearch_test_other_group', null, 'meilisearch-test-other' ) );
	}
}
