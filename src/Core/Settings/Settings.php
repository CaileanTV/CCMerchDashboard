<?php
/**
 * Plugin settings.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The single option `merchboard_settings` (one autoloaded array,
 * docs/DATABASE.md). Every value is read through sanitize(), so a forged or
 * corrupted option can never produce an unexpected value.
 *
 * Only change settings through SettingsService (authorization and audit);
 * this class performs no authorization.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'merchboard_settings';

	/**
	 * Delete all Merch Dashboard data when the plugin is deleted (default off).
	 * Enabled only by exactly boolean true.
	 */
	public const DELETE_DATA_ON_UNINSTALL = 'delete_data_on_uninstall';

	/**
	 * Defaults.
	 *
	 * @return array{delete_data_on_uninstall: bool}
	 */
	public static function defaults(): array {
		return array(
			self::DELETE_DATA_ON_UNINSTALL => false,
		);
	}

	/**
	 * Keeps only known keys with valid values. Destructive flags count only when
	 * they are exactly true: "1", "yes" or 1 (e.g. written by other code or a
	 * database edit) do not enable deletion.
	 *
	 * @param array<mixed> $values Raw values.
	 * @return array{delete_data_on_uninstall: bool}
	 */
	public static function sanitize( array $values ): array {
		return array(
			self::DELETE_DATA_ON_UNINSTALL => true === ( $values[ self::DELETE_DATA_ON_UNINSTALL ] ?? false ),
		);
	}

	/**
	 * Current settings.
	 *
	 * @return array{delete_data_on_uninstall: bool}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Whether permanent deletion on uninstall was explicitly enabled.
	 */
	public static function delete_data_on_uninstall(): bool {
		return self::all()[ self::DELETE_DATA_ON_UNINSTALL ];
	}

	/**
	 * Stores sanitized settings.
	 *
	 * @param array<mixed> $values Values.
	 * @return bool Whether the option was written.
	 */
	public static function save( array $values ): bool {
		return update_option( self::OPTION, self::sanitize( $values ), true );
	}
}
