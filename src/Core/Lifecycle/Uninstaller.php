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
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\KickAccount;
use MerchBoard\Modules\Alerts\Chatbot\KickWebhookVerifier;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeAccount;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayStore;
use MerchBoard\Modules\Alerts\Legal\YouTubeLegal;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\Import\MerchAlertImportPlan;
use MerchBoard\Modules\Alerts\PageLayout\CreatorPageLayoutStore;
use MerchBoard\Modules\WooCommerce\VendorImport\VendorImportPlan;
use MerchBoard\Modules\Alerts\Presets\OverlayPresetStore;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use WC_Order;
use WP_User;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from uninstall.php when WordPress deletes the plugin (ADR-025).
 *
 * Deletes nothing unless "delete all data on uninstall" is exactly true.
 * With the opt-in it first counts the pending payouts (every method; ADR-059):
 * when one is pending, or the count fails, it throws UninstallBlocked before
 * anything is changed (fail closed), and uninstall.php ends the deletion
 * request so the plugin stays installed. Otherwise, and always without the
 * opt-in, it cancels pending sales ledger backfill jobs (ScheduledJobs; jobs,
 * not data).
 * With the opt-in it removes only what the plugin owns, for the current site:
 *
 * 1. from users: the `merchboard_creator` role and the plugin's capabilities
 *    (Capabilities::all()) granted directly; other roles and capabilities stay,
 *    and users themselves are never deleted;
 * 1a. from users: the user meta in USER_META_KEYS (the dashboard theme,
 *    ADR-044), through WordPress' meta API; users and other meta stay;
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
 * 3b. it archives the creators (ADR-059): every creator becomes inactive and
 *    is unlinked from its WordPress user, so no kept record can give access
 *    again; then it writes RestoredHistory with the highest archived id;
 * 4. the plugin tables (SchemaEraser, by exact name), except the ones kept
 *    as historical documentation (Tables::retained_on_uninstall(): creators,
 *    audit log, payouts, settlement documents, their rows and counters); the
 *    encrypted payout details are always dropped;
 * 5. the plugin options and transient, including the schema version (a
 *    reinstall runs every migration again over the kept tables), and the
 *    settings option LAST. Only RestoredHistory stays.
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
	 * (Modules\WooCommerce\Sales\WooCommerceSalesFailureMarker::META_KEY) and
	 * the customer's username for alerts from the classic and the block
	 * checkout (Modules\Alerts\WooCommercePaidOrderReader::USERNAME_META and
	 * Modules\Alerts\Checkout\CheckoutUsername::BLOCK_META, step 25.9.1); tests
	 * keep them equal, so core does not depend on the modules. MerchAlert's
	 * `_ccmerch_username` is not the plugin's and stays.
	 */
	public const ORDER_META_KEYS = array( '_merchboard_ledger_failure', '_merchboard_alert_username', '_wc_other/merchboard/alert-username', '_wc_other/merchboard/alert-username-show' );

	/**
	 * User meta keys owned by the plugin: the dashboard theme preference
	 * (Modules\Dashboard\ThemePreference::META_KEY, ADR-044) and "Anonymize
	 * usernames" in the alert history (Modules\Alerts\Rest\
	 * MeAlertsHistoryController::ANONYMIZE_META, phase 25 step 7) and the
	 * accepted version of the YouTube chatbot's privacy policy and terms
	 * (Modules\Alerts\Legal\YouTubeTerms::META, step 8.5); a test keeps them
	 * equal, so core does not depend on the modules.
	 */
	public const USER_META_KEYS = array( 'merchboard_dashboard_theme', 'merchboard_alerts_anonymize', 'merchboard_youtube_terms_accepted' );

	/**
	 * Attachment meta keys of the alerts' files: the sounds and images of
	 * creators, the libraries and the global overlay (the OWNER_META and
	 * LIBRARY_META of Modules\Alerts\Sounds, Images and GlobalOverlay; a test
	 * keeps them equal).
	 */
	public const ALERT_MEDIA_META_KEYS = array( '_merchboard_alert_sound_creator', '_merchboard_alert_sound_library', '_merchboard_alert_sound_global', '_merchboard_alert_image_creator', '_merchboard_alert_image_library', '_merchboard_alert_image_global' );

	/**
	 * Marker of a file the MerchAlert import took over
	 * (Modules\Alerts\Import\MerchAlertImporter::IMPORTED_META): MerchAlert's
	 * own file, never deleted; MerchAlert's sound owner meta
	 * (`_ccmerch_audio_creator_id`) counts the same.
	 */
	public const ALERT_IMPORTED_META = '_merchboard_alert_imported';

	/**
	 * The status of a pending payout (Modules\WooCommerce\Payouts\PayoutStatus::Pending;
	 * a test keeps both equal, so core does not depend on the module).
	 */
	public const PENDING_PAYOUT_STATUS = 'pending';

	/**
	 * The status of an archived creator (Modules\Creators\CreatorStatus::Inactive;
	 * a test keeps both equal).
	 */
	public const ARCHIVED_CREATOR_STATUS = 'inactive';

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
	 * @return bool Whether all plugin data that is not kept was deleted.
	 * @throws UninstallBlocked With the opt-in, before any change, when payouts are pending or cannot be counted.
	 */
	public function run(): bool {
		$opted_in = Settings::delete_data_on_uninstall();

		if ( $opted_in ) {
			$pending = self::pending_payouts( $this->wpdb );

			if ( 0 !== $pending ) {
				$this->logger->warning( 'Merch Dashboard data was not deleted on uninstall: payouts are pending or could not be counted.', array( 'pending_payouts' => $pending ) );
				throw new UninstallBlocked( $pending );
			}
		}

		// Not data: pending backfill pages are cancelled with or without the opt-in.
		ScheduledJobs::cancel_sales_backfill();

		if ( ! $opted_in ) {
			return false;
		}

		$this->remove_from_users();
		$this->remove_user_meta();
		$this->remove_from_roles();
		$this->remove_product_meta();
		$this->remove_order_meta();
		$this->remove_alert_media();
		ScheduledJobs::cancel_alert_jobs();

		if ( ! $this->archive_creators() ) {
			// Nothing is dropped; the opt-in survives so a later uninstall can finish.
			return false;
		}

		if ( ! $this->schema->erase() ) {
			// Keep the options (including the opt-in) so a later uninstall can finish.
			return false;
		}

		delete_option( Migrator::VERSION_OPTION );
		delete_option( Migrator::LOCK_OPTION );
		delete_option( RoleManager::VERSION_OPTION );
		delete_option( AlertFonts::KEY_OPTION );
		delete_option( AlertFonts::APPROVED_OPTION );
		delete_option( TwitchAccount::OPTION );
		delete_option( ChatbotFormat::OPTION );
		delete_option( KickAccount::OPTION );
		delete_option( YouTubeAccount::OPTION );
		delete_option( YouTubeLegal::OPTION );
		delete_option( GlobalOverlayStore::OPTION );
		delete_option( OverlayPresetStore::OPTION );
		delete_option( CreatorPageLayoutStore::OPTION );
		delete_option( MerchAlertImportPlan::MARKER_OPTION );
		delete_option( VendorImportPlan::OPTION );
		delete_option( AlertApiSwitch::OPTION );
		delete_transient( KickWebhookVerifier::KEY_TRANSIENT );
		delete_transient( AlertFonts::CATALOG_KEY );
		delete_transient( Installer::FAILURE_TRANSIENT );
		delete_option( Settings::OPTION );

		$this->logger->info( 'Merch Dashboard data deleted on uninstall.', array( 'kept_tables' => implode( ', ', Tables::retained_on_uninstall() ) ) );

		return true;
	}

	/**
	 * Pending payouts of every method, 0 when the payouts table does not
	 * exist (a schema before payouts), or null when the count fails.
	 *
	 * @param wpdb $wpdb WordPress database object (site prefix).
	 */
	public static function pending_payouts( wpdb $wpdb ): ?int {
		$table = Tables::name( $wpdb, Tables::PAYOUTS );
		$found = self::read( $wpdb, $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( ! $found['ok'] ) {
			return null;
		}

		if ( null === $found['value'] ) {
			return 0;
		}

		$count = self::read( $wpdb, $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, self::PENDING_PAYOUT_STATUS ) );

		return $count['ok'] && is_numeric( $count['value'] ) ? (int) $count['value'] : null;
	}

	/**
	 * One value from the database, telling a failed read from NULL (wpdb
	 * resets last_error on every query). Live state; never cached.
	 *
	 * @param wpdb        $wpdb WordPress database object.
	 * @param string|null $sql  Prepared query.
	 * @return array{ok: bool, value: mixed}
	 */
	private static function read( wpdb $wpdb, ?string $sql ): array {
		if ( null === $sql ) {
			return array(
				'ok'    => false,
				'value' => null,
			);
		}

		$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; live state of the plugin's own tables.

		return array(
			'ok'    => '' === $wpdb->last_error,
			'value' => $value,
		);
	}

	/**
	 * Runs one write, true when it succeeded.
	 *
	 * @param wpdb        $wpdb WordPress database object.
	 * @param string|null $sql  Prepared statement.
	 */
	private static function write( wpdb $wpdb, ?string $sql ): bool {
		return null !== $sql && false !== $wpdb->query( $sql ) && '' === $wpdb->last_error; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; one-time uninstall of the plugin's own table.
	}

	/**
	 * Archives every creator (inactive, no WordPress user) and records the
	 * highest archived id (RestoredHistory). Idempotent; a creators table that
	 * does not exist has nothing to archive.
	 *
	 * @return bool False when a write or read failed (logged).
	 */
	private function archive_creators(): bool {
		$wpdb   = $this->wpdb;
		$table  = Tables::name( $wpdb, Tables::CREATORS );
		$exists = self::read( $wpdb, $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( ! $exists['ok'] ) {
			$this->logger->error( 'Could not archive the creators during uninstall.' );
			return false;
		}

		if ( null === $exists['value'] ) {
			return true;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		if ( ! self::write( $wpdb, $wpdb->prepare( 'UPDATE %i SET status = %s, user_id = NULL, updated_at = %s WHERE status <> %s OR user_id IS NOT NULL', $table, self::ARCHIVED_CREATOR_STATUS, $now, self::ARCHIVED_CREATOR_STATUS ) ) ) {
			$this->logger->error( 'Could not archive the creators during uninstall.' );
			return false;
		}

		$max = self::read( $wpdb, $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) );

		if ( ! $max['ok'] ) {
			$this->logger->error( 'Could not read the archived creators during uninstall.' );
			return false;
		}

		if ( is_numeric( $max['value'] ) && (int) $max['value'] > 0 && ! RestoredHistory::record( (int) $max['value'], $now ) ) {
			$this->logger->error( 'Could not record the archived creators during uninstall.' );
			return false;
		}

		return true;
	}

	/**
	 * Deletes the plugin's user meta keys from every user through the meta API
	 * (exact keys; users and their other meta stay). Idempotent.
	 */
	private function remove_user_meta(): void {
		foreach ( self::USER_META_KEYS as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}

	/**
	 * Deletes the files the alerts uploaded (sounds and images of creators,
	 * the libraries and the global overlay; 25.13 review). A file the
	 * MerchAlert import took over is MerchAlert's and stays; only the
	 * plugin's meta is removed from it. Idempotent.
	 */
	private function remove_alert_media(): void {
		$marked = array( 'relation' => 'OR' );

		foreach ( self::ALERT_MEDIA_META_KEYS as $key ) {
			$marked[] = array(
				'key'     => $key,
				'compare' => 'EXISTS',
			);
		}

		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => $marked, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-time uninstall.
			)
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( '' === (string) get_post_meta( $id, self::ALERT_IMPORTED_META, true ) && '' === (string) get_post_meta( $id, '_ccmerch_audio_creator_id', true ) ) {
				wp_delete_attachment( $id, true );
			}
		}

		foreach ( array_merge( self::ALERT_MEDIA_META_KEYS, array( self::ALERT_IMPORTED_META ) ) as $key ) {
			delete_post_meta_by_key( $key );
		}
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
