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
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
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
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
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
		$this->documents  = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), $creators, $this->vat, $audit, $transaction, $clock );
		$this->decisions  = new PayoutDecisions( $this->repository, $balances, $cipher, $audit, $transaction, $clock, $this->documents );
		$this->requests   = new PayoutRequests( $this->repository, $balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock );
		$this->settings   = new PayoutSettings( $this->repository, $audit, $transaction, $clock );
	}

	protected function tearDown(): void {
		$this->second?->close();
		$this->posts->remove_all();
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
		self::assertSame( 3, $document->template_version, 'New documents carry the note on tax and reporting and the VAT on the commission.' );
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

	public function test_a_settled_row_keeps_its_stored_payout_and_new_rows_have_no_deduction(): void {
		// ADR-063: not VAT registered, no 20 % deduction any more.
		$this->vat->save( $this->creator, false );
		$ledger = new LedgerCreatorPayouts( $this->wpdb );
		$now    = new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );

		$this->earn( 2 );
		self::assertSame( '40.0000', SalesAmount::format( (int) $ledger->earned( $this->creator, null, $now, 14 )['DKK']->amount_units() ), '2 × 20.00: the whole profit.' );

		$first = $this->request( '10', 'key-set-0063' );
		$this->decisions->complete( $first->id );
		self::assertSame( array( '20.0000', '20.0000' ), array_map( static fn( array $row ): string => (string) $row['payout_amount'], $this->documents->for_payout( $first->id )['rows'] ?? array() ) );

		// A document issued under ADR-049's 20 % deduction keeps its stored payouts:
		// the balance follows the document, nothing is regulated automatically.
		$old = $this->documents->for_payout( $first->id )['document'] ?? null;
		self::assertNotNull( $old );
		self::assertSame( 2, $this->wpdb->update( $this->table( Tables::SETTLEMENT_ROWS ), array( 'payout_amount' => '16.0000' ), array( 'document_id' => $old->id ) ) );
		$totals = array(
			'commission_amount' => '32.0000',
			'closing_balance'   => '22.0000',
		);
		self::assertSame( 1, $this->wpdb->update( $this->table( Tables::SETTLEMENT_DOCUMENTS ), $totals, array( 'id' => $old->id ) ) );
		$this->earn( 1 );

		self::assertSame( '52.0000', SalesAmount::format( (int) $ledger->earned( $this->creator, null, $now, 14 )['DKK']->amount_units() ), '16 + 16 stored, + 20 for the new row.' );
		$unsettled = $ledger->unsettled_rows( $this->creator, 'DKK', null, $now, 14 );
		self::assertNotNull( $unsettled );
		self::assertSame( array( '20.0000' ), array_map( static fn( array $row ): string => SalesAmount::format( $row['payout_units'] ), $unsettled ), 'Only the new row, at the whole profit.' );

		$second = $this->request( '5', 'key-set-0064' );
		$this->decisions->complete( $second->id );
		$shown = $this->documents->for_payout( $second->id );

		self::assertNotNull( $shown );
		self::assertSame( 'adr063-adr054-v1', $shown['document']->calculation_version, 'New documents name the calculation without the deduction.' );
		// Opening 22 (32 − 10) + commission 20 − paid 5 = 37 = earned 52 − paid 15: chain and balance agree.
		self::assertSame( array( '22.0000', '20.0000', '5.0000', '37.0000' ), array_map( array( SalesAmount::class, 'format' ), array( $shown['document']->opening_units, $shown['document']->commission_units, $shown['document']->payout_units, $shown['document']->closing_units ) ) );
		self::assertSame( array( '16.0000', '16.0000' ), array_map( static fn( array $row ): string => (string) $row['payout_amount'], $this->documents->for_payout( $first->id )['rows'] ?? array() ), 'The earlier document is unchanged.' );
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

	public function test_a_correction_refers_to_the_latest_document_of_its_chain(): void {
		$this->earn( 2 );
		$payout = $this->request( '10', 'key-set-0009' );
		$this->decisions->complete( $payout->id );
		$original = $this->documents->for_payout( $payout->id )['document'] ?? null;
		self::assertNotNull( $original );

		$first = $this->documents->correct( $original->id, 'The creator name was misspelled.' );
		self::assertSame( array( 'AFR-000002', SettlementDocument::TYPE_CORRECTION, $original->id, 0 ), array( $first->number, $first->type, $first->corrects_document_id, $first->commission_units ) );
		self::assertSame( 'AFR-000001', $first->snapshot['corrects'] ?? null );

		$this->refused( PayoutException::DOCUMENT_NOT_CORRECTABLE, fn() => $this->documents->correct( $original->id, 'Again.' ), 'Only the latest of a chain.' );
		$second = $this->documents->correct( $first->id, 'Second fix.' );
		$this->refused( PayoutException::INVALID_REASON, fn() => $this->documents->correct( $second->id, '  ' ) );

		$shown = $this->documents->for_payout( $payout->id );
		self::assertSame( 'AFR-000001', $shown['document']->number ?? null, 'The original is never changed.' );
		self::assertSame( array( 'AFR-000002', 'AFR-000003' ), array_map( static fn( SettlementDocument $doc ): string => $doc->number, $shown['corrections'] ?? array() ) );
		self::assertSame( array( array( 'document_id' => $first->id, 'document_number' => 'AFR-000002', 'corrects' => 'AFR-000001', 'payout_id' => $payout->id, 'has_reason' => true ), array( 'document_id' => $second->id, 'document_number' => 'AFR-000003', 'corrects' => 'AFR-000002', 'payout_id' => $payout->id, 'has_reason' => true ) ), $this->audit( AuditAction::SettlementDocumentCorrected ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$user = $this->create_user( 'mb-set-subscriber' );
		wp_set_current_user( $user );
		$this->refused( PayoutException::NOT_AUTHORIZED, fn() => $this->documents->correct( $second->id, 'No.' ) );
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

		self::assertSame( 3, $document->template_version );
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

		foreach ( array( 'VAT on the commission', 'Commission excl. VAT', '48.00 DKK', 'VAT (25 %)', '12.00 DKK', 'Commission incl. VAT', '60.00 DKK', 'VAT on the sale', 'Template version 3.' ) as $text ) {
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
		self::assertSame( array( '0.0000', '20.0000' ), array( $next['document']->snapshot[ CommissionVat::SNAPSHOT_KEY ]['vat'] ?? null, $next['document']->snapshot[ CommissionVat::SNAPSHOT_KEY ]['untaxed'] ?? null ) );
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
				'untaxed'  => '40.0000',
			),
			$shown['document']->snapshot[ CommissionVat::SNAPSHOT_KEY ] ?? null
		);
		self::assertNull( MePayoutsController::settlement_document( $shown )['commission_vat'], 'K12: nothing that could be read as VAT on the commission.' );

		$pdf = ( new SettlementPdf() )->render( $shown );
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( 'Template version 3.' ) ), $pdf );

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
		self::assertSame( array( '20.0000', '4.0000', '16.0000', '20.0000' ), array_map( static fn( string $key ): mixed => $shown['document']->snapshot[ CommissionVat::SNAPSHOT_KEY ][ $key ] ?? null, array( 'incl', 'vat', 'excl', 'untaxed' ) ), 'The sale while registered carries VAT; the later one none.' );
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
