<?php
/**
 * Payout requests, reservation, decisions, encryption and settings on a real database (ADR-054–056, ADR-058).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutMethod;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Modules\WooCommerce\Admin\PayoutSettingsActions;
use RuntimeException;
use WP_User;
use wpdb;

/**
 * No WooCommerce is loaded (ADR-038 Q4): earnings come from real ledger
 * rows (sales of 100.00 incl. VAT at 20 %, VAT registered: payout 20.00
 * each, dated 2026-09-01) and the services run with a fixed clock
 * (2026-11-30 12:00 UTC) and a test key. Synthetic data only.
 */
final class PayoutsTest extends IntegrationTestCase {

	private const IBAN_LIKE_ACCOUNT = '0012345678';

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private PayoutRepository $repository;

	private PayoutRequests $requests;

	private PayoutDecisions $decisions;

	private PayoutSettings $settings;

	private PayoutBalances $balances;

	private string $key;

	private DateTimeImmutable $now;

	private int $creator;

	private int $other;

	private int $product;

	private int $next_order = 4001;

	private ?wpdb $second = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( Settings::OPTION );

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Payout Alpha', CreatorStatus::Active )->id;
		$this->other   = $creators->create( 'Payout Beta', CreatorStatus::Active )->id;
		$markups       = new CreatorMarkupRepository( $this->wpdb );
		$vat           = new CreatorVatRepository( $this->wpdb );

		foreach ( array( $this->creator, $this->other ) as $id ) {
			$markups->save( $id, new MarkupBps( 2000 ) );
			$vat->save( $id, true );
		}

		$audit          = $this->service( AuditTrail::class );
		$transaction    = $this->service( Transaction::class );
		$this->posts    = new ProductPosts( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );
		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $vat );
		$this->reader   = new FakeOrderReader();
		$this->sync     = new SalesSynchronizer(
			new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) ),
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) )
		);
		$this->product  = $this->posts->add( 'MB-PAY product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $this->product, '_regular_price', '100' );
		$this->posts->meta( $this->product, '_price', '100' );

		$this->key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$this->now = new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$this->services( $this->key );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		$this->second?->close();
		parent::tearDown();
	}

	public function test_the_schema_has_the_payout_tables_and_the_start_column(): void {
		self::assertSame( 15, (int) get_option( 'merchboard_db_version' ), 'Version 9 created them; version 10 added the gift card columns; version 11 the settlement documents; version 12 the gift card assessment tables; version 13 the operation registry; version 14 the recalculations; version 15 their exports.' );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUTS ) );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUT_SECRETS ) );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT payout_start_at FROM %i WHERE id = %d', $this->table( Tables::CREATORS ), $this->creator ) ), 'NULL = the whole history.' );
		self::assertTrue( current_user_can( Capabilities::MANAGE_PAYOUTS ) && current_user_can( Capabilities::VIEW_PAYOUT_DETAILS ), 'Administrators get both (role version 5).' );
	}

	public function test_a_request_reserves_the_amount_and_stores_the_details_only_encrypted(): void {
		$this->earn( 3 );
		self::assertSame( 600000, $this->available() );

		$payout = $this->request( '25.50', 'key-0000-0001' );

		self::assertSame( PayoutStatus::Pending, $payout->status );
		self::assertSame( array( 255000, 'DKK', 2, PayoutMethod::Bank, '•••• 5678' ), array( $payout->amount_units, $payout->currency, $payout->price_decimals, $payout->method, $payout->destination_hint ) );
		self::assertSame( 345000, $this->available(), '60.00 − 25.50 reserved.' );

		$secret = $this->rows_of( Tables::PAYOUT_SECRETS )[0];
		self::assertStringNotContainsString( self::IBAN_LIKE_ACCOUNT, (string) $secret['ciphertext'] );
		self::assertStringNotContainsString( '1234', (string) $secret['ciphertext'] );
		self::assertSame( ( new PayoutCipher( $this->key ) )->key_id(), $secret['key_id'] );

		$audit = $this->audit_rows( AuditAction::PayoutRequested );
		self::assertCount( 1, $audit );
		self::assertSame( array( 'payout_id' => $payout->id, 'amount' => '25.5000', 'currency' => 'DKK', 'method' => 'bank' ), $audit[0] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( self::IBAN_LIKE_ACCOUNT, (string) wp_json_encode( $this->rows_of( Tables::PAYOUTS ) ) );
	}

	public function test_a_resent_request_returns_the_stored_payout(): void {
		$this->earn( 2 );
		$first  = $this->request( '10', 'key-0000-0002' );
		$second = $this->request( '15', 'key-0000-0002' );

		self::assertSame( $first->id, $second->id );
		self::assertSame( 100000, $second->amount_units, 'The stored request, not the resent values.' );
		self::assertSame( 1, $this->count_rows( Tables::PAYOUTS ) );
		self::assertCount( 1, $this->audit_rows( AuditAction::PayoutRequested ) );
	}

	public function test_amounts_are_validated_against_the_available_amount_and_precision(): void {
		$this->earn( 1 );

		foreach ( array( '0', '-1', '1.234', 'abc', '1e3', '' ) as $i => $amount ) {
			$this->refused( PayoutException::INVALID_AMOUNT, fn() => $this->request( $amount, 'key-invalid-' . $i ) );
		}

		$this->refused( PayoutException::INSUFFICIENT_BALANCE, fn() => $this->request( '20.01', 'key-0000-0003' ) );
		self::assertSame( 200000, $this->request( 'all', 'key-0000-0004' )->amount_units );
		$this->refused( PayoutException::INSUFFICIENT_BALANCE, fn() => $this->request( 'all', 'key-0000-0005' ), 'Nothing left.' );
		self::assertSame( 1, $this->count_rows( Tables::PAYOUTS ) );
	}

	public function test_limits_of_3_pending_and_10_per_24_hours(): void {
		$this->earn( 20 );

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->request( '1', 'key-pending-' . $i );
		}

		$this->refused( PayoutException::TOO_MANY_PENDING, fn() => $this->request( '1', 'key-pending-4' ) );

		for ( $i = 4; $i <= 10; $i++ ) {
			$this->requests->cancel( $this->creator, $this->repository->find_by_key( $this->creator, 'key-pending-' . ( $i - 3 ) )->id ?? 0, 1 );
			$this->request( '1', 'key-pending-' . $i );
		}

		self::assertSame( 10, $this->count_rows( Tables::PAYOUTS ) );
		$this->requests->cancel( $this->creator, (int) $this->repository->find_by_key( $this->creator, 'key-pending-8' )?->id, 1 );
		$this->refused( PayoutException::TOO_MANY_REQUESTS, fn() => $this->request( '1', 'key-pending-11' ), 'Cancelled requests still count.' );

		$this->now = $this->now->modify( '+24 hours +1 second' );
		$this->services( $this->key );
		self::assertSame( PayoutStatus::Pending, $this->request( '1', 'key-pending-12' )->status, 'A day later.' );
	}

	public function test_an_unknown_balance_blocks_every_request(): void {
		$this->earn( 2 );
		// An order line from before the snapshots: its payout is unknown.
		$this->order( array( $this->line( 4901, 1, '80', '20' ) ) );

		self::assertNull( $this->available() );
		$this->refused( PayoutException::BALANCE_UNKNOWN, fn() => $this->request( '1', 'key-0000-0006' ) );
		$this->refused( PayoutException::BALANCE_UNKNOWN, fn() => $this->request( 'all', 'key-0000-0007' ) );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUTS ) );
	}

	public function test_bank_and_paypal_are_unavailable_without_a_valid_key_and_details_are_validated(): void {
		$this->earn( 1 );

		foreach ( array( null, '', 'too-short', base64_encode( str_repeat( 'x', 31 ) ) ) as $i => $key ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A wrong test key.
			$this->services( $key );
			self::assertFalse( $this->requests->methods_available() );
			$this->refused( PayoutException::METHOD_UNAVAILABLE, fn() => $this->request( '1', 'key-nokey-' . $i ) );
		}

		$this->services( $this->key );
		$this->refused( PayoutException::INVALID_DETAILS, fn() => $this->requests->request( $this->creator, 'DKK', '1', 'bank', array( 'registration' => '123', 'account' => '1' ), 'key-0000-0008', 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->refused( PayoutException::INVALID_DETAILS, fn() => $this->requests->request( $this->creator, 'DKK', '1', 'bank', array( 'registration' => '1234', 'account' => '12345678901' ), 'key-0000-0009', 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->refused( PayoutException::INVALID_DETAILS, fn() => $this->requests->request( $this->creator, 'DKK', '1', 'paypal', array( 'email' => 'not-an-email' ), 'key-0000-0010', 1 ) );
		$this->refused( PayoutException::METHOD_UNAVAILABLE, fn() => $this->requests->request( $this->creator, 'DKK', '1', 'gift_card', array(), 'key-0000-0011', 1 ), 'Gift cards follow in step 23.7.' );
		$this->refused( PayoutException::INVALID_KEY, fn() => $this->request( '1', 'short' ) );

		$paypal = $this->requests->request( $this->creator, 'DKK', '1', 'paypal', array( 'email' => 'jane.doe@gmail.com' ), 'key-0000-0012', 1 );
		self::assertSame( 'j•••@g•••.com', $paypal->destination_hint );
	}

	public function test_cancel_releases_the_reservation_only_for_the_creators_own_pending_payout(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-0000-0013' );

		$this->refused( PayoutException::NOT_FOUND, fn() => $this->requests->cancel( $this->other, $payout->id, 1 ), 'Another creator: not found (IDOR).' );

		$cancelled = $this->requests->cancel( $this->creator, $payout->id, 1 );
		self::assertSame( PayoutStatus::Cancelled, $cancelled->status );
		self::assertSame( 400000, $this->available() );
		self::assertNull( $this->rows_of( Tables::PAYOUT_SECRETS )[0]['ciphertext'], 'Details removed.' );
		self::assertNotNull( $this->rows_of( Tables::PAYOUT_SECRETS )[0]['purged_at'] );

		self::assertSame( PayoutStatus::Cancelled, $this->requests->cancel( $this->creator, $payout->id, 1 )->status, 'Again: already cancelled.' );
		self::assertCount( 1, $this->audit_rows( AuditAction::PayoutCancelled ) );
	}

	public function test_reject_requires_the_capability_and_a_reason_and_never_audits_the_text(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-0000-0014' );

		wp_set_current_user( $this->create_user( 'mb-pay-subscriber' ) );
		$this->refused( PayoutException::NOT_AUTHORIZED, fn() => $this->decisions->reject( $payout->id, 'No.' ) );
		wp_set_current_user( 1 );

		$this->refused( PayoutException::INVALID_REASON, fn() => $this->decisions->reject( $payout->id, '   ' ) );
		$this->refused( PayoutException::PAYMENT_DETAILS_IN_TEXT, fn() => $this->decisions->reject( $payout->id, 'Account number 0012345678 is closed.' ) );
		$rejected = $this->decisions->reject( $payout->id, 'The account is closed at the bank.' );

		self::assertSame( array( PayoutStatus::Rejected, 'The account is closed at the bank.', 1 ), array( $rejected->status, $rejected->decision_reason, $rejected->decided_by ) );
		self::assertSame( 400000, $this->available() );
		self::assertSame( array( 'payout_id' => $payout->id, 'amount' => '10.0000', 'currency' => 'DKK', 'method' => 'bank', 'has_reason' => true ), $this->audit_rows( AuditAction::PayoutRejected )[0] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( PayoutStatus::Rejected, $this->decisions->reject( $payout->id, 'Again.' )->status, 'Already rejected.' );
		self::assertCount( 1, $this->audit_rows( AuditAction::PayoutRejected ) );
		$this->refused( PayoutException::NOT_PENDING, fn() => $this->decisions->complete( $payout->id ) );
		$this->refused( PayoutException::NOT_PENDING, fn() => $this->requests->cancel( $this->creator, $payout->id, 1 ) );
	}

	public function test_complete_registers_the_transfer_once_and_keeps_the_amount_as_paid(): void {
		$this->earn( 2 );
		$payout = $this->request( '30', 'key-0000-0015' );

		$completed = $this->decisions->complete( $payout->id, 'BANK-REF-1' );
		self::assertSame( array( PayoutStatus::Completed, 'BANK-REF-1' ), array( $completed->status, $completed->reference ) );
		self::assertSame( array( 'payout_id' => $payout->id, 'amount' => '30.0000', 'currency' => 'DKK', 'method' => 'bank', 'has_reference' => true ), $this->audit_rows( AuditAction::PayoutCompleted )[0] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 100000, $this->available(), '40 earned − 30 completed.' );
		self::assertNull( $this->rows_of( Tables::PAYOUT_SECRETS )[0]['ciphertext'] );

		self::assertSame( PayoutStatus::Completed, $this->decisions->complete( $payout->id )->status, 'Double click: already handled.' );
		self::assertCount( 1, $this->audit_rows( AuditAction::PayoutCompleted ) );
		$this->refused( PayoutException::NOT_PENDING, fn() => $this->decisions->reject( $payout->id, 'Too late.' ) );
	}

	public function test_a_refund_while_pending_sends_the_completion_to_manual_handling(): void {
		$items  = $this->earn( 2 );
		$payout = $this->request( 'all', 'key-0000-0016' );

		// A full refund of one sale after the request: the balance no longer covers it.
		$this->order( array( $this->line( $items[0], 1, '80', '20' ) ), array( new RefundSnapshot( 7801, new DateTimeImmutable( '2026-11-01 10:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( $items[0], -1, SalesAmount::parse( '-80' ), SalesAmount::parse( '-20' ) ) ) ) ), 4001 );

		$this->refused( PayoutException::INSUFFICIENT_BALANCE, fn() => $this->decisions->complete( $payout->id ) );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );
		self::assertSame( PayoutStatus::Rejected, $this->decisions->reject( $payout->id, 'A sale was refunded.' )->status, 'It can still be rejected.' );
	}

	public function test_pending_details_that_cannot_be_decrypted_can_only_be_rejected(): void {
		$this->earn( 1 );
		$payout = $this->request( '5', 'key-0000-0017' );

		$this->services( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Another test key.
		$this->refused( PayoutException::DETAILS_UNREADABLE, fn() => $this->decisions->complete( $payout->id ) );
		$this->refused( PayoutException::DETAILS_UNREADABLE, fn() => $this->decisions->details( $payout->id ) );
		self::assertSame( PayoutStatus::Rejected, $this->decisions->reject( $payout->id, 'Details unreadable.' )->status );
	}

	public function test_full_details_need_both_capabilities_and_every_view_is_audited(): void {
		$this->earn( 1 );
		$payout = $this->request( '5', 'key-0000-0018' );

		$details = $this->decisions->details( $payout->id );
		self::assertSame( array( PayoutMethod::Bank, '1234', self::IBAN_LIKE_ACCOUNT ), array( $details->method, $details->registration, $details->account ) );
		self::assertSame( array( 'payout_id' => $payout->id, 'method' => 'bank' ), $this->audit_rows( AuditAction::PayoutDetailsViewed )[0] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$manager = $this->create_user( 'mb-pay-manager' );
		( new WP_User( $manager ) )->add_cap( Capabilities::MANAGE_PAYOUTS );
		wp_set_current_user( $manager );
		$this->refused( PayoutException::NOT_AUTHORIZED, fn() => $this->decisions->details( $payout->id ), 'Managing payouts does not show full details.' );
		wp_set_current_user( 1 );

		$this->decisions->complete( $payout->id );
		$this->refused( PayoutException::NOT_PENDING, fn() => $this->decisions->details( $payout->id ), 'Only while pending.' );
		self::assertCount( 1, $this->audit_rows( AuditAction::PayoutDetailsViewed ) );
	}

	public function test_a_lock_held_by_another_request_refuses_without_changes(): void {
		$this->earn( 1 );
		$second       = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->second = $second;
		$name         = 'merchboard_payout:' . substr( md5( DB_NAME . '|' . $this->wpdb->prefix ), 0, 12 ) . ':' . $this->creator;
		self::assertSame( '1', (string) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );

		$this->repository = new PayoutRepository( $this->wpdb, 0 );
		$this->services( $this->key, $this->repository );
		$this->refused( PayoutException::BUSY, fn() => $this->request( '1', 'key-0000-0019' ) );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUTS ) );

		$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		self::assertSame( PayoutStatus::Pending, $this->request( '1', 'key-0000-0019' )->status );
	}

	public function test_settlement_start_bounds_the_window_and_is_audited(): void {
		$this->earn( 2 );
		$this->earn( 1, '2026-10-20 10:00:00' );
		self::assertSame( 600000, $this->available() );

		self::assertTrue( $this->settings->set_settlement_start( $this->creator, new DateTimeImmutable( '2026-10-01 00:00:00', new DateTimeZone( 'UTC' ) ) ) );
		self::assertSame( 200000, $this->available(), 'Only the sale after the start.' );
		self::assertFalse( $this->settings->set_settlement_start( $this->creator, new DateTimeImmutable( '2026-10-01 02:00:00', new DateTimeZone( 'Europe/Copenhagen' ) ) ), 'The same instant: unchanged.' );
		self::assertTrue( $this->settings->set_settlement_start( $this->creator, null ) );
		self::assertSame( 600000, $this->available() );

		self::assertSame(
			array(
				array(
					'previous_start' => null,
					'start'          => '2026-10-01 00:00:00',
				),
				array(
					'previous_start' => '2026-10-01 00:00:00',
					'start'          => null,
				),
			),
			$this->audit_rows( AuditAction::PayoutStartChanged )
		);
		$this->refused( PayoutException::INVALID_START, fn() => $this->settings->set_settlement_start( $this->creator, $this->now->modify( '+1 day' ) ) );
		$this->refused( PayoutException::NOT_FOUND, fn() => $this->settings->set_settlement_start( 999999, null ) );

		wp_set_current_user( $this->create_user( 'mb-pay-subscriber-2' ) );
		$this->refused( PayoutException::NOT_AUTHORIZED, fn() => $this->settings->set_settlement_start( $this->creator, null ) );
	}

	public function test_the_holding_period_is_a_setting_with_audit(): void {
		$this->earn( 1 );
		$this->earn( 1, '2026-11-25 10:00:00' );
		self::assertSame( 200000, $this->available(), 'The recent sale is held (14 days).' );

		self::assertTrue( $this->settings->set_holding_days( 3 ) );
		self::assertSame( 3, Settings::payout_holding_days() );
		self::assertSame( 400000, $this->available() );
		self::assertFalse( $this->settings->set_holding_days( 3 ) );
		$this->refused( PayoutException::INVALID_HOLDING_DAYS, fn() => $this->settings->set_holding_days( Settings::MAX_PAYOUT_HOLDING_DAYS + 1 ) );
		$this->refused( PayoutException::INVALID_HOLDING_DAYS, fn() => $this->settings->set_holding_days( -1 ) );
		self::assertSame(
			array(
				array(
					'setting'        => 'payout_holding_days',
					'previous_value' => 14,
					'value'          => 3,
				),
			),
			$this->audit_rows( AuditAction::PayoutSettingsChanged )
		);
	}

	public function test_the_admin_forms_check_capability_and_nonce_before_any_change(): void {
		add_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		$actions = new PayoutSettingsActions( $this->settings, new RecordingLogger() );
		$start   = array(
			'creator_id'                       => (string) $this->creator,
			PayoutSettingsActions::FIELD_START => '2026-10-01',
		);

		$this->dies( fn() => $actions->save_holding_days( array( '_wpnonce' => 'x', PayoutSettingsActions::FIELD_HOLDING_DAYS => '5' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->dies( fn() => $actions->save_start( $start + array( '_wpnonce' => wp_create_nonce( PayoutSettingsActions::start_nonce_action( $this->other ) ) ) ), 'A nonce of another creator.' );

		wp_set_current_user( $this->create_user( 'mb-pay-subscriber-3' ) );
		$this->dies( fn() => $actions->save_holding_days( array( '_wpnonce' => wp_create_nonce( PayoutSettingsActions::HOLDING_ACTION ), PayoutSettingsActions::FIELD_HOLDING_DAYS => '5' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		wp_set_current_user( 1 );

		self::assertSame( 14, Settings::payout_holding_days() );
		self::assertNull( $this->settings->settlement_start( $this->creator ) );
		self::assertSame( array(), $this->audit_rows( AuditAction::PayoutSettingsChanged ) );

		self::assertStringContainsString( PayoutSettingsActions::RESULT_ARG . '=saved', $actions->save_holding_days( array( '_wpnonce' => wp_create_nonce( PayoutSettingsActions::HOLDING_ACTION ), PayoutSettingsActions::FIELD_HOLDING_DAYS => '5' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( PayoutSettingsActions::RESULT_ARG . '=invalid_holding_days', $actions->save_holding_days( array( '_wpnonce' => wp_create_nonce( PayoutSettingsActions::HOLDING_ACTION ), PayoutSettingsActions::FIELD_HOLDING_DAYS => '5.5' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( PayoutSettingsActions::RESULT_ARG . '=saved', $actions->save_start( $start + array( '_wpnonce' => wp_create_nonce( PayoutSettingsActions::start_nonce_action( $this->creator ) ) ) ) );
		self::assertStringContainsString( PayoutSettingsActions::RESULT_ARG . '=invalid_start', $actions->save_start( array( 'creator_id' => (string) $this->creator, PayoutSettingsActions::FIELD_START => '2026-02-30', '_wpnonce' => wp_create_nonce( PayoutSettingsActions::start_nonce_action( $this->creator ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 5, Settings::payout_holding_days() );
		self::assertSame( ( new DateTimeImmutable( '2026-10-01', wp_timezone() ) )->getTimestamp(), $this->settings->settlement_start( $this->creator )?->getTimestamp(), 'Midnight in the site\'s time zone.' );
	}

	/**
	 * Requires a call to end in wp_die() (the test's die handler throws).
	 *
	 * @param callable $call    Call.
	 * @param string   $message Message.
	 */
	private function dies( callable $call, string $message = '' ): void {
		try {
			$call();
			self::fail( 'Expected wp_die(). ' . $message );
		} catch ( RuntimeException ) {
			$this->addToAssertionCount( 1 );
		}
	}

	/**
	 * Builds the services with a cipher key (null = none).
	 *
	 * @param string|null           $key        Base64 key.
	 * @param PayoutRepository|null $repository Repository (default: a new one).
	 */
	private function services( ?string $key, ?PayoutRepository $repository = null ): void {
		$clock            = fn(): DateTimeImmutable => $this->now;
		$transaction      = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$audit            = $this->service( AuditTrail::class );
		$cipher           = new PayoutCipher( $key );
		$this->repository = $repository ?? new PayoutRepository( $this->wpdb );
		$this->balances   = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock );
		$this->requests   = new PayoutRequests( $this->repository, $this->balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock );
		$this->decisions  = new PayoutDecisions( $this->repository, $this->balances, $cipher, $audit, $transaction, $clock, new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), new CreatorRepository( $this->wpdb ), new CreatorVatRepository( $this->wpdb ), $audit, $transaction, $clock ) );
		$this->settings   = new PayoutSettings( $this->repository, $audit, $transaction, $clock );
	}

	/**
	 * Sales of 100.00 incl. VAT for the creator, each with its own order (payout 20.00 each).
	 *
	 * @param int    $count Number of sales.
	 * @param string $paid  Payment time (UTC).
	 * @return list<int> Order item ids.
	 */
	private function earn( int $count, string $paid = '2026-09-01 10:05:00' ): array {
		$items = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$item = 5000 + $this->next_order;
			$this->recorder->record( $item, $this->next_order, $this->product, 0, 'DKK' );
			$this->order( array( $this->line( $item, 1, '80', '20' ) ), array(), null, $paid );
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Sets an order and synchronizes it.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param OrderLineSnapshot[] $lines    Lines.
	 * @param RefundSnapshot[]    $refunds  Refunds.
	 * @param int|null            $order_id Order (default: the next one).
	 * @param string              $paid     Payment time (UTC).
	 */
	private function order( array $lines, array $refunds = array(), ?int $order_id = null, string $paid = '2026-09-01 10:05:00' ): void {
		$order_id                          = $order_id ?? $this->next_order++;
		$this->reader->orders[ $order_id ] = array(
			'status'    => 'processing',
			'currency'  => 'DKK',
			'date_paid' => $paid,
			'lines'     => $lines,
			'refunds'   => $refunds,
		);
		$this->sync->sync( $order_id );
	}

	/**
	 * A line of the creator's product.
	 *
	 * @param int    $item     Order item.
	 * @param int    $quantity Quantity.
	 * @param string $net      Amount excl. VAT.
	 * @param string $tax      Tax.
	 */
	private function line( int $item, int $quantity, string $net, string $tax ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, $this->product, 0, 'MB-PAY line', $quantity, SalesAmount::parse( $net ), $this->creator, SalesAmount::parse( $tax ) );
	}

	/**
	 * A bank payout request of the creator.
	 *
	 * @param string $amount Amount.
	 * @param string $key    Request key.
	 */
	private function request( string $amount, string $key ): Payout {
		return $this->requests->request(
			$this->creator,
			'DKK',
			$amount,
			'bank',
			array(
				'registration' => '1234',
				'account'      => self::IBAN_LIKE_ACCOUNT,
			),
			$key,
			1
		);
	}

	/**
	 * The creator's available DKK amount in units, null when unknown.
	 */
	private function available(): ?int {
		return $this->balances->in_currency( $this->creator, 'DKK' )->available_units();
	}

	/**
	 * Requires a refusal with a reason.
	 *
	 * @param string   $reason  Expected reason.
	 * @param callable $call    Call.
	 * @param string   $message Message.
	 */
	private function refused( string $reason, callable $call, string $message = '' ): void {
		try {
			$call();
			self::fail( 'Expected ' . $reason . '. ' . $message );
		} catch ( PayoutException $e ) {
			self::assertSame( $reason, $e->reason(), $message );
		}
	}

	/**
	 * Contexts of the audit records of an action, oldest first.
	 *
	 * @param AuditAction $action Action.
	 * @return list<mixed>
	 */
	private function audit_rows( AuditAction $action ): array {
		$contexts = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT context FROM %i WHERE action = %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), $action->value ) );

		return array_map( static fn( ?string $json ): mixed => json_decode( (string) $json, true ), $contexts );
	}

	/**
	 * Rows of a table without an id column.
	 *
	 * @param string $table Tables constant.
	 * @return list<array<string, mixed>>
	 */
	private function rows_of( string $table ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table( $table ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
