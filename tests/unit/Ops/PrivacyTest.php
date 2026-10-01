<?php
/**
 * Privacy policy text tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Ops;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Ops\Privacy;
use Meilisearch\WordPress\Tests\Unit\TestCase;
use Mockery;

final class PrivacyTest extends TestCase {

	public function test_register_hooks_admin_init(): void {
		$privacy = new Privacy();
		$privacy->register();

		self::assertNotFalse( has_action( 'admin_init', array( $privacy, 'add_policy_content' ) ) );
	}

	public function test_policy_text_is_suggested_through_kses(): void {
		Functions\expect( 'wp_kses_post' )->once()->andReturnFirstArg();
		Functions\expect( 'wp_add_privacy_policy_content' )
			->once()
			->with(
				'Meilisearch',
				Mockery::on(
					static fn ( string $text ): bool => str_contains( $text, 'Suggested text:' )
						&& str_contains( $text, 'Meilisearch Cloud' )
						&& str_contains( $text, 'directly from your browser' )
						&& str_contains( $text, 'does not set tracking cookies' )
				)
			);

		( new Privacy() )->add_policy_content();
	}
}
