<?php
/**
 * Integration tests for the recalculation of settled payouts (ADR-063).
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
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Admin\PayoutRecalculationActions;
use MerchBoard\Modules\WooCommerce\Admin\PayoutRecalculationExportDownload;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutAdjustmentRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculationExportRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculationExports;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculationRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculations;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\Recalculation;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\RecalculationItem;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\RecalculationRule;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementPdf;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorPricingSettings;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatHistoryRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
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
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * Two creators who are not VAT registered (20 % markup; sales of 100.00
 * incl. VAT, profit 20.00 each), a fixed clock (2026-11-30 12:00 UTC) and a
 * 14-day holding period. Documents issued under ADR-049 are simulated by
 * legacy(): it stores the rows' payout at 80 % and the document's totals
 * and calculation as ADR-049 issued them. Synthetic data only; a passing
 * test is no accounting or legal approval.
 */
final class PayoutRecalculationsTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private PayoutRepository $repository;

	private PayoutRequests $requests;

	private PayoutDecisions $decisions;

	private PayoutBalances $balances;

	private SettlementDocuments $documents;

	private PayoutRecalculations $recalculations;

	/**
	 * The service as wired since ADR-064: no new recalculation.
	 *
	 * @var PayoutRecalculations
	 */
	private PayoutRecalculations $disabled;

	private PayoutRecalculationRepository $runs;

	private PayoutRecalculationExports $exports;

	private PayoutRecalculationExportDownload $download;

	private CreatorRepository $creators;

	private int $alpha;

	private int $beta;

	/**
	 * Product per creator.
	 *
	 * @var array<int, int>
	 */
	private array $products = array();

	private int $next_order = 9301;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->creators = new CreatorRepository( $this->wpdb );
		$markups        = new CreatorMarkupRepository( $this->wpdb );
		$vat            = new CreatorVatRepository( $this->wpdb );
		$this->alpha    = $this->creators->create( 'Recalculation Alpha', CreatorStatus::Active )->id;
		$this->beta     = $this->creators->create( 'Recalculation Beta', CreatorStatus::Active )->id;

		foreach ( array( $this->alpha, $this->beta ) as $creator ) {
			$markups->save( $creator, new MarkupBps( 2000 ) );
			$vat->save( $creator, false );
		}

		$transaction    = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$this->posts    = new ProductPosts( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $this->creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $vat );
		$this->reader   = new FakeOrderReader();
		$this->sync     = new SalesSynchronizer( new SalesRepository( $this->wpdb, $transaction ), new SalesPlanner(), $this->reader, new RecordingFailureMarker(), new RecordingLogger(), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) ) );

		foreach ( array( $this->alpha, $this->beta ) as $creator ) {
			$product = $this->posts->add( 'MB-R2 product ' . $creator, 'publish', array( (string) $creator ) );
			$this->posts->meta( $product, '_regular_price', '100' );
			$this->posts->meta( $product, '_price', '100' );
			$this->products[ $creator ] = $product;
		}

		$clock            = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$audit            = $this->service( AuditTrail::class );
		$adjustments      = new PayoutAdjustmentRepository( $this->wpdb );
		$cipher           = new PayoutCipher( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$this->repository = new PayoutRepository( $this->wpdb );
		$this->balances   = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock, $adjustments );
		$this->documents  = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), $this->creators, $vat, $audit, $transaction, $clock, $adjustments );
		$this->decisions  = new PayoutDecisions( $this->repository, $this->balances, $cipher, $audit, $transaction, $clock, $this->documents );
		$this->requests   = new PayoutRequests( $this->repository, $this->balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock );
		$this->runs       = new PayoutRecalculationRepository( $this->wpdb );
		// New runs enabled: these tests stand for recalculations made before ADR-064 disabled them.
		$this->recalculations = new PayoutRecalculations( $this->runs, $adjustments, $this->repository, $this->creators, $audit, $transaction, $this->wpdb, $clock, true );
		$this->disabled       = new PayoutRecalculations( $this->runs, $adjustments, $this->repository, $this->creators, $audit, $transaction, $this->wpdb, $clock );
		$this->exports        = new PayoutRecalculationExports( $this->runs, new PayoutRecalculationExportRepository( $this->wpdb ), $this->creators, $audit, $transaction, $clock );
		$this->download       = new PayoutRecalculationExportDownload( $this->exports, new RecordingLogger() );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_no_new_recalculation_can_be_started_since_adr_064_but_an_existing_one_can_be_finished(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		// A recalculation made before ADR-064, then rejected.
		$rejected = $this->recalculations->preview();
		$this->recalculations->reject( $rejected, 'Made before ADR-064.' );
		$open   = $this->recalculations->reopen( $rejected );
		$before = $this->state();
		$runs   = $this->count_rows( Tables::RECALCULATIONS );
		$audit  = $this->count_rows( Tables::AUDIT_LOG );

		$this->refused( PayoutException::RECALCULATION_DISABLED, fn() => $this->disabled->preview(), 'No new preview.' );
		$this->refused( PayoutException::RECALCULATION_DISABLED, fn() => $this->disabled->reopen( $rejected ), 'No reopening.' );

		self::assertSame( $before, $this->state(), 'Nothing written.' );
		self::assertSame( array( $runs, $audit ), array( $this->count_rows( Tables::RECALCULATIONS ), $this->count_rows( Tables::AUDIT_LOG ) ) );

		// The open recalculation made before ADR-064 is finished as before, with ADR-063's rule.
		self::assertSame( array( '32.0000', '40.0000', '8.0000' ), self::amounts( $this->runs->items( $open )[0] ) );
		$this->disabled->approve( $open );
		self::assertSame( 1, $this->disabled->execute( $open ) );
		$this->disabled->close( $open );
		self::assertSame( Recalculation::CLOSED, $this->runs->find( $open )?->status );
	}

	public function test_a_later_vat_status_registration_changes_no_recalculation_basis(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$id       = $this->recalculations->preview();
		$computed = $this->recalculations->compute();
		$before   = $this->state();
		$pricing  = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY order_item_id', $this->table( Tables::SALES_LINE_PRICING ) ), ARRAY_A );
		$links    = new ProductCreatorLink( new PostMetaProductStore(), $this->creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$settings = new CreatorPricingSettings( $this->creators, new CreatorMarkupRepository( $this->wpdb ), new ProductMarkupRepository( $this->wpdb ), $links, new PostMetaPricingProductStore(), $this->service( AuditTrail::class ), $this->service( Transaction::class ), new CreatorVatRepository( $this->wpdb ), new CreatorVatHistoryRepository( $this->wpdb ), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) ) );

		// ADR-062 K06: registered as VAT registered from before the sales, after the fact.
		self::assertSame( 'vat_saved', $settings->record_vat_status( $this->alpha, true, '2026-01-01', '2026-11-30', 'CVR 12345678', 'Registered retroactively.' ) );

		self::assertSame( $computed, $this->recalculations->compute(), 'The stored rows decide, never the history.' );
		self::assertSame( $before, $this->state(), 'Documents, rows, payouts and adjustments are unchanged.' );
		self::assertSame( $pricing, $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY order_item_id', $this->table( Tables::SALES_LINE_PRICING ) ), ARRAY_A ), 'The sales keep the status they were made with.' );

		$this->recalculations->approve( $id );
		self::assertSame( 1, $this->recalculations->execute( $id ), 'The approved basis is not stale.' );

		$notices = array_values( array_filter( $this->rows( Tables::NOTIFICATIONS ), static fn( array $row ): bool => 'payout_adjusted' === $row['type'] ) );
		self::assertCount( 1, $notices, 'The creator hears of the executed adjustment.' );
		self::assertSame( array( 'currency', 'reference' ), array_keys( array_diff_key( (array) json_decode( (string) $notices[0]['data'], true ), array( 'amount' => true ) ) ) );
		self::assertSame( 'R2-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ), json_decode( (string) $notices[0]['data'], true )['reference'] ?? null );
	}

	public function test_the_preview_shows_only_the_removed_deduction_and_changes_nothing(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$before = $this->state();

		$id    = $this->recalculations->preview();
		$run   = $this->runs->find( $id );
		$items = $this->runs->items( $id );

		self::assertNotNull( $run );
		self::assertSame( array( 'R2-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ), Recalculation::PREVIEW, RecalculationRule::RULE, 'adr063-adr054-v1' ), array( $run->reference(), $run->status, $run->rule, $run->calculation_version ) );
		self::assertCount( 1, $items );
		self::assertSame( array( RecalculationItem::ACTIVE, RecalculationItem::PROPOSED, 2 ), array( $items[0]->category, $items[0]->status, $items[0]->row_count ) );
		self::assertSame( array( '32.0000', '40.0000', '8.0000' ), self::amounts( $items[0] ), '2 × 16.00 settled; 2 × 20.00 without the deduction.' );
		self::assertSame( array( 'AFR-000001' ), $items[0]->detail['documents'] ?? null );

		$row = $items[0]->detail['rows'][0] ?? array();
		self::assertSame( array( '80.0000', '20.0000', 2000, 2, '20.0000', '16.0000', '20.0000', '4.0000' ), array( $row['net'] ?? null, $row['tax'] ?? null, $row['share_bps'] ?? null, $row['price_decimals'] ?? null, $row['profit'] ?? null, $row['original'] ?? null, $row['recalculated'] ?? null, $row['difference'] ?? null ), 'Every other input as stored.' );

		self::assertSame( $before, $this->state(), 'A preview changes no document, row, payout or adjustment.' );
		self::assertSame( '22.0000', $this->balance( $this->alpha ), '32 − 10: the balance is unchanged.' );
		self::assertCount( 1, $this->audit( AuditAction::PayoutRecalculationPreviewed ) );
	}

	public function test_approval_and_execution_are_separate_and_the_next_document_settles_the_adjustment(): void {
		$this->earn( $this->alpha, 2 );
		$first = $this->pay( $this->alpha, '10' );
		$this->legacy( $first );
		$id     = $this->recalculations->preview();
		$before = $this->state();

		$this->refused( PayoutException::RECALCULATION_STATE, fn() => $this->recalculations->execute( $id ), 'Not approved yet.' );

		$this->recalculations->approve( $id, 'Checked.' );
		self::assertSame( Recalculation::APPROVED, $this->runs->find( $id )?->status );
		self::assertSame( $before, $this->state(), 'Approving executes nothing.' );
		self::assertSame( '22.0000', $this->balance( $this->alpha ) );

		self::assertSame( 1, $this->recalculations->execute( $id ) );
		self::assertSame( Recalculation::EXECUTED, $this->runs->find( $id )?->status );
		self::assertSame( RecalculationItem::EXECUTED, $this->runs->items( $id )[0]->status );
		self::assertSame( '30.0000', $this->balance( $this->alpha ), '22 + 8.' );
		self::assertSame( array( 1, 2 ), array( $this->count_rows( Tables::PAYOUT_ADJUSTMENTS ), $this->count_rows( Tables::PAYOUT_ADJUSTMENT_ROWS ) ) );
		self::assertSame( $before['documents'], $this->state()['documents'], 'The issued document is unchanged.' );
		self::assertSame( $before['rows'], $this->state()['rows'] );
		self::assertSame( $before['payouts'], $this->state()['payouts'] );
		self::assertCount( 1, $this->audit( AuditAction::PayoutAdjustmentExecuted ) );

		// The next document settles the adjustment; the new sale is paid excl. VAT (ADR-064): 22 + 16 + 8 − 5 = 41.
		$this->earn( $this->alpha, 1 );
		$second = $this->pay( $this->alpha, '5' );
		$shown  = $this->documents->for_payout( $second->id );

		self::assertNotNull( $shown );
		$document = $shown['document'];
		self::assertSame( array( '22.0000', '16.0000', '8.0000', '5.0000', '41.0000' ), array_map( array( SalesAmount::class, 'format' ), array( $document->opening_units, $document->commission_units, $document->adjustment_units, $document->payout_units, $document->closing_units ) ) );
		self::assertSame( array( 'R2-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ) ), SettlementPdf::adjustment_references( $document ) );
		self::assertSame( '41.0000', $this->balance( $this->alpha ), 'The chain and the balance agree.' );
		self::assertSame( $document->id, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT settled_document_id FROM %i', $this->table( Tables::PAYOUT_ADJUSTMENTS ) ) ) );
		self::assertStringStartsWith( '%PDF-', ( new SettlementPdf() )->render( $shown ), 'The document adds up with its adjustment.' );

		// A later document settles nothing again.
		$third = $this->pay( $this->alpha, '1' );
		self::assertSame( 0, $this->documents->for_payout( $third->id )['document']->adjustment_units ?? null );

		$this->recalculations->close( $id );
		self::assertSame( Recalculation::CLOSED, $this->runs->find( $id )?->status );
		$this->refused( PayoutException::RECALCULATION_STATE, fn() => $this->recalculations->execute( $id ) );
	}

	public function test_history_is_kept_rejections_are_never_reopened_automatically_and_nothing_is_regulated_twice(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );

		$first = $this->recalculations->preview();
		$this->refused( PayoutException::RECALCULATION_OPEN, fn() => $this->recalculations->preview(), 'One open recalculation at a time.' );
		$this->refused( PayoutException::INVALID_NOTE, fn() => $this->recalculations->reject( $first, '  ' ), 'A rejection needs a reason.' );
		$this->recalculations->reject( $first, 'Wrong period.' );

		self::assertSame( array( Recalculation::REJECTED, 'Wrong period.' ), array( $this->runs->find( $first )?->status, $this->runs->find( $first )?->decision_note ) );

		$second = $this->recalculations->preview();
		self::assertSame( Recalculation::REJECTED, $this->runs->find( $first )?->status, 'A new preview does not reopen the rejected one.' );
		self::assertNull( $this->runs->find( $second )?->reopens_id );
		$this->recalculations->reject( $second, 'Again.' );

		$third = $this->recalculations->reopen( $first );
		self::assertSame( array( $first, Recalculation::PREVIEW ), array( $this->runs->find( $third )?->reopens_id, $this->runs->find( $third )?->status ) );
		self::assertSame( Recalculation::REJECTED, $this->runs->find( $first )?->status );
		self::assertCount( 1, $this->audit( AuditAction::PayoutRecalculationReopened ) );

		$this->recalculations->approve( $third );
		$this->recalculations->execute( $third );
		$this->recalculations->close( $third );

		$fourth = $this->recalculations->preview();
		self::assertSame( array(), $this->runs->items( $fourth ), 'Regulated rows are never proposed again.' );
		$this->recalculations->approve( $fourth );
		self::assertSame( 0, $this->recalculations->execute( $fourth ) );
		self::assertSame( 1, $this->count_rows( Tables::PAYOUT_ADJUSTMENTS ) );
		self::assertSame( 4, $this->runs->count(), 'Every recalculation is kept.' );
	}

	public function test_a_changed_basis_needs_a_new_preview_and_approval(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$id = $this->recalculations->preview();

		// Another settled row after the preview: approval is refused.
		$this->earn( $this->alpha, 1 );
		$this->legacy( $this->pay( $this->alpha, '1' ) );
		$this->refused( PayoutException::RECALCULATION_STALE, fn() => $this->recalculations->approve( $id ) );
		$this->recalculations->reject( $id, 'Stale.' );

		$id = $this->recalculations->preview();
		$this->recalculations->approve( $id );
		$before = $this->state();

		// The creator becomes inactive after the approval: nothing is executed.
		$this->creators->set_status( $this->alpha, CreatorStatus::Inactive );
		$this->refused( PayoutException::RECALCULATION_STALE, fn() => $this->recalculations->execute( $id ) );
		self::assertSame( $before, $this->state() );
		self::assertSame( Recalculation::APPROVED, $this->runs->find( $id )?->status );
	}

	public function test_former_creators_are_reported_and_never_paid_or_offset(): void {
		$this->earn( $this->beta, 2 );
		$this->legacy( $this->pay( $this->beta, '10' ) );
		$this->creators->set_status( $this->beta, CreatorStatus::Inactive );
		$balance = $this->balance( $this->beta );

		$id    = $this->recalculations->preview();
		$items = $this->runs->items( $id );

		self::assertSame( array( RecalculationItem::FORMER, RecalculationItem::REPORTED, '8.0000' ), array( $items[0]->category, $items[0]->status, SalesAmount::format( (int) $items[0]->difference_units ) ) );

		$this->recalculations->approve( $id );
		self::assertSame( 0, $this->recalculations->execute( $id ) );
		self::assertSame( RecalculationItem::REPORTED, $this->runs->items( $id )[0]->status );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUT_ADJUSTMENTS ) );
		self::assertSame( $balance, $this->balance( $this->beta ) );
	}

	public function test_data_that_cannot_be_recalculated_safely_needs_a_manual_assessment(): void {
		// Alpha: a settled row that does not reproduce from its stored inputs.
		$this->earn( $this->alpha, 2 );
		$document = $this->legacy( $this->pay( $this->alpha, '10' ) );
		$this->write( $this->wpdb->prepare( "UPDATE %i SET profit_amount = '19.9900' WHERE document_id = %d LIMIT 1", $this->table( Tables::SETTLEMENT_ROWS ), $document ) );

		// Beta: a completed payout without a settlement document (before ADR-060).
		$this->earn( $this->beta, 1 );
		$payout = $this->pay( $this->beta, '5' );
		$this->write( $this->wpdb->prepare( 'DELETE r, d FROM %i d LEFT JOIN %i r ON r.document_id = d.id WHERE d.settlement_payout_id = %d', $this->table( Tables::SETTLEMENT_DOCUMENTS ), $this->table( Tables::SETTLEMENT_ROWS ), $payout->id ) );

		$id    = $this->recalculations->preview();
		$items = $this->runs->items( $id );

		self::assertCount( 2, $items );
		self::assertSame( array( RecalculationItem::MANUAL, RecalculationItem::REASON_INCONSISTENT_ROW, RecalculationItem::NEEDS_REVIEW ), array( $items[0]->category, $items[0]->reason, $items[0]->status ) );
		self::assertSame( array( null, null, null ), array( $items[0]->original_units, $items[0]->recalculated_units, $items[0]->difference_units ), 'Never guessed.' );
		self::assertSame( array( RecalculationItem::REASON_UNDOCUMENTED_PAYOUTS, 1 ), array( $items[1]->reason, $items[1]->detail['undocumented_payouts'] ?? null ) );

		$this->recalculations->approve( $id );
		self::assertSame( 0, $this->recalculations->execute( $id ) );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUT_ADJUSTMENTS ) );
	}

	public function test_a_negative_difference_is_offset_and_the_rest_is_carried_forward(): void {
		// Three sales settled (16.00 each excl. VAT, ADR-064); then two refunds settled under ADR-049 (−16 each instead of R2's −20).
		$items = $this->earn( $this->alpha, 3 );
		$this->pay( $this->alpha, '1' );
		$this->refund( $this->alpha, $items[0], 9701 );
		$this->refund( $this->alpha, $items[1], 9702 );
		// The creator withdraws everything available: 48 − 1 − 32 = 15.
		self::assertSame( '15.0000', $this->balance( $this->alpha ) );
		$this->legacy( $this->pay( $this->alpha, '15' ) );
		self::assertSame( '0.0000', $this->balance( $this->alpha ) );

		$id = $this->recalculations->preview();
		self::assertSame( array( '-32.0000', '-40.0000', '-8.0000' ), self::amounts( $this->runs->items( $id )[0] ) );
		$this->recalculations->approve( $id );
		$this->recalculations->execute( $id );

		$balance = $this->balances->in_currency( $this->alpha, 'DKK' );
		self::assertSame( array( 0, 80000 ), array( $balance->available_units(), $balance->outstanding_units() ), 'Nothing available to offset now: 8.00 is carried forward as outstanding.' );

		// A new sale (16.00 excl. VAT) is offset against the outstanding amount first.
		$this->earn( $this->alpha, 1 );
		self::assertSame( '8.0000', $this->balance( $this->alpha ), '16 − 8.' );
		$document = $this->documents->for_payout( $this->pay( $this->alpha, '8' )->id )['document'] ?? null;
		self::assertNotNull( $document );
		self::assertSame( array( '0.0000', '16.0000', '-8.0000', '8.0000', '0.0000' ), array_map( array( SalesAmount::class, 'format' ), array( $document->opening_units, $document->commission_units, $document->adjustment_units, $document->payout_units, $document->closing_units ) ) );
	}

	public function test_a_failed_item_stops_the_execution_and_a_retry_never_regulates_twice(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$this->earn( $this->beta, 2 );
		$this->legacy( $this->pay( $this->beta, '10' ) );
		$id = $this->recalculations->preview();
		$this->recalculations->approve( $id );
		$items = $this->runs->items( $id );
		self::assertSame( array( $this->alpha, $this->beta ), array( $items[0]->creator_id, $items[1]->creator_id ) );

		// Beta's item cannot be written (its UNIQUE key is taken); alpha's is executed first.
		$this->wpdb->insert(
			$this->table( Tables::PAYOUT_ADJUSTMENTS ),
			array(
				'creator_id'            => $this->beta,
				'currency'              => 'DKK',
				'amount'                => '0.0000',
				'rule'                  => 'test',
				'recalculation_id'      => 0,
				'recalculation_item_id' => $items[1]->id,
				'created_at'            => '2026-11-30 12:00:00',
			)
		);
		$blocker = (int) $this->wpdb->insert_id;
		$this->wpdb->suppress_errors( true );
		$this->refused( PayoutException::RECALCULATION_ITEM_FAILED, fn() => $this->recalculations->execute( $id ) );
		$this->wpdb->suppress_errors( false );

		$items = $this->runs->items( $id );
		self::assertSame( Recalculation::PARTIALLY_EXECUTED, $this->runs->find( $id )?->status );
		self::assertSame( array( RecalculationItem::EXECUTED, RecalculationItem::FAILED, 'database' ), array( $items[0]->status, $items[1]->status, $items[1]->failure ) );
		self::assertSame( 2, $this->count_rows( Tables::PAYOUT_ADJUSTMENT_ROWS ), 'Nothing of the failed item was written.' );
		self::assertSame( array( '30.0000', '22.0000' ), array( $this->balance( $this->alpha ), $this->balance( $this->beta ) ) );
		self::assertCount( 1, $this->audit( AuditAction::PayoutRecalculationItemFailed ) );

		// The cause is removed; the retry is an explicit action and executes only beta.
		$this->wpdb->delete( $this->table( Tables::PAYOUT_ADJUSTMENTS ), array( 'id' => $blocker ) );
		self::assertSame( 1, $this->recalculations->execute( $id ) );
		self::assertSame( Recalculation::EXECUTED, $this->runs->find( $id )?->status );
		self::assertSame( array( 2, 4 ), array( $this->count_rows( Tables::PAYOUT_ADJUSTMENTS ), $this->count_rows( Tables::PAYOUT_ADJUSTMENT_ROWS ) ) );
		self::assertSame( array( '30.0000', '30.0000' ), array( $this->balance( $this->alpha ), $this->balance( $this->beta ) ), 'Alpha is not regulated twice.' );
	}

	public function test_an_export_has_a_manifest_checksums_and_its_complete_scope(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$id = $this->recalculations->preview();
		$this->recalculations->approve( $id );
		$this->recalculations->execute( $id );
		$before = $this->state();

		$export = $this->exports->create( $id );
		$files  = $this->stored( $export );
		$ref    = PayoutRecalculationExports::reference( $id, $export );

		self::assertSame( array( 'items.csv', 'rows.csv', 'timeline.csv', 'manifest.json', 'SHA256SUMS' ), array_keys( $files ) );
		self::assertSame( $before, $this->state(), 'An export changes no document, row, payout or adjustment.' );
		self::assertSame( array_fill_keys( array_keys( $files ), 'ok' ), $this->exports->verify( $export ) );

		$manifest = json_decode( $files['manifest.json'], true );
		self::assertIsArray( $manifest );
		self::assertSame( array( 'merchboard-recalculation-export', 1, $ref ), array( $manifest['format'], $manifest['format_version'], $manifest['export'] ) );
		self::assertSame( array( 'reference' => Recalculation::reference_of( $id ), 'status' => Recalculation::EXECUTED ), array_intersect_key( $manifest['recalculation'], array_flip( array( 'reference', 'status' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( true, 1, 1, 2 ), array( $manifest['scope']['complete'], $manifest['scope']['items_total'], $manifest['scope']['items_exported'], $manifest['scope']['rows_exported'] ) );
		self::assertSame( array( 'group' => null, 'creator_id' => null ), $manifest['scope']['filters'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( '40', $manifest['versions']['schema'] );
		self::assertSame( array( 'adr063-remove-20', 'adr049-adr054-v1' ), array( $manifest['versions']['recalculation'], $manifest['versions']['legacy_payouts'] ) );
		self::assertNull( $manifest['previous_export'] );
		self::assertStringContainsString( 'sha256sum -c SHA256SUMS', $manifest['verification'] );
		self::assertStringContainsString( 'The legal right to offset', implode( ' ', $manifest['limitations'] ) );

		// The manifest lists the data files; SHA256SUMS lists them and the manifest (no circular checksum).
		$sums = PayoutRecalculationExports::parse_sums( $files['SHA256SUMS'] );
		self::assertSame( array( 'items.csv', 'rows.csv', 'timeline.csv', 'manifest.json' ), array_keys( $sums ) );

		foreach ( $manifest['files'] as $listed ) {
			self::assertSame( hash( 'sha256', $files[ $listed['name'] ] ), $listed['sha256'] );
			self::assertSame( $listed['sha256'], $sums[ $listed['name'] ] );
		}

		self::assertSame( hash( 'sha256', $files['manifest.json'] ), $sums['manifest.json'] );
		self::assertStringContainsString( ',32.0000,40.0000,8.0000,', $files['items.csv'] );
		self::assertStringContainsString( 'Recalculation Alpha', $files['items.csv'] );
		self::assertSame( 3, substr_count( $files['rows.csv'], "\n" ), 'Header and two rows.' );
		self::assertSame( array( 'payout_recalculation_previewed', 'payout_recalculation_approved', 'payout_adjustment_executed', 'payout_recalculation_executed' ), $this->timeline_actions( $files['timeline.csv'] ), 'Lifecycle only; exports are not part of it.' );
		self::assertCount( 1, $this->audit( AuditAction::PayoutRecalculationExported ) );
	}

	public function test_a_filtered_export_is_marked_as_not_complete_and_an_invalid_filter_stores_nothing(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$this->earn( $this->beta, 2 );
		$this->legacy( $this->pay( $this->beta, '10' ) );
		$id = $this->recalculations->preview();

		$manifest = json_decode( $this->stored( $this->exports->create( $id, null, $this->beta ) )['manifest.json'], true );

		self::assertIsArray( $manifest );
		self::assertSame( array( false, 2, 1, 2 ), array( $manifest['scope']['complete'], $manifest['scope']['items_total'], $manifest['scope']['items_exported'], $manifest['scope']['rows_exported'] ) );
		self::assertSame( $this->beta, $manifest['scope']['filters']['creator_id'] );
		self::assertStringStartsWith( 'Filtered export', $manifest['limitations'][0] );

		$this->refused( PayoutException::INVALID_FILTER, fn() => $this->exports->create( $id, 'everything' ) );
		$this->refused( PayoutException::NOT_FOUND, fn() => $this->exports->create( 999 ) );
		self::assertSame( 1, $this->count_rows( Tables::RECALCULATION_EXPORTS ) );
	}

	public function test_earlier_exports_are_kept_and_compared(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$id    = $this->recalculations->preview();
		$first = $this->exports->create( $id );
		$kept  = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( Tables::RECALCULATION_EXPORTS ), $first ), ARRAY_A );

		$this->recalculations->approve( $id );
		$this->recalculations->execute( $id );
		$second   = $this->exports->create( $id );
		$manifest = json_decode( $this->stored( $second )['manifest.json'], true );

		self::assertIsArray( $manifest );
		self::assertSame( PayoutRecalculationExports::reference( $id, $first ), $manifest['previous_export'] );
		self::assertSame(
			array(
				'items.csv'    => 'changed',
				'rows.csv'     => 'unchanged',
				'timeline.csv' => 'changed',
			),
			$manifest['changes_since_previous']
		);
		self::assertSame( $kept, $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( Tables::RECALCULATION_EXPORTS ), $first ), ARRAY_A ), 'The earlier export is unchanged.' );

		$third = json_decode( $this->stored( $this->exports->create( $id ) )['manifest.json'], true );
		self::assertIsArray( $third );
		self::assertSame( array_fill_keys( PayoutRecalculationExports::DATA_FILES, 'unchanged' ), $third['changes_since_previous'], 'Nothing changed since the second export.' );
		self::assertSame( 3, $this->count_rows( Tables::RECALCULATION_EXPORTS ) );
	}

	public function test_a_download_is_verified_audited_and_refused_when_a_file_changed_or_is_missing(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$export = $this->exports->create( $this->recalculations->preview() );
		$files  = $this->stored( $export );

		$response = $this->download->response( $this->request( $export, 'items.csv' ) );
		self::assertSame( 200, $response['status'] );
		self::assertSame( $files['items.csv'], $response['body'] );
		self::assertSame( array( 'text/csv; charset=utf-8', 'no-store, private', 'nosniff' ), array( $response['headers']['Content-Type'], $response['headers']['Cache-Control'], $response['headers']['X-Content-Type-Options'] ) );
		self::assertStringStartsWith( 'attachment; filename="R2-', $response['headers']['Content-Disposition'] );
		self::assertCount( 1, $this->audit( AuditAction::PayoutRecalculationExportDownloaded ) );

		// A changed file: the export no longer passes, and nothing is delivered.
		$changed              = $files;
		$changed['items.csv'] = str_replace( '8.0000', '80.0000', $changed['items.csv'] );
		$this->wpdb->update( $this->table( Tables::RECALCULATION_EXPORTS ), array( 'files' => (string) wp_json_encode( $changed ) ), array( 'id' => $export ) );
		self::assertSame( 'changed', $this->exports->verify( $export )['items.csv'] );
		self::assertSame( 409, $this->download->response( $this->request( $export, 'rows.csv' ) )['status'], 'No file of an export that does not pass.' );

		// A missing file.
		unset( $files['rows.csv'] );
		$this->wpdb->update( $this->table( Tables::RECALCULATION_EXPORTS ), array( 'files' => (string) wp_json_encode( $files ) ), array( 'id' => $export ) );
		self::assertSame( 'missing', $this->exports->verify( $export )['rows.csv'] );
		self::assertSame( 409, $this->download->response( $this->request( $export, 'items.csv' ) )['status'] );
		self::assertCount( 1, $this->audit( AuditAction::PayoutRecalculationExportDownloaded ), 'Refused downloads are not audited as downloads.' );
	}

	public function test_exports_need_the_capability_and_a_valid_link(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$id     = $this->recalculations->preview();
		$export = $this->exports->create( $id );

		$invalid           = $this->request( $export, 'items.csv' );
		$invalid['file']   = 'manifest.json';
		$other             = $this->request( $export, 'items.csv' );
		$other['_wpnonce'] = 'invalid';
		self::assertSame( 403, $this->download->response( $invalid )['status'], 'The nonce is bound to the file.' );
		self::assertSame( 403, $this->download->response( $other )['status'] );

		wp_set_current_user( $this->create_user( 'mb-r2-subscriber' ) );
		$this->refused( PayoutException::NOT_AUTHORIZED, fn() => $this->exports->create( $id ) );
		self::assertSame( 403, $this->download->response( $this->request( $export, 'items.csv' ) )['status'] );
		self::assertSame( 1, $this->count_rows( Tables::RECALCULATION_EXPORTS ) );
		self::assertCount( 0, $this->audit( AuditAction::PayoutRecalculationExportDownloaded ) );
	}

	public function test_a_failed_export_stores_nothing_and_reports_no_success(): void {
		$this->earn( $this->alpha, 2 );
		$this->legacy( $this->pay( $this->alpha, '10' ) );
		$id      = $this->recalculations->preview();
		$table   = $this->table( Tables::RECALCULATION_EXPORTS );
		$failing = static fn( string $sql ): string => str_starts_with( $sql, "UPDATE `{$table}` SET files" ) ? 'UPDATE mbtest_this_table_does_not_exist SET x = 1' : $sql;
		add_filter( 'query', $failing );
		$this->wpdb->suppress_errors( true );
		$actions = new PayoutRecalculationActions( $this->recalculations, new RecordingLogger(), $this->exports );

		try {
			$url = $actions->handle(
				array(
					'action'           => PayoutRecalculationActions::EXPORT_ACTION,
					'recalculation_id' => (string) $id,
					'_wpnonce'         => wp_create_nonce( PayoutRecalculationActions::nonce_action( PayoutRecalculationActions::EXPORT_ACTION, $id ) ),
					'group'            => '',
					'creator_id'       => '',
				)
			);
		} finally {
			remove_filter( 'query', $failing );
			$this->wpdb->suppress_errors( false );
		}

		self::assertStringContainsString( 'result=error', $url, 'The failure is reported, never success.' );
		self::assertSame( 0, $this->count_rows( Tables::RECALCULATION_EXPORTS ), 'The reserved export was rolled back.' );
		self::assertCount( 0, $this->audit( AuditAction::PayoutRecalculationExported ) );
	}

	/**
	 * The stored files of an export.
	 *
	 * @param int $export Export id.
	 * @return array<string, string>
	 */
	private function stored( int $export ): array {
		$json  = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT files FROM %i WHERE id = %d', $this->table( Tables::RECALCULATION_EXPORTS ), $export ) );
		$files = json_decode( is_string( $json ) ? $json : '', true );
		self::assertIsArray( $files );

		return array_map( 'strval', $files );
	}

	/**
	 * A download request with a valid nonce.
	 *
	 * @param int    $export Export id.
	 * @param string $file   File name.
	 * @return array<string, string>
	 */
	private function request( int $export, string $file ): array {
		return array(
			'export_id' => (string) $export,
			'file'      => $file,
			'_wpnonce'  => wp_create_nonce( PayoutRecalculationExportDownload::nonce_action( $export, $file ) ),
		);
	}

	/**
	 * The actions of a timeline file, in order.
	 *
	 * @param string $csv Content of timeline.csv.
	 * @return list<string>
	 */
	private function timeline_actions( string $csv ): array {
		$lines = array_slice( array_values( array_filter( explode( "\n", $csv ) ) ), 1 );

		return array_map( static fn( string $line ): string => explode( ',', $line )[1] ?? '', $lines );
	}

	/**
	 * Sales of 100.00 incl. VAT (profit 20.00 each) for a creator.
	 *
	 * @param int $creator Creator.
	 * @param int $count   Number of sales.
	 * @return list<int> Order item ids.
	 */
	private function earn( int $creator, int $count ): array {
		$items = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$order = $this->next_order++;
			$item  = 1000 + $order;
			$this->recorder->record( $item, $order, $this->products[ $creator ], 0, 'DKK' );
			$this->reader->orders[ $order ] = array(
				'status'    => 'processing',
				'currency'  => 'DKK',
				'date_paid' => '2026-09-01 10:05:00',
				'lines'     => array( new OrderLineSnapshot( $item, $this->products[ $creator ], 0, 'MB-R2 line', 1, SalesAmount::parse( '80' ), $creator, SalesAmount::parse( '20' ) ) ),
				'refunds'   => array(),
			);
			$this->sync->sync( $order );
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Refunds the one line of an order (counts at once, D2).
	 *
	 * @param int $creator Creator.
	 * @param int $item    Order item.
	 * @param int $refund  Refund id.
	 */
	private function refund( int $creator, int $item, int $refund ): void {
		$order                                     = $item - 1000;
		$this->reader->orders[ $order ]['refunds'] = array( new RefundSnapshot( $refund, new DateTimeImmutable( '2026-11-29 10:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( $item, -1, SalesAmount::parse( '-80' ), SalesAmount::parse( '-20' ) ) ) ) );
		$this->sync->sync( $order );
		self::assertSame( $creator, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT creator_id FROM %i WHERE order_item_id = %d LIMIT 1', $this->table( Tables::SALES ), $item ) ) );
	}

	/**
	 * Requests and completes a bank payout.
	 *
	 * @param int    $creator Creator.
	 * @param string $amount  Amount.
	 */
	private function pay( int $creator, string $amount ): Payout {
		$payout = $this->requests->request( $creator, 'DKK', $amount, 'bank', array( 'registration' => '1234', 'account' => '0012345678' ), 'key-r2-' . $this->next_order++, 1 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		return $this->decisions->complete( $payout->id, 'Bank' );
	}

	/**
	 * Turns a payout's document into one issued under ADR-049: the rows'
	 * payout at 80 % of their profit, the totals and the calculation to match.
	 *
	 * @param Payout $payout Completed payout.
	 * @return int Document id.
	 */
	private function legacy( Payout $payout ): int {
		$document = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $document );
		$rows       = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT event_key, profit_amount FROM %i WHERE document_id = %d', $this->table( Tables::SETTLEMENT_ROWS ), $document->id ), ARRAY_A );
		$commission = 0;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$payout_units = PricingMath::share( SalesAmount::parse( (string) $row['profit_amount'] ), RecalculationRule::LEGACY_PAYOUT_BPS, 2 );
			$commission   = SalesAmount::add( $commission, $payout_units );
			$this->wpdb->update( $this->table( Tables::SETTLEMENT_ROWS ), array( 'payout_amount' => SalesAmount::format( $payout_units ) ), array( 'event_key' => (string) $row['event_key'] ) );
		}

		$totals = array(
			'commission_amount'   => SalesAmount::format( $commission ),
			'closing_balance'     => SalesAmount::format( $document->opening_units + $commission + $document->adjustment_units - $document->payout_units ),
			'calculation_version' => RecalculationRule::LEGACY_CALCULATION,
		);
		$this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), $totals, array( 'id' => $document->id ) );

		return $document->id;
	}

	/**
	 * A creator's DKK balance, formatted.
	 *
	 * @param int $creator Creator.
	 */
	private function balance( int $creator ): string {
		return SalesAmount::format( (int) $this->balances->in_currency( $creator, 'DKK' )->balance_units() );
	}

	/**
	 * Original, recalculated and difference of an item, formatted.
	 *
	 * @param RecalculationItem $item Item.
	 * @return list<string>
	 */
	private static function amounts( RecalculationItem $item ): array {
		return array_map( static fn( ?int $units ): string => SalesAmount::format( (int) $units ), array( $item->original_units, $item->recalculated_units, $item->difference_units ) );
	}

	/**
	 * The documents, rows, payouts and adjustments as stored.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$wpdb = $this->wpdb;

		return array(
			'documents'   => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $this->table( Tables::SETTLEMENT_DOCUMENTS ) ), ARRAY_A ),
			'rows'        => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY event_key', $this->table( Tables::SETTLEMENT_ROWS ) ), ARRAY_A ),
			'payouts'     => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $this->table( Tables::PAYOUTS ) ), ARRAY_A ),
			'adjustments' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $this->table( Tables::PAYOUT_ADJUSTMENTS ) ), ARRAY_A ),
		);
	}

	/**
	 * Runs a prepared write of the test's own setup.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 */
	private function write( ?string $sql ): void {
		self::assertIsString( $sql );
		self::assertNotFalse( $this->wpdb->query( $sql ) );
	}

	/**
	 * Requires a refusal with a reason.
	 *
	 * @param string   $reason  Reason.
	 * @param callable $call    Call.
	 * @param string   $message Message.
	 */
	private function refused( string $reason, callable $call, string $message = '' ): void {
		try {
			$call();
			self::fail( 'Expected a refusal: ' . $reason . ' ' . $message );
		} catch ( PayoutException $e ) {
			self::assertSame( $reason, $e->reason(), $message );
		}
	}

	/**
	 * Contexts of the audit records of an action.
	 *
	 * @param AuditAction $action Action.
	 * @return list<mixed>
	 */
	private function audit( AuditAction $action ): array {
		return $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT context FROM %i WHERE action = %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), $action->value ) );
	}
}
