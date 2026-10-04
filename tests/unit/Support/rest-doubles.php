<?php
/**
 * Minimal REST class doubles, loaded only when WordPress is absent.
 *
 * @package Meilisearch
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	/**
	 * Request double: parameters only.
	 */
	class WP_REST_Request {
		private array $params = array();

		public function __construct( string $method = 'GET', string $route = '' ) {
			unset( $method, $route );
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response', false ) ) {
	/**
	 * Response double: data and status.
	 */
	class WP_REST_Response {
		public function __construct( private mixed $data = null, private int $status = 200 ) {}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}
