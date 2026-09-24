<?php
/**
 * Opt-in permanent deletion on uninstall.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Lifecycle;

use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\SchemaEraser;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Core\Settings\Settings;
use WP_User;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from uninstall.php when WordPress deletes the plugin (ADR-025).
 *
 * Deletes nothing unless "delete all data on uninstall" is exactly true.
 * With the opt-in it removes only what the plugin owns, for the current site:
 *
 * 1. from users: the `merchboard_creator` role and the plugin's capabilities
 *    (Capabilities::all()) granted directly; other roles and capabilities stay,
 *    and users themselves are never deleted;
 * 2. from roles: the plugin's capabilities (Capabilities::all()), then the
 *    `merchboard_creator` role itself.
 *
 * Only the exact defined names are removed, not everything that starts with
 * `merchboard_`: a role or capability another plugin or a site owner happens
 * to name with that prefix is not ours to delete. A capability that is ever
 * retired from Capabilities must stay listed for this cleanup (ADR-025).
 * 3. the plugin tables (SchemaEraser, by exact name);
 * 4. the plugin options and transient, and the settings option LAST.
 *
 * DROP TABLE cannot be part of a transaction, so there is no pretend
 * atomicity. Every step is idempotent, and the opt-in is removed last: if a
 * step fails or the request dies, the opt-in survives and the next uninstall
 * continues where this one stopped. No audit records are written; uninstall is
 * a lifecycle operation, and the audit log itself is being deleted.
 */
final class Uninstaller {

	/**
	 * Constructor.
	 *
	 * @param wpdb         $wpdb   WordPress database object (site prefix).
	 * @param SchemaEraser $schema Table removal.
	 * @param Logger       $logger Technical log.
	 */
	public function __construct( private wpdb $wpdb, private SchemaEraser $schema, private Logger $logger ) {
	}

	/**
	 * Deletes the plugin's data if (and only if) the opt-in is enabled.
	 *
	 * @return bool Whether all plugin data was deleted.
	 */
	public function run(): bool {
		if ( ! Settings::delete_data_on_uninstall() ) {
			return false;
		}

		$this->remove_from_users();
		$this->remove_from_roles();

		if ( ! $this->schema->erase() ) {
			// Keep the options (including the opt-in) so a later uninstall can finish.
			return false;
		}

		delete_option( Migrator::VERSION_OPTION );
		delete_option( Migrator::LOCK_OPTION );
		delete_option( RoleManager::VERSION_OPTION );
		delete_transient( Installer::FAILURE_TRANSIENT );
		delete_option( Settings::OPTION );

		$this->logger->info( 'Merch Dashboard data deleted on uninstall.' );

		return true;
	}

	/**
	 * Removes the creator role and direct plugin capabilities from users of
	 * the current site. Only users whose capabilities mention the prefix are
	 * loaded.
	 */
	private function remove_from_users(): void {
		$users = get_users(
			array(
				'meta_key'     => $this->wpdb->get_blog_prefix() . 'capabilities', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time uninstall.
				'meta_value'   => Capabilities::PREFIX, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One-time uninstall.
				'meta_compare' => 'LIKE',
			)
		);

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			foreach ( array_keys( $user->caps ) as $capability ) {
				if ( Capabilities::CREATOR_ROLE === $capability ) {
					$user->remove_role( $capability );
				} elseif ( in_array( $capability, Capabilities::all(), true ) ) {
					$user->remove_cap( (string) $capability );
				}
			}
		}
	}

	/**
	 * Removes plugin capabilities from every role of the current site, then the
	 * creator role.
	 */
	private function remove_from_roles(): void {
		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( Capabilities::CREATOR_ROLE === $name ) {
				continue;
			}

			foreach ( array_keys( $role->capabilities ) as $capability ) {
				if ( in_array( $capability, Capabilities::all(), true ) ) {
					$role->remove_cap( (string) $capability );
				}
			}
		}

		remove_role( Capabilities::CREATOR_ROLE );
	}
}
