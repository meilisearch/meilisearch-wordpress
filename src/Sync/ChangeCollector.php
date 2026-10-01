<?php
/**
 * Collects changed post IDs during a request and enqueues sync jobs on shutdown.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\Options;

/**
 * Per-request set of changed post IDs keyed by logical index (spec § 6.1).
 *
 * Hook callbacks accept untyped arguments and cast them: a TypeError raised inside
 * save_post would break the editor's save, which must never happen because of us.
 */
final class ChangeCollector implements Registrable {

	public const CHUNK       = 100;
	public const DELAY       = 5;
	public const PENDING_TTL = 60;

	private const TRANSIENT_PREFIX = 'meilisearch_pending_';

	/**
	 * Logical index => set of post IDs.
	 *
	 * @var array<string, array<int, true>>
	 */
	private array $pending = array();

	/**
	 * Constructor.
	 *
	 * @param Indexability $indexability Resolves the logical index of a post (type check only).
	 * @param Queue        $queue        Action Scheduler wrapper.
	 * @param Options      $options      Source of the indexed meta keys.
	 */
	public function __construct(
		private readonly Indexability $indexability,
		private readonly Queue $queue,
		private readonly Options $options
	) {}

	/**
	 * Attaches the § 6.1 hooks and the shutdown flush.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 1 );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 10, 1 );
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 10, 1 );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'flush' ), 10, 0 );
	}

	/**
	 * Callback for save_post( int $post_id, WP_Post $post, bool $update ).
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function on_save_post( mixed $post_id ): void {
		$this->add( (int) $post_id );
	}

	/**
	 * Callback for transition_post_status( string $new_status, string $old_status, WP_Post $post ).
	 *
	 * @param mixed $new_status New status (unused: the job re-reads the post).
	 * @param mixed $old_status Old status (unused).
	 * @param mixed $post       Post object.
	 */
	public function on_transition_post_status( mixed $new_status, mixed $old_status, mixed $post ): void {
		if ( $post instanceof \WP_Post ) {
			$this->add( (int) $post->ID );
		}
	}

	/**
	 * Callback for before_delete_post( int $post_id, WP_Post $post ). The post still exists here,
	 * so its logical index can be resolved; the job later finds it gone and deletes the document.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function on_before_delete_post( mixed $post_id ): void {
		$this->add( (int) $post_id );
	}

	/**
	 * Callback for set_object_terms( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ).
	 *
	 * @param mixed $object_id Object ID.
	 */
	public function on_set_object_terms( mixed $object_id ): void {
		$this->add( (int) $object_id );
	}

	/**
	 * Callback for added_post_meta / updated_post_meta ( int $meta_id, … ) and
	 * deleted_post_meta ( string[] $meta_ids, … ). Only indexed meta keys count, so
	 * _edit_lock / _edit_last heartbeats never enqueue anything.
	 *
	 * @param mixed $meta_ids  Meta ID (added/updated) or list of meta IDs (deleted); unused.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 */
	public function on_meta_change( mixed $meta_ids, mixed $object_id, mixed $meta_key ): void {
		if ( ! is_string( $meta_key ) || ! in_array( $meta_key, $this->options->all_meta_keys(), true ) ) {
			return;
		}
		$this->add( (int) $object_id );
	}

	/**
	 * Adds a post when its type maps to an index (revisions, autosaves and other types are ignored).
	 *
	 * @param int $post_id Post ID.
	 */
	public function add( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$logical = $this->indexability->index_for( $post );
		if ( null === $logical ) {
			return;
		}
		$this->pending[ $logical ][ $post_id ] = true;
	}

	/**
	 * Adds several posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 */
	public function add_many( array $post_ids ): void {
		foreach ( $post_ids as $post_id ) {
			$this->add( (int) $post_id );
		}
	}

	/**
	 * Returns the collected IDs, sorted, per logical index.
	 *
	 * @return array<string, list<int>>
	 */
	public function pending(): array {
		$pending = array();
		foreach ( $this->pending as $logical => $set ) {
			$ids = array_keys( $set );
			sort( $ids );
			$pending[ $logical ] = $ids;
		}
		return $pending;
	}

	/**
	 * Enqueues Queue::SYNC_POSTS jobs in chunks of CHUNK IDs, skipping IDs that already
	 * have a job waiting (transient meilisearch_pending_{blog_id}_{id}), then empties the set.
	 */
	public function flush(): void {
		$pending       = $this->pending();
		$this->pending = array();
		$blog_id       = get_current_blog_id();

		foreach ( $pending as $logical => $ids ) {
			$fresh = array();
			foreach ( $ids as $post_id ) {
				if ( false === get_transient( self::transient_key( $blog_id, $post_id ) ) ) {
					$fresh[] = $post_id;
				}
			}
			foreach ( array_chunk( $fresh, self::CHUNK ) as $chunk ) {
				$this->enqueue( $logical, $chunk, $blog_id );
			}
		}
	}

	/**
	 * Schedules one job and, only when Action Scheduler stored it, marks its posts as waiting.
	 *
	 * @param string $logical Logical index.
	 * @param int[]  $chunk   Post IDs.
	 * @param int    $blog_id Site ID.
	 */
	private function enqueue( string $logical, array $chunk, int $blog_id ): void {
		$action_id = $this->queue->schedule(
			Queue::SYNC_POSTS,
			array(
				'index'   => $logical,
				'ids'     => $chunk,
				'attempt' => 1,
			),
			self::DELAY
		);
		if ( $action_id <= 0 ) {
			// Nothing was stored: leave no marker so the next change retries.
			return;
		}
		foreach ( $chunk as $post_id ) {
			set_transient( self::transient_key( $blog_id, $post_id ), 1, self::PENDING_TTL );
		}
	}

	/**
	 * Removes the "job waiting" markers. SyncJob calls this before reading the posts, so a
	 * change that lands while the job runs is enqueued again instead of being lost.
	 *
	 * @param array<int|string> $post_ids Post IDs.
	 */
	public static function clear_pending( array $post_ids ): void {
		$blog_id = get_current_blog_id();
		foreach ( $post_ids as $post_id ) {
			delete_transient( self::transient_key( $blog_id, (int) $post_id ) );
		}
	}

	/**
	 * Transient name for a post.
	 *
	 * @param int $blog_id Site ID.
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function transient_key( int $blog_id, int $post_id ): string {
		return self::TRANSIENT_PREFIX . $blog_id . '_' . $post_id;
	}
}
