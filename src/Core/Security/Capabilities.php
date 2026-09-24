<?php
/**
 * Role and capability definitions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the plugin's role and capabilities
 * (docs/USER-ROLES.md). Use these constants instead of string literals, and
 * check capabilities, never roles or display names, e.g.
 * `current_user_can( Capabilities::MANAGE_CREATORS )`.
 *
 * A capability says what a WordPress user may do. It does not make the user a
 * creator: creator access (roadmap phase 8) additionally requires an active
 * creator record linked to the user.
 *
 * The plugin owns every capability that starts with `merchboard_`.
 */
final class Capabilities {

	/**
	 * Prefix of all plugin-owned capabilities.
	 */
	public const PREFIX = 'merchboard_';

	/**
	 * Role for creators (login accounts that may use the creator dashboard).
	 */
	public const CREATOR_ROLE = 'merchboard_creator';

	/**
	 * Display name of the creator role (translated for display by WordPress'
	 * translate_user_role(); stored untranslated).
	 */
	public const CREATOR_ROLE_NAME = 'Merch Dashboard Creator';

	/**
	 * Role that receives the plugin's administrative capabilities.
	 */
	public const ADMIN_ROLE = 'administrator';

	/**
	 * May use the creator dashboard. Necessary but not sufficient for creator
	 * access: an active, linked creator record is also required (phase 8).
	 */
	public const ACCESS_DASHBOARD = 'merchboard_access_dashboard';

	/**
	 * May see the Merch Dashboard admin menu and overview.
	 */
	public const MANAGE = 'merchboard_manage';

	/**
	 * May create, edit, activate/deactivate creators and link/unlink users.
	 */
	public const MANAGE_CREATORS = 'merchboard_manage_creators';

	/**
	 * May change Merch Dashboard settings.
	 */
	public const MANAGE_SETTINGS = 'merchboard_manage_settings';

	/**
	 * May view the business audit trail (read-only). Not the technical logs,
	 * which live in WooCommerce/PHP logs with their own access rules (ADR-021).
	 */
	public const VIEW_LOGS = 'merchboard_view_logs';

	/**
	 * Exact capability set of the creator role. `read` is WordPress' baseline
	 * for authenticated users (the Subscriber role has only `read`); nothing
	 * else from WordPress is granted.
	 *
	 * @return list<string>
	 */
	public static function creator_capabilities(): array {
		return array(
			'read',
			self::ACCESS_DASHBOARD,
		);
	}

	/**
	 * Plugin capabilities granted to administrators.
	 *
	 * @return list<string>
	 */
	public static function admin_capabilities(): array {
		return array(
			self::MANAGE,
			self::MANAGE_CREATORS,
			self::MANAGE_SETTINGS,
			self::VIEW_LOGS,
		);
	}

	/**
	 * All capabilities the plugin defines.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array_values(
			array_unique(
				array_merge(
					array_filter( self::creator_capabilities(), array( self::class, 'is_plugin_capability' ) ),
					self::admin_capabilities()
				)
			)
		);
	}

	/**
	 * Whether a capability is owned by the plugin (by prefix). Used so that
	 * synchronisation never touches capabilities owned by WordPress or other plugins.
	 *
	 * @param string $capability Capability name.
	 */
	public static function is_plugin_capability( string $capability ): bool {
		return str_starts_with( $capability, self::PREFIX );
	}
}
