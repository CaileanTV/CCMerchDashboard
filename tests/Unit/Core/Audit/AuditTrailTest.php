<?php
/**
 * Tests for the audit trail.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Audit;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditEntry;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass( AuditTrail::class )]
#[CoversClass( AuditRepository::class )]
#[CoversClass( AuditEntry::class )]
#[CoversClass( AuditAction::class )]
final class AuditTrailTest extends TestCase {

	private FakeDatabase $db;

	private AuditRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$this->db         = new FakeDatabase();
		$this->repository = new AuditRepository( $this->db );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-09-24 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
	}

	public function test_action_names_are_a_closed_set(): void {
		self::assertSame(
			array( 'creator_created', 'creator_updated', 'creator_activated', 'creator_deactivated', 'creator_user_linked', 'creator_user_unlinked', 'settings_updated', 'product_creator_linked', 'product_creator_unlinked', 'creator_category_linked', 'creator_category_unlinked', 'category_sync_completed', 'sales_backfill_completed', 'creator_pricing_applied', 'creator_pricing_drift_overwritten', 'creator_markup_changed', 'product_fixed_profit_set', 'product_fixed_profit_removed', 'creator_vat_status_changed', 'creator_vat_history_recorded', 'creator_billing_details_changed', 'product_markup_set', 'product_markup_removed', 'order_history_deleted', 'payout_requested', 'payout_cancelled', 'payout_rejected', 'payout_completed', 'payout_gift_card_registered', 'payout_details_viewed', 'payout_start_changed', 'payout_settings_changed', 'settlement_document_issued', 'settlement_document_corrected', 'g1_activated', 'payout_recalculation_previewed', 'payout_recalculation_approved', 'payout_recalculation_rejected', 'payout_recalculation_reopened', 'payout_adjustment_executed', 'payout_recalculation_item_failed', 'payout_recalculation_executed', 'payout_recalculation_closed', 'payout_recalculation_exported', 'payout_recalculation_export_downloaded', 'creator_code_registered', 'creator_code_ended', 'order_excluded_by_creator_code', 'alert_overlay_created', 'alert_overlay_id_regenerated', 'alert_fonts_key_changed', 'alert_fonts_synced', 'alert_font_approved', 'alert_font_removed', 'alert_sound_library_added', 'alert_sound_library_removed', 'alert_sound_library_renamed', 'alert_image_library_added', 'alert_image_library_removed', 'alert_image_library_renamed', 'alert_twitch_app_changed', 'alert_twitch_app_removed', 'alert_chatbot_bot_connected', 'alert_chatbot_bot_disconnected', 'alert_chatbot_format_changed', 'alert_chatbot_channel_connected', 'alert_chatbot_channel_disconnected', 'alert_chatbot_message_sent', 'alert_kick_app_changed', 'alert_kick_app_removed', 'alert_youtube_app_changed', 'alert_youtube_app_removed', 'alert_youtube_legal_changed', 'alert_youtube_terms_accepted' ),
			array_map( static fn( AuditAction $a ): string => $a->value, AuditAction::cases() )
		);
	}

	public function test_record_uses_server_side_actor_and_utc_time(): void {
		( new AuditTrail( $this->repository ) )->record( AuditAction::CreatorCreated, 3, array( 'status' => 'active' ) );

		self::assertSame(
			array(
				'id'            => '1',
				'created_at'    => '2026-09-24 12:00:00',
				'action'        => 'creator_created',
				'actor_user_id' => '7',
				'creator_id'    => '3',
				'context'       => '{"status":"active"}',
			),
			$this->db->rows( 'merchboard_audit_log' )[0]
		);
	}

	public function test_record_system_has_no_actor_even_with_a_logged_in_user(): void {
		( new AuditTrail( $this->repository ) )->record_system( AuditAction::CreatorUserUnlinked, 3, array( 'reason' => 'user_deleted' ) );

		$row = $this->db->rows( 'merchboard_audit_log' )[0];
		self::assertNull( $row['actor_user_id'], 'get_current_user_id() returns 7 in this test.' );
		self::assertSame( '2026-09-24 12:00:00', $row['created_at'] );
		self::assertSame( '{"reason":"user_deleted"}', $row['context'] );
	}

	public function test_system_actions_have_no_actor(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		( new AuditTrail( $this->repository ) )->record( AuditAction::CreatorDeactivated, 3 );

		$row = $this->db->rows( 'merchboard_audit_log' )[0];
		self::assertNull( $row['actor_user_id'] );
		self::assertNull( $row['context'], 'Empty context is stored as NULL.' );
	}

	public function test_sensitive_context_values_are_never_stored(): void {
		( new AuditTrail( $this->repository ) )->record(
			AuditAction::CreatorUpdated,
			3,
			array(
				'changed_fields' => array( 'display_name' ),
				'password'       => 'hunter2',
				'user_email'     => 'jane@example.com',
				'api_key'        => 'sk_live_x',
				'session_token'  => 'abc',
			)
		);

		$stored = (string) $this->db->rows( 'merchboard_audit_log' )[0]['context'];
		foreach ( array( 'hunter2', 'jane@example.com', 'sk_live_x', 'abc' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $stored );
		}
		self::assertStringContainsString( 'display_name', $stored );
	}

	public function test_context_is_stored_as_ascii_json_and_decodes_to_the_original_unicode(): void {
		$context = array( 'changed_fields' => array( 'display_name' ), 'note' => 'Æble, øl & å 🎸 “quote” 日本' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->repository->append( AuditAction::CreatorUpdated, 1, 1, $context, '2026-09-24 12:00:00' );

		$stored = (string) $this->db->rows( 'merchboard_audit_log' )[0]['context'];
		self::assertMatchesRegularExpression( '/^[\x20-\x7E]+$/', $stored, 'Plain ASCII: cannot fail on a table charset without 4-byte UTF-8.' );
		self::assertSame( $context, $this->repository->for_creator( 1 )[0]->context );
	}

	public function test_malformed_context_does_not_fail_the_audit_write(): void {
		$id = $this->repository->append(
			AuditAction::CreatorUpdated,
			1,
			1,
			array(
				'bad_utf8' => "ok\xB1\x31",
				'number'   => INF,
			),
			'2026-09-24 12:00:00'
		);

		self::assertSame( 1, $id );
		self::assertSame(
			array(
				'bad_utf8' => "ok\u{FFFD}1",
				'number'   => 0,
			),
			$this->repository->for_creator( 1 )[0]->context
		);
	}

	public function test_malformed_stored_context_reads_as_empty(): void {
		$this->repository->append( AuditAction::CreatorUpdated, 1, 1, array( 'a' => 1 ), '2026-09-24 12:00:00' );
		$this->db->data['wp_merchboard_audit_log'][1]['context'] = '{"broken';

		self::assertSame( array(), $this->repository->for_creator( 1 )[0]->context );
	}

	public function test_stored_row_with_unknown_action_is_rejected_on_read(): void {
		$this->repository->append( AuditAction::CreatorUpdated, 1, 1, array(), '2026-09-24 12:00:00' );
		$this->db->data['wp_merchboard_audit_log'][1]['action'] = "creator_deleted'; DROP TABLE x; --";

		$this->expectException( DatabaseException::class );

		$this->repository->for_creator( 1 );
	}

	public function test_append_rejects_invalid_ids_before_writing(): void {
		foreach ( array( array( 0, 1 ), array( 1, -1 ) ) as [ $actor, $creator ] ) {
			try {
				$this->repository->append( AuditAction::CreatorCreated, $actor, $creator, array(), '2026-09-24 12:00:00' );
				self::fail( 'Expected InvalidArgumentException.' );
			} catch ( InvalidArgumentException ) {
				self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
			}
		}
	}

	public function test_write_failure_raises_generic_error(): void {
		$this->db->fail_writes_to = array( 'merchboard_audit_log' );

		try {
			$this->repository->append( AuditAction::CreatorCreated, 7, 1, array(), '2026-09-24 12:00:00' );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not write audit record.', $e->getMessage() );
			self::assertSame( array( 'action' => 'creator_created' ), $e->context() );
		}
	}

	public function test_for_creator_returns_that_creators_records_newest_first(): void {
		$trail = new AuditTrail( $this->repository );
		$trail->record( AuditAction::CreatorCreated, 1 );
		$trail->record( AuditAction::CreatorCreated, 2 );
		$trail->record( AuditAction::CreatorDeactivated, 1, array( 'previous_status' => 'active' ) );

		$entries = $this->repository->for_creator( 1 );

		self::assertSame( array( AuditAction::CreatorDeactivated, AuditAction::CreatorCreated ), array_map( static fn( AuditEntry $e ): AuditAction => $e->action, $entries ) );
		self::assertSame( array( 'previous_status' => 'active' ), $entries[0]->context );
		self::assertSame( 7, $entries[0]->actor_user_id );
		self::assertSame( 'UTC', $entries[0]->created_at->getTimezone()->getName() );
	}

	public function test_repository_is_append_only(): void {
		$public = array_map(
			static fn( ReflectionMethod $m ): string => $m->getName(),
			( new ReflectionClass( AuditRepository::class ) )->getMethods( ReflectionMethod::IS_PUBLIC )
		);

		// Only appending and reading; no update, delete or truncate (phase 11 adds a read).
		self::assertSame( array( '__construct', 'append', 'insert_on_connection', 'for_creator', 'paginate' ), $public );
	}
}
