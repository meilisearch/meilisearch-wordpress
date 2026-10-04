<?php
/**
 * Tests for ClientFactory.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class ClientFactoryTest extends TestCase {

	private FakeTransport $transport;

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.2' );
		$this->transport = new FakeTransport();
	}

	private function factory(): ClientFactory {
		return new ClientFactory( new Options(), $this->transport );
	}

	public function test_unconfigured_factory_throws(): void {
		$this->stub_options();
		$factory = $this->factory();

		$this->assertFalse( $factory->is_configured() );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Meilisearch is not configured.' );

		$factory->client();
	}

	public function test_client_uses_options_and_user_agent(): void {
		$this->stub_options(
			[
				Options::CONNECTION => [ 'host' => 'https://ms.example.com/meili/' ],
				Options::ADMIN_KEY  => 'admin-key',
			]
		);
		$this->transport->respond( 200, [ 'pkgVersion' => '1.34.0' ] );
		$factory = $this->factory();

		$this->assertTrue( $factory->is_configured() );
		$factory->client()->version();

		$request = $this->transport->last();
		$this->assertSame( 'https://ms.example.com/meili/version', $request['url'] );
		$this->assertSame( 'Bearer admin-key', $request['headers']['Authorization'] );
		$this->assertSame( 'Meilisearch-WordPress/' . MEILISEARCH_VERSION . ' WordPress/7.1.2', $request['headers']['User-Agent'] );
	}

	public function test_client_is_cached_until_credentials_change(): void {
		$this->stub_options(
			[
				Options::CONNECTION => [ 'host' => 'https://ms.example.com' ],
				Options::ADMIN_KEY  => 'admin-key',
			]
		);
		$factory = $this->factory();
		$first   = $factory->client();

		$this->assertSame( $first, $factory->client() );

		$this->option_store[ Options::ADMIN_KEY ] = 'rotated-key';

		$this->assertNotSame( $first, $factory->client() );
	}
}
