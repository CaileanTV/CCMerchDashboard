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
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
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
use wpdb;

/**
 * Sales of 100.00 incl. VAT (20 %, VAT registered: payout 20.00 each) dated
 * 2026-09-01; a fixed clock (2026-11-30 12:00 UTC) and a 14-day holding
 * period. No WooCommerce is loaded. Synthetic data only. The documents are
 * preliminary (060-9): a passing test is no accounting approval.
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

	public function test_a_completion_issues_one_preliminary_document_with_the_released_rows(): void {
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
		self::assertTrue( $document->snapshot['preliminary'] ?? null );
		self::assertSame( 'Settlement Alpha', $document->snapshot['creator']['name'] ?? null );
		self::assertSame( '•••• 5678', $document->snapshot['payout']['destination_hint'] ?? null );
		self::assertStringNotContainsString( '0012345678', (string) wp_json_encode( $document->snapshot ), 'Never the full account.' );
		self::assertSame( $items, array_map( static fn( array $row ): int => (int) $row['order_item_id'], $shown['rows'] ?? array() ) );
		self::assertSame( PayoutStatus::Completed, $this->repository->find( $payout->id )?->status );
		self::assertSame( array( array( 'document_id' => $document->id, 'document_number' => 'AFR-000001', 'payout_id' => $payout->id, 'rows' => 3, 'currency' => 'DKK' ) ), $this->audit( AuditAction::SettlementDocumentIssued ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

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
