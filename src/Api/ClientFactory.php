<?php
/**
 * Builds the Meilisearch client from the plugin options.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Settings\Options;

/**
 * Creates the admin-key client lazily and rebuilds it when the host or key changes.
 */
final class ClientFactory {

	/**
	 * Cached client.
	 *
	 * @var Client|null
	 */
	private ?Client $client = null;

	/**
	 * Hash of the host and admin key the cached client was built with.
	 *
	 * @var string
	 */
	private string $client_hash = '';

	/**
	 * Creates the factory.
	 *
	 * @param Options   $options   Plugin options.
	 * @param Transport $transport HTTP transport.
	 */
	public function __construct( private readonly Options $options, private readonly Transport $transport ) {}

	/**
	 * Whether a host and an admin key are available.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return $this->options->is_configured();
	}

	/**
	 * Returns the client for the current host and admin key.
	 *
	 * @return Client
	 * @throws \RuntimeException When the plugin is not configured.
	 */
	public function client(): Client {
		if ( ! $this->options->is_configured() ) {
			throw new \RuntimeException( __( 'Meilisearch is not configured.', 'meilisearch' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is data; escaped where displayed.
		}

		$host = $this->options->host();
		$key  = $this->options->admin_key();
		$hash = md5( $host . '|' . $key );

		if ( null === $this->client || $hash !== $this->client_hash ) {
			$this->client      = new Client( $this->transport, $host, $key, $this->user_agent() );
			$this->client_hash = $hash;
		}

		return $this->client;
	}

	/**
	 * Returns a client for the configured host authenticated with another key (never cached).
	 *
	 * Used to probe what a candidate browser search key may do.
	 *
	 * @param string $key API key.
	 * @return Client
	 * @throws \RuntimeException When the plugin is not configured.
	 */
	public function client_for_key( string $key ): Client {
		if ( ! $this->options->is_configured() ) {
			throw new \RuntimeException( __( 'Meilisearch is not configured.', 'meilisearch' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is data; escaped where displayed.
		}

		return new Client( $this->transport, $this->options->host(), $key, $this->user_agent() );
	}

	/**
	 * User-Agent sent to Meilisearch.
	 *
	 * @return string
	 */
	private function user_agent(): string {
		return sprintf( 'Meilisearch-WordPress/%s WordPress/%s', MEILISEARCH_VERSION, get_bloginfo( 'version' ) );
	}
}
