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

defined( 'ABSPATH' ) || exit;

/**
 * Connects the Migrator to WordPress: plugin activation and version detection
 * on normal requests (plugin updates do not fire the activation hook).
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
	 * @param Migrator $migrator Schema migrator.
	 * @param Logger   $logger   Technical log.
	 */
	public function __construct(
		private Migrator $migrator,
		private Logger $logger
	) {
	}

	/**
	 * Activation: install or upgrade the schema. Safe to run repeatedly.
	 */
	public function install(): void {
		$this->run();
	}

	/**
	 * Normal request: upgrade only when the stored schema version is behind.
	 * Costs one autoloaded option read when nothing is pending.
	 */
	public function maybe_upgrade(): void {
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
