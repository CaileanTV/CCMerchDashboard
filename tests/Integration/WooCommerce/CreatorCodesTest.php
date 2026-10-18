<?php
/**
 * The creators' own discount codes on a real database (ADR-068).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeDeviations;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeService;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionAssessor;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionRepository;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionStatus;
use MerchBoard\Modules\WooCommerce\Payouts\CreatorRowLocks;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLines;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeCouponLookup;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * K1 (registration, periods, uniqueness), K4 (capability), K5 (the
 * coupon's restriction), the assessment in the synchronization (no ledger
 * rows for an excluded order, a permanent decision, `open` with settled
 * rows, fail closed on unknown coupon data) and M2 (every read model leaves
 * an excluded order's rows out, settled rows keep counting in the payout
 * balance). WooCommerce is not loaded (ADR-038 Q4): coupons and orders come
 * from test doubles; creators, users, ledger, locks, transactions and audit
 * are real. Synthetic ids only.
 */
final class CreatorCodesTest extends IntegrationTestCase {

	/**
	 * The registrations' time (UTC).
	 */
	private const REGISTERED = '2026-10-01 12:00:00';

	private CreatorRepository $creators;

	private CreatorCodeRepository $codes;

	private OrderExclusionRepository $exclusions;

	private FakeCouponLookup $coupons;

	private FakeOrderReader $reader;

	private RecordingFailureMarker $marker;

	private SalesSynchronizer $sync;

	private string $now = self::REGISTERED;

	private int $creator;

	private int $other;

	private int $user;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->creators   = new CreatorRepository( $this->wpdb );
		$this->user       = $this->create_user( 'mbcode-nova' );
		$this->creator    = $this->creators->create( 'Code A', CreatorStatus::Active, $this->user )->id;
		$this->other      = $this->creators->create( 'Code B', CreatorStatus::Active, $this->create_user( 'mbcode-lux' ) )->id;
		$this->codes      = new CreatorCodeRepository( $this->wpdb );
		$this->exclusions = new OrderExclusionRepository( $this->wpdb );
		$this->coupons    = new FakeCouponLookup();
		$this->reader     = new FakeOrderReader();
		$this->marker     = new RecordingFailureMarker();
		$this->sync       = new SalesSynchronizer(
			new SalesRepository( $this->wpdb, $this->service( Transaction::class ) ),
			new SalesPlanner(),
			$this->reader,
			$this->marker,
			new RecordingLogger(),
			fn(): DateTimeImmutable => self::at( $this->now ),
			new OrderExclusionAssessor(
				$this->codes,
				$this->exclusions,
				new CreatorRowLocks( $this->wpdb ),
				new CreatorCodeDeviations( $this->coupons ),
				$this->service( AuditTrail::class ),
				new SalesCache()
			)
		);

		$this->coupons->add( 501, 'nova10' );
	}

	public function test_a_valid_code_is_registered_from_now_on_and_audited(): void {
		self::assertSame( 'code_registered', $this->code_service()->register( $this->creator, '  NOVA10 ' ) );

		$active = $this->codes->active_for_creator( $this->creator );

		self::assertNotNull( $active );
		self::assertSame( 'nova10', $active->code );
		self::assertSame( 501, $active->coupon_id );
		self::assertSame( self::REGISTERED, $active->valid_from->format( 'Y-m-d H:i:s' ) );
		self::assertNull( $active->valid_to );
		self::assertSame( 1, $active->created_by );
		self::assertSame(
			array(
				'creator_code_id'          => $active->id,
				'coupon_id'                => 501,
				'previous_creator_code_id' => null,
				'validation'               => 'passed',
			),
			$this->audit( AuditAction::CreatorCodeRegistered )[0]
		);
	}

	/**
	 * K5 and the input rules: nothing is stored or audited.
	 *
	 * @param string                $code   Entered code.
	 * @param callable(self): mixed $set_up Arrangement.
	 * @param string                $reason Expected reason.
	 */
	#[DataProvider( 'refusals' )]
	public function test_a_registration_is_refused( string $code, callable $set_up, string $reason ): void {
		$set_up( $this );

		try {
			$this->code_service()->register( $this->creator, $code );
			self::fail( 'Expected a refusal.' );
		} catch ( CreatorCodeException $e ) {
			self::assertSame( $reason, $e->reason() );
		}

		self::assertSame( 0, $this->count_rows( Tables::CREATOR_CODES ) );
		self::assertSame( array(), $this->audit( AuditAction::CreatorCodeRegistered ) );
	}

	/**
	 * Refusals.
	 *
	 * @return array<string, array{0: string, 1: callable(self): mixed, 2: string}>
	 */
	public static function refusals(): array {
		$none = static function (): void {};

		return array(
			'empty code'          => array( '   ', $none, CreatorCodeException::INVALID_CODE ),
			'too long'            => array( str_repeat( 'x', 192 ), $none, CreatorCodeException::INVALID_CODE ),
			'no coupon'           => array( 'missing', $none, CreatorCodeException::COUPON_NOT_FOUND ),
			'gift card code'      => array( 'nova10', static fn( self $t ) => $t->coupons->set_gift_cards( array( 'nova10' ) ), CreatorCodeException::GIFT_CARD_CODE ),
			'gift card unchecked' => array( 'nova10', static fn( self $t ) => $t->coupons->set_gift_cards( null ), CreatorCodeException::GIFT_CARD_UNVERIFIED ),
			'no capability'       => array( 'nova10', static fn() => wp_set_current_user( 0 ), CreatorCodeException::NOT_AUTHORIZED ),
		);
	}

	public function test_the_coupon_needs_no_e_mail_restriction_and_no_linked_user(): void {
		// Owner decision 2026-10-18: K5 no longer requires an e-mail restriction.
		$this->creators->unlink_user( $this->creator );

		self::assertSame( 'code_registered', $this->code_service()->register( $this->creator, 'nova10' ) );
		self::assertNotNull( $this->codes->active_for_creator( $this->creator ) );
	}

	public function test_one_active_code_per_creator_and_one_creator_per_code(): void {
		$this->coupons->add( 502, 'lux10' );
		$this->code_service()->register( $this->creator, 'nova10' );
		$this->code_service()->register( $this->other, 'lux10' );

		$this->expect_refusal( CreatorCodeException::CODE_TAKEN, fn() => $this->code_service()->register( $this->creator, 'lux10' ) );
		$this->expect_refusal( CreatorCodeException::UNCHANGED, fn() => $this->code_service()->register( $this->creator, 'NOVA10' ) );

		// The UNIQUE keys hold even without the service.
		$this->wpdb->suppress_errors( true );
		$this->expect_database_error( fn() => $this->codes->insert( $this->creator, 'other', 503, null, self::at( self::REGISTERED ) ) );
		$this->expect_database_error( fn() => $this->codes->insert( $this->other + 100, 'nova10', 501, null, self::at( self::REGISTERED ) ) );
		$this->wpdb->suppress_errors( false );

		self::assertSame( 2, $this->count_rows( Tables::CREATOR_CODES ) );
	}

	public function test_registrations_are_serialised_per_creator_and_per_code_across_connections(): void {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		try {
			// Another request holds the code's lock (e.g. registering it for another creator).
			self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', 'merchboard_code:' . md5( 'nova10' ) ) ) );
			$this->expect_refusal( CreatorCodeException::BUSY, fn() => $this->code_service()->register( $this->creator, 'nova10' ) );
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', 'merchboard_code:' . md5( 'nova10' ) ) );

			// Another request holds the creator's lock.
			self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', 'merchboard_code_creator:' . $this->creator ) ) );
			$this->expect_refusal( CreatorCodeException::BUSY, fn() => $this->code_service()->register( $this->creator, 'nova10' ) );
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', 'merchboard_code_creator:' . $this->creator ) );

			self::assertSame( 0, $this->count_rows( Tables::CREATOR_CODES ), 'Nothing is stored while another request holds a lock.' );
			self::assertSame( 'code_registered', $this->code_service()->register( $this->creator, 'nova10' ), 'Both locks are released afterwards.' );
			self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', 'merchboard_code:' . md5( 'nova10' ) ) ) );
		} finally {
			$other->close();
		}
	}

	public function test_a_change_ends_the_old_registration_and_an_ending_keeps_the_history(): void {
		$this->code_service()->register( $this->creator, 'nova10' );
		$first = $this->codes->active_for_creator( $this->creator );
		$this->coupons->add( 502, 'nova20' );
		$this->now = '2026-10-03 08:00:00';

		self::assertSame( 'code_changed', $this->code_service()->register( $this->creator, 'nova20' ) );

		$this->now = '2026-10-04 08:00:00';
		$this->code_service()->end( $this->creator );

		$history = $this->codes->for_creator( $this->creator );

		self::assertCount( 2, $history );
		self::assertSame( array( 'nova20', 'nova10' ), array_map( static fn( $code ): string => $code->code, $history ) );
		self::assertSame( '2026-10-04 08:00:00', $history[0]->valid_to?->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '2026-10-03 08:00:00', $history[1]->valid_to?->format( 'Y-m-d H:i:s' ) );
		self::assertNull( $this->codes->active_for_creator( $this->creator ) );
		self::assertCount( 2, $this->audit( AuditAction::CreatorCodeEnded ) );
		self::assertSame( $first?->id, $this->audit( AuditAction::CreatorCodeRegistered )[1]['previous_creator_code_id'] );
		$this->expect_refusal( CreatorCodeException::NO_ACTIVE_CODE, fn() => $this->code_service()->end( $this->creator ) );
	}

	public function test_deviations_are_reported_but_never_end_the_registration(): void {
		$this->code_service()->register( $this->creator, 'nova10' );
		$active     = $this->codes->active_for_creator( $this->creator );
		$deviations = new CreatorCodeDeviations( $this->coupons );

		self::assertNotNull( $active );
		self::assertSame( array(), $deviations->of( $active ) );

		$this->coupons->add( 777, 'nova10' );
		self::assertSame( array( CreatorCodeDeviations::COUPON_CHANGED ), $deviations->of( $active ) );

		unset( $this->coupons->coupons['nova10'] );
		self::assertSame( array( CreatorCodeDeviations::COUPON_MISSING ), $deviations->of( $active ) );
		self::assertNotNull( $this->codes->active_for_creator( $this->creator ) );
	}

	public function test_only_the_code_creators_lines_are_excluded_and_the_decision_is_permanent(): void {
		$this->code_service()->register( $this->creator, 'nova10' );
		$this->now = '2026-10-05 10:00:00';
		$this->order( 41, '2026-10-05 09:00:00', array( 'nova10' ), array( $this->line( 4111, $this->creator ), $this->line( 4112, $this->other ) ) );

		$this->sync->sync( 41 );

		self::assertSame( array( 's:4112:1' ), $this->keys( 41 ), "Creator B's line counts; creator A's line gets no row (owner decision 2026-10-18)." );
		self::assertSame( array( $this->other ), $this->ledger_creators( 41 ) );
		$decisions = $this->exclusions->for_order( 41 );
		self::assertCount( 1, $decisions );
		$decision = $decisions[0];
		self::assertSame( OrderExclusionStatus::Excluded, $decision->status );
		self::assertSame( $this->creator, $decision->creator_id );
		self::assertSame( 'nova10', $decision->code );
		self::assertFalse( $decision->coupon_deviation );
		self::assertSame( '2026-10-05 09:00:00', $decision->order_created_at->format( 'Y-m-d H:i:s' ) );
		self::assertSame( array( 'clear:41' ), $this->marker->calls );

		$audit = $this->audit( AuditAction::OrderExcludedByCreatorCode );
		self::assertCount( 1, $audit );
		self::assertSame( 'excluded', $audit[0]['decision'] );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT actor_user_id FROM %i WHERE action = %s', $this->table( Tables::AUDIT_LOG ), AuditAction::OrderExcludedByCreatorCode->value ) ), 'A system decision, never the customer.' );

		// Removing the coupon, refunds or a later save never bring creator A's line back; B's refund is recorded.
		$this->order( 41, '2026-10-05 09:00:00', array(), array( $this->line( 4111, $this->creator ), $this->line( 4112, $this->other ) ), array( self::refund( 900, 4111 ), self::refund( 901, 4112 ) ) );
		$this->sync->sync( 41 );
		$this->sync->sync( 41 );

		self::assertSame( array( 's:4112:1', 'r:4112:901' ), $this->keys( 41 ) );
		self::assertCount( 1, $this->audit( AuditAction::OrderExcludedByCreatorCode ) );
	}

	public function test_a_code_without_lines_of_its_creator_excludes_nothing(): void {
		$this->code_service()->register( $this->creator, 'nova10' );
		$this->order( 42, '2026-10-05 09:00:00', array( 'nova10' ), array( $this->line( 4211, $this->other ) ) );

		$this->sync->sync( 42 );

		self::assertSame( array( 's:4211:1' ), $this->keys( 42 ) );
		self::assertSame( array(), $this->exclusions->for_order( 42 ) );
	}

	public function test_codes_of_two_creators_exclude_both_creators_lines(): void {
		$this->coupons->add( 502, 'lux10' );
		$this->code_service()->register( $this->creator, 'nova10' );
		$this->code_service()->register( $this->other, 'lux10' );
		$this->order( 43, '2026-10-05 09:00:00', array( 'nova10', 'lux10' ), array( $this->line( 4311, $this->creator ), $this->line( 4312, $this->other ) ) );

		$this->sync->sync( 43 );

		self::assertSame( 0, $this->ledger_rows( 43 ) );
		self::assertSame( array( $this->creator, $this->other ), array_map( static fn( $decision ): int => $decision->creator_id, $this->exclusions->for_order( 43 ) ) );
		self::assertSame( 0, ( new SalesOrderLines( $this->wpdb ) )->count( $this->other ) );
	}
	public function test_only_orders_created_within_the_registration_period_are_covered(): void {
		$this->code_service()->register( $this->creator, 'nova10' );
		$this->now = '2026-10-10 08:00:00';
		$this->code_service()->end( $this->creator );

		$this->order( 50, '2026-09-30 23:59:59', array( 'nova10' ) );
		$this->order( 51, self::REGISTERED, array( 'nova10' ) );
		$this->order( 52, '2026-10-10 07:59:59', array( 'nova10' ) );
		$this->order( 53, '2026-10-10 08:00:00', array( 'nova10' ) );
		$this->order( 54, '2026-10-05 08:00:00', array( 'other' ) );
		$this->order( 55, '2026-10-05 08:00:00', array() );

		foreach ( array( 50, 51, 52, 53, 54, 55 ) as $order ) {
			$this->sync->sync( $order );
		}

		$excluded = array_keys( $this->exclusions->for_orders( array( 50, 51, 52, 53, 54, 55 ) ) );
		sort( $excluded );

		self::assertSame( array( 51, 52 ), $excluded );
		self::assertSame( array( 1, 0, 0, 1, 1, 1 ), array_map( fn( int $order ): int => $this->ledger_rows( $order ), array( 50, 51, 52, 53, 54, 55 ) ) );
	}

	public function test_unknown_coupon_data_is_never_read_as_no_code(): void {
		$this->order( 60, '2026-10-05 09:00:00', null );

		$this->sync->sync( 60 );

		self::assertSame( 0, $this->ledger_rows( 60 ) );
		self::assertNull( $this->exclusions->for_order( 60 )[0] ?? null );
		self::assertSame( array( 'record:60:unexpected' ), $this->marker->calls );
	}

	public function test_an_excluded_order_with_ledger_rows_is_left_out_of_every_read_model(): void {
		$this->order( 70, '2026-10-05 09:00:00', array() );
		$this->order( 71, '2026-10-05 09:00:00', array() );
		$this->sync->sync( 70 );
		$this->sync->sync( 71 );
		self::assertSame( 1, $this->ledger_rows( 70 ) );

		$before = $this->read_models();
		self::assertSame( array( 2, 2, 2, 2, 2 ), $before, 'Two orders, one unknown-profit row each.' );

		// The code is registered afterwards and then added to an order created later (e.g. an admin edit).
		$this->code_service()->register( $this->creator, 'nova10' );
		$this->order( 70, '2026-10-05 09:00:00', array( 'nova10' ) );
		$this->sync->sync( 70 );

		self::assertSame( 1, $this->ledger_rows( 70 ), 'The existing rows are never changed or reversed (M2).' );
		self::assertSame( OrderExclusionStatus::Excluded, $this->exclusions->for_order( 70 )[0]->status );
		self::assertSame( array( 1, 1, 1, 1, 1 ), $this->read_models() );
	}

	public function test_an_order_with_settled_rows_becomes_an_open_case_and_its_settled_rows_keep_counting(): void {
		$this->order( 80, '2026-10-02 09:00:00', array() );
		$this->sync->sync( 80 );
		$this->settle( 's:8011:1', 8011, 80 );

		$this->code_service()->register( $this->creator, 'nova10' );
		$this->order( 80, '2026-10-02 09:00:00', array( 'nova10' ) );
		$this->sync->sync( 80 );

		self::assertSame( OrderExclusionStatus::Open, $this->exclusions->for_order( 80 )[0]->status );
		self::assertSame( 'open', $this->audit( AuditAction::OrderExcludedByCreatorCode )[0]['decision'] );
		self::assertTrue( $this->audit( AuditAction::OrderExcludedByCreatorCode )[0]['settled_rows'] );

		$earned = ( new LedgerCreatorPayouts( $this->wpdb ) )->earned( $this->creator, null, self::at( '2026-12-01 00:00:00' ), 14 );
		self::assertSame( 1, $earned['DKK']->unknown_rows, 'The settled row still counts in the balance.' );
		self::assertSame( 1, $this->exclusions->count( OrderExclusionStatus::Open ) );
		self::assertCount( 1, $this->exclusions->page( OrderExclusionStatus::Open, new Pagination( 1, 20 ) ) );
	}

	public function test_the_backfill_reports_an_excluded_order_as_not_counted(): void {
		$this->code_service()->register( $this->creator, 'nova10' );
		$this->order( 90, '2026-10-05 09:00:00', array( 'nova10' ) );

		self::assertSame( SalesBackfillStatus::NotCounted, $this->sync->preview( 90 )->status );
		self::assertSame( SalesBackfillStatus::NotCounted, $this->sync->backfill( 90 )->status );
		self::assertSame( 0, $this->ledger_rows( 90 ) );
		self::assertNotNull( $this->exclusions->for_order( 90 )[0] ?? null );

		// With another creator's line, that line is registered.
		$this->order( 91, '2026-10-05 09:00:00', array( 'nova10' ), array( $this->line( 9111, $this->creator ), $this->line( 9112, $this->other ) ) );

		self::assertSame( SalesBackfillStatus::Registered, $this->sync->preview( 91 )->status );
		self::assertSame( SalesBackfillStatus::Registered, $this->sync->backfill( 91 )->status );
		self::assertSame( array( 's:9112:1' ), $this->keys( 91 ) );
	}

	/**
	 * The service with the test clock.
	 */
	private function code_service(): CreatorCodeService {
		return new CreatorCodeService(
			$this->creators,
			$this->codes,
			$this->coupons,
			$this->service( Transaction::class ),
			$this->service( AuditTrail::class ),
			fn(): DateTimeImmutable => self::at( $this->now )
		);
	}

	/**
	 * What the read models count for creator A: sales rows (statistics),
	 * profit rows, payout rows, unsettled rows and order lines.
	 *
	 * @return list<int>
	 */
	private function read_models(): array {
		$from     = self::at( '2026-01-01 00:00:00' );
		$to       = self::at( '2027-01-01 00:00:00' );
		$sales    = ( new LedgerSalesStatistics( $this->wpdb ) )->totals( $this->creator, $from, $to );
		$profit   = ( new LedgerCreatorProfits( $this->wpdb ) )->totals( $this->creator, $from, $to );
		$payouts  = new LedgerCreatorPayouts( $this->wpdb );
		$earned   = $payouts->earned( $this->creator, null, self::at( '2026-12-01 00:00:00' ), 14 );
		$wpdb     = $this->wpdb;
		$unsettle = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i r' . OrderExclusionRepository::JOIN . ' WHERE ' . OrderExclusionRepository::COUNTS . ' AND r.creator_id = %d', $this->table( Tables::SALES ), OrderExclusionRepository::table_name( $wpdb ), $this->creator ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The join adds the exclusions table placeholder.

		return array(
			$sales[0]->orders ?? 0,
			isset( $profit['DKK'] ) ? $profit['DKK']->unknown_rows : 0,
			isset( $earned['DKK'] ) ? $earned['DKK']->unknown_rows : 0,
			$unsettle,
			( new SalesOrderLines( $this->wpdb ) )->count( $this->creator ),
		);
	}

	/**
	 * Sets an order in a counted status, with creator A's line 11 by default.
	 *
	 * @phpstan-param list<string>|null $coupons
	 * @phpstan-param list<OrderLineSnapshot>|null $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param int                      $id      Order.
	 * @param string                   $created WooCommerce creation (UTC).
	 * @param string[]|null            $coupons Normalized coupon codes, null for unknown.
	 * @param OrderLineSnapshot[]|null $lines   Lines.
	 * @param RefundSnapshot[]         $refunds Refunds.
	 */
	private function order( int $id, string $created, ?array $coupons, ?array $lines = null, array $refunds = array() ): void {
		$this->reader->orders[ $id ] = array(
			'status'       => 'processing',
			'currency'     => 'DKK',
			'date_created' => $created,
			'date_paid'    => $created,
			'lines'        => $lines ?? array( $this->line( $id * 100 + 11, $this->creator ) ),
			'refunds'      => $refunds,
			'coupons'      => $coupons,
		);
	}

	/**
	 * Line 11 of product 20 for a creator.
	 *
	 * @param int $item    Order item.
	 * @param int $creator Creator.
	 */
	private function line( int $item, int $creator ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, 20, 0, 'MB-CODE line', 1, SalesAmount::parse( '100' ), $creator );
	}

	/**
	 * A full refund of line 11.
	 *
	 * @param int $id   Refund id.
	 * @param int $item Order item.
	 */
	private static function refund( int $id, int $item ): RefundSnapshot {
		return new RefundSnapshot( $id, self::at( '2026-10-06 09:00:00' ), array( new RefundLineSnapshot( $item, -1, SalesAmount::parse( '-100' ) ) ) );
	}

	/**
	 * Event keys of an order's ledger rows, in insertion order.
	 *
	 * @param int $order Order.
	 * @return list<string>
	 */
	private function keys( int $order ): array {
		return array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT event_key FROM %i WHERE order_id = %d ORDER BY id', $this->table( Tables::SALES ), $order ) ) );
	}

	/**
	 * Creators of an order's ledger rows, ascending.
	 *
	 * @param int $order Order.
	 * @return list<int>
	 */
	private function ledger_creators( int $order ): array {
		return array_map( 'intval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT creator_id FROM %i WHERE order_id = %d ORDER BY creator_id', $this->table( Tables::SALES ), $order ) ) );
	}

	/**
	 * Number of ledger rows of an order.
	 *
	 * @param int $order Order.
	 */
	private function ledger_rows( int $order ): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', $this->table( Tables::SALES ), $order ) );
	}

	/**
	 * Records a ledger row as settled in a (synthetic) settlement document.
	 *
	 * @param string $event_key Ledger row.
	 * @param int    $item      Its order item.
	 * @param int    $order     Its order.
	 */
	private function settle( string $event_key, int $item, int $order ): void {
		$this->execute(
			'INSERT INTO %i (event_key, document_id, order_id, order_item_id, product_id, type, quantity, net_amount, tax_amount, share_bps, vat_registered, price_decimals, profit_amount, payout_amount, occurred_at) VALUES (%s, 1, %d, %d, 20, %s, 1, %s, %s, 1000, 1, 2, %s, %s, %s)',
			$this->table( Tables::SETTLEMENT_ROWS ),
			$event_key,
			$order,
			$item,
			'sale',
			'100.0000',
			'25.0000',
			'12.5000',
			'12.5000',
			'2026-10-02 09:00:00'
		);
	}

	/**
	 * The contexts of an action's audit records, oldest first.
	 *
	 * @param AuditAction $action Action.
	 * @return list<array<string, mixed>>
	 */
	private function audit( AuditAction $action ): array {
		$contexts = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT context FROM %i WHERE action = %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), $action->value ) );

		return array_map( static fn( ?string $json ): array => (array) json_decode( (string) $json, true ), $contexts );
	}

	/**
	 * Asserts a refusal with a reason.
	 *
	 * @param string   $reason Expected reason.
	 * @param callable $work   Work.
	 */
	private function expect_refusal( string $reason, callable $work ): void {
		try {
			$work();
			self::fail( 'Expected ' . $reason . '.' );
		} catch ( CreatorCodeException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}

	/**
	 * Asserts a DatabaseException.
	 *
	 * @param callable $work Work.
	 */
	private function expect_database_error( callable $work ): void {
		try {
			$work();
			self::fail( 'Expected a database error.' );
		} catch ( DatabaseException ) {
			$this->addToAssertionCount( 1 );
		}
	}

	/**
	 * A UTC time.
	 *
	 * @param string $time MySQL datetime.
	 */
	private static function at( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}
}
