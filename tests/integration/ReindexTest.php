<?php
/**
 * In-place full reindex against WordPress and a real Meilisearch.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration;

use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Plugin;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;

/**
 * Spec § 12.2 ReindexTest.
 */
final class ReindexTest extends TestCase {

	/**
	 * Live content index UID.
	 *
	 * @var string
	 */
	private string $uid;

	/**
	 * Creates the live content index.
	 */
	public function set_up(): void {
		parent::set_up();
		$names = Plugin::instance()->get( 'names' );
		assert( $names instanceof IndexNames );
		$this->uid = $names->uid( 'content' );
		$indexes   = Plugin::instance()->get( 'index_manager' );
		assert( $indexes instanceof IndexManager );
		$indexes->ensure_index( 'content' );
	}

	/**
	 * The reindexer service.
	 *
	 * @return Reindexer
	 */
	private function reindexer(): Reindexer {
		$reindexer = Plugin::instance()->get( 'reindexer' );
		assert( $reindexer instanceof Reindexer );
		return $reindexer;
	}

	/**
	 * Starts an Action Scheduler run and drives it to done/failed (finalization polls every 10 s at most).
	 *
	 * @return array<string, mixed> Final state.
	 */
	private function reindex(): array {
		$this->reindexer()->start( 'content' );
		for ( $i = 0; $i < 240; $i++ ) {
			$this->run_actions();
			$state = $this->reindexer()->status( 'content' );
			if ( null !== $state && 'running' !== $state['status'] ) {
				break;
			}
			usleep( 250000 );
		}
		$this->wait_for_tasks();
		return (array) $this->reindexer()->status( 'content' );
	}

	/**
	 * Adds documents directly to the live index (simulating writes the plugin no longer knows about).
	 *
	 * @param list<array<string, mixed>> $documents Documents.
	 */
	private function seed_documents( array $documents ): void {
		$this->meili( 'POST', '/indexes/' . $this->uid . '/documents', $documents );
		$this->wait_for_tasks();
	}

	/**
	 * IDs currently in the live index.
	 *
	 * @return list<int>
	 */
	private function indexed_ids(): array {
		$ids = array_map( static fn ( array $doc ): int => (int) $doc['id'], $this->index_documents( $this->uid ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Only published, password-less posts end up in the index; flags are cleared.
	 */
	public function test_reindex_indexes_all_published_posts(): void {
		$published = self::factory()->post->create_many( 3 );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_password' => 'secret' ) );
		$options = Plugin::instance()->get( 'options' );
		assert( $options instanceof Options );
		$options->flag_reindex( 'content', true );

		$state = $this->reindex();

		$this->assertSame( 'done', $state['status'], (string) $state['error'] );
		$this->assertSame( 3, $state['sent'] );
		sort( $published );
		$this->assertSame( $published, $this->indexed_ids() );
		$this->assertFalse( $options->needs_reindex( 'content' ) );
		$this->assertTrue( (bool) $options->state( 'first_reindex_done' ) );
	}

	/**
	 * Synonyms, ranking rules and embedders configured in Meilisearch are untouched by a reindex.
	 */
	public function test_meilisearch_side_settings_survive(): void {
		$rules = array( 'words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'date:desc' );
		$this->meili(
			'PATCH',
			'/indexes/' . $this->uid . '/settings',
			array(
				'synonyms'     => array( 'phone' => array( 'mobile' ) ),
				'rankingRules' => $rules,
				'embedders'    => array(
					'manual' => array(
						'source'     => 'userProvided',
						'dimensions' => 3,
					),
				),
			)
		);
		$this->wait_for_tasks();
		// A userProvided embedder requires vectors on every document.
		add_filter(
			'meilisearch_document',
			static function ( array $doc ): array {
				$doc['_vectors'] = array( 'manual' => array( 0.1, 0.2, 0.3 ) );
				return $doc;
			}
		);
		$id = self::factory()->post->create();

		$state = $this->reindex();

		$this->assertSame( 'done', $state['status'], (string) $state['error'] );
		$this->assertSame( array( $id ), $this->indexed_ids() );
		$settings = $this->meili( 'GET', '/indexes/' . $this->uid . '/settings', null );
		$this->assertSame( array( 'mobile' ), $settings['synonyms']['phone'] );
		$this->assertSame( $rules, $settings['rankingRules'] );
		$this->assertSame( 'userProvided', $settings['embedders']['manual']['source'] );
		$this->assertSame( 3, $settings['embedders']['manual']['dimensions'] );
	}

	/**
	 * The sweep removes an orphan document and the document of a post that is now a draft.
	 */
	public function test_sweep_removes_orphans_and_unpublished_posts(): void {
		$kept  = self::factory()->post->create();
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->seed_documents(
			array(
				array(
					'id'    => 999999,
					'title' => 'Orphan',
				),
				array(
					'id'    => $draft,
					'title' => 'Stale copy of a draft',
				),
			)
		);

		$state = $this->reindex();

		$this->assertSame( 'done', $state['status'], (string) $state['error'] );
		$this->assertSame( 2, $state['deleted'] );
		$this->assertSame( array( $kept ), $this->indexed_ids() );
	}

	/**
	 * A failed Meilisearch task (invalid document id) marks the run failed and logs the engine error.
	 */
	public function test_failed_task_marks_run_failed(): void {
		self::factory()->post->create();
		$bad = self::factory()->post->create();
		add_filter(
			'meilisearch_document',
			static function ( array $doc, \WP_Post $post ) use ( $bad ): array {
				if ( $bad === $post->ID ) {
					$doc['id'] = 'a b'; // Accepted at enqueue time, rejected when the task is processed.
				}
				return $doc;
			},
			10,
			2
		);

		$state = $this->reindex();

		$this->assertSame( 'failed', $state['status'] );
		$this->assertNotSame( '', $state['error'] );
		$log = Plugin::instance()->get( 'error_log' );
		assert( $log instanceof ErrorLog );
		$this->assertSame( 'reindex', $log->all()[0]['context'] );
		$options = Plugin::instance()->get( 'options' );
		assert( $options instanceof Options );
		$this->assertFalse( (bool) $options->state( 'first_reindex_done' ) );
	}

	/**
	 * The WP-CLI path reports progress and completes all phases inline.
	 */
	public function test_run_sync_reports_progress(): void {
		$ids = self::factory()->post->create_many( 3 );
		$this->seed_documents(
			array(
				array(
					'id'    => 999999,
					'title' => 'Orphan',
				),
			)
		);
		$calls = array();

		$this->reindexer()->run_sync(
			'content',
			2,
			static function ( int $sent, int $total ) use ( &$calls ): void {
				$calls[] = array( $sent, $total );
			}
		);
		$this->wait_for_tasks();

		$this->assertSame( array( 0, 3 ), $calls[0] );
		$this->assertSame( array( 3, 3 ), end( $calls ) );
		$state = $this->reindexer()->status( 'content' );
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 1, $state['deleted'] );
		sort( $ids );
		$this->assertSame( $ids, $this->indexed_ids() );
	}

	/**
	 * A second start while a run is active is refused.
	 */
	public function test_second_start_is_refused(): void {
		self::factory()->post->create();
		$this->reindexer()->start( 'content' );

		try {
			$this->reindexer()->start( 'content' );
			$this->fail( 'Expected already_running.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'already_running', $e->getMessage() );
		}
	}

	/**
	 * A flag raised while a run is in progress survives the run's completion.
	 */
	public function test_flag_raised_mid_run_survives_finalize(): void {
		self::factory()->post->create();
		$options = Plugin::instance()->get( 'options' );
		assert( $options instanceof Options );
		$options->flag_reindex( 'content', true );
		$this->reindexer()->start( 'content' );
		$this->assertFalse( $options->needs_reindex( 'content' ), 'Starting a run clears the flag.' );
		$options->flag_reindex( 'content', true );

		for ( $i = 0; $i < 240; $i++ ) {
			$this->run_actions();
			$state = $this->reindexer()->status( 'content' );
			if ( null !== $state && 'running' !== $state['status'] ) {
				break;
			}
			usleep( 250000 );
		}
		$this->wait_for_tasks();

		$this->assertSame( 'done', $this->reindexer()->status( 'content' )['status'] );
		$this->assertTrue( $options->needs_reindex( 'content' ) );
	}
}
