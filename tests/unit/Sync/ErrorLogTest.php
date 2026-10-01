<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Sync;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class ErrorLogTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->stub_options( array( Options::LOG => array() ) );
	}

	public function test_add_prepends_and_uses_autoload_off(): void {
		$log    = new ErrorLog();
		$before = time();
		$log->add( 'sync', 'first' );
		$log->add( 'search', 'second' );

		$all = $log->all();
		$this->assertSame( array( 'search', 'sync' ), array_column( $all, 'context' ) );
		$this->assertSame( array( 'second', 'first' ), array_column( $all, 'message' ) );
		$this->assertGreaterThanOrEqual( $before, $all[0]['time'] );
		$this->assertFalse( $this->option_autoload[ Options::LOG ] );
	}

	public function test_add_trims_to_max(): void {
		$log = new ErrorLog();
		for ( $i = 1; $i <= ErrorLog::MAX + 5; $i++ ) {
			$log->add( 'sync', 'error ' . $i );
		}

		$all = $log->all();
		$this->assertCount( ErrorLog::MAX, $all );
		$this->assertSame( 'error 55', $all[0]['message'] );
		$this->assertSame( 'error 6', $all[ ErrorLog::MAX - 1 ]['message'] );
	}

	public function test_all_ignores_malformed_entries(): void {
		$this->option_store[ Options::LOG ] = array(
			array(
				'time'    => '5',
				'context' => 'sync',
				'message' => 'ok',
			),
			'garbage',
			array( 'time' => 1 ),
		);

		$this->assertSame(
			array(
				array(
					'time'    => 5,
					'context' => 'sync',
					'message' => 'ok',
				),
			),
			( new ErrorLog() )->all()
		);
	}

	public function test_all_handles_non_array_option(): void {
		$this->option_store[ Options::LOG ] = 'corrupted';

		$this->assertSame( array(), ( new ErrorLog() )->all() );
	}

	public function test_clear(): void {
		$log = new ErrorLog();
		$log->add( 'sync', 'x' );
		$log->clear();

		$this->assertSame( array(), $log->all() );
	}

	public function test_add_redacts_the_configured_keys(): void {
		$this->stub_options(
			array(
				Options::LOG        => array(),
				Options::ADMIN_KEY  => 'admin-secret',
				Options::CONNECTION => array( 'search_key' => 'search-secret' ),
			)
		);

		( new ErrorLog() )->add( 'sync', 'API key `admin-secret` is invalid; search-secret too' );

		$this->assertSame( 'API key `…` is invalid; … too', ( new ErrorLog() )->all()[0]['message'] );
	}
}
