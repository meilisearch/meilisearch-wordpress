<?php
/**
 * Re-syncs the posts attached to an edited or deleted term.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\Options;

/**
 * Hooks: edited_term → Queue::SYNC_TERM {taxonomy, term_id, page}; delete_term → collector.
 * The handler pages through the term's objects (and those of its descendants, whose
 * ancestor-inclusive *_ids fields change when a term is re-parented) PAGE at a time.
 */
final class TermJob implements Registrable {

	public const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ChangeCollector $collector Change collector.
	 * @param Queue           $queue     Action Scheduler wrapper.
	 * @param Options         $options   Options.
	 */
	public function __construct(
		private readonly ChangeCollector $collector,
		private readonly Queue $queue,
		private readonly Options $options
	) {}

	/**
	 * Attaches the term hooks and the Action Scheduler handler (accepted_args 1).
	 */
	public function register(): void {
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_delete_term' ), 10, 5 );
		add_action( Queue::SYNC_TERM, array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * Callback for edited_term( int $term_id, int $tt_id, string $taxonomy, array $args ).
	 *
	 * @param mixed $term_id  Term ID.
	 * @param mixed $tt_id    Term taxonomy ID (unused).
	 * @param mixed $taxonomy Taxonomy slug.
	 */
	public function on_edited_term( mixed $term_id, mixed $tt_id, mixed $taxonomy ): void {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 || ! is_string( $taxonomy ) || ! $this->is_tracked( $taxonomy ) ) {
			return;
		}
		$this->queue->schedule(
			Queue::SYNC_TERM,
			array(
				'taxonomy' => $taxonomy,
				'term_id'  => $term_id,
				'page'     => 0,
			)
		);
	}

	/**
	 * Callback for delete_term( int $term, int $tt_id, string $taxonomy, WP_Term $deleted_term, array $object_ids ).
	 * The relationships are already gone when this fires, so the objects are collected directly.
	 *
	 * @param mixed $term         Term ID (unused).
	 * @param mixed $tt_id        Term taxonomy ID (unused).
	 * @param mixed $taxonomy     Taxonomy slug.
	 * @param mixed $deleted_term Copy of the deleted term (unused).
	 * @param mixed $object_ids   IDs of the objects that had the term.
	 */
	public function on_delete_term( mixed $term, mixed $tt_id, mixed $taxonomy, mixed $deleted_term, mixed $object_ids ): void {
		if ( ! is_string( $taxonomy ) || ! $this->is_tracked( $taxonomy ) || ! is_array( $object_ids ) ) {
			return;
		}
		$this->collector->add_many( array_map( 'intval', $object_ids ) );
	}

	/**
	 * Feeds one page of the term's objects to the collector and chains the next page.
	 *
	 * @param array<string, mixed> $payload {taxonomy: string, term_id: int, page: int}.
	 */
	public function handle( array $payload ): void {
		$taxonomy = isset( $payload['taxonomy'] ) && is_string( $payload['taxonomy'] ) ? $payload['taxonomy'] : '';
		$term_id  = isset( $payload['term_id'] ) ? (int) $payload['term_id'] : 0;
		$page     = isset( $payload['page'] ) ? max( 0, (int) $payload['page'] ) : 0;
		if ( '' === $taxonomy || $term_id <= 0 ) {
			return;
		}

		$term_ids = array( $term_id );
		$children = get_term_children( $term_id, $taxonomy );
		if ( is_array( $children ) ) {
			$term_ids = array_merge( $term_ids, array_map( 'intval', $children ) );
		}

		$object_ids = get_objects_in_term( $term_ids, $taxonomy );
		if ( ! is_array( $object_ids ) ) {
			return;
			// WP_Error: the taxonomy no longer exists.
		}
		$object_ids = array_values( array_unique( array_map( 'intval', $object_ids ) ) );

		$slice = array_slice( $object_ids, $page * self::PAGE, self::PAGE );
		if ( array() !== $slice ) {
			$this->collector->add_many( $slice );
			$this->collector->flush();
			// Deterministic; the shutdown flush would also run in the runner request.
		}

		if ( count( $object_ids ) > ( $page + 1 ) * self::PAGE ) {
			$this->queue->schedule(
				Queue::SYNC_TERM,
				array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
					'page'     => $page + 1,
				)
			);
		}
	}

	/**
	 * Whether a taxonomy feeds an index: enabled content taxonomies, and product taxonomies when products are indexed.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	private function is_tracked( string $taxonomy ): bool {
		if ( in_array( $taxonomy, $this->options->all_taxonomies(), true ) ) {
			return true;
		}
		return $this->options->products_enabled()
			&& ( 'product_cat' === $taxonomy || 'product_tag' === $taxonomy || str_starts_with( $taxonomy, 'pa_' ) );
	}
}
