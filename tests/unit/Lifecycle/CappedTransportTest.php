<?php
/**
 * Tests for CappedTransport.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Lifecycle;

use Meilisearch\WordPress\Lifecycle\CappedTransport;
use Meilisearch\WordPress\Tests\Unit\Support\FakeTransport;
use Meilisearch\WordPress\Tests\Unit\TestCase;

/**
 * The timeout cap used by the site-deletion cleanup.
 */
final class CappedTransportTest extends TestCase {

	public function test_longer_timeouts_are_capped_and_shorter_ones_kept(): void {
		$inner  = ( new FakeTransport() )->respond( 202, array() )->respond( 202, array() );
		$capped = new CappedTransport( $inner, 5.0 );

		$capped->send( 'DELETE', 'http://ms/indexes/a', array(), null, 15.0 );
		$capped->send( 'DELETE', 'http://ms/indexes/b', array(), null, 2.0 );

		$this->assertSame( array( 5.0, 2.0 ), array_column( $inner->requests(), 'timeout' ) );
		$this->assertSame( 'http://ms/indexes/a', $inner->requests()[0]['url'] );
	}
}
