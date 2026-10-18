<?php
/**
 * Settlement documents on a real database (ADR-060, D5, D8–D11).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Pdf\PdfWriter;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\PayoutActions;
use MerchBoard\Modules\WooCommerce\Admin\PayoutsPage;
use MerchBoard\Modules\WooCommerce\Admin\SettlementPdfDownload;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CommissionVat;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetailsStore;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementCorrection;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementPdf;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\VatDeduction;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorPricingSettings;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatHistoryRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Rest\MePayoutsController;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderSettledException;
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
use UnexpectedValueException;
use wpdb;

/**
 * Sales of 100.00 incl. VAT (20 %, VAT registered: payout 20.00 each) dated
 * 2026-09-01; a fixed clock (2026-11-30 12:00 UTC) and a 14-day holding
 * period. No WooCommerce is loaded. Synthetic data only. The documents are
 * not approved by an auditor or the Danish Tax Agency (R1–R4): a passing
 * test is no accounting approval.
 */
final class SettlementDocumentsTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesRepository $sales;

	private PayoutRepository $repository;

	private PayoutRequests $requests;

	private PayoutDecisions $decisions;

	private PayoutSettings $settings;

	private SettlementDocuments $documents;

	private CreatorMarkupRepository $markups;

	private CreatorVatRepository $vat;

	private int $creator;

	private int $product;

	private int $next_order = 9101;

	private string $key;

	/**
	 * Second database connection of the concurrency tests (closed in tearDown()).
	 *
	 * @var wpdb|null
	 */
	private ?wpdb $second = null;

	/**
	 * Seconds the last while_waiting() work ran.
	 *
	 * @var float
	 */
	private float $waited = 0.0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( Settings::OPTION );

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Settlement Alpha', CreatorStatus::Active )->id;
		$this->markups = new CreatorMarkupRepository( $this->wpdb );
		$this->vat     = new CreatorVatRepository( $this->wpdb );
		$this->markups->save( $this->creator, new MarkupBps( 2000 ) );
		$this->vat->save( $this->creator, true );

		$transaction    = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$this->posts    = new ProductPosts( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $this->markups, new ProductMarkupRepository( $this->wpdb ) ), new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $this->vat );
		$this->reader   = new FakeOrderReader();
		$this->sales    = new SalesRepository( $this->wpdb, $transaction );
		$this->sync     = new SalesSynchronizer( $this->sales, new SalesPlanner(), $this->reader, new RecordingFailureMarker(), new RecordingLogger(), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$this->product  = $this->posts->add( 'MB-SET product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $this->product, '_regular_price', '100' );
		$this->posts->meta( $this->product, '_price', '100' );

		$clock            = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$audit            = $this->service( AuditTrail::class );
		$this->key        = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$cipher           = new PayoutCipher( $this->key );
		$this->repository = new PayoutRepository( $this->wpdb );
		$balances         = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock );
		$billing          = new CreatorBillingDetailsStore( new CreatorMetaStore( $this->wpdb ) );
		$this->documents  = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), $creators, $this->vat, $audit, $transaction, $clock, null, $billing );
		$this->decisions  = new PayoutDecisions( $this->repository, $balances, $cipher, $audit, $transaction, $clock, $this->documents );
		$this->requests   = new PayoutRequests( $this->repository, $balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock );
		$this->settings   = new PayoutSettings( $this->repository, $audit, $transaction, $clock, $billing );
	}

	protected function tearDown(): void {
		$this->second?->close();
		$this->posts->remove_all();
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_a_completion_issues_one_document_with_the_released_rows(): void {
		$items  = $this->earn( 3 );
		$payout = $this->request( '25', 'key-set-0001' );

		self::assertNull( $this->documents->for_payout( $payout->id ), 'No document while pending.' );

		$this->decisions->complete( $payout->id, 'Bank 42' );
		$shown    = $this->documents->for_payout( $payout->id );
		$document = $shown['document'] ?? null;

		self::assertInstanceOf( SettlementDocument::class, $document );
		self::assertSame( 'AFR-000001', $document->number );
		self::assertSame( SettlementDocument::TYPE_SETTLEMENT, $document->type );
		self::assertSame( array( $this->creator, $payout->id, 'DKK' ), array( $document->creator_id, $document->payout_id, $document->currency ) );
		self::assertSame( array( '60.0000', '0.0000', '25.0000', '35.0000' ), array_map( array( SalesAmount::class, 'format' ), array( $document->commission_units, $document->opening_units, $document->payout_units, $document->closing_units ) ) );
		self::assertTrue( $document->vat_registered );
		self::assertNull( $document->period_from, 'Without a settlement start: the whole history.' );
		self::assertSame( '2026-11-30 12:00:00', $document->period_to->format( 'Y-m-d H:i:s' ) );
		self::assertSame( 6, $document->template_version, 'New documents carry the note on tax and reporting, the VAT on the commission, the master data and the VAT deduction.' );
		self::assertFalse( $document->snapshot['preliminary'] ?? null );
		self::assertSame( 'Settlement Alpha', $document->snapshot['creator']['name'] ?? null );
		self::assertSame( '•••• 5678', $document->snapshot['payout']['destination_hint'] ?? null );
		self::assertStringNotContainsString( '0012345678', (string) wp_json_encode( $document->snapshot ), 'Never the full account.' );
		self::assertSame( $items, array_map( static fn( array $row ): int => (int) $row['order_item_id'], $shown['rows'] ?? array() ) );
		self::assertSame( PayoutStatus::Completed, $this->repository->find( $payout->id )?->status );
		self::assertSame( array( array( 'document_id' => $document->id, 'document_number' => 'AFR-000001', 'payout_id' => $payout->id, 'rows' => 3, 'adjustments' => 0, 'currency' => 'DKK' ) ), $this->audit( AuditAction::SettlementDocumentIssued ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// Again (double click): the completed payout and its document unchanged, no new document.
		$this->decisions->complete( $payout->id, 'Bank 42' );
		self::assertSame( 1, $this->count_rows( Tables::SETTLEMENT_DOCUMENTS ) );
		self::assertCount( 1, $this->audit( AuditAction::SettlementDocumentIssued ) );
	}

	public function test_the_next_document_settles_only_new_rows_and_carries_the_balance(): void {
		$items = $this->earn( 3 );
		$this->decisions->complete( $this->request( '25', 'key-set-0002' )->id );

		// After the first document: a refund of an earlier sale (counts at once, D2), a new sale
		// that is released and one that is still held.
		$this->order( $items[0], 9101, array( new RefundSnapshot( 7901, new DateTimeImmutable( '2026-11-29 10:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( $items[0], -1, SalesAmount::parse( '-80' ), SalesAmount::parse( '-20' ) ) ) ) ) );
		$this->earn( 1 );
		$this->earn( 1, '2026-11-25 10:00:00' );

		$second = $this->request( '5', 'key-set-0003' );
		$this->decisions->complete( $second->id );
		$shown = $this->documents->for_payout( $second->id );

		self::assertNotNull( $shown );
		self::assertSame( 'AFR-000002', $shown['document']->number );
		self::assertSame( array( 'sale', 'refund' ), array_map( static fn( array $row ): string => (string) $row['type'], $shown['rows'] ), 'Only the rows no document settled; the held sale waits.' );
		self::assertSame( array( '35.0000', '0.0000', '5.0000', '30.0000' ), array_map( array( SalesAmount::class, 'format' ), array( $shown['document']->opening_units, $shown['document']->commission_units, $shown['document']->payout_units, $shown['document']->closing_units ) ), '−20 + 20 = 0 commission; 35 carried.' );
		self::assertSame( '2026-11-30 12:00:00', $shown['document']->period_from?->format( 'Y-m-d H:i:s' ), 'From the end of the previous document.' );
		self::assertSame( 5, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(DISTINCT event_key) FROM %i', $this->table( Tables::SETTLEMENT_ROWS ) ) ), 'Every settled row once.' );
	}

	public function test_a_settled_row_keeps_its_stored_payout_and_new_rows_have_the_vat_deducted(): void {
		// ADR-064: not VAT registered, so the payout is the share of the sale price excl. VAT.
		$this->vat->save( $this->creator, false );
		$ledger = new LedgerCreatorPayouts( $this->wpdb );
		$now    = new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );

		$this->earn( 2 );
		self::assertSame( '32.0000', SalesAmount::format( (int) $ledger->earned( $this->creator, null, $now, 14 )['DKK']->amount_units() ), '2 × 16.00: 80.00 excl. VAT × 20 %.' );

		$first = $this->request( '10', 'key-set-0063' );
		$this->decisions->complete( $first->id );
		self::assertSame( array( '16.0000', '16.0000' ), array_map( static fn( array $row ): string => (string) $row['payout_amount'], $this->documents->for_payout( $first->id )['rows'] ?? array() ) );

		// A document issued under ADR-063 (no deduction) keeps its stored payouts:
		// the balance follows the document, nothing is regulated automatically.
		$old = $this->documents->for_payout( $first->id )['document'] ?? null;
		self::assertNotNull( $old );
		self::assertSame( 2, $this->wpdb->update( $this->table( Tables::SETTLEMENT_ROWS ), array( 'payout_amount' => '20.0000' ), array( 'document_id' => $old->id ) ) );
		$totals = array(
			'commission_amount' => '40.0000',
			'closing_balance'   => '30.0000',
		);
		self::assertSame( 1, $this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), $totals, array( 'id' => $old->id ) ) );
		$this->earn( 1 );

		self::assertSame( '56.0000', SalesAmount::format( (int) $ledger->earned( $this->creator, null, $now, 14 )['DKK']->amount_units() ), '20 + 20 stored, + 16 for the new row.' );
		$unsettled = $ledger->unsettled_rows( $this->creator, 'DKK', null, $now, 14 );
		self::assertNotNull( $unsettled );
		self::assertSame( array( '16.0000' ), array_map( static fn( array $row ): string => SalesAmount::format( $row['payout_units'] ), $unsettled ), 'Only the new row, excl. VAT.' );
		self::assertSame( array( '20.0000' ), array_map( static fn( array $row ): string => SalesAmount::format( $row['profit_units'] ), $unsettled ), 'Its profit is unchanged.' );

		$second = $this->request( '5', 'key-set-0064' );
		$this->decisions->complete( $second->id );
		$shown = $this->documents->for_payout( $second->id );

		self::assertNotNull( $shown );
		self::assertSame( 'adr064-adr054-v1', $shown['document']->calculation_version, 'New documents name the calculation with the VAT deducted.' );
		// Opening 30 (40 − 10) + commission 16 − paid 5 = 41 = earned 56 − paid 15: chain and balance agree.
		self::assertSame( array( '30.0000', '16.0000', '5.0000', '41.0000' ), array_map( array( SalesAmount::class, 'format' ), array( $shown['document']->opening_units, $shown['document']->commission_units, $shown['document']->payout_units, $shown['document']->closing_units ) ) );
		self::assertSame( array( '20.0000', '20.0000' ), array_map( static fn( array $row ): string => (string) $row['payout_amount'], $this->documents->for_payout( $first->id )['rows'] ?? array() ), 'The earlier document is unchanged.' );
		self::assertSame(
			array(
				'version'   => VatDeduction::VERSION,
				'gross'     => '20.0000',
				'deduction' => '4.0000',
				'net'       => '16.0000',
			),
			$shown['document']->snapshot[ VatDeduction::SNAPSHOT_KEY ] ?? null,
			'The deduction is stored with the new document.'
		);
		self::assertStringStartsWith( '%PDF-', ( new SettlementPdf() )->render( $shown ), 'The document adds up with its deduction.' );
	}

	public function test_an_unknown_row_refuses_the_completion_and_nothing_is_issued(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0004' );
		// A line from before the snapshots: its payout is unknown, never 0.
		$this->order( 2900, 9199 );

		$this->refused( PayoutException::BALANCE_UNKNOWN, fn() => $this->decisions->complete( $payout->id ) );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );
		self::assertSame( 0, $this->count_rows( Tables::SETTLEMENT_DOCUMENTS ) );
	}

	public function test_a_failing_document_rolls_the_completion_back(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0005' );
		// A document that already holds the first number: the issue fails on UNIQUE(document_number).
		$this->wpdb->insert( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'document_number' => 'AFR-000001', 'document_series' => 'AFR', 'document_type' => 'settlement', 'creator_id' => 999, 'currency' => 'DKK', 'period_to' => '2026-01-01 00:00:00', 'profit_amount' => '0', 'commission_amount' => '0', 'opening_balance' => '0', 'payout_amount' => '0', 'closing_balance' => '0', 'calculation_version' => 'x', 'template_version' => 1, 'snapshot' => '{}', 'issued_at' => '2026-01-01 00:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->suppress_errors( true );

		try {
			$this->decisions->complete( $payout->id );
			self::fail( 'The completion must fail with its document.' );
		} catch ( \Throwable $e ) {
			self::assertInstanceOf( DatabaseException::class, $e );
		}

		$this->wpdb->suppress_errors( false );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status, 'No completed payout without a document.' );
		self::assertSame( 0, $this->count_rows( Tables::SETTLEMENT_ROWS ) );
		self::assertSame( 0, $this->count_rows( Tables::SETTLEMENT_COUNTERS ), 'The number is given back.' );
		self::assertSame( array(), $this->audit( AuditAction::PayoutCompleted ) );

		$this->wpdb->delete( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'document_number' => 'AFR-000001' ) );
		$this->decisions->complete( $payout->id );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		self::assertSame( 'AFR-000001', $shown['document']->number );
	}

	public function test_documents_are_immutable_snapshots_and_unique_keys_hold(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0006' );
		$this->decisions->complete( $payout->id );
		$before = $this->documents->for_payout( $payout->id );

		// Later changes of share, VAT status and creator name change nothing on the document.
		$this->markups->save( $this->creator, new MarkupBps( 5000 ) );
		$this->vat->save( $this->creator, false );
		$this->wpdb->update( $this->table( Tables::CREATORS ), array( 'display_name' => 'Renamed' ), array( 'id' => $this->creator ) );

		self::assertEquals( $before, $this->documents->for_payout( $payout->id ) );

		$this->wpdb->suppress_errors( true );
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i LIMIT 1', $this->table( Tables::SETTLEMENT_ROWS ) ), ARRAY_A );
		self::assertIsArray( $row );
		self::assertFalse( $this->wpdb->insert( $this->table( Tables::SETTLEMENT_ROWS ), array( 'document_id' => 77 ) + $row ), 'A ledger row is settled at most once.' );
		$document = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i LIMIT 1', $this->table( Tables::SETTLEMENT_DOCUMENTS ) ), ARRAY_A );
		self::assertIsArray( $document );
		unset( $document['id'] );
		self::assertFalse( $this->wpdb->insert( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'document_number' => 'AFR-999999' ) + $document ), 'One document per payout.' );
		$this->wpdb->suppress_errors( false );
	}

	public function test_the_settlement_start_is_locked_by_the_first_document(): void {
		$this->earn( 2 );
		self::assertTrue( $this->settings->set_settlement_start( $this->creator, new DateTimeImmutable( '2026-08-01 00:00:00', new DateTimeZone( 'UTC' ) ) ) );
		$payout = $this->request( '10', 'key-set-0007' );
		$this->decisions->complete( $payout->id );

		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		self::assertSame( '2026-08-01 00:00:00', $shown['document']->period_from?->format( 'Y-m-d H:i:s' ) );
		$this->refused( PayoutException::START_LOCKED, fn() => $this->settings->set_settlement_start( $this->creator, null ) );
		self::assertFalse( $this->settings->set_settlement_start( $this->creator, new DateTimeImmutable( '2026-08-01 00:00:00', new DateTimeZone( 'UTC' ) ) ), 'Unchanged is no change.' );
	}

	public function test_a_settled_order_history_cannot_be_deleted(): void {
		$items = $this->earn( 1 );
		$this->decisions->complete( $this->request( '10', 'key-set-0008' )->id );
		$this->earn( 1, '2026-11-25 10:00:00' );

		try {
			$this->sales->with_order_lock( 9101, fn() => $this->sales->erase_order( 9101, static function (): void {} ) );
			self::fail( 'A settled order must be kept (D5).' );
		} catch ( \Throwable $e ) {
			self::assertInstanceOf( SalesOrderSettledException::class, $e );
		}

		self::assertSame( 1, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_item_id = %d', $this->table( Tables::SALES ), $items[0] ) ) );

		$result = $this->sales->with_order_lock( 9102, fn() => $this->sales->erase_order( 9102, static function (): void {} ) );
		self::assertSame( 1, $result['ledger_rows'], 'An unsettled order can still be erased.' );
	}

	public function test_a_correction_document_states_the_amounts_and_changes_nothing_else(): void {
		// VAT registered at the sales: 3 × 20.00 commission incl. VAT (25 %): 48.00 + 12.00.
		$this->earn( 3 );
		$payout = $this->request( '25', 'key-set-0501' );
		$this->decisions->complete( $payout->id );
		$original = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $original );
		$before = $this->state();

		$first = $this->documents->correct( $payout->id, $original->id, 'The markup of the product was wrong.', '50', '0' );

		self::assertSame( array( 'AFR-000001-K1', SettlementDocument::TYPE_CORRECTION, 5, $original->id, $payout->id ), array( $first->number, $first->type, $first->template_version, $first->corrects_document_id, $first->payout_id ) );
		self::assertSame( array( 0, 0, 0, 0, 0, 0 ), array( $first->profit_units, $first->commission_units, $first->adjustment_units, $first->opening_units, $first->payout_units, $first->closing_units ), 'No balance or sum ever counts a correction.' );
		self::assertSame( array( 'AFR-000001', 'The markup of the product was wrong.' ), array( $first->snapshot['corrects'] ?? null, $first->snapshot['correction_reason'] ?? null ) );
		self::assertSame(
			array(
				'version'    => 1,
				'original'   => 'AFR-000001',
				'corrects'   => 'AFR-000001',
				'sequence'   => 1,
				'rate_bps'   => 2500,
				'decimals'   => 2,
				'before'     => array(
					'commission' => '60.0000',
					'excl'       => '48.0000',
					'vat'        => '12.0000',
					'incl'       => '60.0000',
					'untaxed'    => '0.0000',
				),
				'after'      => array(
					'commission' => '50.0000',
					'excl'       => '40.0000',
					'vat'        => '10.0000',
					'incl'       => '50.0000',
					'untaxed'    => '0.0000',
				),
				'difference' => array(
					'commission' => '-10.0000',
					'excl'       => '-8.0000',
					'vat'        => '-2.0000',
					'incl'       => '-10.0000',
					'untaxed'    => '0.0000',
				),
				'payout'     => '25.0000',
			),
			$first->snapshot['correction'] ?? null
		);

		// The original, its rows, the number counter and the payout are unchanged.
		$after = $this->state();
		self::assertSame( $before['documents'], array_slice( $after['documents'], 0, count( $before['documents'] ) ), 'The original is never changed.' );
		self::assertSame( array( $before['rows'], $before['counters'], $before['payouts'] ), array( $after['rows'], $after['counters'], $after['payouts'] ) );
		self::assertSame(
			array(
				array(
					'document_id'           => $first->id,
					'document_number'       => 'AFR-000001-K1',
					'original'              => 'AFR-000001',
					'corrects'              => 'AFR-000001',
					'payout_id'             => $payout->id,
					'currency'              => 'DKK',
					'commission_before'     => '60.0000',
					'commission_after'      => '50.0000',
					'commission_difference' => '-10.0000',
					'has_reason'            => true,
				),
			),
			$this->audit( AuditAction::SettlementDocumentCorrected ),
			'Audited without the reason.'
		);

		// Repeated or stale: the original is no longer the latest of the chain.
		$this->refused( PayoutException::DOCUMENT_NOT_CORRECTABLE, fn() => $this->documents->correct( $payout->id, $original->id, 'Again.', '50', '0' ), 'Only the latest of a chain.' );

		// A second correction corrects the first: the amounts before are the first one's corrected amounts.
		// Moving commission to sales without VAT would remove VAT the original's rows carry: refused, nothing written.
		$state = $this->state();
		$this->refused( PayoutException::CORRECTION_VAT_STATUS, fn() => $this->documents->correct( $payout->id, $first->id, 'One sale was without VAT.', '40', '10' ) );
		self::assertSame( $state, $this->state() );
		$second = $this->documents->correct( $payout->id, $first->id, 'The markup was still wrong.', '45', '0' );
		self::assertSame( array( 'AFR-000001-K2', $first->id ), array( $second->number, $second->corrects_document_id ) );
		self::assertSame( array( 'AFR-000001', 'AFR-000001-K1', 2 ), array( $second->snapshot['correction']['original'] ?? null, $second->snapshot['correction']['corrects'] ?? null, $second->snapshot['correction']['sequence'] ?? null ) );
		self::assertSame( '50.0000', $second->snapshot['correction']['before']['commission'] ?? null );
		self::assertSame( array( '45.0000', '36.0000', '9.0000', '45.0000', '0.0000' ), array_values( $second->snapshot['correction']['after'] ?? array() ) );
		self::assertSame( array( '-5.0000', '-4.0000', '-1.0000', '-5.0000', '0.0000' ), array_values( $second->snapshot['correction']['difference'] ?? array() ) );

		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		self::assertSame( 'AFR-000001', $shown['document']->number );
		self::assertSame( array( 'AFR-000001-K1', 'AFR-000001-K2' ), array_map( static fn( SettlementDocument $doc ): string => $doc->number, $shown['corrections'] ) );

		// The next settlement document takes the next number and carries the original's balance.
		$this->earn( 1 );
		$next = $this->request( '5', 'key-set-0502' );
		$this->decisions->complete( $next->id );
		$later = $this->documents->for_payout( $next->id )['document'] ?? null;
		self::assertNotNull( $later );
		self::assertSame( array( 'AFR-000002', '35.0000' ), array( $later->number, SalesAmount::format( $later->opening_units ) ), 'The difference is not settled automatically.' );

		// Shown in the PDFs and to the creator.
		$pdf  = new SettlementPdf();
		$text = static fn( string $value ): string => PdfWriter::escape( PdfWriter::encode( $value ) );
		$body = $pdf->render_correction( $shown, $shown['corrections'][1] );

		foreach ( array( 'Correction document', 'AFR-000001-K2', 'Corrects settlement document AFR-000001 of 2026-11-30.', 'Replaces the corrected amounts of correction document AFR-000001-K1.', 'Before (DKK)', 'Corrected (DKK)', 'Difference (DKK)', 'Commission in the period', 'VAT (25 %)', '-1.00', '45.00', 'Paid out with the original document (unchanged): 25.00 DKK', 'The markup was still wrong.', 'is not settled automatically.', 'Tax and reporting', 'Template version 5.' ) as $expected ) {
			self::assertStringContainsString( $text( $expected ), $body, $expected );
		}

		self::assertStringNotContainsString( $text( 'Commission without VAT (not VAT registered when sold)' ), $body, 'No category the original does not have.' );
		self::assertSame( 'attachment; filename="AFR-000001-K2.pdf"', SettlementPdf::headers( $shown['corrections'][1] )['Content-Disposition'] );
		self::assertSame( $shown['corrections'][1], SettlementPdf::correction( $shown, 'K2' ) );
		self::assertNull( SettlementPdf::correction( $shown, 'K3' ) );
		self::assertStringContainsString( $text( 'Commission in the period corrected from 60.00 to 50.00 DKK (difference -10.00 DKK); see the correction document.' ), $pdf->render( $shown ), 'The original lists its corrections.' );

		$api = MePayoutsController::settlement_document( $shown );
		self::assertSame( array( 'suffix', 'original', 'corrects', 'rate_bps', 'before', 'after', 'difference', 'payout' ), array_keys( $api['corrections'][0]['amounts'] ?? array() ) );
		self::assertSame( array( 'K2', 'AFR-000001-K1', '-5.0000', '25.0000' ), array( $api['corrections'][1]['amounts']['suffix'] ?? null, $api['corrections'][1]['amounts']['corrects'] ?? null, $api['corrections'][1]['amounts']['difference']['incl'] ?? null, $api['corrections'][1]['amounts']['payout'] ?? null ) );
		self::assertSame( '60.0000', $api['commission'], 'The original as issued.' );
	}

	public function test_invalid_or_unchanged_corrections_are_refused_and_write_nothing(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0503' );
		$other  = $this->request( '5', 'key-set-0504' );
		$this->decisions->complete( $payout->id );
		$original = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $original );
		$before = $this->state();
		$fix    = fn( string $reason, string $incl, string $untaxed = '0', ?int $payout_id = null, ?int $document_id = null ) => $this->documents->correct( $payout_id ?? $payout->id, $document_id ?? $original->id, $reason, $incl, $untaxed );

		foreach ( array( '', '   ', str_repeat( 'x', 501 ), "Line\x01break", "Invalid \xC3" ) as $reason ) {
			$this->refused( PayoutException::INVALID_CORRECTION_REASON, fn() => $fix( $reason, '30' ), 'Reason ' . bin2hex( substr( $reason, 0, 12 ) ) );
		}

		foreach ( array( '', 'abc', '1e3', '30.005', '1.234.5', '+30', '30 kr', '1234567890123' ) as $amount ) {
			$this->refused( PayoutException::INVALID_CORRECTION_AMOUNT, fn() => $fix( 'Wrong.', $amount ), 'Amount ' . $amount );
		}

		foreach ( array( 'Paid to jane.doe@example.com instead.', 'Wrong account DK50 0040 0440 1162 43.' ) as $reason ) {
			$this->refused( PayoutException::PAYMENT_DETAILS_IN_TEXT, fn() => $fix( $reason, '30' ), 'No payment details in the reason.' );
		}

		$this->refused( PayoutException::INVALID_CORRECTION_AMOUNT, fn() => $fix( 'Wrong.', '30', '0.001' ), 'More decimals than the shop.' );
		$this->refused( PayoutException::DOCUMENT_NOT_CORRECTABLE, fn() => $fix( 'Wrong.', '30', '0', $other->id ), 'The document of another payout.' );
		$this->refused( PayoutException::DOCUMENT_NOT_CORRECTABLE, fn() => $fix( 'Wrong.', '30', '0', null, 999999 ), 'An unknown document.' );
		$this->refused( PayoutException::CORRECTION_UNCHANGED, fn() => $fix( 'Nothing.', '40', '0' ), 'The amounts in force and the same parties.' );
		$this->refused( PayoutException::CORRECTION_UNCHANGED, fn() => $fix( 'Nothing.', '40,00', '0,00' ), 'Also with a decimal comma.' );

		wp_set_current_user( $this->create_user( 'mb-set-correct-subscriber' ) );
		$this->refused( PayoutException::NOT_AUTHORIZED, fn() => $fix( 'No.', '30' ) );
		wp_set_current_user( 1 );

		self::assertSame( $before, $this->state(), 'Nothing written, not even an audit record.' );

		// Corrected master data (K09) with unchanged amounts: a correction with no difference.
		$this->settings->set_billing_details( $this->creator, array( 'legal_name' => 'Alpha Merch ApS' ) );
		$fixed = $fix( 'The legal name was missing.', '40,00', '0' );
		self::assertSame( array( '0.0000', 'Alpha Merch ApS' ), array( $fixed->snapshot['correction']['difference']['commission'] ?? null, $fixed->snapshot['creator']['legal_name'] ?? null ) );
		self::assertSame( '', $original->snapshot['creator']['legal_name'] ?? null, 'The original keeps its parties.' );
	}

	public function test_documents_issued_before_keep_working_in_a_chain_of_corrections(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0505' );
		$this->decisions->complete( $payout->id );
		$issued = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $issued );
		$original = $issued['document'];
		// The original as issued with template version 2 (no VAT split), and a correction without amounts (D11 a), both made by the test.
		$legacy = $original->snapshot;
		unset( $legacy[ CommissionVat::SNAPSHOT_KEY ] );
		$this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'template_version' => 2, 'snapshot' => (string) wp_json_encode( $legacy ) ), array( 'id' => $original->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->insert( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'document_number' => 'AFR-000002', 'document_series' => 'AFR', 'document_type' => 'correction', 'creator_id' => $this->creator, 'payout_id' => $payout->id, 'corrects_document_id' => $original->id, 'currency' => 'DKK', 'period_to' => '2026-11-30 12:00:00', 'profit_amount' => '0', 'commission_amount' => '0', 'opening_balance' => '0', 'payout_amount' => '0', 'closing_balance' => '0', 'calculation_version' => 'adr063-adr054-v1', 'template_version' => 4, 'snapshot' => (string) wp_json_encode( array( 'corrects' => 'AFR-000001', 'correction_reason' => 'Name misspelled.' ) + $legacy ), 'issued_at' => '2026-11-30 12:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$old = (int) $this->wpdb->insert_id;

		$this->refused( PayoutException::DOCUMENT_NOT_CORRECTABLE, fn() => $this->documents->correct( $payout->id, $original->id, 'Wrong.', '30', '0' ), 'The old correction is the latest.' );
		$fixed = $this->documents->correct( $payout->id, $old, 'The markup was wrong.', '30', '0' );

		self::assertSame( array( 'AFR-000001-K2', 'AFR-000002', 'AFR-000001' ), array( $fixed->number, $fixed->snapshot['correction']['corrects'] ?? null, $fixed->snapshot['correction']['original'] ?? null ) );
		self::assertSame( array( '40.0000', '32.0000', '8.0000', '40.0000', '0.0000' ), array_values( $fixed->snapshot['correction']['before'] ?? array() ), 'The split of the original\'s rows; the old correction has no amounts.' );

		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		$api = MePayoutsController::settlement_document( $shown );
		self::assertNull( $api['corrections'][0]['amounts'], 'The old correction as it was.' );
		self::assertNull( $api['corrections'][0]['master_data'], 'No master data block for a correction without amounts.' );
		self::assertNotNull( $api['corrections'][1]['master_data'] ?? null );
		self::assertSame( 'Name misspelled.', $api['corrections'][0]['reason'] );
		self::assertNull( SettlementPdf::correction( $shown, 'K1' ), 'No PDF for a correction without amounts.' );
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Template version 5.' ) ), ( new SettlementPdf() )->render_correction( $shown, $shown['corrections'][1] ) );
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Template version 2.' ) ), ( new SettlementPdf() )->render( $shown ), 'The original keeps its template.' );
	}

	public function test_a_correction_document_that_does_not_match_its_chain_gives_no_pdf(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0506' );
		$this->decisions->complete( $payout->id );
		$original = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $original );
		$fixed    = $this->documents->correct( $payout->id, $original->id, 'Wrong markup.', '30', '0' );
		$snapshot = $fixed->snapshot;
		$cases    = array(
			'other before'     => array( 'before' => array( 'commission' => '41.0000', 'incl' => '41.0000', 'excl' => '32.8000', 'vat' => '8.2000', 'untaxed' => '0.0000' ) ) + $snapshot['correction'], // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'other difference' => array( 'difference' => array( 'commission' => '-9.0000' ) + $snapshot['correction']['difference'] ) + $snapshot['correction'], // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'other vat'        => array( 'after' => array( 'excl' => '25.0000', 'vat' => '5.0000' ) + $snapshot['correction']['after'] ) + $snapshot['correction'], // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'other payout'     => array( 'payout' => '11.0000' ) + $snapshot['correction'],
			'other original'   => array( 'corrects' => 'AFR-000009' ) + $snapshot['correction'],
		);

		foreach ( $cases as $case => $correction ) {
			$this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'snapshot' => (string) wp_json_encode( array( 'correction' => $correction ) + $snapshot ) ), array( 'id' => $fixed->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$shown = $this->documents->for_payout( $payout->id );
			self::assertNotNull( $shown );

			try {
				( new SettlementPdf() )->render_correction( $shown, $shown['corrections'][0] );
				self::fail( 'Expected no PDF: ' . $case );
			} catch ( UnexpectedValueException ) {
				$this->addToAssertionCount( 1 );
			}

			self::assertNull( MePayoutsController::settlement_document( $shown )['corrections'][0]['amounts'] ?? null, 'Not shown with amounts that do not add up: ' . $case . '.' );
		}
	}

	public function test_a_correction_for_sales_while_not_vat_registered_never_adds_vat(): void {
		$this->vat->save( $this->creator, false );
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0508' );
		$this->decisions->complete( $payout->id );
		$issued = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $issued );
		$original = $issued['document'];

		// The creator is VAT registered now: the categories still come from the rows' status at the sale.
		$this->vat->save( $this->creator, true );
		$before = $this->state();
		$this->refused( PayoutException::CORRECTION_VAT_STATUS, fn() => $this->documents->correct( $payout->id, $original->id, 'Wrong.', '10', '30' ), 'Part with VAT.' );
		$this->refused( PayoutException::CORRECTION_VAT_STATUS, fn() => $this->documents->correct( $payout->id, $original->id, 'Wrong.', '40', '0' ), 'All with VAT.' );
		self::assertSame( $before, $this->state(), 'Refused before any write, also of the audit log.' );

		$fixed = $this->documents->correct( $payout->id, $original->id, 'The markup was wrong.', '0', '30' );
		self::assertSame( array( '30.0000', '0.0000', '0.0000', '0.0000', '30.0000' ), array_values( $fixed->snapshot['correction']['after'] ?? array() ) );
		// The original paid 2 × 16.00 excl. VAT (ADR-064): 30.00 − 32.00.
		self::assertSame( array( '-2.0000', '0.0000', '0.0000', '0.0000', '-2.0000' ), array_values( $fixed->snapshot['correction']['difference'] ?? array() ) );

		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		self::assertSame( $original->snapshot, $shown['document']->snapshot, 'The original and its VAT split are unchanged.' );
		self::assertSame( $issued['rows'], $shown['rows'] );
		$pdf = ( new SettlementPdf() )->render_correction( $shown, $shown['corrections'][0] );
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Commission without VAT (not VAT registered when sold)' ) ), $pdf );

		foreach ( array( 'Commission incl. VAT', 'Commission excl. VAT', 'VAT (25 %)' ) as $text ) {
			self::assertStringNotContainsString( PdfWriter::escape( PdfWriter::encode( $text ) ), $pdf, $text );
		}

		// The form offers only the category the original has; a field it does not send is 0.
		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		$html = $this->page_html( $page, $payout->id );
		self::assertStringNotContainsString( 'name="commission_incl"', $html );
		self::assertStringContainsString( 'name="commission_untaxed" value="30.00"', $html );

		$actions = new PayoutActions( $this->decisions, new RecordingLogger(), null, $this->documents );
		$url     = $actions->handle(
			array(
				'action'             => PayoutActions::CORRECT_ACTION,
				'payout_id'          => (string) $payout->id,
				'document_id'        => (string) $shown['corrections'][0]->id,
				'reason'             => 'The markup was still wrong.',
				'commission_untaxed' => '25.00',
				'confirm_material'   => '1',
				'_wpnonce'           => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::CORRECT_ACTION, $payout->id ) ),
			)
		);
		self::assertStringContainsString( 'result=corrected', $url );
		self::assertSame( '0.0000', $this->documents->for_payout( $payout->id )['corrections'][1]->snapshot['correction']['after']['incl'] ?? null );
		self::assertStringContainsString( 'result=' . PayoutException::CORRECTION_VAT_STATUS, $actions->handle( array( 'commission_incl' => '5' ) + array( 'document_id' => (string) ( $this->documents->for_payout( $payout->id )['corrections'][1]->id ?? 0 ) ) + array( 'action' => PayoutActions::CORRECT_ACTION, 'payout_id' => (string) $payout->id, 'reason' => 'Wrong.', 'commission_untaxed' => '25', 'confirm_material' => '1', '_wpnonce' => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::CORRECT_ACTION, $payout->id ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_a_mixed_document_keeps_both_vat_categories_and_vat_only_on_the_part_with_vat(): void {
		// One sale while VAT registered (20.00 incl. 4.00 VAT), one while not (16.00 excl. VAT, ADR-064).
		$this->earn( 1 );
		$this->vat->save( $this->creator, false );
		$this->earn( 1 );
		$payout = $this->request( '10', 'key-set-0509' );
		$this->decisions->complete( $payout->id );
		$original = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $original );

		$fixed = $this->documents->correct( $payout->id, $original->id, 'Wrong markup.', '15', '25' );
		self::assertSame( array( '40.0000', '12.0000', '3.0000', '15.0000', '25.0000' ), array_values( $fixed->snapshot['correction']['after'] ?? array() ) );
		self::assertSame( array( '4.0000', '-4.0000', '-1.0000', '-5.0000', '9.0000' ), array_values( $fixed->snapshot['correction']['difference'] ?? array() ) );

		// Both categories exist, so the division between them is the administrator's responsibility.
		$moved = $this->documents->correct( $payout->id, $fixed->id, 'The division was wrong.', '40', '0' );
		self::assertSame( array( '40.0000', '32.0000', '8.0000', '40.0000', '0.0000' ), array_values( $moved->snapshot['correction']['after'] ?? array() ) );

		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		self::assertNotNull( SettlementPdf::verified_correction( $shown, $shown['corrections'][0] ) );
		self::assertNotNull( SettlementPdf::verified_correction( $shown, $shown['corrections'][1] ) );
		self::assertSame( $original->snapshot[ CommissionVat::SNAPSHOT_KEY ], $shown['document']->snapshot[ CommissionVat::SNAPSHOT_KEY ] ?? null, 'The original\'s VAT split is unchanged.' );
	}

	public function test_a_stored_correction_outside_the_vat_categories_gives_no_pdf_and_shows_no_amounts(): void {
		$this->vat->save( $this->creator, false );
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0510' );
		$this->decisions->complete( $payout->id );
		$issued = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $issued );
		$fixed = $this->documents->correct( $payout->id, $issued['document']->id, 'Wrong markup.', '0', '30' );
		// Made by the test: a correction that adds up in itself, with VAT on the commission of sales while not VAT registered.
		$before                 = SettlementCorrection::in_force( array( $issued['document'] ), $issued['rows'] );
		$snapshot               = $fixed->snapshot;
		$snapshot['correction'] = SettlementCorrection::snapshot( 'AFR-000001', 'AFR-000001', 1, 2, $before, SettlementCorrection::corrected( 2500, SalesAmount::parse( '10' ), SalesAmount::parse( '20' ), 2 ), $issued['document']->payout_units );
		$this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'snapshot' => (string) wp_json_encode( $snapshot ) ), array( 'id' => $fixed->id ) );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		$correction = $shown['corrections'][0];

		self::assertNotNull( SettlementCorrection::of_document( $correction ), 'It adds up in itself.' );
		self::assertNull( SettlementPdf::verified_correction( $shown, $correction ) );
		self::assertNull( MePayoutsController::settlement_document( $shown )['corrections'][0]['amounts'] ?? null );

		try {
			( new SettlementPdf() )->render_correction( $shown, $correction );
			self::fail( 'Expected no PDF.' );
		} catch ( UnexpectedValueException ) {
			$this->addToAssertionCount( 1 );
		}

		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		self::assertStringContainsString( 'cannot be shown', $this->page_html( $page, $payout->id ) );
	}

	public function test_a_changed_current_vat_status_or_name_alone_is_no_correction_and_is_labelled_as_such(): void {
		$this->vat->save( $this->creator, false );
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0511' );
		$this->decisions->complete( $payout->id );
		$original = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $original );

		// Since the issue the creator became VAT registered and was renamed: no error of the document.
		$this->vat->save( $this->creator, true );
		( new CreatorRepository( $this->wpdb ) )->rename( $this->creator, 'Settlement Renamed' );
		$before = $this->state();
		$this->refused( PayoutException::CORRECTION_UNCHANGED, fn() => $this->documents->correct( $payout->id, $original->id, 'Nothing.', '0', '32' ), 'The same amounts (2 × 16.00 excl. VAT).' );
		self::assertSame( $before, $this->state(), 'Refused before any write, also of the audit log.' );

		$fixed = $this->documents->correct( $payout->id, $original->id, 'The markup was wrong.', '0', '30' );
		self::assertTrue( $fixed->vat_registered, 'The status when the correction was issued.' );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		$text = static fn( string $value ): string => PdfWriter::escape( PdfWriter::encode( $value ) );
		$pdf  = ( new SettlementPdf() )->render_correction( $shown, $shown['corrections'][0] );

		foreach ( array( 'VAT registered when this correction was issued: Yes', 'Commission without VAT (not VAT registered when sold)', 'The VAT follows the creator\'s VAT status at each sale the original settled, not the current status.' ) as $expected ) {
			self::assertStringContainsString( $text( $expected ), $pdf, $expected );
		}

		foreach ( array( 'VAT registered at issue', 'Commission incl. VAT', 'VAT (25 %)' ) as $unexpected ) {
			self::assertStringNotContainsString( $text( $unexpected ), $pdf, $unexpected );
		}

		self::assertStringContainsString( $text( 'VAT registered at issue: No' ), ( new SettlementPdf() )->render( $shown ), 'The original keeps its status at issue.' );
		self::assertSame( $original->snapshot, $shown['document']->snapshot );
	}

	public function test_a_chain_has_at_most_100_corrections_and_every_issued_one_can_be_read(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0512' );
		$this->decisions->complete( $payout->id );
		$original = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $original );
		$previous = $original->id;

		// 99 corrections without amounts (D11 a), made by the test.
		for ( $i = 1; $i <= 99; $i++ ) {
			$this->wpdb->insert( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'document_number' => 'AFR-' . ( 900000 + $i ), 'document_series' => 'AFR', 'document_type' => 'correction', 'creator_id' => $this->creator, 'payout_id' => $payout->id, 'corrects_document_id' => $previous, 'currency' => 'DKK', 'period_to' => '2026-11-30 12:00:00', 'profit_amount' => '0', 'commission_amount' => '0', 'opening_balance' => '0', 'payout_amount' => '0', 'closing_balance' => '0', 'calculation_version' => 'adr063-adr054-v1', 'template_version' => 4, 'snapshot' => (string) wp_json_encode( array( 'corrects' => 'x', 'correction_reason' => 'Old.' ) + $original->snapshot ), 'issued_at' => '2026-11-30 12:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$previous = (int) $this->wpdb->insert_id;
		}

		$last = $this->documents->correct( $payout->id, $previous, 'The markup was wrong.', '30', '0' );
		self::assertSame( 'AFR-000001-K100', $last->number );

		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		self::assertCount( 100, $shown['corrections'], 'Every issued correction is read.' );
		self::assertSame( $last->id, $shown['corrections'][99]->id );
		self::assertNotNull( SettlementPdf::verified_correction( $shown, $shown['corrections'][99] ) );
		self::assertSame( $shown['corrections'][99], SettlementPdf::correction( $shown, 'K100' ) );
		self::assertStringStartsWith( '%PDF-1.4', ( new SettlementPdf() )->render_correction( $shown, $shown['corrections'][99] ) );
		self::assertNotNull( MePayoutsController::settlement_document( $shown )['corrections'][99]['amounts'] ?? null );

		// A 101st is refused before any write.
		$before = $this->state();
		$this->refused( PayoutException::CORRECTION_LIMIT, fn() => $this->documents->correct( $payout->id, $last->id, 'Again.', '20', '0' ) );
		self::assertSame( $before, $this->state(), 'No document, no change of the chain and no audit record.' );
		self::assertNull( $this->documents->for_payout( $payout->id )['corrections'][100] ?? null );

		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		$html = $this->page_html( $page, $payout->id );
		self::assertStringContainsString( 'already has 100 correction documents', $html );
		self::assertStringNotContainsString( 'name="confirm_material"', $html, 'No form.' );
	}

	public function test_corrected_master_data_are_shown_from_the_correction_snapshot_in_admin_and_api(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0513' );
		$this->decisions->complete( $payout->id );
		$original = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $original );

		// K1 corrects the master data (no CVR/SE number registered), K2 only the amounts.
		$this->settings->set_company_cvr( '12345678' );
		$this->settings->set_billing_details(
			$this->creator,
			array(
				'legal_name' => 'Alpha Merch ApS',
				'address_1'  => 'Nørregade 2',
				'postcode'   => '8000',
				'city'       => 'Aarhus C',
			)
		);
		$first = $this->documents->correct( $payout->id, $original->id, 'The legal name was missing.', '40', '0' );
		$this->documents->correct( $payout->id, $first->id, 'Wrong markup.', '30', '0' );

		// Later master data never change the corrections.
		$this->settings->set_company_cvr( '11112222' );
		$this->settings->set_billing_details(
			$this->creator,
			array(
				'legal_name' => 'Other Name ApS',
				'cvr'        => '87654321',
			)
		);
		$before = $this->state();
		$shown  = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		$api      = MePayoutsController::settlement_document( $shown );
		$expected = array(
			'creator_legal_name' => 'Alpha Merch ApS',
			'creator_address'    => array( 'Nørregade 2', '8000 Aarhus C' ),
			'creator_cvr'        => '',
			'company_name'       => (string) ( $first->snapshot['company']['name'] ?? '' ),
			'company_address'    => $first->snapshot['company']['address'] ?? array(),
			'company_cvr'        => '12345678',
		);

		self::assertSame( $expected + array( 'changed' => true ), $api['corrections'][0]['master_data'] ?? null, 'From the snapshot of K1.' );
		self::assertSame( $expected + array( 'changed' => false ), $api['corrections'][1]['master_data'] ?? null, 'K2 kept the parties of K1.' );
		self::assertSame( '', $api['master_data']['creator_legal_name'] ?? null, 'The original as issued: nothing was registered.' );

		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		$html = $this->page_html( $page, $payout->id );

		self::assertSame( 1, substr_count( $html, 'Corrected master data' ), 'Only for the correction that changed them.' );

		foreach ( array( 'Alpha Merch ApS', esc_html( 'Nørregade 2, 8000 Aarhus C' ), 'Issuer CVR no.', '12345678' ) as $text ) {
			self::assertStringContainsString( $text, $html, $text );
		}

		$block = (string) strstr( $html, 'Corrected master data' );
		self::assertStringContainsString( '<th scope="row">CVR/SE no.</th><td>Not registered</td>', $block );

		foreach ( array( 'Other Name ApS', '87654321', '11112222' ) as $current ) {
			self::assertStringNotContainsString( $current, $html, 'Never the current master data: ' . $current );
		}

		self::assertSame( $before, $this->state(), 'Showing changes no document, row, counter, payout or audit record.' );
		self::assertSame( array( '30.0000', '0.0000' ), array( $shown['corrections'][1]->snapshot['correction']['after']['commission'] ?? null, $shown['corrections'][1]->snapshot['correction']['after']['untaxed'] ?? null ) );
	}

	public function test_the_admin_form_issues_a_correction_only_when_confirmed_and_once(): void {
		add_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0507' );
		$this->decisions->complete( $payout->id );
		$original = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $original );
		$actions = new PayoutActions( $this->decisions, new RecordingLogger(), null, $this->documents );
		$page    = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		$input   = fn( array $fields = array() ): array => $fields + array(
			'action'             => PayoutActions::CORRECT_ACTION,
			'payout_id'          => (string) $payout->id,
			'document_id'        => (string) $original->id,
			'reason'             => 'The markup was wrong.',
			'commission_incl'    => '30.00',
			'commission_untaxed' => '0.00',
			'confirm_material'   => '1',
			'_wpnonce'           => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::CORRECT_ACTION, $payout->id ) ),
		);

		$html = $this->page_html( $page, $payout->id );
		self::assertStringContainsString( 'Issue a correction document', $html );
		self::assertStringContainsString( 'name="document_id" value="' . $original->id . '"', $html );
		self::assertStringContainsString( 'name="commission_incl" value="40.00"', $html, 'Filled in with the amounts in force.' );

		try {
			foreach ( array( array( '_wpnonce' => 'x' ), array( '_wpnonce' => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::REJECT_ACTION, $payout->id ) ) ) ) as $fields ) {
				try {
					$actions->handle( $input( $fields ) );
					self::fail( 'Expected wp_die().' );
				} catch ( \RuntimeException ) {
					$this->addToAssertionCount( 1 );
				}
			}

			self::assertStringContainsString( 'result=' . PayoutActions::NOT_ASSESSED, $actions->handle( $input( array( 'confirm_material' => '' ) ) ) );
			self::assertSame( array(), $this->documents->for_payout( $payout->id )['corrections'] ?? null, 'Nothing without the assessment.' );
			self::assertStringContainsString( 'result=corrected', $actions->handle( $input() ) );
			self::assertStringContainsString( 'result=' . PayoutException::DOCUMENT_NOT_CORRECTABLE, $actions->handle( $input() ), 'The same form again.' );
		} finally {
			remove_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		}

		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		self::assertCount( 1, $shown['corrections'] );
		$html = $this->page_html( $page, $payout->id );

		foreach ( array( 'Correction document AFR-000001-K1', 'Corrects AFR-000001 (original: AFR-000001).', 'Before', 'Corrected', 'Difference', 'The markup was wrong.', 'correction=K1', 'name="document_id" value="' . $shown['corrections'][0]->id . '"', 'name="commission_incl" value="30.00"' ) as $expected ) {
			self::assertStringContainsString( $expected, $html, $expected );
		}

		$download = new SettlementPdfDownload( $this->documents, new SettlementPdf(), new RecordingLogger() );
		$nonce    = wp_create_nonce( SettlementPdfDownload::nonce_action( $payout->id ) );
		$ok       = $download->response( array( 'payout_id' => (string) $payout->id, '_wpnonce' => $nonce, 'correction' => 'K1' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( 200, $ok['status'] );
		self::assertSame( ( new SettlementPdf() )->render_correction( $shown, $shown['corrections'][0] ), $ok['body'] );
		self::assertSame( 'attachment; filename="AFR-000001-K1.pdf"', $ok['headers']['Content-Disposition'] ?? null );
		self::assertSame( 404, $download->response( array( 'payout_id' => (string) $payout->id, '_wpnonce' => $nonce, 'correction' => 'K2' ) )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 404, $download->response( array( 'payout_id' => (string) $payout->id, '_wpnonce' => $nonce, 'correction' => '../K1' ) )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'correction=K1', SettlementPdfDownload::url( $payout->id, 'K1' ) );
	}

	public function test_the_pdf_is_made_from_the_stored_document_and_changes_nothing(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0201' );
		$this->decisions->complete( $payout->id );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		$before = $this->state();

		$first = ( new SettlementPdf() )->render( $shown );

		self::assertStringStartsWith( '%PDF-1.4', $first );

		foreach ( array( 'AFR-000001', 'Settlement Alpha', '40.00 DKK', '10.00 DKK', '30.00 DKK', 'Tax and reporting', '•••• 5678' ) as $text ) {
			self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( $text ) ), $first, $text );
		}

		self::assertStringNotContainsString( 'Preliminary', $first );
		self::assertStringNotContainsString( '0012345678', $first, 'Never the full account.' );

		$again = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $again );
		self::assertSame( $first, ( new SettlementPdf() )->render( $again ), 'The same document gives the same bytes.' );
		self::assertSame( $before, $this->state(), 'Making the PDF writes nothing.' );
	}

	public function test_a_document_that_does_not_add_up_gives_no_pdf_and_changes_nothing(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0202' );
		$this->decisions->complete( $payout->id );
		// A stored document that no longer matches its rows (made inconsistent by the test).
		$this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'commission_amount' => '41.0000' ), array( 'settlement_payout_id' => $payout->id ) );
		$before   = $this->state();
		$logger   = new RecordingLogger();
		$download = new SettlementPdfDownload( $this->documents, new SettlementPdf(), $logger );

		$response = $download->response(
			array(
				'payout_id' => (string) $payout->id,
				'_wpnonce'  => wp_create_nonce( SettlementPdfDownload::nonce_action( $payout->id ) ),
			)
		);

		self::assertSame( 500, $response['status'] );
		self::assertSame( array(), $response['headers'] );
		self::assertStringNotContainsString( '%PDF', $response['body'] );
		self::assertSame( array( array( 'error', 'The settlement document PDF could not be made.', array( 'payout_id' => $payout->id, 'error' => UnexpectedValueException::class ) ) ), array_map( static fn( array $entry ): array => array( $entry['level'], $entry['message'], $entry['context'] ), $logger->entries ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( $before, $this->state(), 'Nothing written: payout, rows, number counter and audit unchanged.' );
	}

	public function test_the_admin_download_needs_the_capability_and_a_nonce_for_the_payout(): void {
		$this->earn( 2 );
		$payout  = $this->request( '10', 'key-set-0203' );
		$pending = $this->request( '5', 'key-set-0204' );
		$this->decisions->complete( $payout->id );
		$shown    = $this->documents->for_payout( $payout->id );
		$download = new SettlementPdfDownload( $this->documents, new SettlementPdf(), new RecordingLogger() );
		$input    = static fn( int $id, string $nonce ): array => array(
			'payout_id' => (string) $id,
			'_wpnonce'  => $nonce,
		);
		$nonce    = wp_create_nonce( SettlementPdfDownload::nonce_action( $payout->id ) );
		self::assertNotNull( $shown );

		$ok = $download->response( $input( $payout->id, $nonce ) );

		self::assertSame( 200, $ok['status'] );
		self::assertSame( ( new SettlementPdf() )->render( $shown ), $ok['body'] );
		self::assertSame(
			array(
				'Content-Type'           => 'application/pdf',
				'Content-Disposition'    => 'attachment; filename="AFR-000001.pdf"',
				'Cache-Control'          => 'no-store, private',
				'X-Content-Type-Options' => 'nosniff',
			),
			$ok['headers']
		);
		self::assertSame( 403, $download->response( $input( $payout->id, '' ) )['status'], 'No nonce.' );
		self::assertSame( 403, $download->response( $input( $payout->id, wp_create_nonce( SettlementPdfDownload::nonce_action( $pending->id ) ) ) )['status'], 'The nonce of another payout.' );
		self::assertSame( 403, $download->response( array( 'payout_id' => '1x', '_wpnonce' => $nonce ) )['status'], 'Not a payout id.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 404, $download->response( $input( $pending->id, wp_create_nonce( SettlementPdfDownload::nonce_action( $pending->id ) ) ) )['status'], 'A pending payout has no document.' );
		self::assertStringContainsString( 'action=' . SettlementPdfDownload::ACTION, SettlementPdfDownload::url( $payout->id ) );
		self::assertStringContainsString( '_wpnonce=', SettlementPdfDownload::url( $payout->id ) );

		wp_set_current_user( $this->create_user( 'mb-set-pdf-subscriber' ) );
		self::assertSame( 403, $download->response( $input( $payout->id, $nonce ) )['status'], 'Without merchboard_manage_payouts.' );
	}

	public function test_a_template_1_document_keeps_its_preliminary_mark(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0205' );
		$this->decisions->complete( $payout->id );
		$issued = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $issued );
		// A document as issued before template version 2 (made by the test).
		$this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'template_version' => 1, 'snapshot' => (string) wp_json_encode( array( 'preliminary' => true ) + $issued['document']->snapshot ) ), array( 'id' => $issued['document']->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );

		$pdf = ( new SettlementPdf() )->render( $shown );

		self::assertSame( 1, $shown['document']->template_version );
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Preliminary, not approved by an auditor or SKAT.' ) ), $pdf );
		self::assertStringNotContainsString( 'Tax and reporting', $pdf );
		self::assertTrue( MePayoutsController::settlement_document( $shown )['preliminary'] );
		self::assertFalse( MePayoutsController::settlement_document( $issued )['preliminary'] );
	}

	public function test_vat_on_the_commission_is_stored_shown_and_never_changed_by_a_later_vat_status(): void {
		// VAT registered at the sales: 3 × 20.00 commission incl. VAT, 25 %.
		$this->earn( 3 );
		$payout = $this->request( '25', 'key-set-0301' );
		$this->decisions->complete( $payout->id );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		$document = $shown['document'];

		self::assertSame( 6, $document->template_version );
		self::assertSame(
			array(
				'version'  => 1,
				'rate_bps' => 2500,
				'incl'     => '60.0000',
				'vat'      => '12.0000',
				'excl'     => '48.0000',
				'untaxed'  => '0.0000',
			),
			$document->snapshot[ CommissionVat::SNAPSHOT_KEY ] ?? null
		);
		self::assertSame( array( '60.0000', '35.0000' ), array( SalesAmount::format( $document->commission_units ), SalesAmount::format( $document->closing_units ) ), 'Commission and balance unchanged: the commission includes the VAT.' );

		$api = MePayoutsController::settlement_document( $shown );
		self::assertSame(
			array(
				'rate_bps' => 2500,
				'excl'     => '48.0000',
				'vat'      => '12.0000',
				'incl'     => '60.0000',
				'untaxed'  => '0.0000',
			),
			$api['commission_vat']
		);

		$pdf = ( new SettlementPdf() )->render( $shown );

		foreach ( array( 'VAT on the commission', 'Commission excl. VAT', '48.00 DKK', 'VAT (25 %)', '12.00 DKK', 'Commission incl. VAT', '60.00 DKK', 'VAT on the sale', 'Template version 6.' ) as $text ) {
			self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( $text ) ), $pdf, $text );
		}

		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		$html = $this->page_html( $page, $payout->id );

		foreach ( array( 'Commission excl. VAT', 'VAT (25 %)', 'Commission incl. VAT' ) as $text ) {
			self::assertStringContainsString( esc_html( $text ), $html, $text );
		}

		// A later change of the creator's VAT status changes nothing on the issued document.
		$before = $this->state();
		$this->vat->save( $this->creator, false );
		$again = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $again );

		self::assertSame( $document->snapshot, $again['document']->snapshot );
		self::assertSame( $api, MePayoutsController::settlement_document( $again ) );
		self::assertSame( $pdf, ( new SettlementPdf() )->render( $again ), 'The same bytes.' );
		self::assertSame( array_diff_key( $before, array( 'audit' => true ) ), array_diff_key( $this->state(), array( 'audit' => true ) ), 'Nothing of the document, its rows, the counter or the payouts changed.' );
	}

	public function test_a_vat_history_registration_changes_no_issued_document_and_applies_to_new_sales_only(): void {
		// VAT registered at the first sales: 2 × 20.00 commission incl. 25 % VAT.
		$this->earn( 2 );
		$first = $this->request( '10', 'key-set-0305' );
		$this->decisions->complete( $first->id );
		$issued = $this->documents->for_payout( $first->id );
		self::assertNotNull( $issued );
		$api = MePayoutsController::settlement_document( $issued );
		$pdf = ( new SettlementPdf() )->render( $issued );

		$creators = new CreatorRepository( $this->wpdb );
		$links    = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$settings = new CreatorPricingSettings( $creators, $this->markups, new ProductMarkupRepository( $this->wpdb ), $links, new PostMetaPricingProductStore(), $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->vat, new CreatorVatHistoryRepository( $this->wpdb ), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) ) );

		// ADR-062 K06: registered after the fact as not VAT registered since before those sales.
		self::assertSame( 'vat_saved', $settings->record_vat_status( $this->creator, false, '2026-08-01', '2026-11-30', 'CVR 12345678', 'Deregistered; found at a later check.' ) );
		$again = $this->documents->for_payout( $first->id );
		self::assertNotNull( $again );

		self::assertSame( $issued['document']->snapshot, $again['document']->snapshot, 'The issued document keeps its VAT on the commission.' );
		self::assertSame( $api, MePayoutsController::settlement_document( $again ) );
		self::assertSame( $pdf, ( new SettlementPdf() )->render( $again ) );
		self::assertSame( '8.0000', $api['commission_vat']['vat'] ?? null );

		// A sale after the registration gets the new status; the next document has no VAT on its commission.
		$this->earn( 1, '2026-11-01 10:00:00' );
		$second = $this->request( '5', 'key-set-0306' );
		$this->decisions->complete( $second->id );
		$next = $this->documents->for_payout( $second->id );
		self::assertNotNull( $next );
		self::assertSame( array( '0.0000', '16.0000' ), array( $next['document']->snapshot[ CommissionVat::SNAPSHOT_KEY ]['vat'] ?? null, $next['document']->snapshot[ CommissionVat::SNAPSHOT_KEY ]['untaxed'] ?? null ), 'Not VAT registered: 16.00 excl. VAT (ADR-064).' );
		self::assertNull( MePayoutsController::settlement_document( $next )['commission_vat'] );
	}

	public function test_no_vat_on_the_commission_for_sales_while_not_vat_registered(): void {
		$this->vat->save( $this->creator, false );
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0302' );
		$this->decisions->complete( $payout->id );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );

		self::assertSame(
			array(
				'version'  => 1,
				'rate_bps' => 2500,
				'incl'     => '0.0000',
				'vat'      => '0.0000',
				'excl'     => '0.0000',
				'untaxed'  => '32.0000',
			),
			$shown['document']->snapshot[ CommissionVat::SNAPSHOT_KEY ] ?? null
		);
		self::assertNull( MePayoutsController::settlement_document( $shown )['commission_vat'], 'K12: nothing that could be read as VAT on the commission.' );

		$pdf = ( new SettlementPdf() )->render( $shown );
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Template version 6.' ) ), $pdf );
		// ADR-064: the VAT deducted from the payout is shown instead.
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'VAT deducted (not VAT registered when sold)' ) ), $pdf );
		self::assertSame(
			array(
				'gross'     => '40.0000',
				'deduction' => '8.0000',
				'net'       => '32.0000',
			),
			MePayoutsController::settlement_document( $shown )['vat_deduction']
		);

		foreach ( array( 'VAT on the commission', 'Commission excl. VAT', 'Commission incl. VAT' ) as $text ) {
			self::assertStringNotContainsString( PdfWriter::escape( PdfWriter::encode( $text ) ), $pdf, $text );
		}

		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		self::assertStringNotContainsString( 'Commission excl. VAT', $this->page_html( $page, $payout->id ) );
	}

	public function test_each_row_uses_the_vat_status_at_its_sale(): void {
		$this->earn( 1 );
		$this->vat->save( $this->creator, false );
		$this->earn( 1 );
		$payout = $this->request( '10', 'key-set-0303' );
		$this->decisions->complete( $payout->id );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );

		self::assertFalse( $shown['document']->vat_registered, 'The status at issue.' );
		self::assertSame( array( '20.0000', '4.0000', '16.0000', '16.0000' ), array_map( static fn( string $key ): mixed => $shown['document']->snapshot[ CommissionVat::SNAPSHOT_KEY ][ $key ] ?? null, array( 'incl', 'vat', 'excl', 'untaxed' ) ), 'The sale while registered carries VAT; the later one is paid excl. VAT (ADR-064).' );
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Commission without VAT (not VAT registered when sold)' ) ), ( new SettlementPdf() )->render( $shown ) );
	}

	public function test_an_earlier_document_never_shows_vat_on_the_commission(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0304' );
		$this->decisions->complete( $payout->id );
		$issued = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $issued );
		// A document as issued with template version 2, before the VAT on the commission (made by the test).
		$legacy = $issued['document']->snapshot;
		unset( $legacy[ CommissionVat::SNAPSHOT_KEY ] );
		$this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'template_version' => 2, 'snapshot' => (string) wp_json_encode( $legacy ) ), array( 'id' => $issued['document']->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );

		$pdf = ( new SettlementPdf() )->render( $shown );

		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Template version 2.' ) ), $pdf );
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Tax and reporting' ) ), $pdf );
		self::assertStringNotContainsString( PdfWriter::escape( PdfWriter::encode( 'VAT on the commission' ) ), $pdf );
		self::assertNull( MePayoutsController::settlement_document( $shown )['commission_vat'] );

		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		self::assertStringNotContainsString( 'Commission excl. VAT', $this->page_html( $page, $payout->id ) );
	}

	public function test_master_data_is_stored_at_issue_and_never_changed_by_later_master_data(): void {
		$this->settings->set_company_cvr( '12345678' );
		$this->settings->set_billing_details(
			$this->creator,
			array(
				'legal_name' => 'Alpha Merch ApS',
				'address_1'  => 'Nørregade 2',
				'postcode'   => '8000',
				'city'       => 'Aarhus C',
				'country'    => 'Danmark',
				'cvr'        => '87654321',
			)
		);
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0401' );
		$this->decisions->complete( $payout->id );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		$document = $shown['document'];

		self::assertSame( 6, $document->template_version );
		self::assertSame(
			array(
				'id'             => $this->creator,
				'name'           => 'Settlement Alpha',
				'vat_registered' => true,
				'legal_name'     => 'Alpha Merch ApS',
				'address'        => array( 'Nørregade 2', '8000 Aarhus C', 'Danmark' ),
				'cvr'            => '87654321',
			),
			$document->snapshot['creator'] ?? null
		);
		self::assertSame( '12345678', $document->snapshot['company']['cvr'] ?? null );

		$api = MePayoutsController::settlement_document( $shown );
		self::assertSame(
			array(
				'creator_legal_name' => 'Alpha Merch ApS',
				'creator_address'    => array( 'Nørregade 2', '8000 Aarhus C', 'Danmark' ),
				'creator_cvr'        => '87654321',
				'company_cvr'        => '12345678',
			),
			$api['master_data']
		);
		self::assertSame( 'Settlement Alpha', $api['creator_name'], 'The display name as before.' );

		$pdf = ( new SettlementPdf() )->render( $shown );

		foreach ( array( 'on behalf of Alpha Merch ApS.', 'CVR no.: 12345678', 'Display name: Settlement Alpha', 'Nørregade 2', '8000 Aarhus C', 'CVR/SE no.: 87654321', 'Template version 6.' ) as $text ) {
			self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( $text ) ), $pdf, $text );
		}

		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		$html = $this->page_html( $page, $payout->id );

		foreach ( array( 'Legal name', 'Alpha Merch ApS', 'Nørregade 2, 8000 Aarhus C, Danmark', 'CVR/SE no.', '87654321', 'Issuer CVR no.', '12345678' ) as $text ) {
			self::assertStringContainsString( esc_html( $text ), $html, $text );
		}

		// Later master data of both parties changes nothing on the issued document.
		$before = $this->state();
		$this->settings->set_company_cvr( '11112222' );
		$this->settings->set_billing_details( $this->creator, array( 'legal_name' => 'Other Name ApS' ) );
		( new CreatorRepository( $this->wpdb ) )->rename( $this->creator, 'Settlement Renamed' );
		$again = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $again );

		self::assertSame( $document->snapshot, $again['document']->snapshot );
		self::assertSame( $api, MePayoutsController::settlement_document( $again ) );
		self::assertSame( $pdf, ( new SettlementPdf() )->render( $again ), 'The same bytes.' );
		self::assertStringNotContainsString( 'Other Name ApS', $this->page_html( $page, $payout->id ) );
		self::assertSame( array_diff_key( $before, array( 'audit' => true ) ), array_diff_key( $this->state(), array( 'audit' => true ) ), 'Nothing of the document, its rows, the counter or the payouts changed.' );

		// The next document has the new master data.
		$this->earn( 1 );
		$next = $this->request( '5', 'key-set-0402' );
		$this->decisions->complete( $next->id );
		$later = $this->documents->for_payout( $next->id );
		self::assertNotNull( $later );
		self::assertSame( 'Other Name ApS', $later['document']->snapshot['creator']['legal_name'] ?? null );
		self::assertSame( array(), $later['document']->snapshot['creator']['address'] ?? null );
		self::assertSame( '11112222', $later['document']->snapshot['company']['cvr'] ?? null );
	}

	public function test_missing_master_data_is_stored_as_not_registered_and_never_invented(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0403' );
		$this->decisions->complete( $payout->id );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );
		$snapshot = $shown['document']->snapshot;

		self::assertSame( '', Settings::company_cvr() );
		self::assertSame( array( '', array(), '' ), array( $snapshot['creator']['legal_name'] ?? null, $snapshot['creator']['address'] ?? null, $snapshot['creator']['cvr'] ?? null ) );
		self::assertSame( '', $snapshot['company']['cvr'] ?? null );
		self::assertSame(
			array(
				'creator_legal_name' => '',
				'creator_address'    => array(),
				'creator_cvr'        => '',
				'company_cvr'        => '',
			),
			MePayoutsController::settlement_document( $shown )['master_data']
		);

		$pdf = ( new SettlementPdf() )->render( $shown );

		foreach ( array( 'on behalf of Settlement Alpha.', 'CVR no.: Not registered', 'Address: Not registered', 'CVR/SE no.: Not registered' ) as $text ) {
			self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( $text ) ), $pdf, $text );
		}

		self::assertStringNotContainsString( PdfWriter::escape( PdfWriter::encode( 'Display name:' ) ), $pdf );

		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		self::assertSame( 4, substr_count( $this->page_html( $page, $payout->id ), 'Not registered' ), 'Legal name, address, CVR/SE number and the issuer\'s CVR number.' );

		// Master data registered later is never filled into the issued document.
		$this->settings->set_company_cvr( '12345678' );
		$this->settings->set_billing_details( $this->creator, array( 'cvr' => '87654321' ) );
		$again = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $again );
		self::assertSame( $snapshot, $again['document']->snapshot );
		self::assertSame( $pdf, ( new SettlementPdf() )->render( $again ) );
	}

	public function test_an_earlier_document_never_shows_master_data(): void {
		$this->settings->set_company_cvr( '12345678' );
		$this->settings->set_billing_details( $this->creator, array( 'legal_name' => 'Alpha Merch ApS' ) );
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0404' );
		$this->decisions->complete( $payout->id );
		$issued = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $issued );
		// A document as issued with template version 3, before the master data (made by the test).
		$legacy = $issued['document']->snapshot;
		unset( $legacy['creator']['legal_name'], $legacy['creator']['address'], $legacy['creator']['cvr'], $legacy['company']['cvr'] );
		$this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'template_version' => 3, 'snapshot' => (string) wp_json_encode( $legacy ) ), array( 'id' => $issued['document']->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$shown = $this->documents->for_payout( $payout->id );
		self::assertNotNull( $shown );

		$pdf = ( new SettlementPdf() )->render( $shown );

		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Template version 3.' ) ), $pdf );
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'on behalf of Settlement Alpha.' ) ), $pdf );

		foreach ( array( 'CVR no.:', 'CVR/SE no.:', 'Not registered', 'Alpha Merch ApS', '12345678' ) as $text ) {
			self::assertStringNotContainsString( PdfWriter::escape( PdfWriter::encode( $text ) ), $pdf, $text );
		}

		self::assertNull( MePayoutsController::settlement_document( $shown )['master_data'] );

		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );
		$html = $this->page_html( $page, $payout->id );

		foreach ( array( 'Issuer CVR no.', 'Not registered', 'Alpha Merch ApS' ) as $text ) {
			self::assertStringNotContainsString( $text, $html, $text );
		}
	}

	public function test_the_admin_page_shows_the_note_or_the_original_mark_and_links_the_pdf(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0206' );
		$this->decisions->complete( $payout->id );
		$page = new PayoutsPage( $this->repository, $this->decisions, new PayoutCipher( $this->key ), new CreatorRepository( $this->wpdb ), new RecordingLogger(), null, $this->documents );

		$html = $this->page_html( $page, $payout->id );

		self::assertStringContainsString( 'Tax and reporting', $html );
		self::assertStringContainsString( esc_html( SettlementPdf::tax_note()[1] ), $html );
		self::assertStringNotContainsString( 'Preliminary, not approved', $html );
		self::assertStringContainsString( 'Download PDF', $html );
		self::assertStringContainsString( esc_url( SettlementPdfDownload::url( $payout->id ) ), $html );

		$document = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $document );
		// A document as issued before template version 2 (made by the test).
		$this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), array( 'template_version' => 1 ), array( 'id' => $document->id ) );
		$html = $this->page_html( $page, $payout->id );

		self::assertStringContainsString( 'Preliminary, not approved by an auditor or SKAT.', $html );
		self::assertStringNotContainsString( 'Tax and reporting', $html );
		self::assertStringContainsString( 'Download PDF', $html );
	}

	public function test_concurrent_issues_on_two_connections_get_unique_numbers_and_the_waiting_one_rolls_back(): void {
		$creators = new CreatorRepository( $this->wpdb );
		$beta     = $creators->create( 'Settlement Beta', CreatorStatus::Active )->id;
		$this->markups->save( $beta, new MarkupBps( 2000 ) );
		$this->vat->save( $beta, true );
		$product = $this->posts->add( 'MB-SET product B', 'publish', array( (string) $beta ) );
		$this->posts->meta( $product, '_price', '100' );
		$this->earn( 2 );
		$this->earn( 2, '2026-09-01 10:05:00', $beta, $product );
		$alpha_payout = $this->request( '10', 'key-set-0101' );
		$beta_payout  = $this->requests->request( $beta, 'DKK', '10', 'bank', array( 'registration' => '1234', 'account' => '0012345678' ), 'key-set-0102', 1 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$main         = $this->stack( $this->wpdb, 0 );
		$refused      = null;

		// Connection 2 completes Alpha's payout. Right after its first settled row (document
		// AFR-000001 and the number counter written, not committed), connection 1 completes
		// Beta's payout: a different creator lock, so it gets as far as the shared counter.
		$second = $this->second_connection(
			'INSERT',
			$this->table( Tables::SETTLEMENT_ROWS ),
			function () use ( $main, $beta_payout, &$refused ): void {
				$refused = $this->while_waiting( fn() => $main->complete( $beta_payout->id ) );
			}
		);
		$this->stack( $second, 0 )->complete( $alpha_payout->id );

		self::assertInstanceOf( DatabaseException::class, $refused, 'The waiting issue times out instead of taking a number twice.' );
		self::assertGreaterThanOrEqual( 0.9, $this->waited, 'It really waited for the other connection\'s lock.' );
		self::assertSame( PayoutStatus::Completed, $this->repository->find( $alpha_payout->id )?->status );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $beta_payout->id )?->status, 'Rolled back: no completion without its document.' );
		self::assertNull( $this->documents->for_payout( $beta_payout->id ) );
		self::assertSame( 'AFR-000001', $this->documents->for_payout( $alpha_payout->id )['document']->number ?? null );
		self::assertCount( 1, $this->audit( AuditAction::PayoutCompleted ) );

		$this->decisions->complete( $beta_payout->id );
		self::assertSame( 'AFR-000002', $this->documents->for_payout( $beta_payout->id )['document']->number ?? null, 'The next free number; none was lost or taken twice.' );
		self::assertSame( array( 2, 4 ), $this->settlement_counts(), 'Two sales of each creator.' );
		self::assertSame( 2, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(DISTINCT document_number) FROM %i', $this->table( Tables::SETTLEMENT_DOCUMENTS ) ) ) );
	}

	public function test_a_concurrent_completion_of_the_same_creator_is_refused_and_settles_nothing_twice(): void {
		$this->earn( 3 );
		$first   = $this->request( '10', 'key-set-0103' );
		$other   = $this->request( '5', 'key-set-0104' );
		$main    = $this->stack( $this->wpdb, 0 );
		$refused = null;

		$second = $this->second_connection(
			'INSERT',
			$this->table( Tables::SETTLEMENT_ROWS ),
			function () use ( $main, $other, &$refused ): void {
				$refused = $this->while_waiting( fn() => $main->complete( $other->id ) );
			}
		);
		$this->stack( $second, 0 )->complete( $first->id );

		self::assertInstanceOf( PayoutException::class, $refused );
		self::assertSame( PayoutException::BUSY, $refused->reason(), 'The creator lock is held by the other connection.' );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $other->id )?->status );
		self::assertSame( array( 1, 3 ), $this->settlement_counts() );

		$this->decisions->complete( $other->id );
		$shown = $this->documents->for_payout( $other->id );
		self::assertSame( 'AFR-000002', $shown['document']->number ?? null );
		self::assertSame( array(), $shown['rows'] ?? null, 'Every released row is already in AFR-000001.' );
		self::assertSame( array( 2, 3 ), $this->settlement_counts() );
	}

	public function test_a_row_settled_on_another_connection_is_never_settled_twice_and_the_waiting_issue_rolls_back(): void {
		$this->earn( 3 );
		$payout = $this->request( '10', 'key-set-0105' );
		$keys   = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT event_key FROM %i ORDER BY id', $this->table( Tables::SALES ) ) );
		self::assertCount( 3, $keys );

		// Past the creator lock (a defect or a manual write): connection 2 holds an uncommitted
		// settled row for the first sale; the unique event key is the last guard.
		$second = $this->second_connection( '', '', null );
		$second->query( 'START TRANSACTION' );
		self::assertSame( 1, $second->insert( $this->table( Tables::SETTLEMENT_ROWS ), $this->settled_row( (string) $keys[0] ) ) );

		$refused = $this->while_waiting( fn() => $this->stack( $this->wpdb, 0 )->complete( $payout->id ) );

		self::assertInstanceOf( DatabaseException::class, $refused );
		self::assertGreaterThanOrEqual( 0.9, $this->waited, 'It really waited for the other connection\'s row lock.' );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );
		self::assertSame( array( 0, 0 ), $this->settlement_counts(), 'Fully rolled back.' );
		self::assertSame( 0, $this->count_rows( Tables::SETTLEMENT_COUNTERS ), 'The number is given back.' );
		self::assertSame( array(), $this->audit( AuditAction::SettlementDocumentIssued ) );

		$second->query( 'COMMIT' );
		$this->decisions->complete( $payout->id );
		$shown = $this->documents->for_payout( $payout->id );
		self::assertSame( 'AFR-000001', $shown['document']->number ?? null );
		self::assertSame( array_slice( $keys, 1 ), array_map( static fn( array $row ): string => (string) $row['event_key'], $shown['rows'] ?? array() ), 'Only the rows not yet settled.' );
		self::assertSame( 3, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(DISTINCT event_key) FROM %i', $this->table( Tables::SETTLEMENT_ROWS ) ) ) );
		self::assertSame( 3, $this->count_rows( Tables::SETTLEMENT_ROWS ), 'Every ledger row once.' );
	}

	/**
	 * Payout services on a database connection (the test's key and clock).
	 *
	 * @param wpdb $db           Connection.
	 * @param int  $lock_timeout Seconds to wait for a creator lock.
	 */
	private function stack( wpdb $db, int $lock_timeout ): PayoutDecisions {
		$clock       = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$transaction = new Transaction( $db, new TransactionSupport( $db ) );
		$audit       = new AuditTrail( new AuditRepository( $db ) );
		$repository  = new PayoutRepository( $db, $lock_timeout );
		$documents   = new SettlementDocuments( new SettlementRepository( $db ), $repository, new LedgerCreatorPayouts( $db ), new CreatorRepository( $db ), new CreatorVatRepository( $db ), $audit, $transaction, $clock );

		return new PayoutDecisions( $repository, new PayoutBalances( new LedgerCreatorPayouts( $db ), $repository, $clock ), new PayoutCipher( $this->key ), $audit, $transaction, $clock, $documents );
	}

	/**
	 * A second, real database connection (own session, locks and
	 * transactions) with the test's table prefix. It runs $hook once, right
	 * after its first query that contains both needles, while its own
	 * transaction is still open.
	 *
	 * @param string        $verb  First needle ('' = no hook).
	 * @param string        $table Second needle.
	 * @param callable|null $hook  Work of the other connection.
	 */
	private function second_connection( string $verb, string $table, ?callable $hook ): wpdb {
		$second = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {

			/**
			 * Hook and its needles.
			 *
			 * @var array{0: string, 1: string, 2: callable}|null
			 */
			public ?array $after = null;

			/**
			 * Runs the query, then the hook once.
			 *
			 * @param string $query Query.
			 * @return int|bool
			 */
			public function query( $query ) {
				$result = parent::query( $query );
				$after  = $this->after;

				if ( null !== $after && str_starts_with( ltrim( $query ), $after[0] ) && str_contains( $query, $after[1] ) ) {
					$this->after = null;
					( $after[2] )();
				}

				return $result;
			}
		};
		$second->set_prefix( $this->wpdb->prefix );
		$second->after = null === $hook ? null : array( $verb, $table, $hook );
		$this->second  = $second;
		self::assertNotSame( $this->wpdb->get_var( 'SELECT CONNECTION_ID()' ), $second->get_var( 'SELECT CONNECTION_ID()' ), 'Two real connections.' );

		return $second;
	}

	/**
	 * Runs work on the test's connection with a 1-second InnoDB lock wait
	 * and returns what it threw (null when it returned).
	 *
	 * @param callable $work Work.
	 */
	private function while_waiting( callable $work ): ?\Throwable {
		$previous = (int) $this->wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		$this->wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$this->wpdb->suppress_errors( true );
		$start = microtime( true );

		try {
			$work();
			return null;
		} catch ( \Throwable $e ) {
			return $e;
		} finally {
			$this->waited = microtime( true ) - $start;
			$this->wpdb->suppress_errors( false );
			$restore = $this->wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', $previous );

			if ( null !== $restore ) {
				$this->wpdb->query( $restore );
			}
		}
	}

	/**
	 * The admin page of one payout.
	 *
	 * @param PayoutsPage $page      Page.
	 * @param int         $payout_id Payout.
	 */
	private function page_html( PayoutsPage $page, int $payout_id ): string {
		$_GET = array( 'payout' => (string) $payout_id );
		ob_start();

		try {
			$page->render();
		} finally {
			$html = (string) ob_get_clean();
			$_GET = array();
		}

		return $html;
	}

	/**
	 * Everything a settlement or its PDF could change: documents, settled
	 * rows, the number counters, payouts and the audit log.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$wpdb = $this->wpdb;

		return array(
			'documents' => $wpdb->get_results( (string) $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $this->table( Tables::SETTLEMENT_DOCUMENTS ) ), ARRAY_A ),
			'rows'      => $wpdb->get_results( (string) $wpdb->prepare( 'SELECT * FROM %i ORDER BY event_key', $this->table( Tables::SETTLEMENT_ROWS ) ), ARRAY_A ),
			'counters'  => $wpdb->get_results( (string) $wpdb->prepare( 'SELECT * FROM %i ORDER BY series', $this->table( Tables::SETTLEMENT_COUNTERS ) ), ARRAY_A ),
			'payouts'   => $wpdb->get_results( (string) $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $this->table( Tables::PAYOUTS ) ), ARRAY_A ),
			'audit'     => $wpdb->get_results( (string) $wpdb->prepare( 'SELECT id, action FROM %i ORDER BY id', $this->table( Tables::AUDIT_LOG ) ), ARRAY_A ),
		);
	}

	/**
	 * Numbers of settlement documents and settled rows.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function settlement_counts(): array {
		return array( $this->count_rows( Tables::SETTLEMENT_DOCUMENTS ), $this->count_rows( Tables::SETTLEMENT_ROWS ) );
	}

	/**
	 * A settled row of a stray document (raw, for the unique-key guard).
	 *
	 * @param string $event_key Ledger event.
	 * @return array<string, int|string>
	 */
	private function settled_row( string $event_key ): array {
		return array(
			'event_key'      => $event_key,
			'document_id'    => 999999,
			'order_id'       => 9101,
			'order_item_id'  => 10101,
			'product_id'     => $this->product,
			'type'           => 'sale',
			'quantity'       => 1,
			'net_amount'     => '80',
			'tax_amount'     => '20',
			'share_bps'      => 2000,
			'vat_registered' => 1,
			'price_decimals' => 2,
			'profit_amount'  => '20',
			'payout_amount'  => '20',
			'occurred_at'    => '2026-09-01 10:05:00',
		);
	}

	/**
	 * A bank request of the creator, as user 1.
	 *
	 * @param string $amount Amount.
	 * @param string $key    Request key.
	 */
	private function request( string $amount, string $key ): Payout {
		return $this->requests->request( $this->creator, 'DKK', $amount, 'bank', array( 'registration' => '1234', 'account' => '0012345678' ), $key, 1 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Sales of 100.00 incl. VAT (payout 20.00 each).
	 *
	 * @param int      $count   Number of sales.
	 * @param string   $paid    Payment time (UTC).
	 * @param int|null $creator Creator (null = the test's creator).
	 * @param int|null $product Product of that creator (null = the test's product).
	 * @return list<int> Order item ids.
	 */
	private function earn( int $count, string $paid = '2026-09-01 10:05:00', ?int $creator = null, ?int $product = null ): array {
		$items = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$order = $this->next_order++;
			$item  = 1000 + $order;
			$this->recorder->record( $item, $order, $product ?? $this->product, 0, 'DKK' );
			$this->order( $item, $order, array(), $paid, $creator, $product );
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Sets an order with one line and synchronizes it. Without a recorded
	 * snapshot (see earn()) the line's payout is unknown.
	 *
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param int              $item     Order item.
	 * @param int              $order_id Order.
	 * @param RefundSnapshot[] $refunds  Refunds.
	 * @param string           $paid     Payment time (UTC).
	 * @param int|null         $creator  Creator (null = the test's creator).
	 * @param int|null         $product  Product (null = the test's product).
	 */
	private function order( int $item, int $order_id, array $refunds = array(), string $paid = '2026-09-01 10:05:00', ?int $creator = null, ?int $product = null ): void {
		$this->reader->orders[ $order_id ] = array(
			'status'    => 'processing',
			'currency'  => 'DKK',
			'date_paid' => $paid,
			'lines'     => array( new OrderLineSnapshot( $item, $product ?? $this->product, 0, 'MB-SET line', 1, SalesAmount::parse( '80' ), $creator ?? $this->creator, SalesAmount::parse( '20' ) ) ),
			'refunds'   => $refunds,
		);
		$this->sync->sync( $order_id );
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
	 * Contexts of the audit records of an action, oldest first.
	 *
	 * @param AuditAction $action Action.
	 * @return list<mixed>
	 */
	private function audit( AuditAction $action ): array {
		$contexts = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT context FROM %i WHERE action = %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), $action->value ) );

		return array_map( static fn( ?string $json ): mixed => json_decode( (string) $json, true ), $contexts );
	}
}
