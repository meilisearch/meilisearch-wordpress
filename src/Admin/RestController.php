<?php
/**
 * REST routes behind the admin buttons, and the admin script that calls them.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Api\ApiError;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Registrable;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;

/**
 * Routes under meilisearch/v1 (spec § 7.1), all restricted to manage_options:
 * POST /connection/test, POST /reindex {index}, GET /reindex/status.
 * Also enqueues assets/js/admin.js (with its localized config) on the plugin screen only.
 */
final class RestController implements Registrable {

	public const NS            = 'meilisearch/v1';
	public const SCRIPT_HANDLE = 'meilisearch-admin';

	/**
	 * Constructor.
	 *
	 * @param IndexManager $indexes   Index manager.
	 * @param Reindexer    $reindexer Reindexer.
	 * @param Options      $options   Options.
	 */
	public function __construct(
		private readonly IndexManager $indexes,
		private readonly Reindexer $reindexer,
		private readonly Options $options
	) {}

	/**
	 * Attaches the route registration and the admin script.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 10, 1 );
	}

	/**
	 * Registers the three routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/connection/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(),
			)
		);
		register_rest_route(
			self::NS,
			'/reindex',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_reindex' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'index' => array(
						'description'       => __( 'Logical index to rebuild.', 'meilisearch' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_index' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/reindex/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'reindex_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Permission callback shared by every route.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Validates the "index" argument against the active logical indexes.
	 *
	 * @param mixed $value Raw value.
	 * @return bool|\WP_Error
	 */
	public function validate_index( mixed $value ): bool|\WP_Error {
		if ( is_string( $value ) && in_array( $value, $this->active_logicals(), true ) ) {
			return true;
		}
		return $this->unknown_index_error();
	}

	/**
	 * POST /connection/test → {ok, version} or an error with the Meilisearch message.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_connection(): \WP_REST_Response|\WP_Error {
		if ( ! $this->options->is_configured() ) {
			return new \WP_Error( 'meilisearch_not_configured', __( 'Save a Meilisearch host and admin API key first.', 'meilisearch' ), array( 'status' => 400 ) );
		}
		try {
			$version = $this->indexes->check_connection();
		} catch ( ApiError $e ) {
			return new \WP_Error(
				'meilisearch_connection_failed',
				$e->getMessage(),
				array(
					'status'           => 400,
					'meilisearch_code' => $e->error_code,
				)
			);
		} catch ( \RuntimeException $e ) {
			if ( str_starts_with( $e->getMessage(), 'unsupported_version' ) ) {
				return new \WP_Error(
					'meilisearch_unsupported_version',
					/* translators: %s: minimum Meilisearch version. */
					sprintf( __( 'Meilisearch %s or later is required.', 'meilisearch' ), IndexManager::MIN_VERSION ),
					array( 'status' => 400 )
				);
			}
			return new \WP_Error( 'meilisearch_connection_failed', $e->getMessage(), array( 'status' => 400 ) );
		}//end try
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'version' => $version,
			),
			200
		);
	}

	/**
	 * POST /reindex → 202 with the new run state; 409 with the current state while a run is active.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_reindex( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$logical = (string) $request->get_param( 'index' );
		if ( ! $this->options->is_configured() ) {
			return new \WP_Error( 'meilisearch_not_configured', __( 'Save a Meilisearch host and admin API key first.', 'meilisearch' ), array( 'status' => 400 ) );
		}
		try {
			$state = $this->reindexer->start( $logical );
		} catch ( \InvalidArgumentException $e ) {
			return $this->unknown_index_error();
		} catch ( \RuntimeException $e ) {
			if ( 'already_running' === $e->getMessage() ) {
				return new \WP_Error(
					'meilisearch_already_running',
					__( 'A reindex of this index is already running.', 'meilisearch' ),
					array(
						'status'    => 409,
						'state'     => $this->reindexer->status( $logical ),
						'abandoned' => $this->reindexer->is_stalled( $logical ),
					)
				);
			}
			return new \WP_Error( 'meilisearch_reindex_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
		return new \WP_REST_Response( $state, 202 );
	}

	/**
	 * GET /reindex/status → {content: state|null, products: state|null, abandoned: {content: bool, products: bool}}; a state carries
	 * status, phase (upsert|sweep|finalizing), sent, total and deleted for the progress UI.
	 *
	 * @return \WP_REST_Response
	 */
	public function reindex_status(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'content'   => $this->reindexer->status( 'content' ),
				'products'  => $this->reindexer->status( 'products' ),
				'abandoned' => array(
					'content'  => $this->reindexer->is_stalled( 'content' ),
					'products' => $this->reindexer->is_stalled( 'products' ),
				),
			),
			200
		);
	}

	/**
	 * Logical indexes that can be reindexed on this site (same rule as IndexNames::active_logicals()).
	 *
	 * @return list<string>
	 */
	private function active_logicals(): array {
		return ( new IndexNames( $this->options ) )->active_logicals();
	}

	/**
	 * Error for an index argument that is not an active logical index.
	 *
	 * @return \WP_Error
	 */
	private function unknown_index_error(): \WP_Error {
		return new \WP_Error(
			'rest_invalid_param',
			/* translators: %s: comma-separated list of index names. */
			sprintf( __( 'Unknown index. Expected one of: %s.', 'meilisearch' ), implode( ', ', $this->active_logicals() ) ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Enqueues admin.js on the plugin screen only, with window.meilisearchAdmin {restUrl, nonce, i18n}.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . Menu::SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/admin.js', MEILISEARCH_FILE ),
			array(),
			MEILISEARCH_VERSION,
			array( 'in_footer' => true )
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'meilisearchAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( self::NS . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'requestFailed' => __( 'The request failed. Please try again.', 'meilisearch' ),
					'testing'       => __( 'Testing the connection…', 'meilisearch' ),
					/* translators: %s: Meilisearch version. */
					'connected'     => __( 'Connected to Meilisearch %s.', 'meilisearch' ),
					'starting'      => __( 'Starting the reindex…', 'meilisearch' ),
					/* translators: 1: posts sent so far, 2: total posts. */
					'upsert'        => __( 'Indexing: %1$s of %2$s posts sent.', 'meilisearch' ),
					/* translators: %s: number of stale documents removed so far. */
					'sweep'         => __( 'Removing stale documents: %s removed so far.', 'meilisearch' ),
					'finalizing'    => __( 'Finalizing: waiting for Meilisearch to process the changes.', 'meilisearch' ),
					/* translators: 1: posts sent, 2: stale documents removed. */
					'done'          => __( 'Last reindex completed: %1$s posts sent, %2$s stale documents removed.', 'meilisearch' ),
					/* translators: %s: error message. */
					'failed'        => __( 'Last reindex failed: %s', 'meilisearch' ),
					'abandoned'     => __( 'The previous reindex stopped responding. Start a new one.', 'meilisearch' ),
				),
			)
		);
	}
}
