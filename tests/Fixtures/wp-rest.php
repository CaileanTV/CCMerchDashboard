<?php
/**
 * Minimal stand-ins for WP_Error and WP_REST_Request (the parts the plugin
 * uses). Loaded only in isolated test processes.
 *
 * @package MerchBoard
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals -- Mirrors WordPress' global classes.

/**
 * WordPress error.
 */
class WP_Error {

	/**
	 * Constructor.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param mixed  $data    Data.
	 */
	public function __construct( public $code = '', public $message = '', public $data = '' ) {
	}

	/**
	 * Error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->code;
	}

	/**
	 * Error data.
	 *
	 * @return mixed
	 */
	public function get_error_data() {
		return $this->data;
	}
}

/**
 * REST request.
 */
class WP_REST_Request {

	/**
	 * Parameters.
	 *
	 * @var array<string, mixed>
	 */
	private $params = array();

	/**
	 * Constructor.
	 *
	 * @param string $method     Method.
	 * @param string $route      Route.
	 * @param array  $attributes Attributes.
	 */
	public function __construct( private $method = '', private $route = '', private $attributes = array() ) {
	}

	/**
	 * Sets a parameter.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function set_param( $key, $value ) {
		$this->params[ $key ] = $value;
	}

	/**
	 * Route.
	 *
	 * @return string
	 */
	public function get_route() {
		return $this->route;
	}

	/**
	 * Parameter.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}
