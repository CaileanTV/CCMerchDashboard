<?php
/**
 * The creator row lock of every payout path on two real database connections (K6.1).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Payouts\CreatorRowLock;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardSnapshot;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutGiftCards;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutMethod;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
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
use MerchBoard\Tests\Integration\Support\FakeGiftCardProvider;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use wpdb;

/**
 * The lock protocol (ADR-055, K6.1): request, cancellation, rejection,
 * completion with its settlement document, gift card registration and the
 * settlement start each lock the creator's row as the first statement of
 * their transaction and hold it until COMMIT. A second, real connection
 * stands in for a later decision that locks the row without the named
 * payout lock. While it holds the row, every path waits (InnoDB lock wait of
 * 1 second, set and restored by the test) and fails without any side effect
 * and with its named lock released; after its COMMIT the same call succeeds
 * and a repeat changes nothing. Three sales of 100.00 incl. VAT (payout
 * 20.00 each), a fixed clock and a 14-day holding period. Synthetic data.
 */
final class PayoutCreatorRowLockTest extends IntegrationTestCase {

	private const DETAILS = array(
		'registration' => '1234',
		'account'      => '0012345678',
	);

	private ProductPosts $posts;

	private PayoutRepository $repository;

	private PayoutRequests $requests;

	private PayoutDecisions $decisions;

	private PayoutGiftCards $gift_cards;

	private PayoutSettings $settings;

	private FakeGiftCardProvider $fake;

	private int $creator;

	/**
	 * Settings before the test.
	 *
	 * @var mixed
	 */
	private mixed $saved_settings;

	/**
	 * Second database connection (closed in tearDown()).
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
		$this->saved_settings = get_option( Settings::OPTION );

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Row lock Alpha', CreatorStatus::Active )->id;
		$markups       = new CreatorMarkupRepository( $this->wpdb );
		$vat           = new CreatorVatRepository( $this->wpdb );
		$markups->save( $this->creator, new MarkupBps( 2000 ) );
		$vat->save( $this->creator, true );

		$transaction = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$this->posts = new ProductPosts( $this->wpdb );
		$product     = $this->posts->add( 'MB-K61 lock product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $product, '_regular_price', '100' );
		$this->posts->meta( $product, '_price', '100' );
		$links    = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $vat );
		$reader   = new FakeOrderReader();
		$sync     = new SalesSynchronizer( new SalesRepository( $this->wpdb, $transaction ), new SalesPlanner(), $reader, new RecordingFailureMarker(), new RecordingLogger(), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) ) );

		for ( $order = 9601; $order <= 9603; $order++ ) {
			$recorder->record( 1000 + $order, $order, $product, 0, 'DKK' );
			$reader->orders[ $order ] = array(
				'status'    => 'processing',
				'currency'  => 'DKK',
				'date_paid' => '2026-09-01 10:05:00',
				'lines'     => array( new OrderLineSnapshot( 1000 + $order, $product, 0, 'MB-K61 lock line', 1, SalesAmount::parse( '80' ), $this->creator, SalesAmount::parse( '20' ) ) ),
				'refunds'   => array(),
			);
			$sync->sync( $order );
		}

		$this->fake = new FakeGiftCardProvider();
		Settings::save( array( Settings::PAYOUT_GIFT_CARD_PROVIDER => 'fake' ) + Settings::all() );

		$clock            = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$audit            = $this->service( AuditTrail::class );
		$cipher           = new PayoutCipher( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$providers        = new GiftCardProviders( array( $this->fake ) );
		$this->repository = new PayoutRepository( $this->wpdb );
		$balances         = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock );
		$documents        = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), $creators, $vat, $audit, $transaction, $clock );
		$this->decisions  = new PayoutDecisions( $this->repository, $balances, $cipher, $audit, $transaction, $clock, $documents );
		$this->requests   = new PayoutRequests( $this->repository, $balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock, $providers );
		$this->gift_cards = new PayoutGiftCards( $this->repository, $balances, $providers, $audit, $transaction, new RecordingLogger(), $clock, $documents );
		$this->settings   = new PayoutSettings( $this->repository, $audit, $transaction, $clock );
	}

	protected function tearDown(): void {
		$this->second?->close();
		$this->posts->remove_all();
		update_option( Settings::OPTION, $this->saved_settings );
		parent::tearDown();
	}

	public function test_every_payout_path_waits_for_the_creator_row_and_succeeds_after_its_commit(): void {
		// The settlement start first: D3 locks it once a settlement document exists.
		$start = self::at( '2026-08-01 00:00:00' );
		self::assertTrue( $this->locked_then_done( 'settlement start', fn(): bool => $this->settings->set_settlement_start( $this->creator, $start ) ) );
		self::assertFalse( $this->settings->set_settlement_start( $this->creator, $start ), 'Repeated: unchanged.' );
		self::assertEquals( $start, $this->settings->settlement_start( $this->creator ) );

		$requested = $this->locked_then_done( 'request', fn(): Payout => $this->request( '5', 'key-lock-0001' ) );
		self::assertSame( array( PayoutStatus::Pending, 50000 ), array( $requested->status, $requested->amount_units ) );
		self::assertSame( $requested->id, $this->request( '5', 'key-lock-0001' )->id, 'Repeated with the same key: the stored payout.' );

		$payout    = $this->request( '1', 'key-lock-0002' );
		$cancelled = $this->locked_then_done( 'cancel', fn(): Payout => $this->requests->cancel( $this->creator, $payout->id, 1 ) );
		self::assertSame( PayoutStatus::Cancelled, $cancelled->status );
		self::assertSame( PayoutStatus::Cancelled, $this->requests->cancel( $this->creator, $payout->id, 1 )->status );

		$payout   = $this->request( '1', 'key-lock-0003' );
		$rejected = $this->locked_then_done( 'reject', fn(): Payout => $this->decisions->reject( $payout->id, 'Wrong account.' ) );
		self::assertSame( PayoutStatus::Rejected, $rejected->status );
		self::assertSame( PayoutStatus::Rejected, $this->decisions->reject( $payout->id, 'Wrong account.' )->status, 'Already handled.' );

		$payout    = $this->request( '10', 'key-lock-0004' );
		$completed = $this->locked_then_done( 'complete', fn(): Payout => $this->decisions->complete( $payout->id ) );
		self::assertSame( PayoutStatus::Completed, $completed->status );
		self::assertSame( PayoutStatus::Completed, $this->decisions->complete( $payout->id )->status, 'Already handled.' );
		self::assertSame( array( 1, 3 ), array( $this->count_rows( Tables::SETTLEMENT_DOCUMENTS ), $this->count_rows( Tables::SETTLEMENT_ROWS ) ), 'One document with the three sales.' );

		$payout                         = $this->requests->request( $this->creator, 'DKK', '10', PayoutMethod::GiftCard->value, array(), 'key-lock-0005', 1 );
		$this->fake->cards['MB-K61-GC'] = new GiftCardSnapshot( 'gc-k61', SalesAmount::parse( '10' ), SalesAmount::parse( '10' ), 'DKK', GiftCardStatus::Active, null, true );
		$registered                     = $this->locked_then_done( 'gift card', fn(): Payout => $this->gift_cards->register( $payout->id, 'MB-K61-GC' ) );
		self::assertSame( array( PayoutStatus::Completed, 'gc-k61' ), array( $registered->status, $registered->gift_card_external_id ) );
		self::assertSame( PayoutStatus::Completed, $this->gift_cards->register( $payout->id, 'MB-K61-GC' )->status, 'Already registered.' );
		self::assertSame( array( 2, 3 ), array( $this->count_rows( Tables::SETTLEMENT_DOCUMENTS ), $this->count_rows( Tables::SETTLEMENT_ROWS ) ), 'A second document; no row settled twice.' );
	}

	public function test_the_lock_requires_the_creators_named_lock(): void {
		try {
			CreatorRowLock::acquire( $this->repository, $this->creator );
			self::fail( 'The row lock is taken only under the named lock.' );
		} catch ( DatabaseException $e ) {
			self::assertStringContainsString( 'not held', $e->getMessage() );
		}

		self::assertSame( array(), $this->wpdb->get_col( 'SELECT trx_id FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()' ), 'Nothing was locked.' );
	}

	/**
	 * Runs $action while the second connection holds the creator's row:
	 * it must wait, fail and change nothing, with its named lock released.
	 * After the second connection's COMMIT it runs again and must succeed,
	 * with the row lock as the first statement of its transaction and held
	 * until its COMMIT. Returns the successful result.
	 *
	 * @template T
	 * @param string        $label  Path.
	 * @param callable(): T $action The path.
	 * @return T
	 */
	private function locked_then_done( string $label, callable $action ): mixed {
		$before = $this->state();
		$second = $this->second_connection();
		self::assertNotFalse( $second->query( 'START TRANSACTION' ) );
		self::assertSame( (string) $this->creator, $second->get_var( $second->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $this->table( Tables::CREATORS ), $this->creator ) ) );

		$refused = $this->while_waiting( $action );

		self::assertInstanceOf( DatabaseException::class, $refused, $label . ': refused while the row is locked.' );
		self::assertGreaterThanOrEqual( 0.9, $this->waited, $label . ': it really waited for the row.' );
		self::assertSame( $before, $this->state(), $label . ': nothing changed (rolled back).' );
		$connection = (int) $second->get_var( 'SELECT CONNECTION_ID()' );
		self::assertSame( $connection, ( new PayoutRepository( $second, 0 ) )->with_creator_lock( $this->creator, static fn(): int => (int) $second->get_var( 'SELECT CONNECTION_ID()' ) ), $label . ': the named lock was released.' );
		self::assertNotFalse( $second->query( 'COMMIT' ) );

		$queries = array();
		$probe   = null;
		$record  = function ( string $query ) use ( &$queries, &$probe, $second ): string {
			$queries[] = $query;

			// Late in the transaction (its audit record): the row must still be locked.
			if ( null === $probe && str_starts_with( ltrim( $query ), 'INSERT' ) && str_contains( $query, $this->table( Tables::AUDIT_LOG ) ) ) {
				$probe = $second->get_var( $second->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $this->table( Tables::CREATORS ), $this->creator ) );
				$probe = null === $probe ? 'waited' : 'free';
				$second->query( 'ROLLBACK' );
			}

			return $query;
		};
		add_filter( 'query', $record );

		try {
			$result = $action();
		} finally {
			remove_filter( 'query', $record );
		}

		$start = array_search( 'START TRANSACTION', $queries, true );
		self::assertIsInt( $start, $label . ': runs in a transaction.' );
		self::assertMatchesRegularExpression( '/^SELECT id, payout_start_at FROM `?' . preg_quote( $this->table( Tables::CREATORS ), '/' ) . '`? WHERE id = ' . $this->creator . ' FOR UPDATE$/', $queries[ $start + 1 ] ?? '', $label . ': the creator row is the first statement of the transaction.' );
		self::assertSame( 'waited', $probe, $label . ': the row stays locked until COMMIT.' );

		return $result;
	}

	/**
	 * Everything a payout path may change, as stored.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		return array(
			'creators'  => $this->rows( Tables::CREATORS ),
			'payouts'   => $this->rows( Tables::PAYOUTS ),
			'secrets'   => $this->count_rows( Tables::PAYOUT_SECRETS ),
			'audit'     => $this->rows( Tables::AUDIT_LOG ),
			'documents' => $this->count_rows( Tables::SETTLEMENT_DOCUMENTS ),
			'rows'      => $this->count_rows( Tables::SETTLEMENT_ROWS ),
			'counters'  => $this->count_rows( Tables::SETTLEMENT_COUNTERS ),
		);
	}

	/**
	 * A second, real database connection (own session, locks and
	 * transactions) with the test's table prefix and an InnoDB lock wait of
	 * 1 second (session only).
	 */
	private function second_connection(): wpdb {
		if ( null === $this->second ) {
			$this->second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$this->second->set_prefix( $this->wpdb->prefix );
			$this->second->suppress_errors( true );
			$this->second->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
			self::assertNotSame( $this->wpdb->get_var( 'SELECT CONNECTION_ID()' ), $this->second->get_var( 'SELECT CONNECTION_ID()' ), 'Two real connections.' );
		}

		return $this->second;
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
	 * A bank request of the creator, as user 1.
	 *
	 * @param string $amount Amount.
	 * @param string $key    Request key.
	 */
	private function request( string $amount, string $key ): Payout {
		return $this->requests->request( $this->creator, 'DKK', $amount, 'bank', self::DETAILS, $key, 1 );
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
