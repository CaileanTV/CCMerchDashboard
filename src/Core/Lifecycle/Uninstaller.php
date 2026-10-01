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
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Core\Settings\Settings;
use WC_Order;
use WP_User;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from uninstall.php when WordPress deletes the plugin (ADR-025).
 *
 * Always cancels pending sales ledger backfill jobs (ScheduledJobs; jobs,
 * not data). Deletes nothing unless "delete all data on uninstall" is
 * exactly true.
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
 * 3. the product meta `_merchboard_creator_id` and
 *    `_merchboard_creator_link_source` from every post (ADR-030 D4), through
 *    WordPress' meta API with `delete_post_meta_by_key()`, which matches every
 *    key spelling under MySQL's case-insensitive collation. Never direct SQL.
 *    When the plugin is loaded in this request (e.g. WP-CLI
 *    `wp plugin uninstall --deactivate`), the protected meta guard allows only
 *    this deletion, through ADR-036's uninstall window, which needs the
 *    opt-in still on: hence before the settings are deleted. Products and all
 *    other meta stay;
 * 3a. the sales ledger failure marker `_merchboard_ledger_failure` from every
 *    order (ADR-038 pkt. 20 and Q3): legacy post meta through the meta API;
 *    HPOS order meta only through WooCommerce CRUD while WooCommerce is loaded.
 *    With WooCommerce inactive, markers in HPOS' meta table stay (accepted).
 *    Never direct SQL against WooCommerce tables. Orders themselves stay;
 * 4. the plugin tables (SchemaEraser, by exact name);
 * 5. the plugin options and transient, and the settings option LAST.
 *
 * DROP TABLE cannot be part of a transaction, so there is no pretend
 * atomicity. Every step is idempotent, and the opt-in is removed last: if a
 * step fails or the request dies, the opt-in survives and the next uninstall
 * continues where this one stopped. No audit records are written; uninstall is
 * a lifecycle operation, and the audit log itself is being deleted.
 */
final class Uninstaller {

	/**
	 * Order meta keys owned by the plugin: the sales ledger failure marker
	 * (Modules\WooCommerce\Sales\WooCommerceSalesFailureMarker::META_KEY; a
	 * test keeps both equal, so core does not depend on the module).
	 */
	public const ORDER_META_KEYS = array( '_merchboard_ledger_failure' );

	/**
	 * WooCommerce' HPOS filter deciding whether an order is saved after a meta change.
	 */
	private const HPOS_SAVE_FILTER = 'woocommerce_orders_table_datastore_should_save_after_meta_change';

	/**
	 * Orders per CRUD batch, and the most batches per key (a safety stop).
	 */
	private const ORDER_BATCH = 100;

	private const MAX_ORDER_BATCHES = 1000;

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
		// Not data: pending backfill pages are cancelled with or without the opt-in.
		ScheduledJobs::cancel_sales_backfill();

		if ( ! Settings::delete_data_on_uninstall() ) {
			return false;
		}

		$this->remove_from_users();
		$this->remove_from_roles();
		$this->remove_product_meta();
		$this->remove_order_meta();

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
	 * Deletes the plugin's product meta keys from every post through the meta
	 * API. Idempotent: WordPress reports false when there was nothing to delete.
	 */
	private function remove_product_meta(): void {
		foreach ( ProtectedProductMeta::KEYS as $key ) {
			delete_post_meta_by_key( $key );
		}
	}

	/**
	 * Deletes the plugin's order meta: legacy post meta through the meta API,
	 * HPOS order meta through WooCommerce CRUD in batches while WooCommerce is
	 * loaded. Only the meta is saved, never the whole order, and HPOS' save
	 * after a meta change is suppressed like WooCommerce does itself.
	 */
	private function remove_order_meta(): void {
		foreach ( self::ORDER_META_KEYS as $key ) {
			delete_post_meta_by_key( $key );

			if ( ! function_exists( 'wc_get_orders' ) ) {
				continue;
			}

			for ( $batch = 0; $batch < self::MAX_ORDER_BATCHES; $batch++ ) {
				// meta_key + meta_compare filter in both HPOS and legacy storage;
				// legacy wc_get_orders() ignores `meta_query` and would return every order.
				$orders = wc_get_orders(
					array(
						'type'         => 'shop_order',
						'limit'        => self::ORDER_BATCH,
						'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once on uninstall.
						'meta_compare' => 'EXISTS',
					)
				);
				$orders = array_filter( is_array( $orders ) ? $orders : array(), static fn( mixed $order ): bool => $order instanceof WC_Order );

				if ( array() === $orders ) {
					continue 2;
				}

				foreach ( $orders as $order ) {
					$order->delete_meta_data( $key );
					add_filter( self::HPOS_SAVE_FILTER, '__return_false' );

					try {
						$order->save_meta_data();
					} finally {
						remove_filter( self::HPOS_SAVE_FILTER, '__return_false' );
					}
				}
			}

			$this->logger->warning( 'Stopped removing order meta on uninstall after the batch limit.', array( 'key' => $key ) );
		}
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
