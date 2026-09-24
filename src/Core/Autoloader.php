<?php
/**
 * PSR-4 autoloader for the plugin's own classes.
 *
 * Used at runtime instead of Composer's vendor/autoload.php so the plugin needs
 * no vendor/ directory and never loads development packages into WordPress
 * (see ADR-017).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `MerchBoard\Foo\Bar` to `<base_dir>/Foo/Bar.php`.
 */
final class Autoloader {

	/**
	 * Registers the autoloader.
	 *
	 * @param string $prefix   Namespace prefix including trailing backslash, e.g. 'MerchBoard\\'.
	 * @param string $base_dir Directory that holds the prefix's classes.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				$file = self::resolve( $class_name, $prefix, $base_dir );

				if ( null !== $file && is_file( $file ) ) {
					require $file;
				}
			}
		);
	}

	/**
	 * Resolves a class name to a file path, or null when the class is not ours.
	 *
	 * Class names can reach autoloaders from untrusted input (e.g. class_exists()
	 * on request data in other code), so only plain namespace segments are accepted
	 * to rule out path traversal.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @param string $prefix     Namespace prefix including trailing backslash.
	 * @param string $base_dir   Directory that holds the prefix's classes.
	 */
	public static function resolve( string $class_name, string $prefix, string $base_dir ): ?string {
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return null;
		}

		$relative = substr( $class_name, strlen( $prefix ) );

		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relative ) ) {
			return null;
		}

		return rtrim( $base_dir, '/\\' ) . '/' . str_replace( '\\', '/', $relative ) . '.php';
	}
}
