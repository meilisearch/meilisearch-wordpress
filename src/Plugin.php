<?php
/**
 * Composition root.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Builds every service by hand and registers the hooks of each Registrable service.
 */
final class Plugin {

	/**
	 * The booted instance; null before boot() and after reset().
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Services keyed by id, in construction order.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Use boot().
	 */
	private function __construct() {}

	/**
	 * Builds all services and registers their hooks. Calling it again does nothing.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}
		$plugin = new self();
		$plugin->build();
		self::$instance = $plugin;
		$plugin->register_services();
	}

	/**
	 * Returns the booted instance.
	 *
	 * @return Plugin|null Null before boot().
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Drops the instance so the next boot() builds a fresh one. Tests only: hooks stay attached.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Returns a service by id.
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws \OutOfBoundsException When no service has that id.
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			throw new \OutOfBoundsException( sprintf( 'Unknown Meilisearch service "%s".', $id ) );
		}
		return $this->services[ $id ];
	}

	/**
	 * Returns every service keyed by id.
	 *
	 * @return array<string, object>
	 */
	public function services(): array {
		return $this->services;
	}

	/**
	 * Stores a service under an id.
	 *
	 * @param string $id      Service id.
	 * @param object $service Service.
	 * @throws \LogicException When the id is already taken.
	 */
	private function add( string $id, object $service ): void {
		if ( isset( $this->services[ $id ] ) ) {
			throw new \LogicException( sprintf( 'Meilisearch service "%s" is already registered.', $id ) );
		}
		$this->services[ $id ] = $service;
	}

	/**
	 * Calls register() on every Registrable service, in construction order.
	 */
	private function register_services(): void {
		foreach ( $this->services as $service ) {
			if ( $service instanceof Registrable ) {
				$service->register();
			}
		}
	}

	/**
	 * Constructs every service.
	 */
	private function build(): void {
		// Task 5 — settings.
		$options = new Settings\Options();
		$names   = new Settings\IndexNames( $options );
		$this->add( 'options', $options );
		$this->add( 'names', $names );

		// Task 5 — API client factory (needs Options).
		$clients = new Api\ClientFactory( $options, new Api\WpTransport() );
		$this->add( 'clients', $clients );
	}
}
