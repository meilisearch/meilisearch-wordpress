<?php
/**
 * Replaces search queries with Meilisearch results.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;

/**
 * Spec § 9.1 / § 9.7. pre_get_posts (priority 20, after WooCommerce's priority 10) decides and
 * flags the query; posts_pre_query translates again from the final query state (WordPress
 * rebuilds $query->tax_query after pre_get_posts), runs the search and returns the posts. Any
 * error falls back to MySQL for this request and opens the circuit breaker.
 */
final class Interceptor implements Registrable {

	public const QUERY_FLAG = 'meilisearch_intercept';

	/**
	 * Constructor.
	 *
	 * @param QueryTranslator $translator Translator.
	 * @param Searcher        $searcher   Searcher.
	 * @param ResultMapper    $mapper     Result mapper.
	 * @param CircuitBreaker  $breaker    Circuit breaker.
	 * @param Options         $options    Options.
	 * @param ErrorLog        $log        Error log.
	 */
	public function __construct(
		private QueryTranslator $translator,
		private Searcher $searcher,
		private ResultMapper $mapper,
		private CircuitBreaker $breaker,
		private Options $options,
		private ErrorLog $log
	) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'on_pre_get_posts' ), 20 );
		add_filter( 'posts_pre_query', array( $this, 'on_posts_pre_query' ), 10, 2 );
	}

	/**
	 * Flags queries that will be answered by Meilisearch.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function on_pre_get_posts( \WP_Query $query ): void {
		if ( $query->is_main_query() ) {
			$this->mapper->reset();
		}
		if ( $this->should_intercept( $query ) ) {
			$query->set( self::QUERY_FLAG, true );
		} elseif ( true === $query->get( self::QUERY_FLAG ) ) {
			// A re-run query object must be re-evaluated.
			$query->set( self::QUERY_FLAG, false );
		}
	}

	/**
	 * Answers flagged queries.
	 *
	 * @param array<int, mixed>|null $posts Posts from an earlier filter, null to let WordPress query.
	 * @param \WP_Query              $query Query.
	 * @return array<int, mixed>|null
	 */
	public function on_posts_pre_query( ?array $posts, \WP_Query $query ): ?array {
		if ( null !== $posts || true !== $query->get( self::QUERY_FLAG ) || $this->breaker->is_open() ) {
			return $posts;
		}
		$request = $this->translator->translate( $query );
		if ( null === $request ) {
			// A later pre_get_posts callback made the query untranslatable.
			return $posts;
		}
		try {
			$result = $this->searcher->execute( $request );
		} catch ( \RuntimeException $e ) {
			// ApiError extends RuntimeException: log the message only, never the trace.
			$this->log->add( 'search', $e->getMessage() );
			$this->breaker->trip();
			return $posts;
		}
		return $this->mapper->apply( $query, $result );
	}

	/**
	 * Spec § 9.1 rules. Opt-in queries ('meilisearch' => true) skip the main-query, search,
	 * admin, REST and "Replace site search" checks but follow the same translation rules.
	 *
	 * @param \WP_Query $query Query.
	 * @return bool
	 */
	public function should_intercept( \WP_Query $query ): bool {
		if ( true !== $query->get( 'meilisearch' ) ) {
			if ( ! $query->is_main_query() || ! $query->is_search() || is_admin() || wp_is_serving_rest_request() ) {
				return false;
			}
			if ( ! $this->options->search()['replace'] ) {
				return false;
			}
		}
		if ( ! $this->options->is_configured() || $this->breaker->is_open() ) {
			return false;
		}
		if ( null === $this->translator->translate( $query ) ) {
			return false;
		}
		/**
		 * Filters whether a query is answered by Meilisearch.
		 *
		 * @param bool      $intercept Whether to intercept.
		 * @param \WP_Query $query     Query.
		 */
		return (bool) apply_filters( 'meilisearch_should_intercept', true, $query );
	}
}
