<?php
/**
 * Installs and upgrades the database schema.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Lifecycle;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\RoleManager;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and upgrades the plugin's persistent state: the database schema
 * (Migrator) and roles/capabilities (RoleManager). Runs on plugin activation
 * and on normal requests when a stored version is behind (plugin updates do
 * not fire the activation hook). Both are independent: a failing migration
 * does not block the role sync, and vice versa.
 *
 * It never derives RestoredHistory (ADR-059): on a fresh install (schema
 * version 0), before the migrations, it only removes a marker whose creators
 * table is missing. An upgrade never touches the marker.
 */
final class Installer {

	/**
	 * Transient set after a failed migration. While it exists, automatic
	 * upgrades on normal requests are paused so a failing schema change is not
	 * retried on every request; activation always retries.
	 */
	public const FAILURE_TRANSIENT = 'merchboard_db_migration_failed';

	/**
	 * Pause between automatic retries after a failure, in seconds.
	 */
	private const RETRY_DELAY = 300;

	/**
	 * Constructor.
	 *
	 * @param Migrator    $migrator Schema migrator.
	 * @param RoleManager $roles    Role/capability synchronisation.
	 * @param Logger      $logger   Technical log.
	 * @param wpdb|null   $wpdb     Database for the RestoredHistory check; null skips it.
	 */
	public function __construct(
		private Migrator $migrator,
		private RoleManager $roles,
		private Logger $logger,
		private ?wpdb $wpdb = null
	) {
	}

	/**
	 * Activation: install or upgrade schema and roles. Safe to run repeatedly.
	 */
	public function install(): void {
		$this->run();
		$this->roles->sync();
	}

	/**
	 * Normal request: upgrade only what is behind. Costs two autoloaded option
	 * reads when nothing is pending.
	 */
	public function maybe_upgrade(): void {
		if ( $this->roles->needs_sync() ) {
			$this->roles->sync();
		}

		if ( ! $this->migrator->needs_migration() ) {
			return;
		}

		if ( false !== get_transient( self::FAILURE_TRANSIENT ) ) {
			return;
		}

		$this->run();
	}

	/**
	 * Admin notice while a migration failure is recorded. Only shown to users
	 * who can manage plugins; contains no database details.
	 */
	public function render_failure_notice(): void {
		if ( false === get_transient( self::FAILURE_TRANSIENT ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Merch Dashboard could not update its database tables. The update is retried automatically. Technical details are in the log (WooCommerce → Status → Logs, source "merchboard", or the server error log).', 'merchboard' );
		echo '</p></div>';
	}

	/**
	 * Runs the migrator and records failures.
	 */
	private function run(): void {
		$from = $this->migrator->installed_version();

		if ( 0 === $from && null !== $this->wpdb && RestoredHistory::forget_without_creators( $this->wpdb ) ) {
			$this->logger->warning( 'Removed the record of archived creators: the creators table was missing on a fresh install.' );
		}

		try {
			$completed = $this->migrator->migrate();
		} catch ( DatabaseException $e ) {
			// Handled here: pause automatic retries, show the generic admin notice and
			// log the technical detail once. The schema version was not advanced.
			set_transient( self::FAILURE_TRANSIENT, time(), self::RETRY_DELAY );
			$this->logger->error(
				'Database migration failed.',
				array_merge( $e->context(), array( 'retry_in_seconds' => self::RETRY_DELAY ) )
			);
			return;
		}

		if ( ! $completed ) {
			$this->logger->debug( 'Database migration skipped: another request holds the lock.' );
			return;
		}

		delete_transient( self::FAILURE_TRANSIENT );

		$to = $this->migrator->installed_version();

		if ( $to !== $from ) {
			$this->logger->info(
				'Database schema migrated.',
				array(
					'from_version' => $from,
					'to_version'   => $to,
				)
			);
		}
	}
}
