<?php
/**
 * Integration tests for the import from MerchAlert.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Admin\MerchAlertImportActions;
use MerchBoard\Modules\Alerts\Admin\MerchAlertImportTab;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertSettings;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessageRepository;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlaySounds;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayStore;
use MerchBoard\Modules\Alerts\Import\MerchAlertImporter;
use MerchBoard\Modules\Alerts\Import\MerchAlertImportException;
use MerchBoard\Modules\Alerts\Import\MerchAlertImportPlan;
use MerchBoard\Modules\Alerts\Import\MerchAlertImportReport;
use MerchBoard\Modules\Alerts\Import\MerchAlertSource;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\PaidOrder;
use MerchBoard\Modules\Alerts\PaidOrderLine;
use MerchBoard\Modules\Alerts\PaidOrderReader;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Step 25.10.1: MerchAlert's data (made here as fixtures in its own tables
 * and options) is imported once after a dry run: overlays keep their ids,
 * sounds become the creator's, alerts keep their time without first names,
 * the chatbot's template, switch and sent messages move, the global overlay
 * comes switched off, and the settings and fonts move once. MerchAlert's
 * data is never changed, and a second run only adds what is missing.
 */
#[CoversClass( MerchAlertImporter::class )]
#[CoversClass( MerchAlertImportPlan::class )]
#[CoversClass( MerchAlertSource::class )]
#[CoversClass( MerchAlertImportReport::class )]
#[CoversClass( MerchAlertImportActions::class )]
final class MerchAlertImportTest extends IntegrationTestCase {

	private const NOVA_ID   = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const ORION_ID  = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const TEST_ID   = 'cccccccccccccccccccccccccccccccc';
	private const GLOBAL_ID = 'dddddddddddddddddddddddddddddddd';

	private const TABLES = array( 'creator_profiles', 'creator_category_assignments', 'creator_overlay_settings', 'alert_events', 'creator_chatbot', 'chatbot_messages', 'google_fonts' );

	private AlertOverlayRepository $overlays;
	private int $nova;
	private int $orion;
	private int $lyra;
	private bool $merchalert_active = false;

	/**
	 * Sound attachments: Nova's own, another MerchAlert creator's and an administrator's upload.
	 *
	 * @var array{own: int, foreign: int, admin: int}
	 */
	private array $sounds;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_set_current_user( 1 );

		$this->overlays = new AlertOverlayRepository( $this->wpdb );
		$creators       = new CreatorRepository( $this->wpdb );
		$categories     = new CreatorCategoryRepository( $this->wpdb );
		$this->nova     = $creators->create( 'Nova', CreatorStatus::Active )->id;
		$this->orion    = $creators->create( 'Orion', CreatorStatus::Active )->id;
		$this->lyra     = $creators->create( 'Lyra', CreatorStatus::Active )->id;
		$categories->insert( 501, $this->nova, 1 );
		$categories->insert( 502, $this->orion, 1 );
		$categories->insert( 503, $this->lyra, 1 );

		// Orion tried the overlay already: MerchAlert's replaces it.
		$this->overlays->create( $this->orion, self::TEST_ID, OverlaySettings::defaults() );

		$this->sounds = array(
			'own'     => $this->sound( 1 ),
			'foreign' => $this->sound( 2 ),
			'admin'   => $this->sound( 0 ),
		);
		$this->merchalert();
	}

	protected function tearDown(): void {
		foreach ( self::TABLES as $table ) {
			$this->execute( 'DROP TABLE IF EXISTS %i', $this->wpdb->prefix . 'ccmerch_' . $table );
		}

		foreach ( array( MerchAlertSource::SETTINGS_OPTION, MerchAlertSource::OVERLAY_OPTION, MerchAlertSource::LAYOUT_OPTION, MerchAlertImportPlan::MARKER_OPTION, GlobalOverlayStore::OPTION, AlertFonts::KEY_OPTION, AlertFonts::APPROVED_OPTION ) as $option ) {
			delete_option( $option );
		}

		foreach ( $this->sounds as $sound ) {
			wp_delete_attachment( $sound, true );
		}

		parent::tearDown();
	}

	public function test_the_dry_run_shows_what_happens_and_writes_nothing(): void {
		$before = $this->snapshot();
		$report = $this->importer()->preview();

		self::assertSame(
			array(
				array( 'Nova MA', MerchAlertImportReport::NEW, $this->nova ),
				array( 'Orion MA', MerchAlertImportReport::REPLACE, $this->orion ),
				array( 'Lyra MA', MerchAlertImportReport::NEW, $this->lyra ),
				array( 'Lyra again', MerchAlertImportReport::DUPLICATE, $this->lyra ),
				array( 'Unknown', MerchAlertImportReport::NO_MATCH, 0 ),
				array( 'Uncategorised', MerchAlertImportReport::NO_CATEGORY, 0 ),
			),
			array_map( static fn( array $row ): array => array( $row['name'], $row['status'], $row['creator_id'] ), $report->creators )
		);
		self::assertSame( array( 2, 1, 1, true ), array( $report->creators[0]['events'], $report->creators[0]['messages'], $report->creators[0]['sounds'], $report->creators[0]['chatbot'] ), 'Only Nova\'s own sound stays.' );
		self::assertSame( MerchAlertImportReport::NEW, $report->global_overlay['status'] );
		self::assertSame( array( 2097152, 20, true, '', 1, true, false ), array_values( $report->settings ), 'MerchAlert\'s default label is not imported.' );
		self::assertSame( $report->fingerprint(), $this->importer()->preview()->fingerprint(), 'Stable.' );
		self::assertSame( $before, $this->snapshot(), 'Nothing written.' );

		$this->merchalert_active = true;
		$this->refused( fn() => $this->importer()->preview(), MerchAlertImportException::MERCHALERT_ACTIVE );
		$this->merchalert_active = false;
		$this->refused( fn() => $this->importer()->run( str_repeat( '0', 64 ) ), MerchAlertImportException::PREVIEW_OUTDATED );
		self::assertSame( $before, $this->snapshot(), 'A stale confirmation writes nothing.' );
	}

	public function test_the_import_moves_overlays_sounds_alerts_chatbot_global_overlay_and_settings_once(): void {
		$merchalert = $this->merchalert_snapshot();
		$importer   = $this->importer();
		$added      = $importer->run( $importer->preview()->fingerprint() );

		self::assertSame(
			array(
				'creators' => 3,
				'events'   => 2,
				'messages' => 1,
			),
			$added
		);

		// Nova: MerchAlert's id, so the old OBS URL works, settings and her own sound.
		$overlay = $this->overlays->find_for_creator( $this->nova );
		self::assertNotNull( $overlay );
		self::assertSame( self::NOVA_ID, $overlay->overlay_id );
		$values = $overlay->settings->to_array();
		self::assertSame( array( '$Product by $FirstName!', '#123456', true, $this->sounds['own'], array() ), array( $values['template'], $values['bg_color'], $values['audio_enabled'], $values['audio_default_sound'], $values['audio_category_map'] ) );
		self::assertSame( (string) $this->nova, get_post_meta( $this->sounds['own'], AlertSounds::OWNER_META, true ) );
		self::assertSame( (string) $this->orion, get_post_meta( $this->sounds['foreign'], AlertSounds::OWNER_META, true ), 'Another MerchAlert creator\'s sound goes to that creator only (Orion\'s category sound), never to Nova.' );
		self::assertSame( array( 0, array( 501 => $this->sounds['foreign'] ) ), array( $this->overlays->find_for_creator( $this->orion )?->settings->to_array()['audio_default_sound'], $this->overlays->find_for_creator( $this->orion )?->settings->to_array()['audio_category_map'] ) );

		// Orion: the test overlay is replaced.
		self::assertSame( self::ORION_ID, $this->overlays->find_for_creator( $this->orion )?->overlay_id );
		self::assertNull( $this->overlays->find_by_overlay_id( self::TEST_ID ) );

		// Alerts with their time, the username from the order, never a first name.
		$events = $this->rows( Tables::ALERT_EVENTS );
		self::assertSame(
			array(
				array( 9001, 'StreamFan', 'Size: L', '2026-01-02 10:00:00' ),
				array( 9011, '', '', '2026-01-03 11:00:00' ),
			),
			array_map( static fn( array $row ): array => array( (int) $row['order_item_id'], $row['customer_username'], $row['variation'], $row['created_at'] ), $events )
		);
		self::assertStringNotContainsString( 'Jens', (string) wp_json_encode( $events ) );

		// Imported alerts are marked: the live feeds (overlay, API, global overlay) never show them as new (25.13 review).
		self::assertSame( array( '1', '1' ), array_map( 'strval', array_column( $events, 'imported' ) ) );
		$repository = new AlertEventRepository( $this->wpdb );
		self::assertSame( array(), $repository->after( (int) $events[0]['creator_id'], 0, 50 ) );
		self::assertSame( array(), $repository->after_all( 0, 50 ) );
		self::assertSame( (int) $events[1]['id'], max( $repository->latest_id_all(), (int) $events[0]['id'] ), 'The history and the starting cursor still see them.' );

		// The chatbot: template without first names, the switch and the sent message.
		$chatbot = array_values( array_filter( $this->rows( Tables::ALERT_CHATBOT ), fn( array $row ): bool => (int) $row['creator_id'] === $this->nova && 'twitch' === $row['platform'] ) )[0] ?? array();
		self::assertSame( array( '🔔 $UserName just bought $Product!', '1' ), array( $chatbot['template'] ?? null, (string) ( $chatbot['enabled'] ?? '' ) ) );
		$message = $this->rows( Tables::ALERT_CHAT_MESSAGES )[0] ?? array();
		self::assertSame( array( 'twitch', '9001', 'sent', '2026-01-02 10:05:00' ), array( $message['platform'] ?? null, (string) ( $message['order_item_id'] ?? '' ), $message['status'] ?? null, $message['created_at'] ?? null ) );

		// The global overlay: its id, the older layout, the administrator's sound, switched off.
		$global = ( new GlobalOverlayStore() )->find();
		self::assertNotNull( $global );
		self::assertSame( array( self::GLOBAL_ID, false, 'Global $Product!', 300, $this->sounds['admin'] ), array( $global->overlay_id, $global->enabled, $global->settings->template(), $global->settings->to_array()['layout']['elements']['main_text']['x'], $global->settings->to_array()['audio_default_sound'] ) );
		self::assertSame( '1', get_post_meta( $this->sounds['admin'], GlobalOverlaySounds::OWNER_META, true ) );

		// Settings and fonts.
		self::assertSame( array( 2097152, 20, true ), array( Settings::sound_max_bytes(), Settings::sound_max_seconds(), Settings::checkout_username_enabled() ) );
		self::assertSame( array( 'Roboto' ), array_keys( $this->fonts()->approved() ) );
		self::assertTrue( $this->fonts()->has_key() );
		self::assertFalse( Settings::alerts_enabled(), 'Alerts stay switched off until the administrator switches them on.' );

		$actions = array_column( $this->rows( Tables::AUDIT_LOG ), 'action' );
		self::assertSame( 3, count( array_keys( $actions, 'alert_creator_imported', true ) ) );
		self::assertContains( 'alert_import_completed', $actions );
		self::assertSame( $merchalert, $this->merchalert_snapshot(), 'MerchAlert\'s data is never changed.' );

		// Later changes stay; a second run only adds what is missing.
		$this->overlays->update_settings( $overlay->id, OverlaySettings::from_array( array_merge( $values, array( 'template' => 'Changed later' ) ) ) );
		$this->wpdb->delete( $this->table( Tables::ALERT_EVENTS ), array( 'order_item_id' => 9011 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test setup.
		$second = $this->importer()->preview();
		self::assertSame( MerchAlertImportReport::IMPORTED, $second->creators[0]['status'] );
		self::assertTrue( $second->settings['done'] );
		self::assertSame(
			array(
				'creators' => 0,
				'events'   => 1,
				'messages' => 0,
			),
			$this->importer()->run( $second->fingerprint() )
		);
		self::assertSame( 'Changed later', $this->overlays->find_for_creator( $this->nova )?->settings->template() );
		self::assertCount( 2, $this->rows( Tables::ALERT_EVENTS ) );
	}

	public function test_the_admin_action_requires_the_backup_checkbox_and_the_dry_runs_fingerprint(): void {
		$actions = new MerchAlertImportActions( $this->importer(), $this->service( Logger::class ) );
		$input   = array(
			'_wpnonce'    => wp_create_nonce( MerchAlertImportActions::ACTION ),
			'fingerprint' => $this->importer()->preview()->fingerprint(),
		);

		self::assertStringContainsString( MerchAlertImportException::BACKUP_REQUIRED, $actions->process( $input ) );
		self::assertStringContainsString(
			MerchAlertImportException::PREVIEW_OUTDATED,
			$actions->process(
				array_merge(
					$input,
					array(
						'backup'      => '1',
						'fingerprint' => 'nope',
					)
				)
			)
		);
		self::assertNull( $this->overlays->find_for_creator( $this->nova ), 'Nothing imported yet.' );

		$url = $actions->process( array_merge( $input, array( 'backup' => '1' ) ) );
		self::assertStringContainsString( 'tab=' . MerchAlertImportTab::TAB, $url );
		self::assertStringContainsString( '=imported', $url );
		self::assertSame( self::NOVA_ID, $this->overlays->find_for_creator( $this->nova )?->overlay_id );
	}

	/**
	 * The import with a stub order reader.
	 */
	private function importer(): MerchAlertImporter {
		$audit       = $this->service( AuditTrail::class );
		$transaction = $this->service( Transaction::class );
		$source      = new MerchAlertSource( $this->wpdb );
		$global      = new GlobalOverlayStore();
		$plan        = new MerchAlertImportPlan( $source, new CreatorCategoryRepository( $this->wpdb ), new CreatorRepository( $this->wpdb ), $this->overlays, $global );
		$orders      = new class() implements PaidOrderReader {
			/**
			 * Order 900 only.
			 *
			 * @param int $order_id Order.
			 */
			public function read( int $order_id ): ?PaidOrder {
				return 900 === $order_id ? new PaidOrder( 900, '900', 'StreamFan', array( new PaidOrderLine( 9001, 70, 71, 'Hoodie', 'Size: L', 1, 0 ) ) ) : null;
			}
		};
		$active      = &$this->merchalert_active;

		return new MerchAlertImporter(
			$plan,
			$source,
			new AlertsAvailability(
				static function () use ( &$active ): bool {
					return $active;
				}
			),
			$transaction,
			$audit,
			$this->overlays,
			new AlertEventRepository( $this->wpdb ),
			new ChatbotChannelRepository( $this->wpdb ),
			new ChatMessageRepository( $this->wpdb ),
			$orders,
			new AlertSettings( $audit, $transaction ),
			$this->fonts(),
			$global
		);
	}

	/**
	 * The fonts service.
	 */
	private function fonts(): AlertFonts {
		return new AlertFonts( $this->service( AuditTrail::class ), $this->service( Transaction::class ), static fn(): mixed => null );
	}

	/**
	 * MerchAlert's tables, options and data, as Beta 2.0.2 leaves them.
	 */
	private function merchalert(): void {
		$wpdb    = $this->wpdb;
		$schemas = array(
			'creator_profiles'             => 'id bigint unsigned NOT NULL AUTO_INCREMENT, creator_name varchar(255) NOT NULL, status varchar(20) NOT NULL DEFAULT \'active\', PRIMARY KEY (id)',
			'creator_category_assignments' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, creator_id bigint unsigned NOT NULL, wc_category_term_id bigint unsigned NOT NULL, PRIMARY KEY (id)',
			'creator_overlay_settings'     => 'id bigint unsigned NOT NULL AUTO_INCREMENT, creator_id bigint unsigned NOT NULL, overlay_id varchar(32) NOT NULL, settings longtext NOT NULL, PRIMARY KEY (id)',
			'alert_events'                 => 'id bigint unsigned NOT NULL AUTO_INCREMENT, creator_id bigint unsigned NOT NULL, overlay_id varchar(32) NOT NULL, order_id bigint unsigned NOT NULL, order_number varchar(64) NOT NULL, order_item_id bigint unsigned NOT NULL, customer_first_name varchar(255) NOT NULL, quantity int NOT NULL, product_id bigint unsigned NOT NULL, variation_id bigint unsigned NOT NULL, product_name varchar(255) NOT NULL, featured_image_attachment_id bigint unsigned NOT NULL, category_term_ids text NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (id)',
			'creator_chatbot'              => 'id bigint unsigned NOT NULL AUTO_INCREMENT, creator_id bigint unsigned NOT NULL, twitch_channel varchar(100) NOT NULL, enabled tinyint(1) NOT NULL, template text NOT NULL, PRIMARY KEY (id)',
			'chatbot_messages'             => 'id bigint unsigned NOT NULL AUTO_INCREMENT, creator_id bigint unsigned NOT NULL, order_id bigint unsigned NOT NULL, order_item_id bigint unsigned NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (id)',
			'google_fonts'                 => 'id bigint unsigned NOT NULL AUTO_INCREMENT, family varchar(255) NOT NULL, category varchar(50) NOT NULL, variants text NOT NULL, sort_order int NOT NULL, approved tinyint(1) NOT NULL, PRIMARY KEY (id)',
		);

		foreach ( $schemas as $table => $columns ) {
			// MerchAlert creates its tables with the site's charset (utf8mb4: emoji in templates).
			$wpdb->query( 'CREATE TABLE ' . $wpdb->prefix . 'ccmerch_' . $table . ' (' . $columns . ') ENGINE=InnoDB ' . $wpdb->get_charset_collate() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test fixture with constant names.
		}

		$insert = fn( string $table, array $row ) => $wpdb->insert( $wpdb->prefix . 'ccmerch_' . $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.

		foreach ( array(
			1 => 'Nova MA',
			2 => 'Orion MA',
			3 => 'Lyra MA',
			4 => 'Lyra again',
			5 => 'Unknown',
			6 => 'Uncategorised',
		) as $id => $name ) {
			$insert(
				'creator_profiles',
				array(
					'id'           => $id,
					'creator_name' => $name,
				)
			);
		}

		foreach ( array(
			1 => 501,
			2 => 502,
			3 => 503,
			4 => 503,
			5 => 999,
		) as $creator => $term ) {
			$insert(
				'creator_category_assignments',
				array(
					'creator_id'          => $creator,
					'wc_category_term_id' => $term,
				)
			);
		}

		$settings = array(
			'template'            => '$Product by $FirstName!',
			'bg_color'            => '#123456',
			'font_size'           => '40',
			'audio_enabled'       => '1',
			'audio_default_sound' => (string) $this->sounds['own'],
			'audio_category_map'  => (string) wp_json_encode( array( '501' => $this->sounds['foreign'] ) ),
		);

		foreach ( array(
			1 => self::NOVA_ID,
			2 => self::ORION_ID,
			3 => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
			4 => 'ffffffffffffffffffffffffffffffff',
			5 => '11111111111111111111111111111111',
		) as $creator => $id ) {
			$insert(
				'creator_overlay_settings',
				array(
					'creator_id' => $creator,
					'overlay_id' => $id,
					'settings'   => (string) wp_json_encode( $settings ),
				)
			);
		}

		foreach ( array( array( 900, 9001, '2026-01-02 10:00:00' ), array( 901, 9011, '2026-01-03 11:00:00' ) ) as [ $order, $item, $time ] ) {
			$insert(
				'alert_events',
				array(
					'creator_id'                   => 1,
					'overlay_id'                   => self::NOVA_ID,
					'order_id'                     => $order,
					'order_number'                 => (string) $order,
					'order_item_id'                => $item,
					'customer_first_name'          => 'Jens',
					'quantity'                     => 1,
					'product_id'                   => 70,
					'variation_id'                 => 0,
					'product_name'                 => 'Hoodie',
					'featured_image_attachment_id' => 0,
					'category_term_ids'            => '[501]',
					'created_at'                   => $time,
				)
			);
		}

		$insert(
			'creator_chatbot',
			array(
				'creator_id'     => 1,
				'twitch_channel' => 'nova',
				'enabled'        => 1,
				'template'       => '🔔 $Name just bought $Product!',
			)
		);
		$insert(
			'chatbot_messages',
			array(
				'creator_id'    => 1,
				'order_id'      => 900,
				'order_item_id' => 9001,
				'created_at'    => '2026-01-02 10:05:00',
			)
		);

		foreach ( array( array( 'Roboto', 1 ), array( 'Lato', 0 ) ) as [ $family, $approved ] ) {
			$insert(
				'google_fonts',
				array(
					'family'     => $family,
					'category'   => 'sans-serif',
					'variants'   => '["regular","700"]',
					'sort_order' => 0,
					'approved'   => $approved,
				)
			);
		}

		update_option(
			MerchAlertSource::SETTINGS_OPTION,
			array(
				'enable_alerts'        => 1,
				'max_audio_size'       => 2097152,
				'max_audio_duration'   => 20,
				'google_fonts_api_key' => 'AIzaSyA-valid_key_1234567890',
				'username_enabled'     => 1,
				'username_label'       => 'Stream Username',
			)
		);
		update_option(
			MerchAlertSource::OVERLAY_OPTION,
			array(
				'overlay_id'          => self::GLOBAL_ID,
				'template'            => 'Global $Product!',
				'audio_default_sound' => (string) $this->sounds['admin'],
			)
		);
		update_option(
			MerchAlertSource::LAYOUT_OPTION,
			array(
				'elements' => array(
					'main_text' => array(
						'x'       => 300,
						'y'       => 900,
						'w'       => 600,
						'h'       => 80,
						'visible' => true,
					),
				),
			)
		);
	}

	/**
	 * An audio attachment with MerchAlert's owner meta (0: an administrator's upload).
	 *
	 * @param int $owner MerchAlert creator.
	 */
	private function sound( int $owner ): int {
		$id = (int) wp_insert_attachment(
			array(
				'post_title'     => 'Sound ' . $owner,
				'post_mime_type' => 'audio/mpeg',
				'post_status'    => 'inherit',
			)
		);

		if ( $owner > 0 ) {
			update_post_meta( $id, MerchAlertSource::SOUND_OWNER_META, (string) $owner );
		}

		return $id;
	}

	/**
	 * What the import may write.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		return array(
			'overlays' => $this->rows( Tables::ALERT_OVERLAYS ),
			'events'   => $this->rows( Tables::ALERT_EVENTS ),
			'chatbot'  => $this->rows( Tables::ALERT_CHATBOT ),
			'messages' => $this->rows( Tables::ALERT_CHAT_MESSAGES ),
			'audit'    => $this->rows( Tables::AUDIT_LOG ),
			'global'   => get_option( GlobalOverlayStore::OPTION, null ),
			'marker'   => get_option( MerchAlertImportPlan::MARKER_OPTION, null ),
			'settings' => get_option( Settings::OPTION, null ),
		);
	}

	/**
	 * MerchAlert's data.
	 *
	 * @return array<string, mixed>
	 */
	private function merchalert_snapshot(): array {
		$snapshot = array();

		foreach ( self::TABLES as $table ) {
			$snapshot[ $table ] = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $this->wpdb->prefix . 'ccmerch_' . $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test.
		}

		foreach ( array( MerchAlertSource::SETTINGS_OPTION, MerchAlertSource::OVERLAY_OPTION, MerchAlertSource::LAYOUT_OPTION ) as $option ) {
			$snapshot[ $option ] = get_option( $option );
		}

		$snapshot['owners'] = array_map( static fn( int $sound ): mixed => get_post_meta( $sound, MerchAlertSource::SOUND_OWNER_META, true ), $this->sounds );

		return $snapshot;
	}

	/**
	 * Asserts a refused import.
	 *
	 * @param callable $call   Call.
	 * @param string   $reason Expected reason.
	 */
	private function refused( callable $call, string $reason ): void {
		try {
			$call();
			self::fail( 'Expected MerchAlertImportException.' );
		} catch ( MerchAlertImportException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
