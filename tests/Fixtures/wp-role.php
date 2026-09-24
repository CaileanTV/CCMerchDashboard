<?php
/**
 * Minimal stand-in for WordPress' WP_Role so unit tests run without WordPress.
 * Mirrors the parts the plugin uses: public $name/$capabilities, add_cap()
 * (grant flag), remove_cap() and has_cap(). Excluded from PHPStan, which uses
 * the real WordPress stubs.
 *
 * @package MerchBoard
 */

if ( ! class_exists( 'WP_Role', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName -- Must match WordPress' class name.
	class WP_Role {

		/**
		 * Role name.
		 *
		 * @var string
		 */
		public $name;

		/**
		 * Capabilities: name => granted.
		 *
		 * @var array<string, bool>
		 */
		public $capabilities;

		/**
		 * Constructor.
		 *
		 * @param string              $role         Role name.
		 * @param array<string, bool> $capabilities Capabilities.
		 */
		public function __construct( $role, $capabilities ) {
			$this->name         = $role;
			$this->capabilities = $capabilities;
		}

		/**
		 * Adds (or denies) a capability.
		 *
		 * @param string $cap   Capability.
		 * @param bool   $grant Grant or deny.
		 */
		public function add_cap( $cap, $grant = true ) {
			$this->capabilities[ $cap ] = $grant;
		}

		/**
		 * Removes a capability.
		 *
		 * @param string $cap Capability.
		 */
		public function remove_cap( $cap ) {
			unset( $this->capabilities[ $cap ] );
		}

		/**
		 * Whether the role grants a capability.
		 *
		 * @param string $cap Capability.
		 * @return bool
		 */
		public function has_cap( $cap ) {
			return ! empty( $this->capabilities[ $cap ] );
		}
	}
}
