<?php
/**
 * Admin page "Payouts" and its actions on a real database (ADR-055, ADR-056, ADR-058).
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
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\PayoutActions;
use MerchBoard\Modules\WooCommerce\Admin\PayoutsPage;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
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
use RuntimeException;
use WP_User;

/**
 * No WooCommerce is loaded (ADR-038 Q4): earnings come from real ledger
 * rows (sales of 100.00 incl. VAT at 20 %, VAT registered: payout 20.00
 * each), requests from the real PayoutRequests, and the page and actions
 * run with a fixed clock and a test key. wp_die() throws (test handler).
 * Synthetic data only.
 */
final class PayoutsAdminTest extends IntegrationTestCase {

	private const ACCOUNT = '0012345678';

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private PayoutRepository $repository;

	private PayoutRequests $requests;

	private PayoutsPage $page;

	private PayoutActions $actions;

	private string $key;

	private int $creator;

	private int $product;

	private int $next_order = 8001;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		add_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Payout Admin Alpha', CreatorStatus::Active )->id;
		$markups       = new CreatorMarkupRepository( $this->wpdb );
		$vat           = new CreatorVatRepository( $this->wpdb );
		$markups->save( $this->creator, new MarkupBps( 2000 ) );
		$vat->save( $this->creator, true );

		$this->posts    = new ProductPosts( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
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
		$this->services( $this->key );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		$this->posts->remove_all();
		$_GET = array();
		parent::tearDown();
	}

	public function test_users_without_the_payout_capability_get_no_page_and_no_action(): void {
		$this->earn( 1 );
		$payout = $this->request( '5', 'key-admin-0001' );

		foreach ( array( 'subscriber' => array(), 'manager without payouts' => array( Capabilities::MANAGE_CREATORS, Capabilities::VIEW_PAYOUT_DETAILS ) ) as $login => $caps ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$user = $this->create_user( sanitize_user( str_replace( ' ', '-', $login ) ) );

			foreach ( $caps as $cap ) {
				( new WP_User( $user ) )->add_cap( $cap );
			}

			wp_set_current_user( $user );
			$this->dies( fn() => $this->render(), $login . ': page' );
			$this->dies( fn() => $this->actions->handle( $this->reject_input( $payout->id, 'No.' ) ), $login . ': reject' );
			$this->dies( fn() => $this->actions->handle( $this->complete_input( $payout->id ) ), $login . ': complete' );
		}

		wp_set_current_user( 1 );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );
		self::assertSame( array(), $this->audit( AuditAction::PayoutRejected ) );
	}

	public function test_queue_and_history_list_masked_destinations_only(): void {
		$this->earn( 3 );
		$first  = $this->request( '10', 'key-admin-0002' );
		$second = $this->request( '15', 'key-admin-0003' );
		$this->actions->handle( $this->reject_input( $first->id, 'Closed account.' ) );

		$queue = $this->render();
		self::assertStringContainsString( 'Queue (1)', $queue );
		self::assertStringContainsString( 'History (1)', $queue );
		self::assertStringContainsString( 'Payout Admin Alpha', $queue );
		self::assertStringContainsString( '#' . $second->id, $queue );
		self::assertStringContainsString( '•••• 5678', $queue );
		self::assertStringNotContainsString( '#' . $first->id . '<', $queue );

		$history = $this->render( array( 'view' => 'history' ) );
		self::assertStringContainsString( '#' . $first->id . '<', $history );
		self::assertStringContainsString( 'Rejected', $history );

		foreach ( array( $queue, $history, $this->render( array( 'payout' => (string) $second->id ) ) ) as $html ) {
			self::assertStringNotContainsString( self::ACCOUNT, $html );
		}
	}

	public function test_nonces_are_bound_to_the_action_and_the_payout(): void {
		$this->earn( 2 );
		$payout = $this->request( '5', 'key-admin-0004' );
		$other  = $this->request( '5', 'key-admin-0005' );

		$this->dies( fn() => $this->actions->handle( array( '_wpnonce' => 'x' ) + $this->reject_input( $payout->id, 'No.' ) ) );
		$this->dies( fn() => $this->actions->handle( array( '_wpnonce' => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::REJECT_ACTION, $other->id ) ) ) + $this->reject_input( $payout->id, 'No.' ) ), 'Another payout.' );
		$this->dies( fn() => $this->actions->handle( array( '_wpnonce' => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::COMPLETE_ACTION, $payout->id ) ) ) + $this->reject_input( $payout->id, 'No.' ) ), 'Another action.' );

		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );
	}

	public function test_reject_needs_a_reason_and_never_audits_its_text(): void {
		$this->earn( 1 );
		$payout = $this->request( '5', 'key-admin-0006' );

		self::assertStringContainsString( 'result=invalid_reason', $this->actions->handle( $this->reject_input( $payout->id, '   ' ) ) );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );

		self::assertStringContainsString( 'result=rejected', $this->actions->handle( $this->reject_input( $payout->id, 'Account 0012345678 is closed.' ) ) );
		self::assertSame( PayoutStatus::Rejected, $this->repository->find( $payout->id )?->status );
		self::assertSame( array( array( 'payout_id' => $payout->id, 'amount' => '5.0000', 'currency' => 'DKK', 'method' => 'bank', 'has_reason' => true ) ), $this->audit( AuditAction::PayoutRejected ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'result=not_pending', $this->actions->handle( $this->complete_input( $payout->id ) ) );
	}

	public function test_complete_requires_the_confirmation_and_never_audits_the_reference(): void {
		$this->earn( 1 );
		$payout = $this->request( '5', 'key-admin-0007' );
		$input  = $this->complete_input( $payout->id );
		unset( $input['confirm_transfer'] );

		self::assertStringContainsString( 'result=not_confirmed', $this->actions->handle( $input ) );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );

		self::assertStringContainsString( 'result=completed', $this->actions->handle( $this->complete_input( $payout->id, 'BANK-REF-77' ) ) );
		$stored = $this->repository->find( $payout->id );
		self::assertSame( array( PayoutStatus::Completed, 'BANK-REF-77' ), array( $stored?->status, $stored?->reference ) );
		self::assertSame( array( array( 'payout_id' => $payout->id, 'amount' => '5.0000', 'currency' => 'DKK', 'method' => 'bank', 'has_reference' => true ) ), $this->audit( AuditAction::PayoutCompleted ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( 'BANK-REF-77', (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );
		self::assertStringContainsString( 'result=completed', $this->actions->handle( $this->complete_input( $payout->id ) ), 'Double submit: already registered, nothing changes (ADR-055).' );
		self::assertSame( 'BANK-REF-77', $this->repository->find( $payout->id )?->reference );
		self::assertCount( 1, $this->audit( AuditAction::PayoutCompleted ) );
	}

	public function test_a_payout_the_balance_no_longer_covers_is_refused_for_manual_handling(): void {
		$items  = $this->earn( 2 );
		$payout = $this->request( 'all', 'key-admin-0008' );
		// A full refund of one sale after the request.
		$this->order( $items[0], 8001, array( new RefundSnapshot( 7901, new DateTimeImmutable( '2026-11-01 10:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( $items[0], -1, SalesAmount::parse( '-80' ), SalesAmount::parse( '-20' ) ) ) ) ) );

		$url = $this->actions->handle( $this->complete_input( $payout->id ) );

		self::assertStringContainsString( 'result=insufficient_balance', $url );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status, 'Never completed automatically.' );
		self::assertStringContainsString( 'Handle it manually', $this->render( array( 'payout' => (string) $payout->id, 'result' => 'insufficient_balance' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array(), $this->audit( AuditAction::PayoutCompleted ) );
	}

	public function test_without_a_valid_key_pending_requests_can_only_be_rejected(): void {
		$this->earn( 1 );
		$payout = $this->request( '5', 'key-admin-0009' );
		$this->services( null );

		self::assertStringContainsString( 'No valid MERCHBOARD_PAYOUT_KEY', $this->render() );
		self::assertStringContainsString( 'result=details_unreadable', $this->actions->handle( $this->complete_input( $payout->id ) ) );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );
		self::assertStringContainsString( 'result=rejected', $this->actions->handle( $this->reject_input( $payout->id, 'Details unreadable.' ) ) );
	}

	public function test_full_details_need_both_capabilities_an_explicit_request_and_are_audited(): void {
		$this->earn( 1 );
		$payout = $this->request( '5', 'key-admin-0010' );
		$id     = (string) $payout->id;
		$nonce  = wp_create_nonce( PayoutsPage::REVEAL_ACTION . '_' . $payout->id );

		$html = $this->render( array( 'payout' => $id ) );
		self::assertStringContainsString( 'Show full payment details', $html );
		self::assertStringNotContainsString( self::ACCOUNT, $html );
		self::assertSame( array(), $this->audit( AuditAction::PayoutDetailsViewed ), 'Opening the payout is not a view of its details.' );

		self::assertStringNotContainsString( self::ACCOUNT, $this->render( array( 'payout' => $id, '_wpnonce' => 'x' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( self::ACCOUNT, $this->render( array( 'payout' => $id, '_wpnonce' => wp_create_nonce( PayoutsPage::REVEAL_ACTION . '_999' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$html = $this->render( array( 'payout' => $id, '_wpnonce' => $nonce ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( self::ACCOUNT, $html );
		self::assertStringContainsString( '1234', $html );
		self::assertSame( array( array( 'payout_id' => $payout->id, 'method' => 'bank' ) ), $this->audit( AuditAction::PayoutDetailsViewed ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( self::ACCOUNT, (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );

		$manager = $this->create_user( 'mb-pay-manager' );
		( new WP_User( $manager ) )->add_cap( Capabilities::MANAGE_PAYOUTS );
		wp_set_current_user( $manager );
		$html = $this->render( array( 'payout' => $id, '_wpnonce' => wp_create_nonce( PayoutsPage::REVEAL_ACTION . '_' . $payout->id ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'Only users allowed to see full payment details', $html );
		self::assertStringNotContainsString( self::ACCOUNT, $html );
		self::assertCount( 1, $this->audit( AuditAction::PayoutDetailsViewed ), 'No view without the capability.' );
		self::assertStringContainsString( 'result=rejected', $this->actions->handle( $this->reject_input( $payout->id, 'Handled by a manager.' ) ), 'Managing works without the details capability.' );
	}

	/**
	 * Builds services, page and actions with a cipher key (null = none).
	 *
	 * @param string|null $key Base64 key.
	 */
	private function services( ?string $key ): void {
		$clock            = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$transaction      = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$audit            = $this->service( AuditTrail::class );
		$cipher           = new PayoutCipher( $key );
		$this->repository = new PayoutRepository( $this->wpdb );
		$balances         = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock );
		$decisions        = new PayoutDecisions( $this->repository, $balances, $cipher, $audit, $transaction, $clock, new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), new CreatorRepository( $this->wpdb ), new CreatorVatRepository( $this->wpdb ), $audit, $transaction, $clock ) );
		$this->requests   = new PayoutRequests( $this->repository, $balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock );
		$this->page       = new PayoutsPage( $this->repository, $decisions, $cipher, new CreatorRepository( $this->wpdb ), $this->service( Logger::class ) );
		$this->actions    = new PayoutActions( $decisions, $this->service( Logger::class ) );
	}

	/**
	 * The page's HTML for GET parameters.
	 *
	 * @param array<string, string> $get GET parameters.
	 */
	private function render( array $get = array() ): string {
		$_GET = $get;
		ob_start();

		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
			$_GET = array();
		}

		return $html;
	}

	/**
	 * A valid reject request.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $reason    Reason.
	 * @return array<string, string>
	 */
	private function reject_input( int $payout_id, string $reason ): array {
		return array(
			'action'    => PayoutActions::REJECT_ACTION,
			'payout_id' => (string) $payout_id,
			'reason'    => $reason,
			'_wpnonce'  => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::REJECT_ACTION, $payout_id ) ),
		);
	}

	/**
	 * A valid complete request.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $reference Reference.
	 * @return array<string, string>
	 */
	private function complete_input( int $payout_id, string $reference = '' ): array {
		return array(
			'action'           => PayoutActions::COMPLETE_ACTION,
			'payout_id'        => (string) $payout_id,
			'reference'        => $reference,
			'confirm_transfer' => '1',
			'_wpnonce'         => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::COMPLETE_ACTION, $payout_id ) ),
		);
	}

	/**
	 * Requires wp_die() (the test handler throws).
	 *
	 * @param callable $call    Call.
	 * @param string   $message Message.
	 */
	private function dies( callable $call, string $message = '' ): void {
		ob_start();

		try {
			$call();
			self::fail( 'Expected wp_die(). ' . $message );
		} catch ( RuntimeException ) {
			$this->addToAssertionCount( 1 );
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * A bank request of the creator, as user 1.
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
				'account'      => self::ACCOUNT,
			),
			$key,
			1
		);
	}

	/**
	 * Sales of 100.00 incl. VAT (payout 20.00 each), dated 2026-09-01.
	 *
	 * @param int $count Number of sales.
	 * @return list<int> Order item ids.
	 */
	private function earn( int $count ): array {
		$items = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$order = $this->next_order++;
			$item  = 1000 + $order;
			$this->recorder->record( $item, $order, $this->product, 0, 'DKK' );
			$this->order( $item, $order );
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Sets an order with one line and synchronizes it.
	 *
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param int              $item     Order item.
	 * @param int              $order_id Order.
	 * @param RefundSnapshot[] $refunds  Refunds.
	 */
	private function order( int $item, int $order_id, array $refunds = array() ): void {
		$this->reader->orders[ $order_id ] = array(
			'status'    => 'processing',
			'currency'  => 'DKK',
			'date_paid' => '2026-09-01 10:05:00',
			'lines'     => array( new OrderLineSnapshot( $item, $this->product, 0, 'MB-PAY line', 1, SalesAmount::parse( '80' ), $this->creator, SalesAmount::parse( '20' ) ) ),
			'refunds'   => $refunds,
		);
		$this->sync->sync( $order_id );
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
