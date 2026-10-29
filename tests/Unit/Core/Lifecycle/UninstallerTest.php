<?php
/**
 * Tests for the opt-in uninstall and the table eraser.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Lifecycle;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\SchemaEraser;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\UninstallBlocked;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Modules\Creators\Proposals\ProposalFiles;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Chatbot\ChatSender;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayImages;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlaySounds;
use MerchBoard\Modules\Alerts\Images\AlertImageLibrary;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Legal\YouTubeTerms;
use MerchBoard\Modules\Alerts\Rest\MeAlertsHistoryController;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundLibrary;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDispatcher;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\ThemePreference;
use MerchBoard\Modules\Notifications\NotificationPreferences;
use MerchBoard\Modules\Notifications\NotificationSender;
use MerchBoard\Modules\WooCommerce\Payouts\BalanceReadyNotices;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Uninstaller::class )]
#[CoversClass( SchemaEraser::class )]
final class UninstallerTest extends TestCase {

	private RecordingWpdb $wpdb;

	private RecordingLogger $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb   = new RecordingWpdb();
		$this->logger = new RecordingLogger();
		// No folder of proposed images (ADR-077) to remove.
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => sys_get_temp_dir() . '/merchboard-no-uploads' ) );
	}

	public function test_without_opt_in_nothing_is_touched(): void {
		$options = InMemoryOptions::install(
			array(
				'merchboard_db_version'    => 2,
				'merchboard_roles_version' => 1,
			)
		);
		Functions\expect( 'get_users' )->never();
		Functions\expect( 'wp_roles' )->never();
		Functions\expect( 'remove_role' )->never();
		Functions\expect( 'delete_post_meta_by_key' )->never();
		Functions\expect( 'delete_metadata' )->never();

		self::assertFalse( $this->uninstaller()->run() );

		self::assertSame( array(), $this->wpdb->prepared, 'No table is dropped.' );
		self::assertSame( 2, $options->options['merchboard_db_version'] );
		self::assertSame( 1, $options->options['merchboard_roles_version'] );
	}

	public function test_forged_opt_in_values_delete_nothing(): void {
		InMemoryOptions::install( array( Settings::OPTION => array( Settings::DELETE_DATA_ON_UNINSTALL => '1' ) ) );
		Functions\expect( 'get_users' )->never();

		self::assertFalse( $this->uninstaller()->run() );
		self::assertSame( array(), $this->wpdb->prepared );
	}

	public function test_eraser_drops_exactly_the_plugin_tables_of_the_current_prefix_that_are_not_kept(): void {
		$this->wpdb->prefix = 'wp_3_';

		self::assertTrue( ( new SchemaEraser( $this->wpdb, $this->logger ) )->erase() );

		self::assertSame(
			array(
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_creatormeta' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_creator_categories' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_sales' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_sales_cycles' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_pricing_overrides' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_sales_line_pricing' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_sales_tax' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_payout_secrets' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_alert_events' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_alert_overlays' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_alert_chatbot' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_alert_chat_messages' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_alert_chat_log' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_news' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_news_reads' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_alert_api_keys' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_alert_webhooks' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_alert_webhook_deliveries' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_category_proposals' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_notifications' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_designer_bases' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_designer_products' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_designer_versions' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_designer_assets' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_designer_provider_tasks' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_designer_campaigns' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_item_pricing' ),
				),
			),
			$this->wpdb->prepared,
			'Kept (ADR-059): creators, audit log, payouts, settlement documents, rows and counters. The encrypted details are dropped.'
		);
		self::assertSame(
			array( 'merchboard_creators', 'merchboard_audit_log', 'merchboard_payouts', 'merchboard_settlement_documents', 'merchboard_settlement_document_rows', 'merchboard_settlement_counters', 'merchboard_order_gift_card_assessments', 'merchboard_order_gift_card_payouts', 'merchboard_order_gift_card_attempts', 'merchboard_g1_state', 'merchboard_operations', 'merchboard_payout_recalculations', 'merchboard_payout_recalculation_items', 'merchboard_payout_adjustments', 'merchboard_payout_adjustment_rows', 'merchboard_payout_recalculation_exports', 'merchboard_creator_vat_history', 'merchboard_creator_codes', 'merchboard_order_exclusions' ),
			Tables::retained_on_uninstall()
		);
	}

	public function test_eraser_stops_and_logs_when_a_table_cannot_be_dropped(): void {
		$this->wpdb->query_results['PREPARED#0'] = false;

		self::assertFalse( ( new SchemaEraser( $this->wpdb, $this->logger ) )->erase() );

		self::assertCount( 1, $this->wpdb->prepared, 'The remaining tables are left alone.' );
		self::assertSame( array( 'error' ), array_column( $this->logger->entries, 'level' ) );
		self::assertSame( array( 'table' => 'wp_merchboard_creatormeta' ), $this->logger->entries[0]['context'] );
	}

	public function test_a_pending_payout_blocks_before_anything_is_changed(): void {
		$options = InMemoryOptions::install(
			array(
				Settings::OPTION        => array( Settings::DELETE_DATA_ON_UNINSTALL => true ),
				'merchboard_db_version' => 11,
			)
		);

		$this->wpdb->tables_exist = true;
		$this->wpdb->var          = '2';
		Functions\expect( 'as_unschedule_all_actions' )->never();
		Functions\expect( 'get_users' )->never();
		Functions\expect( 'wp_roles' )->never();
		Functions\expect( 'remove_role' )->never();
		Functions\expect( 'delete_post_meta_by_key' )->never();
		Functions\expect( 'delete_metadata' )->never();

		try {
			$this->uninstaller()->run();
			self::fail( 'A pending payout must block the uninstall.' );
		} catch ( UninstallBlocked $e ) {
			self::assertSame( 2, $e->pending );
		}

		self::assertSame(
			array(
				array(
					'query' => 'SHOW TABLES LIKE %s',
					'args'  => array( 'wp\_merchboard\_payouts' ),
				),
				array(
					'query' => 'SELECT COUNT(*) FROM %i WHERE status = %s',
					'args'  => array( 'wp_merchboard_payouts', 'pending' ),
				),
			),
			$this->wpdb->prepared,
			'Only the count: no update, no drop.'
		);
		self::assertSame( array(), $this->wpdb->raw_queries );
		self::assertSame( array( Settings::DELETE_DATA_ON_UNINSTALL => true ), $options->options[ Settings::OPTION ], 'The opt-in stays.' );
		self::assertSame( 11, $options->options['merchboard_db_version'] );
		self::assertSame( array( 'warning' ), array_column( $this->logger->entries, 'level' ) );
	}

	public function test_a_failed_count_blocks_as_well(): void {
		InMemoryOptions::install( array( Settings::OPTION => array( Settings::DELETE_DATA_ON_UNINSTALL => true ) ) );
		$this->wpdb->last_error = 'Lost connection';
		Functions\expect( 'get_users' )->never();
		Functions\expect( 'as_unschedule_all_actions' )->never();

		try {
			$this->uninstaller()->run();
			self::fail( 'A failed count must block the uninstall (fail closed).' );
		} catch ( UninstallBlocked $e ) {
			self::assertNull( $e->pending );
		}

		self::assertSame( array(), $this->wpdb->raw_queries );
	}

	public function test_without_the_opt_in_pending_payouts_are_not_even_counted(): void {
		InMemoryOptions::install();
		$this->wpdb->tables_exist = true;
		$this->wpdb->var          = '2';

		self::assertFalse( $this->uninstaller()->run() );
		self::assertSame( array(), $this->wpdb->prepared );
	}

	public function test_pending_payouts_are_zero_without_a_payouts_table(): void {
		self::assertSame( 0, Uninstaller::pending_payouts( $this->wpdb ) );
		self::assertCount( 1, $this->wpdb->prepared, 'No count without the table.' );
	}

	public function test_core_constants_match_the_modules(): void {
		self::assertSame( PayoutStatus::Pending->value, Uninstaller::PENDING_PAYOUT_STATUS );
		self::assertSame( CreatorStatus::Inactive->value, Uninstaller::ARCHIVED_CREATOR_STATUS );
	}

	public function test_failed_table_drop_keeps_the_options_and_the_opt_in_for_a_retry(): void {
		$options = InMemoryOptions::install(
			array(
				Settings::OPTION        => array( Settings::DELETE_DATA_ON_UNINSTALL => true ),
				'merchboard_db_version' => 2,
			)
		);
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'role_objects' => array() ) );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'delete_post_meta_by_key' )->justReturn( true );
		Functions\when( 'delete_metadata' )->justReturn( true );
		// #0 counts pending payouts, #1 looks for the creators table (both absent here), #2 is the first drop.
		$this->wpdb->query_results['PREPARED#2'] = false;

		self::assertFalse( $this->uninstaller()->run() );
		self::assertSame( 'DROP TABLE IF EXISTS %i', $this->wpdb->prepared[2]['query'] );

		self::assertArrayHasKey( Settings::OPTION, $options->options );
		self::assertArrayHasKey( 'merchboard_db_version', $options->options );
	}

	public function test_with_opt_in_the_protected_product_meta_is_deleted_by_key_before_tables_and_settings(): void {
		$options = InMemoryOptions::install( array( Settings::OPTION => array( Settings::DELETE_DATA_ON_UNINSTALL => true ) ) );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'role_objects' => array() ) );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'delete_metadata' )->justReturn( true );
		$deleted = array();
		Functions\when( 'delete_post_meta_by_key' )->alias(
			function ( string $key ) use ( &$deleted, $options ): bool {
				$deleted[] = array(
					'key'          => $key,
					'opt_in_still' => Settings::delete_data_on_uninstall(),
					'tables_left'  => ! in_array( 'DROP TABLE IF EXISTS %i', array_column( $this->wpdb->prepared, 'query' ), true ),
					'settings'     => array_key_exists( Settings::OPTION, $options->options ),
				);
				return false;
			}
		);

		self::assertTrue( $this->uninstaller()->run(), 'Nothing to delete is not a failure.' );

		// The alert files' meta is covered by its own test; the approved category images keep their files.
		$deleted = array_values( array_filter( $deleted, static fn( array $call ): bool => ! in_array( $call['key'], array_merge( Uninstaller::ALERT_MEDIA_META_KEYS, array( Uninstaller::ALERT_IMPORTED_META, ProposalFiles::MEDIA_META ) ), true ) ) );

		self::assertSame(
			array(
				array(
					'key'          => ProtectedProductMeta::LINK_KEY,
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
				array(
					'key'          => ProtectedProductMeta::SOURCE_KEY,
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
				array(
					'key'          => ProtectedProductMeta::DESIGNER_KEY,
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
				array(
					'key'          => Uninstaller::DESIGNER_PENDING_META,
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
				array(
					'key'          => '_merchboard_ledger_failure',
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
				array(
					'key'          => '_merchboard_alert_username',
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
				array(
					'key'          => '_wc_other/merchboard/alert-username',
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
				array(
					'key'          => '_wc_other/merchboard/alert-username-show',
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
			),
			$deleted,
			'Both product keys, the order failure marker and the alert username (legacy post meta), through the meta API, while the opt-in is on and before the tables are dropped.'
		);
		self::assertArrayNotHasKey( Settings::OPTION, $options->options, 'The opt-in is removed last.' );
	}

	public function test_with_opt_in_the_theme_user_meta_is_deleted_for_every_user_by_exact_key(): void {
		$options = InMemoryOptions::install( array( Settings::OPTION => array( Settings::DELETE_DATA_ON_UNINSTALL => true ) ) );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'role_objects' => array() ) );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'delete_post_meta_by_key' )->justReturn( true );
		$calls = array();
		Functions\when( 'delete_metadata' )->alias(
			static function ( string $type, int $object_id, string $key, mixed $value, bool $all ) use ( &$calls, $options ): bool {
				$calls[] = array( $type, $object_id, $key, $value, $all, array_key_exists( Settings::OPTION, $options->options ) );
				return false;
			}
		);

		self::assertTrue( $this->uninstaller()->run(), 'Nothing to delete is not a failure.' );

		self::assertSame( array( ThemePreference::META_KEY, MeAlertsHistoryController::ANONYMIZE_META, YouTubeTerms::META, ImpersonationSession::META, NotificationPreferences::META ), Uninstaller::USER_META_KEYS );
		self::assertSame(
			array(
				array( 'user', 0, 'merchboard_dashboard_theme', '', true, true ),
				array( 'user', 0, 'merchboard_alerts_anonymize', '', true, true ),
				array( 'user', 0, 'merchboard_youtube_terms_accepted', '', true, true ),
				array( 'user', 0, 'merchboard_impersonating', '', true, true ),
				array( 'user', 0, 'merchboard_notification_email_off', '', true, true ),
			),
			$calls,
			'Exact key, every user, through the meta API, while the opt-in still exists.'
		);
	}

	public function test_with_opt_in_the_alert_files_are_deleted_but_merchalerts_stay(): void {
		InMemoryOptions::install( array( Settings::OPTION => array( Settings::DELETE_DATA_ON_UNINSTALL => true ) ) );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'role_objects' => array() ) );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_metadata' )->justReturn( true );
		// 10 was uploaded here; 11 and 12 are MerchAlert's files the import took over.
		Functions\when( 'get_posts' )->justReturn( array( 10, 11, 12 ) );
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key ): string => match ( true ) {
				11 === $id && Uninstaller::ALERT_IMPORTED_META === $key => '1',
				12 === $id && '_ccmerch_audio_creator_id' === $key => '5',
				default => '',
			}
		);
		$keys = array();
		Functions\when( 'delete_post_meta_by_key' )->alias(
			static function ( string $key ) use ( &$keys ): bool {
				$keys[] = $key;
				return true;
			}
		);
		Functions\expect( 'wp_delete_attachment' )->once()->with( 10, true );
		$hooks = array();
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook ) use ( &$hooks ): void {
				$hooks[] = $hook;
			}
		);

		self::assertTrue( $this->uninstaller()->run() );

		self::assertSame( array( AlertSounds::OWNER_META, AlertSoundLibrary::LIBRARY_META, GlobalOverlaySounds::OWNER_META, AlertImages::OWNER_META, AlertImageLibrary::LIBRARY_META, GlobalOverlayImages::OWNER_META ), Uninstaller::ALERT_MEDIA_META_KEYS );
		self::assertSame( array(), array_diff( array_merge( Uninstaller::ALERT_MEDIA_META_KEYS, array( Uninstaller::ALERT_IMPORTED_META ) ), $keys ), 'The markers are removed from the files that stay.' );
		self::assertSame( array( 'merchboard_sales_backfill', ChatSender::HOOK, WebhookDispatcher::HOOK, NotificationSender::HOOK, BalanceReadyNotices::HOOK ), $hooks, 'Queued chat messages, webhook deliveries and notification e-mails are cancelled.' );
	}

	private function uninstaller(): Uninstaller {
		return new Uninstaller( $this->wpdb, new SchemaEraser( $this->wpdb, $this->logger ), $this->logger );
	}
}
