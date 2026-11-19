<?php
/**
 * Re-syncs the posts whose author fields change without the post being saved.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\Options;

/**
 * Documents carry author_id and author_name (the display name), which WordPress changes without
 * saving the posts:
 * - delete_user / remove_user_from_blog with a reassign target move the user's posts to another
 *   author with one SQL UPDATE. The hooks fire before it, so the user's published posts are collected
 *   directly (the job re-reads them later, after the UPDATE).
 * - profile_update with a new display name → Queue::SYNC_AUTHOR {author_id, page}; the handler pages
 *   through the author's published posts PAGE at a time. Only the current site is covered: on a
 *   network, the user's posts on other sites keep the previous name until they are saved or reindexed.
 */
final class AuthorJob implements Registrable {

	public const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ChangeCollector $collector Change collector.
	 * @param Queue           $queue     Action Scheduler wrapper.
	 * @param Options         $options   Options (indexed post types).
	 */
	public function __construct(
		private readonly ChangeCollector $collector,
		private readonly Queue $queue,
		private readonly Options $options
	) {}

	/**
	 * Attaches the user hooks and the Action Scheduler handler (accepted_args 1).
	 */
	public function register(): void {
		add_action( 'delete_user', array( $this, 'on_delete_user' ), 10, 2 );
		add_action( 'remove_user_from_blog', array( $this, 'on_remove_user_from_blog' ), 10, 3 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( Queue::SYNC_AUTHOR, array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * Callback for delete_user( int $id, int|null $reassign, WP_User $user ). Without a reassign
	 * target WordPress deletes the posts one by one, which before_delete_post already covers.
	 *
	 * @param mixed $user_id  ID of the user being deleted.
	 * @param mixed $reassign ID of the user who receives the posts, or null.
	 */
	public function on_delete_user( mixed $user_id, mixed $reassign ): void {
		if ( null === $reassign || (int) $reassign <= 0 ) {
			return;
		}
		$this->collect_posts_of( (int) $user_id );
	}

	/**
	 * Callback for remove_user_from_blog( int $user_id, int $blog_id, int $reassign ) (multisite). It
	 * fires with the site switched in, before the reassignment.
	 *
	 * @param mixed $user_id  ID of the user being removed.
	 * @param mixed $blog_id  Site ID (unused: it is the current site here).
	 * @param mixed $reassign ID of the user who receives the posts, 0 for none.
	 */
	public function on_remove_user_from_blog( mixed $user_id, mixed $blog_id, mixed $reassign ): void {
		if ( (int) $reassign <= 0 ) {
			return;
		}
		$this->collect_posts_of( (int) $user_id );
	}

	/**
	 * Callback for profile_update( int $user_id, WP_User $old_user_data, array $userdata ). The user
	 * cache is already cleaned, so get_userdata() returns the new values.
	 *
	 * @param mixed $user_id       User ID.
	 * @param mixed $old_user_data User before the update.
	 */
	public function on_profile_update( mixed $user_id, mixed $old_user_data ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! is_object( $old_user_data ) || ! isset( $old_user_data->display_name ) || ! $this->options->is_configured() ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! is_object( $user ) || (string) $user->display_name === (string) $old_user_data->display_name ) {
			return;
		}
		$this->queue->schedule(
			Queue::SYNC_AUTHOR,
			array(
				'author_id' => $user_id,
				'page'      => 0,
			)
		);
	}

	/**
	 * Feeds one page of the author's published posts to the collector and chains the next page.
	 *
	 * @param array<string, mixed> $payload {author_id: int, page: int}.
	 */
	public function handle( array $payload ): void {
		$author_id = isset( $payload['author_id'] ) ? (int) $payload['author_id'] : 0;
		$page      = isset( $payload['page'] ) ? max( 0, (int) $payload['page'] ) : 0;
		if ( $author_id <= 0 ) {
			return;
		}

		$ids = $this->published_posts_of( $author_id, self::PAGE, $page * self::PAGE );
		if ( array() !== $ids ) {
			$this->collector->add_many( $ids );
			$this->collector->flush();
			// Deterministic; the shutdown flush would also run in the runner request.
		}

		if ( count( $ids ) === self::PAGE ) {
			$this->queue->schedule(
				Queue::SYNC_AUTHOR,
				array(
					'author_id' => $author_id,
					'page'      => $page + 1,
				)
			);
		}
	}

	/**
	 * Collects every published post of a user. Unpaged, as WordPress itself loads every post ID of
	 * the user to reassign them; only IDs are held, and the collector schedules them in chunks.
	 *
	 * @param int $user_id User ID.
	 */
	private function collect_posts_of( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$this->collector->add_many( $this->published_posts_of( $user_id, -1, 0 ) );
	}

	/**
	 * IDs of an author's published posts of the indexed types, by ascending ID (only published posts
	 * can have a document).
	 *
	 * @param int $author_id Author ID.
	 * @param int $limit     Maximum number of IDs, -1 for all.
	 * @param int $offset    Number of IDs to skip.
	 * @return list<int>
	 */
	private function published_posts_of( int $author_id, int $limit, int $offset ): array {
		$types = $this->options->enabled_post_types();
		if ( $this->options->products_enabled() ) {
			$types[] = 'product';
		}
		if ( array() === $types ) {
			return array();
		}
		$ids = get_posts(
			array(
				'author'         => $author_id,
				'post_type'      => $types,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}
}
