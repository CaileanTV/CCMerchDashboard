<?php
/**
 * Deactivation and uninstall with real WordPress, roles and database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\SchemaEraser;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\RestoredHistory;
use MerchBoard\Core\Lifecycle\UninstallBlocked;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedMetaWrite;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\KickAccount;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeAccount;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayStore;
use MerchBoard\Modules\Alerts\Legal\YouTubeLegal;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\Import\MerchAlertImportPlan;
use MerchBoard\Modules\Alerts\PageLayout\CreatorPageLayoutStore;
use MerchBoard\Modules\Alerts\Presets\OverlayPresetStore;
use MerchBoard\Modules\Alerts\Legal\YouTubeTerms;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Settings\SettingsService;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkup;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricing;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SnapshotStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_User;

final class UninstallTest extends IntegrationTestCase {

	/**
	 * Unrelated table that looks like another site's plugin table.
	 */
	private const OTHER_SITE_TABLE = 'mbtest_2_merchboard_creators';

	private int $creator_user;

	private int $multi_role_user;

	private int $plain_user;

	private int $post_id;

	private int $product;

	private int $other_product;

	private int $variation;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->seed();
	}

	protected function tearDown(): void {
		$this->execute( 'DROP TABLE IF EXISTS %i', self::OTHER_SITE_TABLE );
		remove_role( 'merchboard_test_custom' );
		wp_delete_post( $this->post_id, true );

		foreach ( array( $this->product, $this->other_product, $this->variation ) as $id ) {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $id );
			$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $id );
		}

		delete_option( 'merchboard_test_unrelated' );
		delete_option( Settings::OPTION );
		delete_option( RestoredHistory::OPTION );
		// Restore schema, roles and capabilities before the base class deletes the
		// test users (the plugin stays loaded here; in production it is deleted).
		$this->install();
		parent::tearDown();
	}

	public function test_deactivation_changes_nothing(): void {
		$before = $this->snapshot();

		do_action( 'deactivate_' . plugin_basename( dirname( __DIR__, 3 ) . '/merchboard.php' ) );

		self::assertSame( $before, $this->snapshot() );
	}

	public function test_uninstall_without_opt_in_keeps_everything(): void {
		$before = $this->snapshot();

		$this->run_uninstall();

		self::assertSame( $before, $this->snapshot() );
		self::assertSame( array( 'dark-red', 'light' ), $before['themes'], 'Theme preferences are kept without the opt-in.' );
	}

	public function test_forged_opt_in_value_keeps_everything(): void {
		update_option( Settings::OPTION, array( Settings::DELETE_DATA_ON_UNINSTALL => '1' ) );
		$before = $this->snapshot();

		$this->run_uninstall();

		self::assertSame( $before, $this->snapshot() );
	}

	public function test_uninstall_with_opt_in_removes_only_merch_dashboard_data(): void {
		$this->enable_deletion();
		update_option( AlertFonts::KEY_OPTION, 'AIzaTestKey_0123456789-abcdef', false );
		update_option( AlertFonts::APPROVED_OPTION, array( array( 'family' => 'Roboto' ) ), false );
		set_transient( AlertFonts::CATALOG_KEY, array( array( 'family' => 'Roboto' ) ), 60 );
		update_option( TwitchAccount::OPTION, array( 'client_id' => 'abc' ), false );
		update_option( ChatbotFormat::OPTION, array( 'mode' => 'order' ), false );
		update_option( AlertApiSwitch::OPTION, '1', false );
		update_option( KickAccount::OPTION, array( 'client_id' => 'abc' ), false );
		update_option( YouTubeAccount::OPTION, array( 'client_id' => 'abc' ), false );
		update_option( YouTubeLegal::OPTION, array( 'version' => 1 ), false );
		( new GlobalOverlayStore() )->create();
		( new OverlayPresetStore() )->add( 'Neon' );
		$audit_before = $this->rows( Tables::AUDIT_LOG );
		$max_creator  = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', $this->table( Tables::CREATORS ) ) );

		$this->run_uninstall();

		// Plugin data gone, except the history ADR-059 keeps.
		foreach ( Tables::all() as $table ) {
			$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->table( $table ) ) ) );

			if ( in_array( $table, Tables::retained_on_uninstall(), true ) ) {
				self::assertNotNull( $found, $table . ' is kept.' );
			} else {
				self::assertNull( $found, $table . ' is dropped.' );
			}
		}

		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->table( Tables::PAYOUT_SECRETS ) ) ) ), 'The encrypted payout details are always dropped.' );
		self::assertSame( $audit_before, $this->rows( Tables::AUDIT_LOG ), 'The audit log is kept unchanged.' );

		// Every creator archived: inactive and unlinked; the marker holds the highest id.
		$creators = $this->rows( Tables::CREATORS );
		self::assertCount( 3, $creators );
		self::assertSame( array( 'inactive' ), array_values( array_unique( array_column( $creators, 'status' ) ) ) );
		self::assertSame( array( null ), array_values( array_unique( array_column( $creators, 'user_id' ) ) ) );
		self::assertSame( $max_creator, maybe_unserialize( (string) $this->raw_option( RestoredHistory::OPTION ) )['max_creator_id'] ?? null );

		foreach ( array( Migrator::VERSION_OPTION, Migrator::LOCK_OPTION, RoleManager::VERSION_OPTION, Settings::OPTION, AlertFonts::KEY_OPTION, AlertFonts::APPROVED_OPTION, TwitchAccount::OPTION, ChatbotFormat::OPTION, KickAccount::OPTION, YouTubeAccount::OPTION, YouTubeLegal::OPTION, GlobalOverlayStore::OPTION, OverlayPresetStore::OPTION, CreatorPageLayoutStore::OPTION, MerchAlertImportPlan::MARKER_OPTION, AlertApiSwitch::OPTION ) as $option ) {
			self::assertNull( $this->raw_option( $option ), $option );
		}

		self::assertFalse( get_transient( AlertFonts::CATALOG_KEY ), 'The font catalogue is removed.' );

		self::assertNull( get_role( Capabilities::CREATOR_ROLE ) );
		self::assertSame( array(), $this->plugin_caps_on_roles() );
		self::assertSame( array(), $this->plugin_caps_on_users() );
		self::assertSame( '', get_user_meta( $this->creator_user, 'merchboard_dashboard_theme', true ), 'Theme preference removed (ADR-044).' );
		self::assertSame( '', get_user_meta( $this->multi_role_user, 'merchboard_dashboard_theme', true ) );
		self::assertSame( '', get_user_meta( $this->creator_user, 'merchboard_alerts_anonymize', true ), 'Alert history preference removed.' );
		self::assertSame( '', get_user_meta( $this->creator_user, YouTubeTerms::META, true ), 'YouTube terms acceptance removed.' );

		// Everything else kept.
		self::assertSame( 'kept', get_user_meta( $this->plain_user, 'merchboard_dashboard_theme_backup', true ), 'Exact key only, no prefix match.' );
		self::assertInstanceOf( WP_User::class, get_userdata( $this->creator_user ), 'Linked WordPress user survives.' );
		self::assertSame( array( 'subscriber' ), array_values( $this->user( $this->creator_user )->roles ) );
		self::assertSame( array( 'subscriber', 'merchboard_test_custom' ), array_values( $this->user( $this->multi_role_user )->roles ) );
		self::assertTrue( user_can( $this->multi_role_user, 'merchboard_test_foreign_cap' ), 'Other capabilities of the user survive.' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Capability of the test's custom role.
		self::assertTrue( user_can( $this->multi_role_user, 'edit_posts' ), 'Direct non-plugin capability survives.' );
		self::assertInstanceOf( WP_User::class, get_userdata( $this->plain_user ) );

		$admin = get_role( 'administrator' );
		self::assertNotNull( $admin );
		self::assertTrue( $admin->has_cap( 'manage_options' ) );
		self::assertTrue( $admin->has_cap( 'delete_plugins' ) );
		self::assertTrue( get_role( 'merchboard_test_custom' )?->has_cap( 'merchboard_test_foreign_cap' ) ?? false, 'Custom role keeps its own capabilities.' );
		self::assertNotNull( get_role( 'editor' ) );

		self::assertSame( 'kept', get_option( 'merchboard_test_unrelated' ) );
		self::assertSame( 'publish', get_post_status( $this->post_id ) );
		self::assertSame( 'meta', get_post_meta( $this->post_id, '_merchboard_test_meta', true ) );
		self::assertSame( self::OTHER_SITE_TABLE, $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( self::OTHER_SITE_TABLE ) ) ), "Another site's table with a similar name survives." );

		self::assertStringContainsString( 'Merch Dashboard data deleted on uninstall.', (string) file_get_contents( MERCHBOARD_TEST_LOG ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.
	}

	public function test_pricing_data_follows_the_opt_in_and_woocommerce_prices_are_never_reset(): void {
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Pricing', CreatorStatus::Active );
		( new CreatorMarkupRepository( $this->wpdb ) )->save( $creator->id, new MarkupBps( 1900 ) );
		( new ProductMarkupRepository( $this->wpdb ) )->save( new ProductMarkup( $this->other_product, $creator->id, new MarkupBps( 2500 ) ) );
		( new SalesLinePricingRepository( $this->wpdb ) )->insert( new SalesLinePricing( 9001, 9000, $creator->id, $this->product, 0, SnapshotStatus::Share, 'DKK', new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 1900, MarkupSource::Creator, 2, false ) );
		// WooCommerce' own prices; Merch Dashboard never writes them.
		update_post_meta( $this->product, '_regular_price', '117.81' );
		update_post_meta( $this->product, '_price', '117.81' );

		$this->run_uninstall();

		foreach ( array( Tables::PRICING_OVERRIDES, Tables::SALES_LINE_PRICING ) as $table ) {
			self::assertSame( 1, $this->count_rows( $table ), $table . ' kept without the opt-in.' );
		}

		$this->enable_deletion();
		$this->run_uninstall();

		foreach ( array( Tables::PRICING_OVERRIDES, Tables::SALES_LINE_PRICING ) as $table ) {
			self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->table( $table ) ) ) ), $table . ' dropped with the opt-in.' );
		}

		wp_cache_flush();
		self::assertSame( '117.81', get_post_meta( $this->product, '_regular_price', true ), 'WooCommerce prices are never reset.' );
		self::assertSame( '117.81', get_post_meta( $this->product, '_price', true ) );
	}

	public function test_uninstall_with_the_plugin_loaded_deletes_only_the_protected_meta_through_the_window(): void {
		// The WP-CLI `wp plugin uninstall --deactivate` flow: the booted plugin's
		// core guard is registered in this process while uninstall.php runs.
		$guard = $this->service( ProtectedProductMeta::class );
		self::assertSame( 1000, has_filter( 'delete_post_metadata', array( $guard, 'filter_delete' ) ) );
		$this->enable_deletion();

		$this->run_uninstall();

		self::assertSame( array(), $this->protected_rows(), 'Every protected row, in every spelling, on products and variations.' );
		self::assertSame( '10', get_post_meta( $this->product, '_price', true ), 'Other product meta stays.' );
		self::assertSame( 'publish', get_post_status( $this->product ), 'Products stay.' );
		self::assertSame( 'publish', get_post_status( $this->variation ) );

		$log = (string) file_get_contents( MERCHBOARD_TEST_LOG ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.
		self::assertStringContainsString( 'Deleted protected product meta during the Merch Dashboard uninstall.', $log );
		self::assertStringNotContainsString( '424242', $log, 'Values are never logged.' );
		self::assertStringNotContainsString( 'Blocked a write to protected product meta', $log );
	}

	public function test_uninstall_without_opt_in_keeps_the_protected_meta(): void {
		$before = $this->protected_rows();

		$this->run_uninstall();

		self::assertCount( 4, $before );
		self::assertSame( $before, $this->protected_rows() );
	}

	public function test_during_the_window_the_booted_guard_still_denies_writes_and_other_posts_writes(): void {
		$this->enable_deletion();
		$this->define_uninstall_constant();

		self::assertFalse( add_post_meta( $this->product, ProtectedProductMeta::LINK_KEY, '7' ), 'No new link.' );
		self::assertFalse( update_post_meta( $this->product, ProtectedProductMeta::LINK_KEY, '7' ), 'No change.' );
		self::assertFalse( update_metadata_by_mid( 'post', $this->meta_id( $this->product, ProtectedProductMeta::LINK_KEY ), '3', '_renamed' ), 'No rename.' );
		self::assertTrue( update_post_meta( $this->product, '_price', '11' ), 'Other meta is unaffected.' );
		self::assertTrue( delete_metadata_by_mid( 'post', $this->meta_id( $this->other_product, '_Merchboard_Creator_ID' ) ), 'Deletion by meta id is allowed.' );
		self::assertTrue( delete_post_meta( $this->product, ProtectedProductMeta::SOURCE_KEY ), 'Deletion of one post\'s key is allowed.' );

		delete_option( Settings::OPTION );

		self::assertFalse( delete_post_meta( $this->product, ProtectedProductMeta::LINK_KEY ), 'Without the opt-in the window is closed.' );
		self::assertSame( '424242', get_post_meta( $this->product, ProtectedProductMeta::LINK_KEY, true ) );
	}

	public function test_the_wp_admin_flow_without_the_loaded_plugin_uninstalls_normally(): void {
		$this->enable_deletion();

		$result = $this->run_process( 'unloaded' );

		self::assertSame(
			array(
				'guard'       => false,
				'uninstalled' => true,
			),
			$result,
			'No guard is registered when the plugin is not loaded; uninstall.php runs.'
		);
		wp_cache_flush();
		$this->forget_table_metadata();
		self::assertSame( array(), $this->protected_rows() );
		self::assertSame( '10', get_post_meta( $this->product, '_price', true ) );
		self::assertNull( $this->raw_option( Settings::OPTION ), 'The whole uninstall ran.' );
	}

	public function test_another_plugins_uninstall_does_not_open_the_window(): void {
		$this->enable_deletion();
		$before = $this->protected_rows();

		$result = $this->run_process( 'loaded-other' );

		self::assertSame(
			array(
				'guard'          => true,
				'uninstalling'   => true,
				'deleted_link'   => false,
				'deleted_source' => false,
			),
			$result
		);
		wp_cache_flush();
		self::assertSame( $before, $this->protected_rows() );
	}

	public function test_without_any_uninstall_running_the_opt_in_opens_nothing(): void {
		$this->enable_deletion();
		$before = $this->protected_rows();

		$result = $this->run_process( 'loaded-none' );

		self::assertSame(
			array(
				'guard'          => true,
				'uninstalling'   => false,
				'deleted_link'   => false,
				'deleted_source' => false,
			),
			$result,
			'WP_UNINSTALL_PLUGIN missing: the opt-in alone deletes nothing.'
		);
		wp_cache_flush();
		self::assertSame( $before, $this->protected_rows() );
	}

	public function test_uninstall_deletes_through_the_meta_api_and_the_window_closes_afterwards(): void {
		$guard = $this->service( ProtectedProductMeta::class );
		$this->enable_deletion();
		$seen = array();
		$spy  = static function ( mixed $check, mixed $object_id, mixed $meta_key, mixed $meta_value = '', mixed $delete_all = false ) use ( &$seen ): mixed {
			if ( ProtectedProductMeta::is_protected_key( $meta_key ) ) {
				$seen[] = array( $meta_key, $delete_all );
			}

			return $check;
		};
		add_filter( 'delete_post_metadata', $spy, 10, 5 );

		try {
			$this->run_uninstall();
		} finally {
			remove_filter( 'delete_post_metadata', $spy, 10 );
		}

		self::assertSame( array( array( ProtectedProductMeta::LINK_KEY, true ), array( ProtectedProductMeta::SOURCE_KEY, true ) ), $seen, 'WordPress\' meta API (delete_post_meta_by_key), which the guard sees.' );
		self::assertSame( 1000, has_filter( 'delete_post_metadata', array( $guard, 'filter_delete' ) ), 'No hook was removed.' );

		// After the uninstall the opt-in is gone, so the window is closed again
		// although WP_UNINSTALL_PLUGIN stays defined in this request.
		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $this->product, ProtectedProductMeta::LINK_KEY, '424242' );
		wp_cache_flush();
		self::assertFalse( delete_post_meta( $this->product, ProtectedProductMeta::LINK_KEY ), 'No lasting bypass.' );
		self::assertFalse( update_post_meta( $this->product, ProtectedProductMeta::LINK_KEY, '7' ) );
		self::assertFalse( add_post_meta( $this->other_product, ProtectedProductMeta::SOURCE_KEY, 'admin' ) );
		self::assertSame( '424242', get_post_meta( $this->product, ProtectedProductMeta::LINK_KEY, true ) );
	}

	public function test_only_protected_deletions_are_logged_and_never_with_values(): void {
		$this->enable_deletion();
		$this->define_uninstall_constant();
		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.

		self::assertTrue( delete_post_meta( $this->product, '_price' ), 'Other meta is deleted as usual.' );
		self::assertSame( '', (string) file_get_contents( MERCHBOARD_TEST_LOG ), 'An unrelated deletion is not logged as protected.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.

		self::assertTrue( delete_post_meta( $this->product, ProtectedProductMeta::LINK_KEY ) );
		$log = (string) file_get_contents( MERCHBOARD_TEST_LOG ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.

		self::assertSame( 1, substr_count( $log, 'Deleted protected product meta during the Merch Dashboard uninstall.' ) );
		self::assertStringContainsString( ProtectedProductMeta::LINK_KEY, $log, 'The key is logged.' );
		self::assertStringNotContainsString( '424242', $log, 'The value is not.' );
		self::assertStringNotContainsString( '_price', $log );
	}

	public function test_approved_writes_still_work_during_the_window_and_only_them(): void {
		$guard = $this->service( ProtectedProductMeta::class );
		$this->enable_deletion();
		$this->define_uninstall_constant();

		self::assertTrue( $guard->run( ProtectedMetaWrite::for_manual_link( $this->product ), fn(): int|bool => update_post_meta( $this->product, ProtectedProductMeta::LINK_KEY, '7' ) ), 'ProductCreatorLink\'s manual context.' );
		self::assertIsInt( $guard->run( ProtectedMetaWrite::for_category_reconciliation( $this->other_product ), fn(): int|false => add_post_meta( $this->other_product, ProtectedProductMeta::SOURCE_KEY, 'category' ) ), 'The category reconciliation\'s context.' );
		self::assertFalse( update_post_meta( $this->product, ProtectedProductMeta::LINK_KEY, '8' ), 'Outside a context writes stay denied.' );
		self::assertSame( '7', get_post_meta( $this->product, ProtectedProductMeta::LINK_KEY, true ) );
	}

	public function test_a_repeated_uninstall_after_the_keys_are_gone_raises_nothing(): void {
		$this->enable_deletion();
		$this->run_uninstall();
		self::assertSame( array(), $this->protected_rows() );

		update_option( Settings::OPTION, array( Settings::DELETE_DATA_ON_UNINSTALL => true ) );
		$this->run_uninstall();

		$log = (string) file_get_contents( MERCHBOARD_TEST_LOG ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.
		self::assertStringNotContainsString( 'Blocked a write to protected product meta', $log );
		self::assertStringNotContainsString( 'error', strtolower( $log ) );
		self::assertSame( array(), $this->protected_rows() );
		self::assertSame( '10', get_post_meta( $this->product, '_price', true ) );
	}

	public function test_uninstall_writes_no_audit_records(): void {
		$this->enable_deletion();
		$audit_rows = array();
		add_filter(
			'query',
			static function ( string $query ) use ( &$audit_rows ): string {
				if ( str_contains( $query, 'INSERT INTO' ) && str_contains( $query, 'merchboard_audit_log' ) ) {
					$audit_rows[] = $query;
				}
				return $query;
			}
		);

		$this->run_uninstall();

		self::assertSame( array(), $audit_rows );
	}

	public function test_repeated_uninstall_is_safe(): void {
		$this->enable_deletion();
		$this->run_uninstall();
		$after_first = $this->foreign_snapshot();

		// A second run (opt-in gone) and a third with the opt-in set again
		// against an already empty installation.
		$this->run_uninstall();
		update_option( Settings::OPTION, array( Settings::DELETE_DATA_ON_UNINSTALL => true ) );
		$this->run_uninstall();

		self::assertSame( $after_first, $this->foreign_snapshot() );
		self::assertNull( $this->raw_option( Settings::OPTION ) );
	}

	public function test_failed_table_drop_keeps_the_opt_in_so_uninstall_can_be_retried(): void {
		$this->enable_deletion();
		$this->wpdb->suppress_errors( true );
		$fail = static fn( string $query ): string => str_starts_with( $query, 'DROP TABLE IF EXISTS `mbtest_merchboard_creatormeta`' ) ? 'DROP TABLE mbtest_this_table_does_not_exist' : $query;
		add_filter( 'query', $fail );

		$this->run_uninstall();

		remove_filter( 'query', $fail );
		self::assertTrue( Settings::delete_data_on_uninstall(), 'Opt-in kept for the retry.' );
		self::assertSame( '29', $this->raw_option( Migrator::VERSION_OPTION ) );

		$this->run_uninstall();

		self::assertNull( $this->raw_option( Settings::OPTION ) );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->table( Tables::CREATOR_META ) ) ) ) );
	}

	public function test_a_failed_archive_drops_nothing_and_keeps_the_opt_in(): void {
		$this->enable_deletion();
		$this->wpdb->suppress_errors( true );
		$fail = static fn( string $query ): string => str_starts_with( $query, 'UPDATE `mbtest_merchboard_creators` SET status' ) ? 'UPDATE mbtest_this_table_does_not_exist SET x = 1' : $query;
		add_filter( 'query', $fail );

		try {
			$this->run_uninstall();
		} finally {
			remove_filter( 'query', $fail );
		}

		self::assertTrue( Settings::delete_data_on_uninstall(), 'Opt-in kept for the retry.' );
		self::assertNull( $this->raw_option( RestoredHistory::OPTION ), 'No marker without the archive.' );
		self::assertNotNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->table( Tables::SALES ) ) ) ), 'No table dropped.' );
		self::assertContains( 'active', array_column( $this->rows( Tables::CREATORS ), 'status' ) );

		$this->run_uninstall();

		self::assertNull( $this->raw_option( Settings::OPTION ), 'The retry finishes.' );
		self::assertSame( array( 'inactive' ), array_values( array_unique( array_column( $this->rows( Tables::CREATORS ), 'status' ) ) ) );
	}

	/**
	 * Pending payouts of each method, and of all three.
	 *
	 * @return array<string, array{0: list<string>}>
	 */
	public static function pending_methods(): array {
		return array(
			'bank'      => array( array( 'bank' ) ),
			'PayPal'    => array( array( 'paypal' ) ),
			'gift card' => array( array( 'gift_card' ) ),
			'mixed'     => array( array( 'bank', 'paypal', 'gift_card' ) ),
		);
	}

	/**
	 * The Plugins screen deletes in an AJAX request: it ends with WordPress'
	 * own JSON error and nothing is changed.
	 *
	 * @phpstan-param list<string> $methods
	 *
	 * @param string[] $methods Methods of the pending payouts.
	 */
	#[DataProvider( 'pending_methods' )]
	public function test_a_pending_payout_ends_the_plugins_screen_deletion_and_changes_nothing( array $methods ): void {
		$this->enable_deletion();

		foreach ( $methods as $i => $method ) {
			$this->pending_payout( $method, 'key-pending-' . $i );
		}

		$before = $this->everything();

		$output = $this->run_process_raw( 'unloaded-ajax' );

		$response = json_decode( $output, true );
		self::assertIsArray( $response, 'Output: ' . $output );
		self::assertFalse( $response['success'] ?? null );
		self::assertSame( 'merchboard_pending_payouts', $response['data']['errorCode'] ?? null );
		self::assertSame( plugin_basename( dirname( __DIR__, 3 ) . '/merchboard.php' ), $response['data']['plugin'] ?? null );
		self::assertStringContainsString( count( $methods ) . ' payout', (string) ( $response['data']['errorMessage'] ?? '' ) );
		self::assertArrayNotHasKey( 'uninstalled', $response, 'The fixture never got past uninstall.php.' );

		wp_cache_flush();
		$this->forget_table_metadata();
		self::assertSame( $before, $this->everything(), 'Data, opt-in, roles, capabilities and meta unchanged.' );
	}

	public function test_a_pending_payout_ends_a_deletion_without_javascript_with_an_error_page(): void {
		$this->enable_deletion();
		$this->pending_payout( 'bank', 'key-pending-page' );
		$before = $this->everything();

		$output = $this->run_process_raw( 'unloaded' );

		self::assertStringContainsString( 'Merch Dashboard was not deleted: 1 payout is still pending.', $output );
		self::assertStringNotContainsString( '"uninstalled":true', $output );
		wp_cache_flush();
		$this->forget_table_metadata();
		self::assertSame( $before, $this->everything() );
	}

	public function test_a_failed_count_blocks_before_anything_changes(): void {
		$this->enable_deletion();
		$before = $this->everything();
		$this->wpdb->suppress_errors( true );
		$fail = static fn( string $query ): string => str_starts_with( $query, 'SELECT COUNT(*) FROM `mbtest_merchboard_payouts`' ) ? 'SELECT COUNT(*) FROM mbtest_this_table_does_not_exist' : $query;
		add_filter( 'query', $fail );

		try {
			( new Uninstaller( $this->wpdb, new SchemaEraser( $this->wpdb, new RecordingLogger() ), new RecordingLogger() ) )->run();
			self::fail( 'A failed count must block (fail closed).' );
		} catch ( UninstallBlocked $e ) {
			self::assertNull( $e->pending );
		} finally {
			remove_filter( 'query', $fail );
			$this->wpdb->suppress_errors( false );
		}

		wp_cache_flush();
		self::assertSame( $before, $this->everything() );
	}

	public function test_without_a_payouts_table_the_uninstall_proceeds(): void {
		$this->enable_deletion();
		$this->execute( 'DROP TABLE %i', $this->table( Tables::PAYOUTS ) );
		$this->forget_table_metadata();

		$this->run_uninstall();

		self::assertNull( $this->raw_option( Settings::OPTION ), 'Nothing pending: the uninstall ran.' );
	}

	public function test_once_the_pending_payout_is_handled_the_uninstall_completes(): void {
		$this->enable_deletion();
		$payout = $this->pending_payout( 'gift_card', 'key-pending-later' );

		$this->run_process_raw( 'unloaded-ajax' );
		wp_cache_flush();
		self::assertTrue( Settings::delete_data_on_uninstall(), 'Blocked: the opt-in stays.' );

		$this->wpdb->update( $this->table( Tables::PAYOUTS ), array( 'status' => 'cancelled' ), array( 'id' => $payout ) );
		$this->run_uninstall();

		self::assertNull( $this->raw_option( Settings::OPTION ) );
		self::assertNotNull( $this->raw_option( RestoredHistory::OPTION ) );
		self::assertSame( 'cancelled', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $this->table( Tables::PAYOUTS ), $payout ) ), 'The payout history is kept.' );
	}

	/**
	 * A pending payout row as PayoutRequests stores it (bank and PayPal with
	 * their encrypted details).
	 *
	 * @param string $method Method value.
	 * @param string $key    Request key.
	 * @return int Payout id.
	 */
	private function pending_payout( string $method, string $key ): int {
		$creator = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MIN(id) FROM %i', $this->table( Tables::CREATORS ) ) );

		self::assertSame(
			1,
			$this->wpdb->insert(
				$this->table( Tables::PAYOUTS ),
				array(
					'creator_id'       => $creator,
					'currency'         => 'DKK',
					'amount'           => '10.0000',
					'price_decimals'   => 2,
					'method'           => $method,
					'status'           => 'pending',
					'destination_hint' => 'gift_card' === $method ? '' : '•••• 1234',
					'idempotency_key'  => $key,
					'requested_at'     => '2026-10-12 10:00:00',
					'updated_at'       => '2026-10-12 10:00:00',
				)
			)
		);
		$id = (int) $this->wpdb->insert_id;

		if ( 'gift_card' !== $method ) {
			self::assertSame(
				1,
				$this->wpdb->insert(
					$this->table( Tables::PAYOUT_SECRETS ),
					array(
						'payout_id'  => $id,
						'kind'       => $method,
						'ciphertext' => 'opaque',
						'key_id'     => 'k1',
						'created_at' => '2026-10-12 10:00:00',
					)
				)
			);
		}

		return $id;
	}

	/**
	 * Everything a blocked uninstall must leave alone: every plugin table
	 * (rows in a stable order), the plugin's options, roles, capabilities and
	 * user, product and order meta.
	 *
	 * @return array<string, mixed>
	 */
	private function everything(): array {
		$tables = array();

		foreach ( array_merge( Tables::all(), Tables::retired() ) as $table ) {
			$name = $this->table( $table );

			if ( null === $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $name ) ) ) ) {
				$tables[ $table ] = null;
				continue;
			}

			$rows = array_map( 'wp_json_encode', (array) $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i', $name ), ARRAY_A ) );
			sort( $rows );
			$tables[ $table ] = $rows;
		}

		return array(
			'tables'    => $tables,
			'marker'    => $this->raw_option( RestoredHistory::OPTION ),
			'protected' => $this->protected_rows(),
		) + $this->snapshot();
	}

	/**
	 * Creators, audit, users with several roles, unrelated data.
	 */
	private function seed(): void {
		$creators = $this->service( CreatorService::class );

		$this->creator_user = $this->create_user( 'uninstall_creator' );
		$creators->create( 'Uninstall Creator', CreatorStatus::Active, $this->creator_user );
		$creators->create( 'Unlinked', CreatorStatus::Inactive, null );

		add_role( 'merchboard_test_custom', 'Custom', array( 'read' => true, 'merchboard_test_foreign_cap' => true, Capabilities::MANAGE => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->multi_role_user = $this->create_user( 'uninstall_multi' );
		$multi                 = $this->user( $this->multi_role_user );
		$multi->add_role( 'merchboard_test_custom' );
		$creators->create( 'Multi', CreatorStatus::Active, $this->multi_role_user );
		$multi = $this->user( $this->multi_role_user );
		$multi->add_cap( Capabilities::MANAGE_SETTINGS );
		$multi->add_cap( 'edit_posts' );

		$this->plain_user = $this->create_user( 'uninstall_plain' );

		// The dashboard theme (ADR-044) and a foreign key with a similar name.
		update_user_meta( $this->creator_user, 'merchboard_dashboard_theme', 'dark-red' );
		update_user_meta( $this->multi_role_user, 'merchboard_dashboard_theme', 'light' );
		update_user_meta( $this->plain_user, 'merchboard_dashboard_theme_backup', 'kept' );
		// "Anonymize usernames" in the alert history (phase 25, step 7).
		update_user_meta( $this->creator_user, 'merchboard_alerts_anonymize', '1' );
		update_user_meta(
			$this->creator_user,
			YouTubeTerms::META,
			array(
				'version' => 1,
				'at'      => 1,
			)
		);

		update_option( 'merchboard_test_unrelated', 'kept' );
		$this->post_id = (int) wp_insert_post(
			array(
				'post_title'  => 'Unrelated',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		update_post_meta( $this->post_id, '_merchboard_test_meta', 'meta' );
		$this->execute( 'CREATE TABLE %i (id int)', self::OTHER_SITE_TABLE );

		// Products with protected meta, stored directly: the guard would refuse
		// any generic write (ADR-034). No WooCommerce is loaded here.
		$this->product       = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Linked tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->other_product = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Oddly spelled' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->variation     = (int) wp_insert_post( array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $this->product, 'post_title' => 'Linked tee - S' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_post_meta( $this->product, '_price', '10' );

		foreach (
			array(
				array( $this->product, ProtectedProductMeta::LINK_KEY, '424242' ),
				array( $this->product, ProtectedProductMeta::SOURCE_KEY, 'admin' ),
				array( $this->other_product, '_Merchboard_Creator_ID', '5' ),
				array( $this->variation, ProtectedProductMeta::LINK_KEY, '424242' ),
			) as $row
		) {
			$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, ...$row );
		}

		wp_cache_flush();
	}

	/**
	 * Protected meta rows of the test products, straight from the database.
	 *
	 * @return list<array<string, string>>
	 */
	private function protected_rows(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, meta_key, meta_value FROM %i WHERE post_id IN (%d, %d, %d) AND LOWER(TRIM(meta_key)) IN (%s, %s) ORDER BY meta_id', $wpdb->postmeta, $this->product, $this->other_product, $this->variation, ProtectedProductMeta::LINK_KEY, ProtectedProductMeta::SOURCE_KEY ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion on stored rows.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Meta id of a post's row.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key (exact).
	 */
	private function meta_id( int $post_id, string $key ): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $post_id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture lookup.
	}

	/**
	 * Defines WordPress' uninstall constant for this plugin, as
	 * uninstall_plugin() does. It stays defined for the rest of this process;
	 * tests that need the window closed turn the opt-in off.
	 */
	private function define_uninstall_constant(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( dirname( __DIR__, 3 ) . '/merchboard.php' ) );
		}
	}

	/**
	 * Runs tests/Integration/Fixtures/uninstall-process.php in its own PHP
	 * process (own WordPress, own constants) against the same database.
	 *
	 * @param string $mode 'unloaded', 'loaded-other' or 'loaded-none'.
	 * @return array<string, mixed>
	 */
	private function run_process( string $mode ): array {
		$output = $this->run_process_raw( $mode );
		$result = json_decode( $output, true );
		self::assertIsArray( $result, 'Process output: ' . $output );

		return $result;
	}

	/**
	 * The same, returning what the process printed (standard output, then
	 * standard error).
	 *
	 * @param string $mode Fixture mode.
	 */
	private function run_process_raw( string $mode ): string {
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/uninstall-process.php', $mode ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process, 'Uninstall process could not be started.' );
		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		proc_close( $process );

		return $output . $errors;
	}

	private function enable_deletion(): void {
		self::assertTrue( $this->service( SettingsService::class )->set_delete_data_on_uninstall( true, 'DELETE' ) );
	}

	/**
	 * Runs the real uninstall.php as WordPress' uninstall_plugin() does.
	 */
	private function run_uninstall(): void {
		$this->define_uninstall_constant();

		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.
		include dirname( __DIR__, 3 ) . '/uninstall.php';
		wp_cache_flush();
		$this->forget_table_metadata();
	}

	/**
	 * Everything uninstall may touch.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		return array(
			'creators' => $this->rows( Tables::CREATORS ),
			'audit'    => $this->rows( Tables::AUDIT_LOG ),
			'options'  => array_map( array( $this, 'raw_option' ), array( Migrator::VERSION_OPTION, RoleManager::VERSION_OPTION, Settings::OPTION ) ),
			'role'     => get_role( Capabilities::CREATOR_ROLE )?->capabilities,
			'roles'    => $this->plugin_caps_on_roles(),
			'users'    => $this->plugin_caps_on_users(),
			'themes'   => array(
				get_user_meta( $this->creator_user, 'merchboard_dashboard_theme', true ),
				get_user_meta( $this->multi_role_user, 'merchboard_dashboard_theme', true ),
			),
		) + $this->foreign_snapshot();
	}

	/**
	 * Data uninstall must never touch.
	 *
	 * @return array<string, mixed>
	 */
	private function foreign_snapshot(): array {
		$admin = get_role( 'administrator' );

		return array(
			'user_ids'      => array_map( 'intval', get_users( array( 'fields' => 'ID' ) ) ),
			'admin_caps'    => array_filter( $admin->capabilities ?? array(), static fn( string $cap ): bool => ! self::is_owned( $cap ), ARRAY_FILTER_USE_KEY ),
			'custom_caps'   => get_role( 'merchboard_test_custom' )?->capabilities,
			'multi_roles'   => array_values( array_diff( $this->user( $this->multi_role_user )->roles, array( Capabilities::CREATOR_ROLE ) ) ),
			'post'          => get_post_status( $this->post_id ),
			'unrelated'     => get_option( 'merchboard_test_unrelated' ),
			'user_meta'     => get_user_meta( $this->plain_user, 'merchboard_dashboard_theme_backup', true ),
			'foreign_table' => $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( self::OTHER_SITE_TABLE ) ) ),
		);
	}

	/**
	 * Plugin capabilities per role.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function plugin_caps_on_roles(): array {
		$found = array();

		foreach ( wp_roles()->roles as $name => $role ) {
			$caps = array_values( array_filter( array_map( 'strval', array_keys( $role['capabilities'] ) ), array( self::class, 'is_owned' ) ) );

			if ( array() !== $caps ) {
				$found[ (string) $name ] = $caps;
			}
		}

		return $found;
	}

	/**
	 * Plugin roles/capabilities stored on users.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function plugin_caps_on_users(): array {
		$found = array();

		foreach ( array( $this->creator_user, $this->multi_role_user, $this->plain_user, 1 ) as $user_id ) {
			$caps = array_values( array_filter( array_map( 'strval', array_keys( $this->user( $user_id )->caps ) ), array( self::class, 'is_owned' ) ) );

			if ( array() !== $caps ) {
				$found[ $user_id ] = $caps;
			}
		}

		return $found;
	}

	/**
	 * Whether a role/capability name is owned by the plugin (exact names).
	 *
	 * @param string $name Role or capability.
	 */
	public static function is_owned( string $name ): bool {
		return Capabilities::CREATOR_ROLE === $name || in_array( $name, Capabilities::all(), true );
	}

	/**
	 * Option value straight from the database.
	 *
	 * @param string $name Option name.
	 */
	private function raw_option( string $name ): ?string {
		$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->wpdb->options, $name ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * A fresh user object (roles/caps reloaded from the database).
	 *
	 * @param int $user_id User id.
	 */
	private function user( int $user_id ): WP_User {
		clean_user_cache( $user_id );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		return $user;
	}
}
