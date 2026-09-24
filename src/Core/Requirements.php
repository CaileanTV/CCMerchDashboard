<?php
/**
 * Runtime requirement checks.
 *
 * This file is loaded before the autoloader and before the PHP version is known
 * to be supported, so it must stay parseable on old PHP versions: PHP 7.2 syntax
 * only (no typed properties, arrow functions, union types, match, enums, etc.).
 * CI lints it with PHP 7.2.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Checks PHP and WordPress versions and reports failures as an admin notice.
 */
final class Requirements {

	/**
	 * Minimum PHP version. Keep in sync with "Requires PHP" in the plugin header.
	 */
	const MIN_PHP = '8.2';

	/**
	 * Minimum WordPress version. Keep in sync with "Requires at least" in the plugin header.
	 */
	const MIN_WP = '6.7';

	/**
	 * Checks the current environment and registers an admin notice on failure.
	 *
	 * @return bool True when all requirements are met.
	 */
	public static function check() {
		$errors = self::errors( PHP_VERSION, (string) get_bloginfo( 'version' ) );

		if ( empty( $errors ) ) {
			return true;
		}

		add_action(
			'admin_notices',
			static function () use ( $errors ) {
				Requirements::render_notice( $errors );
			}
		);

		return false;
	}

	/**
	 * Returns the unmet requirements for the given versions.
	 *
	 * @param string $php_version Current PHP version.
	 * @param string $wp_version  Current WordPress version.
	 * @return array<string, array{required: string, current: string}> Keyed by 'php' and/or 'wp'.
	 */
	public static function errors( $php_version, $wp_version ) {
		$errors = array();

		if ( version_compare( $php_version, self::MIN_PHP, '<' ) ) {
			$errors['php'] = array(
				'required' => self::MIN_PHP,
				'current'  => $php_version,
			);
		}

		if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			$errors['wp'] = array(
				'required' => self::MIN_WP,
				'current'  => $wp_version,
			);
		}

		return $errors;
	}

	/**
	 * Prints the admin notice. Only shown to users who can manage plugins,
	 * since it discloses server version information.
	 *
	 * @param array<string, array{required: string, current: string}> $errors Unmet requirements.
	 * @return void
	 */
	public static function render_notice( array $errors ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$labels = array(
			'php' => 'PHP',
			'wp'  => 'WordPress',
		);

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'Merch Dashboard is not running.', 'merchboard' );
		echo '</strong></p><ul>';

		foreach ( $errors as $key => $error ) {
			echo '<li>';
			echo esc_html(
				sprintf(
					/* translators: 1: software name (PHP or WordPress), 2: required version, 3: current version. */
					__( '%1$s %2$s or later is required. Current version: %3$s.', 'merchboard' ),
					isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
					$error['required'],
					$error['current']
				)
			);
			echo '</li>';
		}

		echo '</ul></div>';
	}
}
