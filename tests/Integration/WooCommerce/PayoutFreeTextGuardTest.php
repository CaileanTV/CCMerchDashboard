<?php
/**
 * Payment details refused in payout references and rejection reasons, on a real database.
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
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\PayoutActions;
use MerchBoard\Modules\WooCommerce\Admin\PayoutsPage;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
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
 * Three sales of 100.00 incl. VAT (payout 20.00 each) and bank payouts to
 * registration 1234, account 0012345678. A refused text changes nothing and
 * is never echoed, logged or audited. Synthetic data only.
 */
final class PayoutFreeTextGuardTest extends IntegrationTestCase {

	private const ACCOUNT = '0012345678';

	private ProductPosts $posts;

	private PayoutRepository $repository;

	private PayoutRequests $requests;

	private PayoutDecisions $decisions;

	private RecordingLogger $logger;

	private int $creator;

	private string $key;

	/**
	 * Services the requests and decisions share.
	 *
	 * @var array{audit: AuditTrail, transaction: Transaction, documents: SettlementDocuments, balances: PayoutBalances, clock: \Closure}
	 */
	private array $shared;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Free text Alpha', CreatorStatus::Active )->id;
		$markups       = new CreatorMarkupRepository( $this->wpdb );
		$vat           = new CreatorVatRepository( $this->wpdb );
		$markups->save( $this->creator, new MarkupBps( 2000 ) );
		$vat->save( $this->creator, true );

		$transaction = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$this->posts = new ProductPosts( $this->wpdb );
		$product     = $this->posts->add( 'MB-FT product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $product, '_regular_price', '100' );
		$this->posts->meta( $product, '_price', '100' );
		$links    = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $vat );
		$reader   = new FakeOrderReader();
		$sync     = new SalesSynchronizer( new SalesRepository( $this->wpdb, $transaction ), new SalesPlanner(), $reader, new RecordingFailureMarker(), new RecordingLogger(), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) ) );

		for ( $order = 9701; $order <= 9703; $order++ ) {
			$recorder->record( 1000 + $order, $order, $product, 0, 'DKK' );
			$reader->orders[ $order ] = array(
				'status'    => 'processing',
				'currency'  => 'DKK',
				'date_paid' => '2026-09-01 10:05:00',
				'lines'     => array( new OrderLineSnapshot( 1000 + $order, $product, 0, 'MB-FT line', 1, SalesAmount::parse( '80' ), $this->creator, SalesAmount::parse( '20' ) ) ),
				'refunds'   => array(),
			);
			$sync->sync( $order );
		}

		$clock            = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$audit            = $this->service( AuditTrail::class );
		$this->key        = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$this->repository = new PayoutRepository( $this->wpdb );
		$balances         = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock );
		$documents        = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), $creators, $vat, $audit, $transaction, $clock );
		$this->shared     = array(
			'audit'       => $audit,
			'transaction' => $transaction,
			'documents'   => $documents,
			'balances'    => $balances,
			'clock'       => $clock,
		);
		$this->requests   = new PayoutRequests( $this->repository, $balances, new PayoutCipher( $this->key ), new PostMetaPricingProductStore(), $audit, $transaction, $clock );
		$this->decisions  = $this->decisions( $this->key );
		$this->logger     = new RecordingLogger();
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_a_reference_with_the_account_number_is_refused_and_nothing_changes(): void {
		$payout = $this->request( '10', 'key-ft-0001' );
		$before = $this->state( $payout );

		foreach ( array( self::ACCOUNT, 'Overført til 1234-0012345678', 'Konto 12 345 678', 'jane@example.com', 'DK50 0040 0440 1162 43' ) as $reference ) {
			$this->refused( fn() => $this->decisions->complete( $payout->id, $reference ), $reference );
			self::assertSame( $before, $this->state( $payout ), 'Nothing written: ' . $reference );
		}

		$completed = $this->decisions->complete( $payout->id, 'Bank transaktions-ID 88213377' );
		self::assertSame( PayoutStatus::Completed, $completed->status );
		self::assertSame( 'Bank transaktions-ID 88213377', $completed->reference );
		self::assertSame( 1, $this->count_rows( Tables::SETTLEMENT_DOCUMENTS ) );
	}

	public function test_a_reason_with_payment_details_is_refused_and_an_ordinary_reason_passes(): void {
		$payout = $this->request( '10', 'key-ft-0002' );
		$before = $this->state( $payout );

		foreach ( array( 'Kontoen 0012345678 er lukket', 'Skriv til jane@example.com', 'IBAN DK5000400440116243 bruges ikke' ) as $reason ) {
			$this->refused( fn() => $this->decisions->reject( $payout->id, $reason ), $reason );
			self::assertSame( $before, $this->state( $payout ), 'Nothing written: ' . $reason );
		}

		$rejected = $this->decisions->reject( $payout->id, 'Kontonummeret passer ikke til navnet. Opret anmodningen igen.' );
		self::assertSame( PayoutStatus::Rejected, $rejected->status );
	}

	public function test_unreadable_details_still_allow_a_rejection_and_e_mail_and_iban_are_still_refused(): void {
		$payout    = $this->request( '10', 'key-ft-0003' );
		$other_key = $this->decisions( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A different test key: the details cannot be decrypted.

		$this->refused( fn() => $other_key->reject( $payout->id, 'Skriv til jane@example.com' ), 'e-mail' );
		$this->refused( fn() => $other_key->reject( $payout->id, 'DK50 0040 0440 1162 43' ), 'IBAN' );

		$rejected = $other_key->reject( $payout->id, 'Oplysningerne kan ikke læses. Opret anmodningen igen.' );
		self::assertSame( PayoutStatus::Rejected, $rejected->status, 'Without readable details the own account cannot be compared; the rejection stays possible.' );
	}

	public function test_the_admin_action_redirects_with_the_code_only_and_logs_nothing(): void {
		$payout  = $this->request( '10', 'key-ft-0004' );
		$actions = new PayoutActions( $this->decisions, $this->logger );
		$nonce   = wp_create_nonce( PayoutActions::nonce_action( PayoutActions::COMPLETE_ACTION, $payout->id ) );

		$url = $actions->handle(
			array(
				'action'           => PayoutActions::COMPLETE_ACTION,
				'payout_id'        => (string) $payout->id,
				'_wpnonce'         => $nonce,
				'confirm_transfer' => '1',
				'reference'        => 'Til ' . self::ACCOUNT,
			)
		);

		self::assertStringContainsString( 'result=' . PayoutException::PAYMENT_DETAILS_IN_TEXT, $url );
		self::assertStringNotContainsString( '12345678', $url, 'The value is never in the redirect.' );
		self::assertSame( array(), $this->logger->entries, 'Nothing is logged.' );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );

		$message = PayoutsPage::result_message( PayoutException::PAYMENT_DETAILS_IN_TEXT );
		self::assertNotNull( $message );
		self::assertSame( 'error', $message[1] );
		self::assertStringContainsString( 'must not contain bank or payment details', $message[0] );
		self::assertStringNotContainsString( '12345678', $message[0] );
	}

	/**
	 * PayoutDecisions with a key.
	 *
	 * @param string $key Base64 key.
	 */
	private function decisions( string $key ): PayoutDecisions {
		return new PayoutDecisions( $this->repository, $this->shared['balances'], new PayoutCipher( $key ), $this->shared['audit'], $this->shared['transaction'], $this->shared['clock'], $this->shared['documents'] );
	}

	/**
	 * A bank request of the creator, as user 1.
	 *
	 * @param string $amount Amount.
	 * @param string $key    Request key.
	 */
	private function request( string $amount, string $key ): Payout {
		return $this->requests->request( $this->creator, 'DKK', $amount, 'bank', array( 'registration' => '1234', 'account' => self::ACCOUNT ), $key, 1 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Requires the refusal for payment details.
	 *
	 * @param callable $call  Call.
	 * @param string   $label What was entered.
	 */
	private function refused( callable $call, string $label ): void {
		try {
			$call();
			self::fail( 'Expected a refusal: ' . $label );
		} catch ( PayoutException $e ) {
			self::assertSame( PayoutException::PAYMENT_DETAILS_IN_TEXT, $e->reason(), $label );
		}
	}

	/**
	 * What a decision writes: the payout row, its details, audit records and
	 * settlement documents; and that the text appears nowhere.
	 *
	 * @param Payout $payout Payout.
	 * @return array<string, mixed>
	 */
	private function state( Payout $payout ): array {
		$audit = (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) );

		return array(
			'payout'     => $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT status, decision_reason, reference, decided_at FROM %i WHERE id = %d', $this->table( Tables::PAYOUTS ), $payout->id ), ARRAY_A ),
			'secret'     => $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ciphertext IS NOT NULL FROM %i WHERE payout_id = %d', $this->table( Tables::PAYOUT_SECRETS ), $payout->id ) ),
			'audit'      => $this->count_rows( Tables::AUDIT_LOG ),
			'documents'  => $this->count_rows( Tables::SETTLEMENT_DOCUMENTS ),
			'audit_text' => str_contains( $audit, '12345678' ) || str_contains( $audit, 'example.com' ),
		);
	}
}
