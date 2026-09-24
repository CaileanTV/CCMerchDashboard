<?php
/**
 * Overview admin screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Lifecycle\Installer;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\UserAccounts;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Overview" (phase 10): creator counts and system status
 * from data that exists today. No sales figures, statistics or audit list
 * (the audit viewer is phase 11 and needs `merchboard_view_logs`).
 * Read-only; all data comes from repositories and services, and every value
 * is escaped at output. No personal data is shown.
 */
final class OverviewPage {

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository  $creators     Creator storage (reads only).
	 * @param UserAccounts       $users        User lookups (dangling links).
	 * @param Migrator           $migrator     Schema versions.
	 * @param TransactionSupport $transactions Storage engine check.
	 * @param RoleManager        $roles        Role sync state.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private UserAccounts $users,
		private Migrator $migrator,
		private TransactionSupport $transactions,
		private RoleManager $roles
	) {
	}

	/**
	 * URL of the Overview screen.
	 */
	public static function url(): string {
		return add_query_arg( array( 'page' => AdminMenu::PARENT_SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access Merch Dashboard.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap merchboard-overview">';
		echo '<h1>' . esc_html__( 'Merch Dashboard', 'merchboard' ) . '</h1>';

		$this->render_creators();
		$this->render_status();

		echo '</div>';
	}

	/**
	 * Creator counts and dangling user links.
	 */
	private function render_creators(): void {
		echo '<h2>' . esc_html__( 'Creators', 'merchboard' ) . '</h2>';

		try {
			$counts   = $this->creators->status_counts();
			$linked   = $this->creators->linked_user_ids();
			$dangling = count( array_diff( $linked, array_keys( $this->users->labels( $linked ) ) ) );
		} catch ( DatabaseException ) {
			echo '<p>' . esc_html__( 'Creator data could not be read. See the technical log.', 'merchboard' ) . '</p>';
			return;
		}

		$active   = $counts['active'] ?? 0;
		$inactive = $counts['inactive'] ?? 0;

		echo '<table class="widefat striped merchboard-overview-counts" role="presentation"><tbody>';
		self::row( __( 'Active', 'merchboard' ), (string) $active );
		self::row( __( 'Inactive', 'merchboard' ), (string) $inactive );
		self::row( __( 'Total', 'merchboard' ), (string) ( $active + $inactive ) );
		self::row( __( 'Linked to a WordPress user', 'merchboard' ), (string) count( $linked ) );
		echo '</tbody></table>';

		if ( $dangling > 0 ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of creators. */
					_n(
						'%d creator is linked to a WordPress user that no longer exists. It grants no access; unlink it on the creator\'s edit screen.',
						'%d creators are linked to a WordPress user that no longer exists. They grant no access; unlink them on the creators\' edit screens.',
						$dangling,
						'merchboard'
					),
					$dangling
				)
			) . '</p></div>';
		}

		if ( current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			echo '<p><a class="button" href="' . esc_url( CreatorsAdminPage::url() ) . '">' . esc_html__( 'Manage creators', 'merchboard' ) . '</a></p>';
		}
	}

	/**
	 * System status.
	 */
	private function render_status(): void {
		$installed = $this->migrator->installed_version();
		$latest    = $this->migrator->latest_version();
		$failed    = false !== get_transient( Installer::FAILURE_TRANSIENT );

		if ( $failed ) {
			$schema = __( 'Update failed; retried automatically. See the technical log.', 'merchboard' );
		} elseif ( $installed < $latest ) {
			/* translators: 1: installed schema version, 2: expected schema version. */
			$schema = sprintf( __( 'Update pending (version %1$d of %2$d)', 'merchboard' ), $installed, $latest );
		} else {
			/* translators: %d: schema version. */
			$schema = sprintf( __( 'Up to date (version %d)', 'merchboard' ), $installed );
		}

		$woocommerce = defined( 'WC_VERSION' )
			/* translators: %s: WooCommerce version. */
			? sprintf( __( 'Active (%s)', 'merchboard' ), (string) constant( 'WC_VERSION' ) )
			: __( 'Not active (optional for now)', 'merchboard' );

		echo '<h2>' . esc_html__( 'System status', 'merchboard' ) . '</h2>';
		echo '<table class="widefat striped merchboard-overview-status" role="presentation"><tbody>';
		self::row( __( 'Plugin version', 'merchboard' ), defined( 'MERCHBOARD_VERSION' ) ? (string) constant( 'MERCHBOARD_VERSION' ) : '' );
		self::row( __( 'Database schema', 'merchboard' ), $schema );
		self::row(
			__( 'Transaction support', 'merchboard' ),
			$this->transactions->available()
				? __( 'Available', 'merchboard' )
				: __( 'Not available: creator changes are disabled until the tables use InnoDB.', 'merchboard' )
		);
		self::row( __( 'Roles and capabilities', 'merchboard' ), $this->roles->needs_sync() ? __( 'Update pending', 'merchboard' ) : __( 'Up to date', 'merchboard' ) );
		self::row( __( 'WooCommerce', 'merchboard' ), $woocommerce );
		self::row(
			__( 'Data on uninstall', 'merchboard' ),
			Settings::delete_data_on_uninstall()
				? __( 'DELETED when the plugin is deleted', 'merchboard' )
				: __( 'Kept when the plugin is deleted', 'merchboard' )
		);
		echo '</tbody></table>';

		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo '<p><a href="' . esc_url( SettingsPage::url() ) . '">' . esc_html__( 'Settings', 'merchboard' ) . '</a></p>';
		}
	}

	/**
	 * Prints a label/value row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
