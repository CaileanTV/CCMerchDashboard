<?php
/**
 * Minimal stand-in for WordPress' WP_User so unit tests can pass user
 * objects to hook callbacks without WordPress.
 *
 * @package MerchBoard
 */

if ( ! class_exists( 'WP_User', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Must match WordPress' class name.
	class WP_User {

		/**
		 * User id.
		 *
		 * @var int
		 */
		public $ID = 0;
	}
}
