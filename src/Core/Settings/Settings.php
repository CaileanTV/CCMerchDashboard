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
	 * URL slug of the creator dashboard (phase 12).
	 */
	public const DASHBOARD_SLUG = 'dashboard_slug';

	/**
	 * Default dashboard slug (open question 8, decided in phase 12).
	 */
	public const DEFAULT_DASHBOARD_SLUG = 'creator-dashboard';

	/**
	 * Slugs WordPress, WooCommerce or common setups already use for their own
	 * URLs; the dashboard must never shadow them.
	 */
	private const RESERVED_SLUGS = array(
		'wp-admin',
		'wp-login',
		'wp-login.php',
		'wp-json',
		'wp-content',
		'wp-includes',
		'admin',
		'login',
		'logout',
		'register',
		'feed',
		'rss',
		'comments',
		'page',
		'category',
		'tag',
		'author',
		'search',
		'embed',
		'attachment',
		'sitemap',
		'shop',
		'cart',
		'checkout',
		'my-account',
		'product',
		'product-category',
		'product-tag',
	);

	/**
	 * Defaults.
	 *
	 * @return array{delete_data_on_uninstall: bool, dashboard_slug: string}
	 */
	public static function defaults(): array {
		return array(
			self::DELETE_DATA_ON_UNINSTALL => false,
			self::DASHBOARD_SLUG           => self::DEFAULT_DASHBOARD_SLUG,
		);
	}

	/**
	 * Keeps only known keys with valid values. Destructive flags count only when
	 * they are exactly true: "1", "yes" or 1 (e.g. written by other code or a
	 * database edit) do not enable deletion. An invalid slug falls back to the
	 * default.
	 *
	 * @param array<mixed> $values Raw values.
	 * @return array{delete_data_on_uninstall: bool, dashboard_slug: string}
	 */
	public static function sanitize( array $values ): array {
		$slug = $values[ self::DASHBOARD_SLUG ] ?? null;

		return array(
			self::DELETE_DATA_ON_UNINSTALL => true === ( $values[ self::DELETE_DATA_ON_UNINSTALL ] ?? false ),
			self::DASHBOARD_SLUG           => is_string( $slug ) && self::valid_slug( $slug ) ? $slug : self::DEFAULT_DASHBOARD_SLUG,
		);
	}

	/**
	 * Whether a slug is well-formed and not reserved: 3-50 characters, lowercase
	 * letters, digits and single hyphens, not starting or ending with a hyphen.
	 *
	 * @param string $slug Slug.
	 */
	public static function valid_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug )
			&& strlen( $slug ) >= 3
			&& strlen( $slug ) <= 50
			&& ! in_array( $slug, self::RESERVED_SLUGS, true );
	}

	/**
	 * Current settings.
	 *
	 * @return array{delete_data_on_uninstall: bool, dashboard_slug: string}
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
	 * Current dashboard slug.
	 */
	public static function dashboard_slug(): string {
		return self::all()[ self::DASHBOARD_SLUG ];
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
