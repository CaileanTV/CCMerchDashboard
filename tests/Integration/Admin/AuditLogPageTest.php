<?php
/**
 * The read-only audit log screen with real WordPress, WP_List_Table and database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Admin;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\Installer;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Modules\Admin\AuditLogPage;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Settings\SettingsService;
use MerchBoard\Tests\Integration\IntegrationTestCase;

final class AuditLogPageTest extends IntegrationTestCase {

	/**
	 * Previous $_GET.
	 *
	 * @var array<mixed>
	 */
	private array $saved_get = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture.

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		set_current_screen( 'merch-dashboard_page_' . AuditLogPage::SLUG );
	}

	protected function tearDown(): void {
		$_GET     = $this->saved_get;
		$_REQUEST = array();
		parent::tearDown();
	}

	public function test_every_kind_of_record_is_shown_safely(): void {
		$creators = $this->service( CreatorService::class );
		$user     = $this->create_user( 'audit_linked' );
		$actor    = $this->create_user( 'audit_actor' );
		$creator  = $creators->create( 'Studio <script>x</script>', CreatorStatus::Inactive, $user );
		$creators->set_status( $creator->id, CreatorStatus::Active );
		$creators->update( $creator->id, 'Renamed', CreatorStatus::Active, null );
		$this->service( SettingsService::class )->set_delete_data_on_uninstall( true, 'DELETE' );
		$this->service( SettingsService::class )->set_delete_data_on_uninstall( false );

		// A record by a user who was later deleted, one without actor, one for a creator that is gone.
		$audit = new AuditRepository( $this->wpdb );
		$audit->append( AuditAction::CreatorUpdated, $actor, $creator->id, array( 'changed_fields' => array( 'display_name' ) ), current_time( 'mysql', true ) );
		$audit->append( AuditAction::CreatorDeactivated, null, 9999, array( 'previous_status' => 'active', 'reason' => 'user_deleted' ), current_time( 'mysql', true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		wp_delete_user( $actor );
		wp_set_current_user( 1 );

		$html = $this->render();

		self::assertStringContainsString( 'Creator created', $html );
		self::assertStringContainsString( 'Creator activated', $html );
		self::assertStringContainsString( 'WordPress user unlinked', $html );
		self::assertStringContainsString( 'Settings changed', $html );
		self::assertStringContainsString( 'New value: enabled', $html );
		self::assertStringContainsString( 'Creator #9999 (not found)', $html );
		self::assertStringContainsString( 'Reason: the WordPress user was deleted', $html );
		self::assertStringContainsString( 'User #' . $actor . ' (no longer exists)', $html );
		self::assertStringContainsString( '>System<', $html );
		self::assertStringContainsString( 'WordPress user: audit_linked (audit_linked)', $html );
		self::assertStringNotContainsString( '<script>x</script>', $html );
		self::assertStringNotContainsString( '@example.org', $html, 'No e-mail addresses.' );
		self::assertStringNotContainsString( '"changed_fields"', $html, 'No raw JSON.' );
		self::assertStringNotContainsString( '<form method="post"', $html, 'Nothing can be submitted to change records.' );
		self::assertStringNotContainsString( 'bulk-action', $html );
	}

	public function test_pagination_shows_twenty_per_page(): void {
		$audit = new AuditRepository( $this->wpdb );

		for ( $i = 1; $i <= 25; $i++ ) {
			$audit->append( AuditAction::CreatorCreated, 1, $i, array(), sprintf( '2026-09-24 10:%02d:00', $i ) );
		}

		$first  = $this->render();
		$second = $this->render( array( 'paged' => '2' ) );

		self::assertSame( 20, substr_count( $first, 'Creator #' ) );
		self::assertStringContainsString( '25 items', $first );
		self::assertSame( 5, substr_count( $second, 'Creator #' ) );
		self::assertStringContainsString( 'Creator #5 ', $second );
		self::assertStringNotContainsString( 'Creator #6 ', $second );
	}

	public function test_filters_by_event_creator_and_period(): void {
		$audit = new AuditRepository( $this->wpdb );
		$audit->append( AuditAction::CreatorCreated, 1, 1, array(), '2026-09-01 12:00:00' );
		$audit->append( AuditAction::CreatorActivated, 1, 2, array(), '2026-09-10 12:00:00' );
		$audit->append( AuditAction::CreatorActivated, 1, 1, array(), '2026-09-20 12:00:00' );

		self::assertStringContainsString( '2 items', $this->render( array( 'event' => 'creator_activated' ) ) );
		self::assertStringContainsString( '2 items', $this->render( array( 'creator' => '1' ) ) );
		self::assertStringContainsString( '1 item', $this->render( array( 'from' => '2026-09-05', 'to' => '2026-09-15' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'No audit records match the filter.', $this->render( array( 'event' => 'settings_updated' ) ) );

		$invalid = $this->render( array( 'creator' => "1' OR '1'='1", 'event' => '%' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'Some filter values were invalid and have been ignored.', $invalid );
		self::assertStringContainsString( '3 items', $invalid, 'Invalid values are dropped, not used.' );
		self::assertSame( 3, $this->count_rows( Tables::AUDIT_LOG ), 'Reading changes nothing.' );
	}

	public function test_only_users_with_view_logs_have_access_and_the_menu_uses_it(): void {
		$creator_user = $this->create_user( 'audit_creator' );
		$this->service( CreatorService::class )->create( 'Has dashboard', CreatorStatus::Active, $creator_user );

		self::assertTrue( user_can( 1, Capabilities::VIEW_LOGS ) );
		self::assertFalse( user_can( $creator_user, Capabilities::VIEW_LOGS ), 'Creator access never implies the audit log.' );
		self::assertFalse( user_can( $this->create_user( 'audit_subscriber' ), Capabilities::VIEW_LOGS ) );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$GLOBALS['menu']    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu for this test.
		$GLOBALS['submenu'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu for this test.
		do_action( 'admin_menu', '' );
		$submenu = $GLOBALS['submenu'];
		self::assertIsArray( $submenu );
		self::assertIsArray( $submenu['merchboard'] ?? null );
		self::assertSame(
			array(
				'merchboard'           => Capabilities::MANAGE,
				'merchboard-creators'  => Capabilities::MANAGE_CREATORS,
				'merchboard-settings'  => Capabilities::MANAGE_SETTINGS,
				'merchboard-audit-log' => Capabilities::VIEW_LOGS,
			),
			array_column( $submenu['merchboard'], 1, 2 )
		);
	}

	public function test_existing_sites_get_the_new_capability_on_update(): void {
		$admin = get_role( 'administrator' );
		self::assertInstanceOf( \WP_Role::class, $admin );
		$admin->remove_cap( Capabilities::VIEW_LOGS );
		update_option( RoleManager::VERSION_OPTION, 1 );
		self::assertFalse( user_can( 1, Capabilities::VIEW_LOGS ) );

		$this->service( Installer::class )->maybe_upgrade();

		$admin   = get_role( 'administrator' );
		$creator = get_role( Capabilities::CREATOR_ROLE );
		self::assertInstanceOf( \WP_Role::class, $admin );
		self::assertInstanceOf( \WP_Role::class, $creator );
		self::assertTrue( $admin->has_cap( Capabilities::VIEW_LOGS ) );
		self::assertFalse( $creator->has_cap( Capabilities::VIEW_LOGS ) );
		self::assertSame( RoleManager::VERSION, (int) get_option( RoleManager::VERSION_OPTION ) );
	}

	/**
	 * Renders the screen for query arguments.
	 *
	 * @param array<string, string> $get Query arguments.
	 */
	private function render( array $get = array() ): string {
		$_GET     = $get;
		$_REQUEST = $get; // WP_List_Table reads the page number from $_REQUEST, as in a real GET request.
		ob_start();
		$this->service( AuditLogPage::class )->render();

		return (string) ob_get_clean();
	}
}
