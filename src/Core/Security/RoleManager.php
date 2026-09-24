<?php
/**
 * Installs and updates roles and capabilities.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Security;

use MerchBoard\Core\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Brings WordPress' stored roles in line with Capabilities (ADR-022), using
 * only the role API (get_role, add_role, add_cap, remove_cap).
 *
 * - Creator role: created if missing, then held to EXACTLY the defined
 *   capabilities. Anything else (e.g. added with a role editor) is removed and
 *   logged, so the role can never carry administrative rights.
 * - Administrator role: the plugin's admin capabilities are added. Obsolete
 *   plugin-owned (`merchboard_`) capabilities are removed. Capabilities owned by
 *   WordPress or other plugins are never touched.
 * - No other role is touched. No user is modified.
 *
 * Synchronisation is idempotent. It runs on activation and whenever the stored
 * roles version is lower than VERSION (plugin updates do not fire activation).
 */
final class RoleManager {

	/**
	 * Version of the role/capability definitions. Increase it whenever
	 * Capabilities changes so existing sites are synchronised on update.
	 */
	public const VERSION = 1;

	/**
	 * Option holding the synchronised version (integer).
	 */
	public const VERSION_OPTION = 'merchboard_roles_version';

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Technical log.
	 */
	public function __construct( private Logger $logger ) {
	}

	/**
	 * Cheap check (one autoloaded option) whether a sync is due.
	 */
	public function needs_sync(): bool {
		$stored = get_option( self::VERSION_OPTION, 0 );

		return ( is_numeric( $stored ) ? (int) $stored : 0 ) < self::VERSION;
	}

	/**
	 * Synchronises roles and capabilities.
	 *
	 * @return bool True when the creator role is in the expected state (the
	 *              version is then recorded), false when it could not be created.
	 */
	public function sync(): bool {
		if ( ! $this->sync_creator_role() ) {
			return false;
		}

		$this->sync_admin_role();
		update_option( self::VERSION_OPTION, self::VERSION, true );

		return true;
	}

	/**
	 * Creates the creator role or reduces it to exactly the defined capabilities.
	 */
	private function sync_creator_role(): bool {
		$expected = Capabilities::creator_capabilities();
		$role     = get_role( Capabilities::CREATOR_ROLE );

		if ( null === $role ) {
			$role = add_role( Capabilities::CREATOR_ROLE, Capabilities::CREATOR_ROLE_NAME, array_fill_keys( $expected, true ) );

			if ( null === $role ) {
				$this->logger->error( 'Could not create the creator role.', array( 'role' => Capabilities::CREATOR_ROLE ) );
				return false;
			}

			return true;
		}

		foreach ( $expected as $capability ) {
			if ( true !== ( $role->capabilities[ $capability ] ?? null ) ) {
				$role->add_cap( $capability, true );
			}
		}

		$removed = array();

		foreach ( array_keys( $role->capabilities ) as $capability ) {
			if ( ! in_array( $capability, $expected, true ) ) {
				$role->remove_cap( $capability );
				$removed[] = $capability;
			}
		}

		if ( array() !== $removed ) {
			$this->logger->warning(
				'Unexpected capabilities removed from the creator role.',
				array(
					'role'         => Capabilities::CREATOR_ROLE,
					'capabilities' => $removed,
				)
			);
		}

		return true;
	}

	/**
	 * Adds the plugin's admin capabilities and removes obsolete plugin-owned ones.
	 */
	private function sync_admin_role(): void {
		$role = get_role( Capabilities::ADMIN_ROLE );

		if ( null === $role ) {
			// Sites can remove or rename the administrator role; there is nothing safe
			// to grant then. Plugin administration needs the capabilities assigned manually.
			$this->logger->warning( 'Administrator role not found; plugin capabilities were not granted.' );
			return;
		}

		$expected = Capabilities::admin_capabilities();

		foreach ( $expected as $capability ) {
			if ( true !== ( $role->capabilities[ $capability ] ?? null ) ) {
				$role->add_cap( $capability, true );
			}
		}

		foreach ( array_keys( $role->capabilities ) as $capability ) {
			if ( Capabilities::is_plugin_capability( $capability ) && ! in_array( $capability, $expected, true ) ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
