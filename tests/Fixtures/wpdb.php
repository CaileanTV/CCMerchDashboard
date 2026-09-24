<?php
/**
 * Minimal stand-in for WordPress' wpdb class so unit tests can satisfy `wpdb`
 * type declarations without loading WordPress. Behaviour lives in
 * MerchBoard\Tests\Unit\Support\RecordingWpdb. Excluded from PHPStan, which
 * uses the real WordPress stubs instead.
 *
 * @package MerchBoard
 */

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! class_exists( 'wpdb', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.StartWithCapital -- Must match WordPress' class name.
	class wpdb {

		/**
		 * Table prefix.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';

		/**
		 * Options table.
		 *
		 * @var string
		 */
		public $options = 'wp_options';

		/**
		 * Last error.
		 *
		 * @var string
		 */
		public $last_error = '';

		/**
		 * Last insert id.
		 *
		 * @var int
		 */
		public $insert_id = 0;
	}
}
