<?php
/**
 * The creator's own master data, needed before the first payout, and the
 * encrypted CPR number of a private person (ADR-104), on a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Payouts\CprNumber;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\BillingDetailsReview;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetailsStore;
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
 * Three sales of 100.00 incl. VAT (payout 20.00 each) for one creator
 * without settlement documents. Synthetic data only; the CPR number is a
 * made-up test number.
 */
final class BillingDetailsReviewTest extends IntegrationTestCase {

	private const CPR = '0101901234';

	private const BUSINESS = array(
		'type'       => 'business',
		'legal_name' => 'Nova ApS',
		'address_1'  => 'Nørregade 2',
		'address_2'  => '',
		'postcode'   => '8000',
		'city'       => 'Aarhus C',
		'country'    => 'Danmark',
		'cvr'        => '12345678',
	);

	private ProductPosts $posts;

	private PayoutRepository $repository;

	private PayoutRequests $requests;

	private PayoutDecisions $decisions;

	private BillingDetailsReview $review;

	private CreatorBillingDetailsStore $billing;

	private PayoutSettings $settings;

	private int $creator;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Master data Alpha', CreatorStatus::Active )->id;
		$markups       = new CreatorMarkupRepository( $this->wpdb );
		$vat           = new CreatorVatRepository( $this->wpdb );
		$markups->save( $this->creator, new MarkupBps( 2000 ) );
		$vat->save( $this->creator, true );

		$transaction = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$this->posts = new ProductPosts( $this->wpdb );
		$product     = $this->posts->add( 'MB-BD product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $product, '_regular_price', '100' );
		$this->posts->meta( $product, '_price', '100' );
		$links    = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $vat );
		$reader   = new FakeOrderReader();
		$sync     = new SalesSynchronizer( new SalesRepository( $this->wpdb, $transaction ), new SalesPlanner(), $reader, new RecordingFailureMarker(), new RecordingLogger(), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) ) );

		for ( $order = 9801; $order <= 9803; $order++ ) {
			$recorder->record( 1000 + $order, $order, $product, 0, 'DKK' );
			$reader->orders[ $order ] = array(
				'status'    => 'processing',
				'currency'  => 'DKK',
				'date_paid' => '2026-09-01 10:05:00',
				'lines'     => array( new OrderLineSnapshot( 1000 + $order, $product, 0, 'MB-BD line', 1, SalesAmount::parse( '80' ), $this->creator, SalesAmount::parse( '20' ) ) ),
				'refunds'   => array(),
			);
			$sync->sync( $order );
		}

		$clock            = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$audit            = $this->service( AuditTrail::class );
		$cipher           = new PayoutCipher( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$this->repository = new PayoutRepository( $this->wpdb );
		$this->billing    = new CreatorBillingDetailsStore( new CreatorMetaStore( $this->wpdb ) );
		$balances         = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock );
		$documents        = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), $creators, $vat, $audit, $transaction, $clock, null, $this->billing );
		$this->review     = new BillingDetailsReview( $this->billing, $this->repository, $audit, $transaction, $clock, $cipher );
		$this->requests   = new PayoutRequests( $this->repository, $balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock, null, null, fn( int $id ): bool => $this->review->allows_request( $id ), fn( int $id ): ?CprNumber => $this->review->cpr( $id ) );
		$this->decisions  = new PayoutDecisions( $this->repository, $balances, $cipher, $audit, $transaction, $clock, $documents, fn( int $id ): bool => $this->review->allows_completion( $id ), fn( int $id ): bool => $this->review->cpr_required( $id ) );
		$this->settings   = new PayoutSettings( $this->repository, $audit, $transaction, $clock, $this->billing );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_the_first_payout_needs_master_data_which_apply_at_once(): void {
		self::assertSame( BillingDetailsReview::MISSING, $this->review->state( $this->creator )->status );
		$this->refused( fn() => $this->request( 'key-bd-0001' ), PayoutException::BILLING_DETAILS_REQUIRED );

		self::assertTrue( $this->review->submit( $this->creator, self::BUSINESS ) );
		self::assertFalse( $this->review->submit( $this->creator, self::BUSINESS ), 'The same values again change nothing.' );

		$state = $this->review->state( $this->creator );
		self::assertSame( array( BillingDetailsReview::CONFIRMED, false, false ), array( $state->status, $state->required, $state->cpr_required ) );

		$payout = $this->request( 'key-bd-0002' );
		self::assertSame( PayoutStatus::Completed, $this->decisions->complete( $payout->id )->status, 'No confirmation: the first payout can be completed.' );
		$snapshot = (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT snapshot FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::SETTLEMENT_DOCUMENTS ) ) );
		self::assertStringContainsString( '"legal_name":"Nova ApS"', $snapshot );
		self::assertStringContainsString( '"cvr":"12345678"', $snapshot );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertContains( 'billing_details_submitted', array_column( $audit, 'action' ) );
		self::assertStringNotContainsString( 'Nørregade', (string) wp_json_encode( $audit ), 'Values are never audited.' );

		// After the first settlement document nothing is held back.
		$this->billing->save( $this->creator, new \MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetails() );
		self::assertTrue( $this->review->allows_request( $this->creator ) && $this->review->allows_completion( $this->creator ) );
	}

	public function test_a_private_person_saves_the_cpr_number_once_encrypted_and_it_goes_with_each_payout(): void {
		$private = array(
			'type' => 'private',
			'cvr'  => '',
		) + self::BUSINESS;

		try {
			$this->review->submit( $this->creator, $private );
			self::fail( 'Expected BillingDetailsInvalid.' );
		} catch ( \MerchBoard\Modules\WooCommerce\Payouts\Settlements\BillingDetailsInvalid $e ) {
			self::assertSame( array( 'cpr' => 'required' ), $e->fields() );
		}

		self::assertTrue( $this->review->submit( $this->creator, array( 'cpr' => '010190-1234' ) + $private ) );
		$state = $this->review->state( $this->creator );
		self::assertSame( array( true, true ), array( $state->cpr_required, $state->cpr_saved ) );

		$stored = (string) wp_json_encode( $this->billing->cpr( $this->creator ) );
		self::assertStringNotContainsString( self::CPR, $stored, 'Stored encrypted.' );
		self::assertStringNotContainsString( self::CPR, (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );
		self::assertTrue( $this->review->submit( $this->creator, array( 'city' => 'Aarhus N' ) + $private ), 'Saved without typing it again.' );
		self::assertSame( self::CPR, $this->review->cpr( $this->creator )?->value );

		$payout = $this->request( 'key-bd-0003' );
		self::assertSame( self::CPR, $this->decisions->details( $payout->id )->cpr, 'Copied into the payout for the report.' );
		$this->refused( fn() => $this->decisions->complete( $payout->id, 'CPR 010190-1234' ), PayoutException::PAYMENT_DETAILS_IN_TEXT );
		$this->decisions->complete( $payout->id, 'Bank 7781' );
		self::assertNull( $this->repository->secret( $payout->id ), "The payout's copy is deleted once handled." );
		self::assertNotNull( $this->billing->cpr( $this->creator ), 'The master data keep it.' );

		$everything = (string) wp_json_encode( array( $this->rows( Tables::AUDIT_LOG ), $this->rows( Tables::SETTLEMENT_DOCUMENTS ), $this->rows( Tables::PAYOUTS ) ) );
		self::assertStringNotContainsString( self::CPR, $everything );

		// Becoming a business removes it.
		$this->review->submit( $this->creator, self::BUSINESS );
		self::assertNull( $this->billing->cpr( $this->creator ) );
		self::assertNull( $this->review->cpr( $this->creator ) );
	}

	public function test_a_private_person_without_a_cpr_number_cannot_be_paid(): void {
		// Entered by an administrator as a private person, without a CPR number.
		$this->settings->set_billing_details(
			$this->creator,
			array(
				'type' => 'private',
				'cvr'  => '',
			) + self::BUSINESS
		);
		$this->refused( fn() => $this->request( 'key-bd-0004' ), PayoutException::CPR_MISSING );

		// Requested before the type was chosen, completed after: refused.
		$this->settings->set_billing_details( $this->creator, array( 'type' => '' ) + self::BUSINESS );
		$payout = $this->request( 'key-bd-0005' );
		$this->settings->set_billing_details(
			$this->creator,
			array(
				'type' => 'private',
				'cvr'  => '',
			) + self::BUSINESS
		);
		$this->refused( fn() => $this->decisions->complete( $payout->id ), PayoutException::CPR_MISSING );
		self::assertSame( PayoutStatus::Rejected, $this->decisions->reject( $payout->id, 'Tilføj CPR-nummeret i stamdata og anmod igen.' )->status );
	}

	/**
	 * A bank request of 10.00, as user 1.
	 *
	 * @param string $key Request key.
	 */
	private function request( string $key ): Payout {
		$details = array(
			'registration' => '1234',
			'account'      => '0012345678',
		);

		return $this->requests->request( $this->creator, 'DKK', '10', 'bank', $details, $key, 1 );
	}

	/**
	 * Requires a refusal.
	 *
	 * @param callable $call   Call.
	 * @param string   $reason Expected reason.
	 */
	private function refused( callable $call, string $reason ): void {
		try {
			$call();
			self::fail( 'Expected PayoutException ' . $reason );
		} catch ( PayoutException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
