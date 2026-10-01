<?php
/**
 * In-memory WordPress state shared by the Sync, Indexing and Admin unit tests.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Api\Response;
use Meilisearch\WordPress\Indexing\ContentSchema;
use Meilisearch\WordPress\Indexing\DocumentBuilder;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\Reindexer;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Sync\ErrorLog;
use Meilisearch\WordPress\Sync\Queue;

/**
 * Stubs options, transients, posts and URL helpers against arrays held by the test.
 */
trait SyncFixtures {

	/**
	 * Option name => value.
	 *
	 * @var array<string, mixed>
	 */
	protected array $option_store = array();

	/**
	 * Transient name => value.
	 *
	 * @var array<string, mixed>
	 */
	protected array $transient_store = array();

	/**
	 * Post ID => post.
	 *
	 * @var array<int, \WP_Post>
	 */
	protected array $posts = array();

	/**
	 * Installs the stubs. Connection: host http://meili.test, an admin key, prefix wp_test.
	 * Content: post + page; taxonomy category and meta key price on posts. No reindex state.
	 */
	protected function stub_wordpress_state(): void {
		$defaults = Options::defaults();

		$this->option_store                        = $defaults;
		$this->option_store[ Options::CONNECTION ] = array_merge(
			(array) ( $defaults[ Options::CONNECTION ] ?? array() ),
			array(
				'host'   => 'http://meili.test',
				'prefix' => 'wp_test',
			)
		);
		$this->option_store[ Options::ADMIN_KEY ]  = 'test-admin-key';
		$this->option_store[ Options::CONTENT ]    = array_merge(
			(array) ( $defaults[ Options::CONTENT ] ?? array() ),
			array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array( 'post' => array( 'category' ) ),
				'meta_keys'  => array( 'post' => array( 'price' ) ),
			)
		);
		$this->option_store[ Options::LOG ]        = array();
		$this->transient_store                     = array();
		$this->posts                               = array();

		Functions\when( 'get_option' )->alias(
			fn ( $name, $default_value = false ) => array_key_exists( $name, $this->option_store ) ? $this->option_store[ $name ] : $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ): bool {
				$this->option_store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '' ): bool {
				if ( ! array_key_exists( $name, $this->option_store ) ) {
					$this->option_store[ $name ] = $value;
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ): bool {
				unset( $this->option_store[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn ( $name ) => array_key_exists( $name, $this->transient_store ) ? $this->transient_store[ $name ] : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ): bool {
				$this->transient_store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ): bool {
				unset( $this->transient_store[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_post' )->alias(
			fn ( $post ) => $this->posts[ (int) ( $post instanceof \WP_Post ? $post->ID : $post ) ] ?? null
		);
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'network_home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.2' );
		Functions\when( 'wp_parse_args' )->alias(
			fn ( $args, $defaults = array() ) => array_merge( (array) $defaults, (array) $args )
		);
		Functions\when( 'wp_parse_url' )->alias(
			fn ( $url, $component = -1 ) => parse_url( (string) $url, $component ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		);
		Functions\when( 'untrailingslashit' )->alias( fn ( $value ) => rtrim( (string) $value, '/\\' ) );
		Functions\when( 'trailingslashit' )->alias( fn ( $value ) => rtrim( (string) $value, '/\\' ) . '/' );
		Functions\when( 'sanitize_key' )->alias(
			fn ( $key ) => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) )
		);
		Functions\when( 'sanitize_text_field' )->alias( fn ( $value ) => trim( (string) $value ) );
		$GLOBALS['wp_version'] = '7.1.2'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Unit tests have no WordPress globals.
	}

	/**
	 * Registers a post double returned by get_post().
	 *
	 * @param array<string, mixed> $props Post fields; ID is required.
	 * @return \WP_Post
	 */
	protected function add_post( array $props ): \WP_Post {
		$post = new \WP_Post(
			array_merge(
				array(
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_password' => '',
					'post_title'    => 'Post ' . (int) $props['ID'],
					'post_content'  => '',
				),
				$props
			)
		);

		$this->posts[ (int) $post->ID ] = $post;
		return $post;
	}

	/**
	 * Client factory over the given fake transport.
	 *
	 * @param Options       $options   Options.
	 * @param FakeTransport $transport Fake transport.
	 * @return ClientFactory
	 */
	protected function make_clients( Options $options, FakeTransport $transport ): ClientFactory {
		return new ClientFactory( $options, $transport );
	}

	/**
	 * A real Reindexer wired over the given collaborators (content index only).
	 *
	 * @param Options                        $options  Options.
	 * @param ClientFactory                  $clients  Client factory.
	 * @param Queue                          $queue    Queue.
	 * @param ErrorLog                       $log      Error log.
	 * @param array<string, DocumentBuilder> $builders Logical index => builder.
	 * @return Reindexer
	 */
	protected function make_reindexer( Options $options, ClientFactory $clients, Queue $queue, ErrorLog $log, array $builders ): Reindexer {
		$names   = new IndexNames( $options );
		$indexes = new IndexManager( $clients, $names, new SettingsBuilder( array( 'content' => new ContentSchema( $options ) ) ), $options );
		return new Reindexer( $clients, $indexes, $names, new Indexability( $options ), $builders, $queue, $options, $log );
	}

	/**
	 * Installs a $wpdb double: get_col() returns the given pages in order, get_var() the given count.
	 * Every prepare() call is recorded in $GLOBALS['wpdb']->prepared as [query, args].
	 *
	 * @param list<list<int|string>> $pages Result of each successive get_col() call.
	 * @param string                 $count Result of get_var().
	 * @return object
	 */
	protected function install_wpdb( array $pages, string $count = '0' ): object {
		$wpdb = new class( $pages, $count ) {
			/**
			 * Posts table name.
			 *
			 * @var string
			 */
			public string $posts = 'wp_posts';

			/**
			 * Recorded prepare() calls.
			 *
			 * @var list<array{0: string, 1: array<int, mixed>}>
			 */
			public array $prepared = array();

			/**
			 * Constructor.
			 *
			 * @param list<list<int|string>> $pages Pages.
			 * @param string                 $count Count.
			 */
			public function __construct( private array $pages, private string $count ) {}

			/**
			 * Records the query and returns it unchanged.
			 *
			 * @param string $query   Query.
			 * @param mixed  ...$args Arguments (or one array of arguments).
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array( $query, $args );
				return $query;
			}

			/**
			 * Next page (the query argument is ignored).
			 *
			 * @return list<int|string>
			 */
			public function get_col(): array {
				return array_shift( $this->pages ) ?? array();
			}

			/**
			 * The count (the query argument is ignored).
			 *
			 * @return string
			 */
			public function get_var(): string {
				return $this->count;
			}
		};

		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Unit-test double.
		return $wpdb;
	}

	/**
	 * A JSON response.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $body   Decoded body.
	 * @return Response
	 */
	protected static function json_response( int $status, array $body ): Response {
		return new Response( $status, $body, (string) json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * A 202 "task enqueued" response.
	 *
	 * @param int $uid Task UID.
	 * @return Response
	 */
	protected static function task_response( int $uid ): Response {
		return self::json_response(
			202,
			array(
				'taskUid'    => $uid,
				'indexUid'   => null,
				'status'     => 'enqueued',
				'type'       => 'documentAdditionOrUpdate',
				'enqueuedAt' => '2026-10-01T00:00:00Z',
			)
		);
	}

	/**
	 * A GET /tasks/{uid} response for a succeeded task.
	 *
	 * @param int $uid Task UID.
	 * @return Response
	 */
	protected static function succeeded_task( int $uid ): Response {
		return self::json_response(
			200,
			array(
				'uid'    => $uid,
				'status' => 'succeeded',
				'type'   => 'indexSwap',
				'error'  => null,
			)
		);
	}

	/**
	 * A GET /tasks list response.
	 *
	 * @param list<array<string, mixed>> $results Tasks.
	 * @return Response
	 */
	protected static function tasks_response( array $results ): Response {
		return self::json_response(
			200,
			array(
				'results' => $results,
				'total'   => count( $results ),
				'limit'   => 1,
				'from'    => null,
				'next'    => null,
			)
		);
	}

	/**
	 * A document builder backed by a callable.
	 *
	 * @param callable $build Builder body: fn( \WP_Post $post ): ?array.
	 * @return DocumentBuilder
	 */
	protected static function builder( callable $build ): DocumentBuilder {
		return new class( $build ) implements DocumentBuilder {
			/**
			 * Builder body.
			 *
			 * @var callable
			 */
			private $build;

			/**
			 * Constructor.
			 *
			 * @param callable $build Builder body.
			 */
			public function __construct( callable $build ) {
				$this->build = $build;
			}

			/**
			 * Builds a document.
			 *
			 * @param \WP_Post $post Post.
			 * @return array<string, mixed>|null
			 */
			public function build( \WP_Post $post ): ?array {
				return ( $this->build )( $post );
			}
		};
	}

	/**
	 * Decodes a recorded request body.
	 *
	 * @param string|null $body Raw JSON.
	 * @return mixed
	 */
	protected static function decode( ?string $body ): mixed {
		return null === $body ? null : json_decode( $body, true );
	}
}
