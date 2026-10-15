<?php
/**
 * Payout gift cards on a real database with a fake gift card adapter (ADR-057).
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
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\PayoutActions;
use MerchBoard\Modules\WooCommerce\Admin\PayoutsPage;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardAvailability;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardOrderRegistry;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviderException;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardSnapshot;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardStatus;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\PayoutGiftCardOrders;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\YithGiftCardProvider;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutGiftCards;
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
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeGiftCardProvider;
use MerchBoard\Tests\Integration\Support\FakeOrderRegistryGiftCardProvider;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use RuntimeException;

/**
 * Registration and the creator's view against FakeGiftCardProvider: the
 * payout logic's rules, idempotency, the unique card, access, the provider
 * states and that the code never leaks (report 18.5). Earnings come from
 * real ledger rows (payout 20.00 per sale). A passing test here proves
 * nothing about YITH WooCommerce Gift Cards; that is the runtime test.
 */
final class PayoutGiftCardsTest extends IntegrationTestCase {

	private const CODE = 'MB-SECRET-7731';

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private PayoutRepository $repository;

	private PayoutRequests $requests;

	private PayoutDecisions $decisions;

	private PayoutGiftCards $gift_cards;

	private PayoutsPage $page;

	private PayoutActions $actions;

	private PayoutGiftCardOrders $card_orders;

	private FakeGiftCardProvider $fake;

	/**
	 * The fake with the optional order lookup (null = the plain fake is used).
	 *
	 * @var FakeOrderRegistryGiftCardProvider|null
	 */
	private ?FakeOrderRegistryGiftCardProvider $registry = null;

	private RecordingLogger $logger;

	private CreatorRepository $creators;

	private int $creator;

	private int $product;

	private int $next_order = 9001;

	/**
	 * Settings before the test.
	 *
	 * @var mixed
	 */
	private mixed $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		add_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		$this->settings = get_option( Settings::OPTION );

		$this->creators = new CreatorRepository( $this->wpdb );
		$this->creator  = $this->creators->create( 'Gift Card Alpha', CreatorStatus::Active )->id;
		$markups        = new CreatorMarkupRepository( $this->wpdb );
		$vat            = new CreatorVatRepository( $this->wpdb );
		$markups->save( $this->creator, new MarkupBps( 2000 ) );
		$vat->save( $this->creator, true );

		$this->posts    = new ProductPosts( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $this->creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
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
		$this->product  = $this->posts->add( 'MB-GC product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $this->product, '_regular_price', '100' );
		$this->posts->meta( $this->product, '_price', '100' );

		$this->fake   = new FakeGiftCardProvider();
		$this->logger = new RecordingLogger();
		$this->choose( 'fake' );
		$this->services();
	}

	protected function tearDown(): void {
		remove_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		$this->posts->remove_all();
		update_option( Settings::OPTION, $this->settings );
		$_GET = array();
		parent::tearDown();
	}

	public function test_a_gift_card_can_be_requested_only_with_an_available_provider_and_has_no_details(): void {
		$this->earn( 3 );

		foreach ( array(
			''        => 'not configured',
			'missing' => 'unknown provider',
		) as $choice => $label ) {
			$this->choose( (string) $choice );
			self::assertFalse( $this->requests->gift_cards_available(), $label );
			$this->refused( PayoutException::METHOD_UNAVAILABLE, fn() => $this->request( '20', 'key-gc-none-' . strlen( (string) $choice ) ), $label );
			self::assertTrue( $this->requests->methods_available(), $label . ': bank and PayPal are unaffected.' );
		}

		$this->choose( 'fake' );
		$this->fake->availability = GiftCardAvailability::unavailable( GiftCardAvailability::UNSUPPORTED_VERSION, '9.9' );
		self::assertSame( array( 'state' => GiftCardProviders::UNAVAILABLE, 'code' => 'unsupported_version' ), ( new GiftCardProviders( array( $this->fake ) ) )->state() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->refused( PayoutException::METHOD_UNAVAILABLE, fn() => $this->request( '20', 'key-gc-unavailable' ) );

		$this->fake->availability = GiftCardAvailability::available( '1.0.0' );
		$payout                   = $this->request( '20', 'key-gc-0001' );

		self::assertSame( PayoutStatus::Pending, $payout->status );
		self::assertSame( '', $payout->destination_hint );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUT_SECRETS ), 'A gift card payout has no details.' );
		self::assertSame( array(), $this->fake->reads, 'Requesting reads no card.' );
	}

	public function test_a_fitting_card_completes_the_payout_once_with_the_card_id_only(): void {
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-0002' );
		$this->card( '501', '20' );

		$url = $this->actions->handle( $this->register_input( $payout->id, self::CODE ) );

		self::assertStringContainsString( 'result=gift_card_registered', $url );
		$stored = $this->repository->find( $payout->id );
		self::assertNotNull( $stored );
		self::assertSame( PayoutStatus::Completed, $stored->status );
		self::assertSame( 'fake', $stored->gift_card_provider );
		self::assertSame( '501', $stored->gift_card_external_id );
		self::assertSame( 1, $stored->decided_by );
		self::assertSame(
			array(
				array(
					'payout_id'   => $payout->id,
					'amount'      => '20.0000',
					'currency'    => 'DKK',
					'method'      => 'gift_card',
					'provider'    => 'fake',
					'external_id' => '501',
				),
			),
			$this->audit( AuditAction::PayoutGiftCardRegistered )
		);
		self::assertSame( array(), $this->audit( AuditAction::PayoutCompleted ), 'Registration is its own audit action.' );
		$document = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT document_number, payout_amount, snapshot FROM %i WHERE settlement_payout_id = %d', $this->table( Tables::SETTLEMENT_DOCUMENTS ), $payout->id ), ARRAY_A );
		self::assertSame( array( 'AFR-000001', '20.0000' ), array( $document['document_number'] ?? null, $document['payout_amount'] ?? null ), 'The registration issues the settlement document (ADR-060).' );
		self::assertStringNotContainsString( self::CODE, (string) ( $document['snapshot'] ?? '' ), 'Never the gift card code.' );

		// Again (double click, resend): already registered, no new audit.
		self::assertStringContainsString( 'result=gift_card_registered', $this->actions->handle( $this->register_input( $payout->id, self::CODE ) ) );
		self::assertCount( 1, $this->audit( AuditAction::PayoutGiftCardRegistered ) );

		// Another card on the completed payout is refused.
		$this->card( '502', '20', null, 'OTHER-CODE' );
		self::assertStringContainsString( 'result=not_pending', $this->actions->handle( $this->register_input( $payout->id, 'OTHER-CODE' ) ) );
		self::assertSame( '501', $this->repository->find( $payout->id )?->gift_card_external_id );
	}

	public function test_g1_a_code_is_a_payout_card_only_when_registered_also_without_the_order_lookup(): void {
		// The plain fake implements only GiftCardProvider (ADR-057), not the optional GiftCardOrderRegistry.
		self::assertArrayNotHasKey( GiftCardOrderRegistry::class, (array) class_implements( $this->fake ) );
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-g1-1' );
		$this->card( '501', '20' );

		$this->fake->availability = GiftCardAvailability::unavailable( GiftCardAvailability::INACTIVE );
		self::assertFalse( $this->card_orders->is_payout_card( self::CODE ), 'No card registered on a payout: approved, even with the provider unavailable.' );
		self::assertSame( array(), $this->fake->reads, 'Without registered payout cards no provider is contacted.' );
		$this->fake->availability = GiftCardAvailability::available( '1.0.0' );

		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );
		$this->card( '502', '20', null, 'SHOP-GIFT-CARD' );
		$before = $this->counts();
		$reads  = count( $this->fake->reads );

		self::assertTrue( $this->card_orders->is_payout_card( self::CODE ) );
		self::assertFalse( $this->card_orders->is_payout_card( 'SHOP-GIFT-CARD' ), 'An ordinary gift card of the shop.' );
		self::assertFalse( $this->card_orders->is_payout_card( 'SUMMER10' ), 'Not a gift card at all.' );
		self::assertFalse( $this->card_orders->is_payout_card( '  ' ) );
		self::assertTrue( $this->card_orders->is_payout_card( self::CODE ), 'Asked again: the same answer.' );
		self::assertSame( array_fill( 0, 4, array( 'find_by_code', '' ) ), array_slice( $this->fake->reads, $reads ), 'Only lookups by code; a blank code is not looked up.' );
		self::assertSame( $before, $this->counts(), 'Checking codes writes nothing.' );
	}

	public function test_g1_the_coupon_check_fails_closed_when_a_registered_card_cannot_be_checked(): void {
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-g1-2' );
		$this->card( '501', '20' );
		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );
		$before = $this->counts();

		$unknown = new PayoutGiftCardOrders( $this->repository, new GiftCardProviders( array() ), $this->logger );
		$this->uncheckable( $unknown, 'SUMMER10', GiftCardProviderException::UNAVAILABLE, 'The provider of a registered card is unknown.' );

		foreach ( array( GiftCardAvailability::NOT_INSTALLED, GiftCardAvailability::INACTIVE, GiftCardAvailability::UNSUPPORTED_VERSION, GiftCardAvailability::SELF_TEST_FAILED ) as $code ) {
			$this->fake->availability = GiftCardAvailability::unavailable( $code );
			$this->uncheckable( $this->card_orders, 'SUMMER10', GiftCardProviderException::UNAVAILABLE, 'Provider ' . $code . '.' );
		}

		$this->fake->availability = GiftCardAvailability::available( '1.0.0' );

		foreach ( array( GiftCardProviderException::AMBIGUOUS, GiftCardProviderException::INVALID_DATA, GiftCardProviderException::READ_FAILED ) as $reason ) {
			$this->failing( new GiftCardProviderException( $reason ) );
			$this->uncheckable( $this->card_orders, 'ANY-CODE', $reason, 'Lookup ' . $reason . '.' );
		}

		$this->failing( new RuntimeException( 'Boom' ) );

		try {
			$this->card_orders->is_payout_card( 'ANY-CODE' );
			self::fail( 'An unexpected error is not "no".' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'Boom', $e->getMessage() );
		}

		$this->fake->fail = null;
		self::assertTrue( $this->card_orders->is_payout_card( self::CODE ), 'Readable again.' );
		self::assertSame( $before, $this->counts(), 'Nothing is written.' );
		self::assertStringNotContainsString( 'ANY-CODE', (string) wp_json_encode( $this->logger->entries ) );

		// The payouts themselves cannot be read.
		$this->wpdb->suppress_errors( true );
		$this->execute( 'DROP TABLE %i', $this->table( Tables::PAYOUTS ) );
		$this->forget_table_metadata();

		try {
			$this->card_orders->is_payout_card( 'SUMMER10' );
			self::fail( 'A database error is not "no".' );
		} catch ( DatabaseException ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_g1_an_adapter_without_the_order_lookup_is_unsupported_never_an_error_or_not_paid(): void {
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-g1-3' );
		$this->card( '501', '20' );
		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );
		$before = $this->counts();
		$logged = count( $this->logger->entries );

		$expected = array(
			'paid'        => array(),
			'unsupported' => true,
			'failed'      => false,
		);
		self::assertSame( $expected, $this->card_orders->for_orders( array( 9101, 9102 ) ) );
		self::assertSame( array( 'orders' => null, 'reason' => PayoutGiftCardOrders::UNSUPPORTED ), $this->card_orders->for_payout( $payout->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$html = $this->render( array( 'payout' => (string) $payout->id ) );
		self::assertStringContainsString( 'Gift card plugin does not support order registration lookup', $html );
		self::assertStringNotContainsString( 'Gift card order registration could not be checked', $html, 'Unsupported is not a failed lookup.' );
		self::assertStringNotContainsString( 'Registered by gift card plugin', $html );
		self::assertStringContainsString( 'Status: active.', $html, 'The rest of the page is unaffected.' );
		self::assertCount( $logged, $this->logger->entries, 'Nothing to log: not an error.' );
		self::assertSame( $before, $this->counts() );
	}

	public function test_g1_the_plugins_order_records_are_shown_labelled_and_change_nothing(): void {
		$registry = $this->with_registry();
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-g1-4' );
		$this->card( '501', '20' );
		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );
		// Checkout (coupon field or the plugin's form) recorded two orders on the payout card;
		// an ordinary card of the shop paid another order.
		$this->card( '502', '50', null, 'SHOP-GIFT-CARD' );
		$registry->orders = array(
			'501' => array( 9101, 9103 ),
			'502' => array( 9102 ),
		);
		// An E2 assessment exists for both orders; the plugin's record does not show or change it.
		$this->wpdb->insert( $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), array( 'order_id' => 9101, 'status' => 'not_paid', 'updated_at' => '2026-11-30 12:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->insert( $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), array( 'order_id' => 9103, 'status' => 'pending', 'updated_at' => '2026-11-30 12:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$assessments = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY order_id', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) ), ARRAY_A );
		$before      = $this->counts();
		$balance     = $this->balance();

		$expected = array(
			'paid'        => array(
				9101 => array( $payout->id ),
				9103 => array( $payout->id ),
			),
			'unsupported' => false,
			'failed'      => false,
		);
		self::assertSame( $expected, $this->card_orders->for_orders( array( 9101, 9102, 9103, 9104 ) ) );
		self::assertSame( $expected, $this->card_orders->for_orders( array( 9101, 9102, 9103, 9104 ) ), 'Repeated: the same answer, nothing registered twice.' );
		self::assertSame( array( 'orders' => array( 9101, 9103 ), 'reason' => null ), $this->card_orders->for_payout( $payout->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'orders' => null, 'reason' => PayoutGiftCardOrders::FAILED ), $this->card_orders->for_payout( 999 ), 'No registered card.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array_fill( 0, 4, array( '501' ) ), $registry->lookups, 'Only cards registered on payouts are asked for, by id.' );

		self::assertTrue( $this->card_orders->is_payout_card( self::CODE ) );
		self::assertCount( 4, $registry->lookups, 'The coupon check does not use the order lookup.' );

		$html = $this->render( array( 'payout' => (string) $payout->id ) );
		self::assertStringContainsString( '<strong>Registered by gift card plugin — payout status not confirmed</strong><br>Orders: <a', $html );
		self::assertStringContainsString( '#9101</a>, <a', $html );
		self::assertStringNotContainsString( '#9102', $html );
		self::assertStringNotContainsString( 'not_paid', $html, 'The assessment is not shown as or mixed into the plugin record.' );
		self::assertStringNotContainsString( 'does not support', $html );
		self::assertStringNotContainsString( 'could not be checked', $html );
		self::assertStringNotContainsString( self::CODE, $html );

		$registry->orders = array();
		self::assertStringContainsString( '<strong>Registered by gift card plugin — payout status not confirmed</strong><br>Orders: None', $this->render( array( 'payout' => (string) $payout->id ) ), 'No order recorded on the card.' );

		self::assertSame( $before, $this->counts(), 'Nothing is written: no ledger row, payout, audit record, document or G1 row.' );
		self::assertSame( $assessments, $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY order_id', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) ), ARRAY_A ), 'The assessments are unchanged.' );
		self::assertSame( $balance, $this->balance(), 'The commission basis is not changed (G1 undecided).' );
	}

	public function test_g1_a_failed_lookup_is_distinguished_from_an_unsupported_one(): void {
		$registry = $this->with_registry();
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-g1-5' );
		$this->card( '501', '20' );
		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );
		$registry->orders = array( '501' => array( 9101 ) );
		$unsupported      = array(
			'paid'        => array(),
			'unsupported' => true,
			'failed'      => false,
		);
		$failed           = array(
			'paid'        => array(),
			'unsupported' => false,
			'failed'      => true,
		);

		// The plugin lacks the record (e.g. YITH without YWGC_META_GIFT_CARD_ORDERS) while the adapter is available.
		$registry->registry = GiftCardAvailability::unavailable( GiftCardAvailability::SELF_TEST_FAILED, '1.0.0' );
		self::assertSame( $unsupported, $this->card_orders->for_orders( array( 9101 ) ) );
		self::assertStringContainsString( 'Gift card plugin does not support order registration lookup', $this->render( array( 'payout' => (string) $payout->id ) ) );
		self::assertTrue( $this->card_orders->is_payout_card( self::CODE ), 'Still refused as a payout card, not as "cannot be checked".' );
		self::assertSame( array(), $registry->lookups, 'An unavailable lookup is not called.' );
		$registry->registry = GiftCardAvailability::available( '1.0.0' );

		$registry->registry_fail = new GiftCardProviderException( GiftCardProviderException::INVALID_DATA );
		self::assertSame( $failed, $this->card_orders->for_orders( array( 9101 ) ), 'Not exact.' );
		self::assertSame( array( 'warning', 'The orders recorded on payout gift cards could not be read.', array( 'provider' => 'fake', 'reason' => 'invalid_data' ) ), array_values( (array) end( $this->logger->entries ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$html = $this->render( array( 'payout' => (string) $payout->id ) );
		self::assertStringContainsString( 'Gift card order registration could not be checked', $html );
		self::assertStringNotContainsString( 'does not support', $html );
		self::assertTrue( $this->card_orders->is_payout_card( self::CODE ), 'The coupon check does not depend on the lookup.' );
		$registry->registry_fail = null;

		$this->fake->availability = GiftCardAvailability::unavailable( GiftCardAvailability::INACTIVE );
		self::assertSame( $failed, $this->card_orders->for_orders( array( 9101 ) ), 'Plugin inactive: cannot be checked, not unsupported.' );
		self::assertSame( array( 'orders' => null, 'reason' => PayoutGiftCardOrders::FAILED ), $this->card_orders->for_payout( $payout->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->fake->availability = GiftCardAvailability::available( '1.0.0' );

		$this->fake->cards = array();
		self::assertSame( $failed, $this->card_orders->for_orders( array( 9101 ) ), 'The card was deleted in the plugin.' );
		self::assertStringNotContainsString( self::CODE, (string) wp_json_encode( $this->logger->entries ) );
	}

	public function test_g1_the_coupon_check_does_not_depend_on_g1_activation_or_cut_off(): void {
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-g1-6' );
		$this->card( '501', '20' );
		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );

		self::assertTrue( $this->card_orders->is_payout_card( self::CODE ), 'G1 not activated.' );
		$this->activate( '2027-01-01 00:00:00' );
		self::assertTrue( $this->card_orders->is_payout_card( self::CODE ), 'Activated, the order before the cut-off.' );
		$this->activate( '2026-01-01 00:00:00' );
		self::assertTrue( $this->card_orders->is_payout_card( self::CODE ), 'Activated, the order after the cut-off.' );
		self::assertFalse( $this->card_orders->is_payout_card( 'SUMMER10' ) );
	}

	public function test_g1_the_views_do_not_depend_on_g1_activation_or_cut_off(): void {
		$registry = $this->with_registry();
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-g1-7' );
		$this->card( '501', '20' );
		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );
		$registry->orders = array( '501' => array( 9101 ) );
		$shown            = '<strong>Registered by gift card plugin — payout status not confirmed</strong><br>Orders: <a';

		self::assertStringContainsString( $shown, $this->render( array( 'payout' => (string) $payout->id ) ), 'G1 not activated.' );
		$this->activate( '2027-01-01 00:00:00' );
		self::assertStringContainsString( $shown, $this->render( array( 'payout' => (string) $payout->id ) ), 'Activated, the order before the cut-off.' );
		self::assertSame( array( 9101 ), $this->card_orders->for_payout( $payout->id )['orders'] );
	}

	public function test_cards_that_do_not_fit_are_refused_and_nothing_changes(): void {
		$this->earn( 3 );
		$payout  = $this->request( '20', 'key-gc-0003' );
		$past    = new DateTimeImmutable( '2026-11-01 00:00:00', new DateTimeZone( 'UTC' ) );
		$refusal = array(
			'unknown code'        => array( PayoutException::GIFT_CARD_NOT_FOUND, fn() => null ),
			'disabled'            => array( PayoutException::GIFT_CARD_NOT_ACTIVE, fn() => $this->card( '601', '20', null, self::CODE, 'DKK', GiftCardStatus::Disabled ) ),
			'other status'        => array( PayoutException::GIFT_CARD_NOT_ACTIVE, fn() => $this->card( '601', '20', null, self::CODE, 'DKK', GiftCardStatus::Other ) ),
			'expired status'      => array( PayoutException::GIFT_CARD_EXPIRED, fn() => $this->card( '601', '20', null, self::CODE, 'DKK', GiftCardStatus::Expired ) ),
			'expiry passed'       => array( PayoutException::GIFT_CARD_EXPIRED, fn() => $this->card( '601', '20', null, self::CODE, 'DKK', GiftCardStatus::Active, $past ) ),
			'other currency'      => array( PayoutException::GIFT_CARD_CURRENCY, fn() => $this->card( '601', '20', null, self::CODE, 'EUR' ) ),
			'amount too high'     => array( PayoutException::GIFT_CARD_AMOUNT, fn() => $this->card( '601', '25' ) ),
			'amount too low'      => array( PayoutException::GIFT_CARD_AMOUNT, fn() => $this->card( '601', '19.99' ) ),
			'partly used'         => array( PayoutException::GIFT_CARD_USED, fn() => $this->card( '601', '20', '10' ) ),
			'code not revealable' => array( PayoutException::GIFT_CARDS_UNAVAILABLE, fn() => $this->card( '601', '20', null, self::CODE, 'DKK', GiftCardStatus::Active, null, false ) ),
			'ambiguous'           => array( PayoutException::GIFT_CARD_AMBIGUOUS, fn() => $this->failing( new GiftCardProviderException( GiftCardProviderException::AMBIGUOUS ) ) ),
			'invalid data'        => array( PayoutException::GIFT_CARD_INVALID_DATA, fn() => $this->failing( new GiftCardProviderException( GiftCardProviderException::INVALID_DATA ) ) ),
			'read failed'         => array( PayoutException::GIFT_CARD_READ_FAILED, fn() => $this->failing( new GiftCardProviderException( GiftCardProviderException::READ_FAILED ) ) ),
			'unexpected error'    => array( PayoutException::GIFT_CARD_READ_FAILED, fn() => $this->failing( new RuntimeException( 'Boom ' . self::CODE ) ) ),
		);

		foreach ( $refusal as $label => [ $reason, $arrange ] ) {
			$this->fake->cards = array();
			$this->fake->fail  = null;
			$arrange();

			$url = $this->actions->handle( $this->register_input( $payout->id, self::CODE ) );

			self::assertStringContainsString( 'result=' . $reason, $url, $label );
			self::assertStringNotContainsString( self::CODE, $url, $label );
			self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status, $label );
		}

		self::assertStringContainsString( 'result=invalid_gift_card_code', $this->actions->handle( $this->register_input( $payout->id, '   ' ) ) );
		self::assertSame( array(), $this->audit( AuditAction::PayoutGiftCardRegistered ) );
		self::assertSame( 0, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE gift_card_external_id IS NOT NULL', $this->table( Tables::PAYOUTS ) ) ) );

		$logged = (string) wp_json_encode( $this->logger->entries );
		self::assertStringContainsString( '"reason":"read_failed"', $logged, 'A read failure is logged with its code.' );
		self::assertStringNotContainsString( self::CODE, $logged, 'Never the card code.' );
	}

	public function test_a_card_belongs_to_at_most_one_payout_also_in_the_database(): void {
		$this->earn( 3 );
		$first  = $this->request( '20', 'key-gc-0004' );
		$second = $this->request( '20', 'key-gc-0005' );
		$this->card( '701', '20' );

		self::assertStringContainsString( 'result=gift_card_registered', $this->actions->handle( $this->register_input( $first->id, self::CODE ) ) );
		self::assertStringContainsString( 'result=gift_card_linked', $this->actions->handle( $this->register_input( $second->id, self::CODE ) ) );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $second->id )?->status );

		// Another creator's payout under its own lock: the unique key refuses the same card.
		$other = $this->creators->create( 'Gift Card Beta', CreatorStatus::Active )->id;
		$this->wpdb->insert( $this->table( Tables::PAYOUTS ), array( 'creator_id' => $other, 'currency' => 'DKK', 'amount' => '20.0000', 'price_decimals' => 2, 'method' => 'gift_card', 'status' => 'pending', 'idempotency_key' => 'key-gc-beta', 'requested_at' => '2026-11-30 11:00:00', 'updated_at' => '2026-11-30 11:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$beta = $this->repository->find( (int) $this->wpdb->insert_id );
		self::assertInstanceOf( Payout::class, $beta );
		$transaction = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );

		try {
			$this->repository->with_creator_lock(
				$other,
				fn() => $transaction->run( fn() => $this->repository->complete_gift_card( $beta, 'fake', '701', 1, new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) ) ) )
			);
			self::fail( 'The database must refuse the same card twice.' );
		} catch ( PayoutException $e ) {
			self::assertSame( PayoutException::GIFT_CARD_LINKED, $e->reason() );
		}

		self::assertSame( PayoutStatus::Pending, $this->repository->find( $beta->id )?->status );
		self::assertCount( 1, $this->audit( AuditAction::PayoutGiftCardRegistered ) );
	}

	public function test_the_card_is_read_again_for_every_attempt_and_the_payout_must_still_be_pending(): void {
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-0006' );
		$this->card( '801', '20', '15' );

		self::assertStringContainsString( 'result=gift_card_used', $this->actions->handle( $this->register_input( $payout->id, self::CODE ) ) );
		$this->card( '801', '20' );
		self::assertStringContainsString( 'result=gift_card_registered', $this->actions->handle( $this->register_input( $payout->id, self::CODE ) ), 'A corrected card is read again.' );

		$cancelled = $this->request( '20', 'key-gc-0007' );
		$this->requests->cancel( $this->creator, $cancelled->id, 1 );
		$this->card( '802', '20', null, 'CODE-802' );
		self::assertStringContainsString( 'result=not_pending', $this->actions->handle( $this->register_input( $cancelled->id, 'CODE-802' ) ) );

		$bank = $this->requests->request( $this->creator, 'DKK', '5', 'bank', array( 'registration' => '1234', 'account' => '0012345678' ), 'key-gc-bank', 1 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'result=wrong_method', $this->actions->handle( $this->register_input( $bank->id, 'CODE-802' ) ), 'No gift card on a bank payout.' );

		$pending = $this->request( '10', 'key-gc-0008' );
		$this->refused( PayoutException::WRONG_METHOD, fn() => $this->decisions->complete( $pending->id ) );
		$this->refused( PayoutException::WRONG_METHOD, fn() => $this->decisions->details( $pending->id ) );
	}

	public function test_registration_needs_the_capability_a_valid_nonce_and_an_available_provider(): void {
		$this->earn( 1 );
		$payout = $this->request( '20', 'key-gc-0009' );
		$this->card( '901', '20' );

		$user = $this->create_user( 'mb-gc-subscriber' );
		wp_set_current_user( $user );
		$this->dies( fn() => $this->actions->handle( $this->register_input( $payout->id, self::CODE ) ) );
		$this->refused( PayoutException::NOT_AUTHORIZED, fn() => $this->gift_cards->register( $payout->id, self::CODE ) );
		wp_set_current_user( 1 );

		$this->dies( fn() => $this->actions->handle( array( '_wpnonce' => 'x' ) + $this->register_input( $payout->id, self::CODE ) ) );
		$this->dies( fn() => $this->actions->handle( array( '_wpnonce' => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::COMPLETE_ACTION, $payout->id ) ) ) + $this->register_input( $payout->id, self::CODE ) ), 'Another action.' );
		self::assertSame( array(), $this->fake->reads, 'No card is read before capability and nonce.' );

		foreach ( array(
			''        => 'not configured',
			'missing' => 'unknown provider',
		) as $choice => $label ) {
			$this->choose( (string) $choice );
			self::assertStringContainsString( 'result=gift_cards_unavailable', $this->actions->handle( $this->register_input( $payout->id, self::CODE ) ), $label );
		}

		$this->choose( 'fake' );
		$this->fake->availability = GiftCardAvailability::unavailable( GiftCardAvailability::INACTIVE );
		self::assertStringContainsString( 'result=gift_cards_unavailable', $this->actions->handle( $this->register_input( $payout->id, self::CODE ) ) );
		self::assertStringContainsString( 'No available gift card plugin is chosen', $this->render( array( 'payout' => (string) $payout->id ) ) );
		self::assertSame( PayoutStatus::Pending, $this->repository->find( $payout->id )?->status );
	}

	public function test_the_admin_page_shows_the_form_and_later_the_card_state_never_the_code(): void {
		$this->earn( 1 );
		$payout = $this->request( '20', 'key-gc-0010' );
		$this->card( '1001', '20' );

		$pending = $this->render( array( 'payout' => (string) $payout->id ) );
		self::assertStringContainsString( 'Register gift card', $pending );
		self::assertStringContainsString( 'MB-PAYOUT-' . $payout->id, $pending );
		self::assertStringContainsString( 'name="gift_card_code"', $pending );
		self::assertStringContainsString( 'autocomplete="off"', $pending );
		self::assertStringContainsString( 'https://shop.test/fake-gift-cards/new', $pending );
		self::assertStringNotContainsString( 'Show full payment details', $pending );
		self::assertStringNotContainsString( 'Register as paid out', $pending );

		$this->card( '1001', '20', '5' );
		$failed = $this->render( array( 'payout' => (string) $payout->id, 'result' => 'gift_card_used' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'has been used', $failed );

		$this->card( '1001', '20' );
		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );
		$this->card( '1001', '20', '7.5' );
		$done = $this->render( array( 'payout' => (string) $payout->id ) );
		self::assertStringContainsString( 'Gift card in the plugin now', $done );
		self::assertStringContainsString( 'Fake gift cards, card #1001', $done );
		self::assertStringContainsString( 'Status: active.', $done );
		self::assertStringContainsString( '7.50 DKK', $done );
		self::assertStringNotContainsString( 'name="gift_card_code"', $done );

		foreach ( array( $pending, $failed, $done, $this->render(), $this->render( array( 'view' => 'history' ) ) ) as $html ) {
			self::assertStringNotContainsString( self::CODE, $html );
		}
	}

	public function test_the_creator_sees_only_the_own_card_live_with_its_code(): void {
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-0011' );
		$this->card( '1101', '20' );

		$this->refused( PayoutException::NOT_FOUND, fn() => $this->gift_cards->for_creator( $this->creator, $payout->id ), 'Pending: no card yet.' );
		$this->gift_cards->register( $payout->id, self::CODE );
		$this->card( '1101', '20', '12.5' );

		$shown = $this->gift_cards->for_creator( $this->creator, $payout->id );
		self::assertSame( self::CODE, $shown['code'] );
		self::assertSame( SalesAmount::parse( '12.5' ), $shown['card']->balance_units, 'Read live.' );

		$other = $this->creators->create( 'Gift Card Gamma', CreatorStatus::Active )->id;
		$this->refused( PayoutException::NOT_FOUND, fn() => $this->gift_cards->for_creator( $other, $payout->id ), 'Another creator.' );
		$this->refused( PayoutException::NOT_FOUND, fn() => $this->gift_cards->for_creator( $this->creator, 999999 ) );

		$this->fake->availability = GiftCardAvailability::unavailable( GiftCardAvailability::INACTIVE );
		$this->refused( PayoutException::GIFT_CARDS_UNAVAILABLE, fn() => $this->gift_cards->for_creator( $this->creator, $payout->id ) );
		$this->fake->availability = GiftCardAvailability::available( '1.0.0' );
		$this->fake->cards        = array();
		$this->refused( PayoutException::GIFT_CARDS_UNAVAILABLE, fn() => $this->gift_cards->for_creator( $this->creator, $payout->id ), 'Card deleted in the plugin.' );
		self::assertSame( PayoutStatus::Completed, $this->repository->find( $payout->id )?->status, 'The payout stays completed.' );
	}

	public function test_the_code_is_stored_nowhere_in_merch_dashboard(): void {
		$this->earn( 3 );
		$payout = $this->request( '20', 'key-gc-0012' );
		$this->card( '1201', '20', '3' );
		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );
		$this->card( '1201', '20' );
		$this->actions->handle( $this->register_input( $payout->id, self::CODE ) );

		self::assertSame( PayoutStatus::Completed, $this->repository->find( $payout->id )?->status );
		self::assertStringNotContainsString( self::CODE, (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );
		self::assertStringNotContainsString( self::CODE, (string) wp_json_encode( $this->rows( Tables::PAYOUTS ) ) );
		self::assertStringNotContainsString( self::CODE, (string) wp_json_encode( $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table( Tables::PAYOUT_SECRETS ) ), ARRAY_A ) ) );
		self::assertStringNotContainsString( self::CODE, (string) wp_json_encode( $this->logger->entries ) );
		self::assertSame( 0, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_value LIKE %s', $this->wpdb->options, '%' . $this->wpdb->esc_like( self::CODE ) . '%' ) ), 'Neither options nor transients.' );
		self::assertSame( array( array( 'find_by_code', '' ), array( 'find_by_code', '' ) ), $this->fake->reads, 'Only lookups by code; nothing written to the provider.' );
	}

	public function test_the_yith_adapter_is_unavailable_without_the_plugin(): void {
		$yith = new YithGiftCardProvider( $this->wpdb, new PostMetaPricingProductStore(), static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

		self::assertSame( 'yith-wcgc', $yith->id() );
		self::assertFalse( $yith->availability()->available );
		self::assertContains( $yith->availability()->code, array( GiftCardAvailability::NOT_INSTALLED, GiftCardAvailability::INACTIVE ) );
		self::assertEquals( $yith->availability(), $yith->order_registry_availability(), 'The lookup is unavailable for the same reason.' );

		foreach ( array( fn() => $yith->find_by_code( self::CODE ), fn() => $yith->orders_paid_with( array( '501' ) ) ) as $read ) {
			try {
				$read();
				self::fail( 'No read without the plugin.' );
			} catch ( GiftCardProviderException $e ) {
				self::assertSame( GiftCardProviderException::UNAVAILABLE, $e->reason() );
			}
		}
	}

	/**
	 * Builds the services with the fake provider and a fixed clock.
	 */
	private function services(): void {
		$clock             = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$transaction       = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$audit             = $this->service( AuditTrail::class );
		$cipher            = new PayoutCipher( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$providers         = new GiftCardProviders( array( $this->registry ?? $this->fake ) );
		$this->repository  = new PayoutRepository( $this->wpdb );
		$balances          = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock );
		$documents         = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), new CreatorRepository( $this->wpdb ), new CreatorVatRepository( $this->wpdb ), $audit, $transaction, $clock );
		$this->decisions   = new PayoutDecisions( $this->repository, $balances, $cipher, $audit, $transaction, $clock, $documents );
		$this->requests    = new PayoutRequests( $this->repository, $balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock, $providers );
		$this->gift_cards  = new PayoutGiftCards( $this->repository, $balances, $providers, $audit, $transaction, $this->logger, $clock, $documents );
		$this->card_orders = new PayoutGiftCardOrders( $this->repository, $providers, $this->logger );
		$this->page        = new PayoutsPage( $this->repository, $this->decisions, $cipher, $this->creators, $this->logger, $providers, null, null, $this->card_orders );
		$this->actions     = new PayoutActions( $this->decisions, $this->logger, $this->gift_cards );
	}

	/**
	 * Chooses the gift card provider in Settings.
	 *
	 * @param string $provider Provider id or ''.
	 */
	private function choose( string $provider ): void {
		Settings::save( array( Settings::PAYOUT_GIFT_CARD_PROVIDER => $provider ) + Settings::all() );
	}

	/**
	 * Puts a card into the fake provider.
	 *
	 * @param string                 $id         External id.
	 * @param string                 $amount     Amount.
	 * @param string|null            $balance    Balance (null = the amount).
	 * @param string                 $code       Code.
	 * @param string                 $currency   Currency.
	 * @param GiftCardStatus         $status     Status.
	 * @param DateTimeImmutable|null $expires_at Expiry.
	 * @param bool                   $reveal     Whether its code can be revealed.
	 */
	private function card( string $id, string $amount, ?string $balance = null, string $code = self::CODE, string $currency = 'DKK', GiftCardStatus $status = GiftCardStatus::Active, ?DateTimeImmutable $expires_at = null, bool $reveal = true ): void {
		$this->fake->cards[ $code ] = new GiftCardSnapshot( $id, SalesAmount::parse( $amount ), SalesAmount::parse( $balance ?? $amount ), $currency, $status, $expires_at, $reveal );
	}

	/**
	 * Makes every read of the fake provider fail.
	 *
	 * @param \Throwable $failure Failure.
	 */
	private function failing( \Throwable $failure ): void {
		$this->fake->fail = $failure;
	}

	/**
	 * A gift card request of the creator, as user 1.
	 *
	 * @param string $amount Amount.
	 * @param string $key    Request key.
	 */
	private function request( string $amount, string $key ): Payout {
		return $this->requests->request( $this->creator, 'DKK', $amount, 'gift_card', array(), $key, 1 );
	}

	/**
	 * A valid registration request.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $code      Code.
	 * @return array<string, string>
	 */
	private function register_input( int $payout_id, string $code ): array {
		return array(
			'action'         => PayoutActions::GIFT_CARD_ACTION,
			'payout_id'      => (string) $payout_id,
			'gift_card_code' => $code,
			'_wpnonce'       => wp_create_nonce( PayoutActions::nonce_action( PayoutActions::GIFT_CARD_ACTION, $payout_id ) ),
		);
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
	 * Sales of 100.00 incl. VAT (payout 20.00 each), dated 2026-09-01.
	 *
	 * @param int $count Number of sales.
	 */
	private function earn( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$order = $this->next_order++;
			$item  = 1000 + $order;
			$this->recorder->record( $item, $order, $this->product, 0, 'DKK' );
			$this->reader->orders[ $order ] = array(
				'status'    => 'processing',
				'currency'  => 'DKK',
				'date_paid' => '2026-09-01 10:05:00',
				'lines'     => array( new OrderLineSnapshot( $item, $this->product, 0, 'MB-GC line', 1, SalesAmount::parse( '80' ), $this->creator, SalesAmount::parse( '20' ) ) ),
				'refunds'   => array(),
			);
			$this->sync->sync( $order );
		}
	}

	/**
	 * Rows of the tables a G1 read must never write.
	 *
	 * @return list<int>
	 */
	private function counts(): array {
		return array_map( array( $this, 'count_rows' ), array( Tables::SALES, Tables::SALES_CYCLES, Tables::PAYOUTS, Tables::AUDIT_LOG, Tables::SETTLEMENT_DOCUMENTS, Tables::SETTLEMENT_ROWS, Tables::ORDER_GIFT_CARD_ASSESSMENTS, Tables::ORDER_GIFT_CARD_PAYOUTS, Tables::ORDER_GIFT_CARD_ATTEMPTS ) );
	}

	/**
	 * Uses the fake with the optional order lookup from now on.
	 */
	private function with_registry(): FakeOrderRegistryGiftCardProvider {
		$registry       = new FakeOrderRegistryGiftCardProvider( $this->fake );
		$this->registry = $registry;
		$this->services();

		return $registry;
	}

	/**
	 * Activates G1 directly on its row (test setup; nothing in G1's coupon
	 * check or views may depend on it, owner decision B3).
	 *
	 * @param string $cutoff Cut-off (UTC).
	 */
	private function activate( string $cutoff ): void {
		self::assertSame( 1, $this->execute( "UPDATE %i SET cutoff_utc = %s, activated_at = '2026-08-31 00:00:00', activated_by = 1 WHERE id = 1", $this->table( Tables::G1_STATE ), $cutoff ) );
	}

	/**
	 * Requires the coupon check to be impossible (fail closed), never "no".
	 *
	 * @param PayoutGiftCardOrders $orders  The check.
	 * @param string               $code    Code.
	 * @param string               $reason  Expected provider reason.
	 * @param string               $message Message.
	 */
	private function uncheckable( PayoutGiftCardOrders $orders, string $code, string $reason, string $message ): void {
		try {
			$orders->is_payout_card( $code );
			self::fail( 'Expected the check to be impossible: ' . $message );
		} catch ( GiftCardProviderException $e ) {
			self::assertSame( $reason, $e->reason(), $message );
		}
	}

	/**
	 * The creator's DKK balance as [earned, completed, reserved].
	 *
	 * @return array{0: int|null, 1: int, 2: int}
	 */
	private function balance(): array {
		$clock   = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$balance = ( new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock ) )->in_currency( $this->creator, 'DKK' );

		return array( $balance->earned_units, $balance->completed_units, $balance->reserved_units );
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
