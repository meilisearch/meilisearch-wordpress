<?php
declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Frontend;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Frontend\Autocomplete;
use Meilisearch\WordPress\Settings\IndexNames;
use Meilisearch\WordPress\Settings\Options;
use Meilisearch\WordPress\Tests\Unit\TestCase;

final class AutocompleteTest extends TestCase {

	private const OPTIONS = array(
		Options::CONNECTION => array(
			'host'           => 'http://meili.test:7700',
			'prefix'         => 'wp_test',
			'search_key'     => 'search-key-value',
			'search_key_uid' => 'search-key-uid',
		),
		Options::ADMIN_KEY  => 'admin-key-value',
		Options::SEARCH     => array( 'autocomplete' => true ),
	);

	private array $calls = array();

	protected function set_up(): void {
		parent::set_up();
		$this->calls = array();
		$this->stub_options( self::options() );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'determine_locale' )->justReturn( 'fr_FR' );
		Functions\when( 'plugins_url' )->alias(
			static function ( $path = '', $plugin = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return 'https://shop.test/wp-content/plugins/meilisearch/' . $path;
			}
		);
		foreach ( array( 'wp_register_script', 'wp_localize_script', 'wp_enqueue_script', 'wp_enqueue_style' ) as $function ) {
			Functions\when( $function )->alias(
				function ( ...$args ) use ( $function ) {
					$this->calls[ $function ][] = $args;
					return true;
				}
			);
		}
	}

	/**
	 * OPTIONS with the hash of the stored key recorded as verified (as IndexManager::rotate_search_key() does).
	 *
	 * @return array<string, mixed>
	 */
	private static function options(): array {
		$options = self::OPTIONS;
		$options[ Options::STATE ]['search_key_verified'] = Options::key_fingerprint( 'search-key-value' );
		return $options;
	}

	private function autocomplete(): Autocomplete {
		$options = new Options();
		return new Autocomplete( $options, new IndexNames( $options ) );
	}

	public function test_register_hooks_wp_enqueue_scripts(): void {
		$autocomplete = $this->autocomplete();
		$autocomplete->register();

		self::assertSame( 10, has_action( 'wp_enqueue_scripts', array( $autocomplete, 'enqueue' ) ) );
	}

	public function test_enqueued_when_enabled_configured_and_search_key_present(): void {
		self::assertTrue( $this->autocomplete()->should_enqueue() );
	}

	/**
	 * @dataProvider disabled_cases
	 */
	public function test_not_enqueued( array $overrides, bool $admin ): void {
		$options = self::options();
		foreach ( $overrides as $name => $value ) {
			$options[ $name ] = is_array( $value ) ? array_merge( $options[ $name ], $value ) : $value;
		}
		$this->stub_options( $options );
		Functions\when( 'is_admin' )->justReturn( $admin );

		$autocomplete = $this->autocomplete();
		$autocomplete->enqueue();

		self::assertFalse( $autocomplete->should_enqueue() );
		self::assertSame( array(), $this->calls );
	}

	/**
	 * @dataProvider created_mode_states
	 *
	 * @param array<string, mixed> $state State option.
	 */
	public function test_created_key_is_not_served_without_its_recorded_hash( array $state ): void {
		$options                   = self::OPTIONS;
		$options[ Options::STATE ] = $state;
		$this->stub_options( $options );

		$autocomplete = $this->autocomplete();
		$autocomplete->enqueue();

		self::assertFalse( $autocomplete->should_enqueue() );
		self::assertSame( array(), $this->calls );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function created_mode_states(): array {
		return array(
			'lost state'      => array( array() ),
			'mismatched hash' => array(
				array(
					'search_key_manual'   => false,
					'search_key_verified' => Options::key_fingerprint( 'another-key' ),
				),
			),
		);
	}

	public function test_manual_key_is_served_only_when_its_hash_was_recorded(): void {
		$options                   = self::OPTIONS;
		$options[ Options::STATE ] = array( 'search_key_manual' => true );
		$this->stub_options( $options );
		self::assertFalse( $this->autocomplete()->should_enqueue(), 'No verification recorded.' );

		$options[ Options::STATE ]['search_key_verified'] = Options::key_fingerprint( 'another-key' );
		$this->stub_options( $options );
		self::assertFalse( $this->autocomplete()->should_enqueue(), 'Recorded for a different key.' );

		$options[ Options::STATE ]['search_key_verified'] = Options::key_fingerprint( 'search-key-value' );
		$this->stub_options( $options );
		self::assertTrue( $this->autocomplete()->should_enqueue() );
	}

	public function disabled_cases(): array {
		return array(
			'autocomplete off' => array( array( Options::SEARCH => array( 'autocomplete' => false ) ), false ),
			'no search key'    => array( array( Options::CONNECTION => array( 'search_key' => '' ) ), false ),
			'not configured'   => array( array( Options::ADMIN_KEY => '' ), false ),
			'admin screen'     => array( array(), true ),
		);
	}

	public function test_enqueue_registers_deferred_footer_script_config_and_style(): void {
		$autocomplete = $this->autocomplete();
		$autocomplete->enqueue();

		$base = 'https://shop.test/wp-content/plugins/meilisearch/';
		self::assertSame(
			array(
				array(
					'meilisearch-autocomplete',
					$base . 'assets/js/' . $autocomplete->script_file(),
					array(),
					MEILISEARCH_VERSION,
					array(
						'in_footer' => true,
						'strategy'  => 'defer',
					),
				),
			),
			$this->calls['wp_register_script']
		);
		self::assertSame( array( array( 'meilisearch-autocomplete', 'meilisearchAutocomplete', $autocomplete->config() ) ), $this->calls['wp_localize_script'] );
		self::assertSame( array( array( 'meilisearch-autocomplete' ) ), $this->calls['wp_enqueue_script'] );
		self::assertSame(
			array( array( 'meilisearch-autocomplete', $base . 'assets/css/autocomplete.css', array(), MEILISEARCH_VERSION ) ),
			$this->calls['wp_enqueue_style']
		);
	}

	public function test_script_file_prefers_minified_build_when_present(): void {
		$minified = rtrim( MEILISEARCH_DIR, '/\\' ) . '/assets/js/autocomplete.min.js';
		$expected = ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) && file_exists( $minified ) ) ? 'autocomplete.min.js' : 'autocomplete.js';

		self::assertSame( $expected, $this->autocomplete()->script_file() );
	}

	public function test_config_contains_scoped_key_indexes_limits_and_strings(): void {
		$options = new Options();
		$config  = $this->autocomplete()->config();

		self::assertSame( $options->host(), $config['host'] );
		self::assertSame( 'search-key-value', $config['key'] );
		self::assertSame(
			array(
				'content'  => 'wp_test_content',
				'products' => null,
			),
			$config['indexes']
		);
		self::assertSame(
			array(
				'content'  => 5,
				'products' => 5,
				'minChars' => 2,
				'debounce' => 150,
			),
			$config['limits']
		);
		self::assertSame( Autocomplete::DEFAULT_SELECTOR, $config['selector'] );
		self::assertNull( $config['currency'] );
		self::assertSame( 'fr-FR', $config['locale'] );
		self::assertSame( array( 'products', 'posts', 'listLabel', 'noResults', 'oneResult', 'results' ), array_keys( $config['i18n'] ) );
		self::assertStringContainsString( '%d', $config['i18n']['results'] );
	}

	public function test_config_never_contains_the_admin_key(): void {
		self::assertStringNotContainsString( 'admin-key-value', (string) json_encode( $this->autocomplete()->config() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_selector_is_filterable_and_falls_back_on_invalid_values(): void {
		Filters\expectApplied( 'meilisearch_autocomplete_selector' )->once()->with( Autocomplete::DEFAULT_SELECTOR )->andReturn( '#site-search' );
		self::assertSame( '#site-search', $this->autocomplete()->config()['selector'] );

		Filters\expectApplied( 'meilisearch_autocomplete_selector' )->once()->andReturn( array( 'not', 'a', 'string' ) );
		self::assertSame( Autocomplete::DEFAULT_SELECTOR, $this->autocomplete()->config()['selector'] );
	}
}
