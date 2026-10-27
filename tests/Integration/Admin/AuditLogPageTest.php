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

	public function test_product_and_category_events_are_readable_with_bulk_lookups(): void {
		register_post_type( 'product', array( 'capability_type' => 'post', 'show_ui' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Edit links need show_ui.
		register_taxonomy( 'product_cat', 'product' );
		add_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10, 2 );

		try {
			$creator = $this->service( CreatorService::class )->create( 'Studio <b>Nova</b>', CreatorStatus::Active, null );
			$trigger = $this->create_user( 'audit_trigger' );
			$gone    = $this->create_user( 'audit_gone' );
			$product = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Tee <i>x</i>' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$deleted = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Gone' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$term    = wp_insert_term( 'Nova <u>shirts</u>', 'product_cat' );
			$old     = wp_insert_term( 'Old', 'product_cat' );
			self::assertIsArray( $term );
			self::assertIsArray( $old );
			$term_id = (int) $term['term_id'];
			$old_id  = (int) $old['term_id'];
			$run_id  = '0b9f2c4e-1d2a-4c3b-8e5f-6a7b8c9d0e1f';

			$audit = new AuditRepository( $this->wpdb );
			$now   = current_time( 'mysql', true );
			$audit->append( AuditAction::ProductCreatorLinked, null, $creator->id, array( 'product_id' => $product, 'previous_creator_id' => null, 'source' => 'category', 'reason' => 'category_added', 'category_ids' => array( $term_id, $old_id ), 'trigger_user_id' => $trigger, 'run_id' => $run_id ), $now ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$audit->append( AuditAction::ProductCreatorUnlinked, null, $creator->id, array( 'product_id' => $deleted, 'source' => 'category', 'reason' => 'term_deleted', 'trigger_user_id' => $gone ), $now ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$audit->append( AuditAction::CreatorCategoryUnlinked, 1, $creator->id, array( 'term_id' => $old_id, 'reason' => 'admin', 'choice' => 'keep_as_manual' ), $now ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$audit->append( AuditAction::CategorySyncCompleted, null, null, array( 'run_id' => $run_id, 'dry_run' => false, 'trigger' => 'full_sync', 'trigger_user_id' => $trigger, 'scope' => 'all', 'linked' => 12, 'moved' => 3, 'unlinked' => 2, 'unchanged' => 85, 'skipped_manual' => 4, 'ambiguous' => 1, 'skipped_inactive' => 0, 'errors' => 0, 'total' => 107 ), $now ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			wp_delete_post( $deleted, true );
			wp_delete_term( $old_id, 'product_cat' );
			wp_delete_user( $gone );
			wp_set_current_user( 1 );
			$before = $this->rows( Tables::AUDIT_LOG );

			$html = $this->render();

			self::assertStringContainsString( 'Product: <a href="' . esc_url( (string) get_edit_post_link( $product, 'raw' ) ) . '">Tee &lt;i&gt;x&lt;/i&gt; (#' . $product . ')</a>', $html );
			// WordPress strips tags from term names on insert.
			self::assertStringContainsString( 'Product categories: Nova shirts (#' . $term_id . '), #' . $old_id, $html, 'The deleted category is "#id".' );
			self::assertStringContainsString( 'Product: #' . $deleted, $html, 'The deleted product is "#id".' );
			self::assertStringContainsString( 'Triggered by: audit_trigger (audit_trigger)', $html );
			self::assertStringContainsString( 'Triggered by: #' . $gone . ' (no longer exists)', $html );
			self::assertStringContainsString( 'Origin: Product category', $html );
			self::assertStringContainsString( 'Reason: the product category was deleted', $html );
			self::assertStringContainsString( 'Product category: #' . $old_id, $html );
			self::assertStringContainsString( 'Choice: Keep as manual links', $html );
			self::assertStringContainsString( 'Run: <abbr title="' . $run_id . '">0b9f2c4e</abbr>', $html );
			self::assertStringContainsString( 'Linked: 12 · Moved: 3 · Removed: 2 · Unchanged: 85 · Manual: 4 · Ambiguous: 1 · Inactive: 0 · Errors: 0', $html );
			self::assertStringNotContainsString( '<i>x</i>', $html );
			self::assertStringNotContainsString( '<u>', $html );
			self::assertStringNotContainsString( 'product_id:', $html, 'No raw keys for known context.' );
			self::assertStringNotContainsString( '<form method="post"', $html, 'Still read-only.' );
			self::assertSame( $before, $this->rows( Tables::AUDIT_LOG ), 'The context is not changed.' );

			$viewer = get_user_by( 'id', $this->create_user( 'audit_viewer' ) );
			self::assertInstanceOf( \WP_User::class, $viewer );
			$viewer->add_cap( Capabilities::VIEW_LOGS );
			wp_set_current_user( $viewer->ID );
			$html = $this->render();
			self::assertStringContainsString( 'Product: Tee &lt;i&gt;x&lt;/i&gt; (#' . $product . ')', $html );
			self::assertStringNotContainsString( 'post.php?post=' . $product, $html, 'No product link without edit_product.' );
		} finally {
			remove_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10 );
			unregister_taxonomy( 'product_cat' );
			unregister_post_type( 'product' );
		}
	}

	public function test_product_lookups_do_not_grow_with_the_number_of_records(): void {
		register_post_type( 'product', array( 'capability_type' => 'post', 'show_ui' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Edit links need show_ui.
		register_taxonomy( 'product_cat', 'product' );
		add_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10, 2 );

		try {
			$creator = $this->service( CreatorService::class )->create( 'Studio', CreatorStatus::Active, null );
			$term    = wp_insert_term( 'Shirts', 'product_cat' );
			self::assertIsArray( $term );
			$audit = new AuditRepository( $this->wpdb );
			$add   = function ( int $count ) use ( $audit, $creator, $term ): void {
				for ( $i = 0; $i < $count; $i++ ) {
					$product = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Product ' . $i ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					$audit->append( AuditAction::ProductCreatorLinked, 1, $creator->id, array( 'product_id' => $product, 'source' => 'admin', 'category_ids' => array( (int) $term['term_id'] ) ), current_time( 'mysql', true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}
			};
			wp_set_current_user( 1 );

			$add( 3 );
			$this->render(); // Warm-up: one-time work of the first rendering in a process.

			// Both measurements start with a cold object cache, so repeated
			// lookups the cache would answer still show up as queries.
			wp_cache_flush();
			$queries = $this->wpdb->num_queries;
			$this->render();
			$few = $this->wpdb->num_queries - $queries;

			$add( 9 );
			wp_cache_flush();
			$queries = $this->wpdb->num_queries;
			$this->render();
			$many = $this->wpdb->num_queries - $queries;

			self::assertSame( $few, $many, '3 or 12 product records: the same number of queries.' );
		} finally {
			remove_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10 );
			unregister_taxonomy( 'product_cat' );
			unregister_post_type( 'product' );
		}
	}

	/**
	 * Maps `edit_product` like WooCommerce's product post type does.
	 *
	 * @param mixed $caps Primitive capabilities.
	 * @param mixed $cap  Requested capability.
	 * @return mixed
	 */
	public static function product_caps( mixed $caps, mixed $cap ): mixed {
		return 'edit_product' === $cap ? array( 'edit_posts' ) : $caps;
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
				'merchboard'                    => Capabilities::MANAGE,
				'merchboard-creators'           => Capabilities::MANAGE_CREATORS,
				'merchboard-category-proposals' => Capabilities::MANAGE_CREATORS,
				'merchboard-settings'           => Capabilities::MANAGE_SETTINGS,
				'merchboard-notifications'      => Capabilities::MANAGE_SETTINGS,
				'merchboard-news'               => Capabilities::MANAGE_CREATORS,
				'merchboard-audit-log'          => Capabilities::VIEW_LOGS,
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
