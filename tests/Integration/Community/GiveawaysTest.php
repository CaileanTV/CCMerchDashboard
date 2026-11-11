<?php
/**
 * Giveaway links: price, balance, the link, the bot and the shop (ADR-110).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Community;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Community\Gifting\GiftCodes;
use MerchBoard\Modules\Community\Gifting\GiftDraws;
use MerchBoard\Modules\Community\Gifting\GiftingSettingsRepository;
use MerchBoard\Modules\Community\Gifting\GiftRepository;
use MerchBoard\Modules\Community\Giveaways\Giveaway;
use MerchBoard\Modules\Community\Giveaways\GiveawayException;
use MerchBoard\Modules\Community\Giveaways\GiveawayRepository;
use MerchBoard\Modules\Community\Giveaways\Giveaways;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutAdjustmentRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * The price is a fixed quote (WooCommerce is not loaded here): a product
 * of 249 kr. whose base price incl. VAT is 149 kr., so the creator pays
 * 199 kr. with the shipping. Nova's balance is a positive payout
 * adjustment of 500 kr.; the chat, the live status and the jobs are
 * recorded closures.
 */
final class GiveawaysTest extends IntegrationTestCase {

	/**
	 * Messages sent, as [creator, text].
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	private array $sent = array();

	/**
	 * Jobs queued, as [hook, args, delay].
	 *
	 * @var list<array{0: string, 1: array<mixed>, 2: int}>
	 */
	private array $jobs = array();

	/**
	 * Notifications, as [type, creator].
	 *
	 * @var list<array{0: string, 1: int}>
	 */
	private array $notified = array();

	private string $cost = '199.0000';

	/**
	 * This test's notification listener.
	 *
	 * @var Closure(string, int): void
	 */
	private Closure $listener;

	private int $nova;

	private int $orion;

	private GiftRepository $gifts;

	private GiveawayRepository $repository;

	private GiftDraws $draws;

	private Giveaways $giveaways;

	private PayoutRepository $payouts;

	private PayoutAdjustmentRepository $adjustments;

	private PayoutBalances $balances;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_GIVEAWAYS_ENABLED => true ) ) );

		$creators    = new CreatorRepository( $this->wpdb );
		$this->nova  = $creators->create( 'Nova', CreatorStatus::Active )->id;
		$this->orion = $creators->create( 'Orion', CreatorStatus::Active )->id;
		$channels    = new ChatbotChannelRepository( $this->wpdb );
		$channels->connect( $this->nova, ChatPlatform::Twitch, '4711', 'nova' );

		$clock             = static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$this->gifts       = new GiftRepository( $this->wpdb, $this->service( Transaction::class ) );
		$this->repository  = new GiveawayRepository( $this->wpdb );
		$this->payouts     = new PayoutRepository( $this->wpdb );
		$this->adjustments = new PayoutAdjustmentRepository( $this->wpdb );
		$this->balances    = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->payouts, $clock, $this->adjustments );
		$enqueue           = function ( string $hook, array $args, int $delay ): bool {
			$this->jobs[] = array( $hook, $args, $delay );

			return true;
		};
		$settings          = new GiftingSettingsRepository(
			$this->service( CreatorMetaStore::class ),
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			static fn(): bool => true
		);
		$this->draws       = new GiftDraws(
			$this->gifts,
			$settings,
			new GiftCodes( $this->gifts, new RecordingLogger() ),
			function ( int $creator_id, string $message ): bool {
				$this->sent[] = array( $creator_id, $message );

				return true;
			},
			static fn(): bool => true,
			static fn( string $token ): string => 'https://shop.test/?merchboard_gift_claim=' . $token,
			static fn(): string => 'Stream tee',
			$enqueue,
			static function ( string $event, Gift $gift, array $data ): void {
				do_action( GiftDraws::EVENT_HOOK, $event, $gift, $data );
			},
			new RecordingLogger()
		);
		$this->giveaways   = new Giveaways(
			$this->repository,
			$this->gifts,
			$this->draws,
			fn( int $product_id ): ?array => 77 === $product_id ? array(
				'variation_id' => 0,
				'unit_price'   => '249.0000',
				'base_price'   => '119.2000',
				'shipping'     => '50.0000',
				'cost'         => $this->cost,
				'currency'     => 'DKK',
			) : null,
			$this->balances,
			$this->payouts,
			$this->adjustments,
			$this->service( Transaction::class ),
			$this->service( AuditTrail::class ),
			$enqueue,
			$clock,
			static fn(): string => 'Nova',
			static fn(): string => 'Stream tee',
			static fn(): string => 'da',
			new RecordingLogger()
		);
		$this->giveaways->register();
		$this->listener = function ( string $type, int $creator_id ): void {
			$this->notified[] = array( $type, $creator_id );
		};
		add_action( 'merchboard_notify', $this->listener, 10, 2 );

		$this->credit( $this->nova, '500' );
	}

	protected function tearDown(): void {
		// Only this test's own callbacks: the plugin's listeners stay for the next tests.
		remove_filter( 'merchboard_payout_held', array( $this->giveaways, 'held' ) );
		remove_action( GiftCodes::USED_HOOK, array( $this->giveaways, 'used' ) );
		remove_action( GiftDraws::EVENT_HOOK, array( $this->giveaways, 'gift_event' ) );
		remove_action( Giveaways::CHARGE_HOOK, array( $this->giveaways, 'charge' ) );
		remove_action( Giveaways::EXPIRE_HOOK, array( $this->giveaways, 'expire' ) );
		remove_action( 'merchboard_notify', $this->listener );
		$this->execute( 'DELETE FROM %i WHERE option_name LIKE %s', $this->wpdb->options, '%merchboard_gift_%' );
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_a_giveaway_from_the_balance_is_set_aside_and_charged_once_when_its_code_is_used(): void {
		$giveaway = $this->giveaways->create( $this->nova, 77, Giveaway::MANUAL, Giveaway::BALANCE, 60 );
		$gift     = $this->gifts->find_by_giveaway( $giveaway->id );

		self::assertSame( array( Giveaway::READY, Giveaway::MANUAL, '199.0000', '249.0000', '50.0000' ), array( $giveaway->status, $giveaway->mode, $giveaway->cost, $giveaway->unit_price, $giveaway->shipping_credit ) );
		self::assertNotNull( $gift );
		self::assertSame( $gift->id, $giveaway->gift_id );
		self::assertTrue( $gift->open_link(), 'A link anybody can redeem, without a Twitch winner.' );
		self::assertSame( 0, $gift->order_id, 'A giveaway is not a gift order.' );
		self::assertNotNull( $gift->claim_token );
		self::assertContains( array( GiftDraws::EXPIRE_HOOK, array( $gift->id ), 30 * DAY_IN_SECONDS + 5 ), $this->jobs );
		self::assertSame( SalesAmount::parse( '301' ), $this->available(), 'The cost is set aside at once.' );
		self::assertSame( SalesAmount::parse( '199' ), $this->balances->in_currency( $this->nova, 'DKK' )->held_units );
		self::assertSame( 'giveaway_created', $this->last_audit() );

		// The balance left does not cover a second one.
		$this->cost = '400.0000';

		try {
			$this->giveaways->create( $this->nova, 77, Giveaway::MANUAL, Giveaway::BALANCE, 60 );
			self::fail( 'The balance does not cover it.' );
		} catch ( GiveawayException $e ) {
			self::assertSame( GiveawayException::BALANCE, $e->getMessage() );
		}

		// The link is redeemed and its code used in a paid order: a job charges it, once.
		self::assertTrue( $this->gifts->claim( $gift->id, 0 ) );
		self::assertTrue( $this->gifts->used_in( $gift->id, 501 ) );
		do_action( GiftCodes::USED_HOOK, $this->gifts->find( $gift->id ), 501 );

		self::assertContains( array( Giveaways::CHARGE_HOOK, array( $giveaway->id, 501 ), 0 ), $this->jobs );

		$this->giveaways->charge( $giveaway->id, 501 );
		$this->giveaways->charge( $giveaway->id, 501 );

		$used = $this->repository->find( $giveaway->id );
		self::assertSame( Giveaway::USED, $used?->status );
		self::assertNotNull( $used->adjustment_id );
		self::assertSame( array( 'DKK' => SalesAmount::parse( '301' ) ), $this->adjustments->totals( $this->nova ), 'Charged once: 500 − 199.' );
		self::assertSame( SalesAmount::parse( '301' ), $this->available(), 'Nothing is set aside any more.' );
		self::assertSame( 'giveaway_charged', $this->last_audit() );
		self::assertContains( array( 'giveaway_used', $this->nova ), $this->notified );

		$charge = $this->adjustments->unsettled_for_update( $this->nova, 'DKK' )[1] ?? array();
		self::assertSame( array( $giveaway->id, -SalesAmount::parse( '199' ) ), array( $charge['giveaway_id'] ?? null, $charge['amount_units'] ?? null ) );
		self::assertSame( sprintf( 'GIV-%06d', $giveaway->id ), PayoutAdjustmentRepository::giveaway_reference( $giveaway->id ) );
	}

	public function test_a_link_nobody_redeemed_runs_out_and_costs_nothing(): void {
		$giveaway = $this->giveaways->create( $this->nova, 77, Giveaway::MANUAL, Giveaway::BALANCE, 60 );
		$gift     = (int) $giveaway->gift_id;

		$this->execute( 'UPDATE %i SET claim_deadline = %s WHERE id = %d', $this->table( Tables::GIFTS ), gmdate( 'Y-m-d H:i:s', time() - 60 ), $gift );
		$this->draws->expire( $gift );

		self::assertSame( Giveaway::EXPIRED, $this->repository->find( $giveaway->id )?->status );
		self::assertSame( Gift::CANCELLED, $this->gifts->find( $gift )?->status );
		self::assertSame( SalesAmount::parse( '500' ), $this->available(), 'The money is no longer set aside.' );
		self::assertSame( array( 'DKK' => SalesAmount::parse( '500' ) ), $this->adjustments->totals( $this->nova ), 'Nothing is charged.' );
		self::assertContains( array( 'giveaway_expired', $this->nova ), $this->notified );
	}

	public function test_a_redeemed_code_that_runs_out_unused_releases_the_money(): void {
		$giveaway = $this->giveaways->create( $this->nova, 77, Giveaway::MANUAL, Giveaway::BALANCE, 60 );
		$gift     = (int) $giveaway->gift_id;

		self::assertTrue( $this->gifts->claim( $gift, 0 ) );
		do_action( GiftDraws::EVENT_HOOK, 'claimed', $this->gifts->find( $gift ), array() );

		self::assertContains( array( Giveaways::EXPIRE_HOOK, array( $giveaway->id ), 30 * DAY_IN_SECONDS + MINUTE_IN_SECONDS ), $this->jobs );

		$this->giveaways->expire( $giveaway->id );

		self::assertSame( Giveaway::EXPIRED, $this->repository->find( $giveaway->id )?->status );
		self::assertSame( SalesAmount::parse( '500' ), $this->available() );

		// A code used in an order paid later is still charged.
		$this->giveaways->charge( $giveaway->id, 502 );

		self::assertSame( Giveaway::USED, $this->repository->find( $giveaway->id )?->status );
		self::assertSame( array( 'DKK' => SalesAmount::parse( '301' ) ), $this->adjustments->totals( $this->nova ) );
	}

	public function test_a_bot_giveaway_starts_in_the_chat_and_goes_to_the_creator_after_three_draws(): void {
		$giveaway = $this->giveaways->create( $this->nova, 77, Giveaway::BOT, Giveaway::BALANCE, 45 );
		$gift     = (int) $giveaway->gift_id;

		self::assertSame( Gift::WAITING, $this->gifts->find( $gift )?->status );
		self::assertFalse( $this->draws->start( $this->nova ), 'Twitch gifting never starts a giveaway.' );

		self::assertSame( 'started', $this->giveaways->start( $this->nova, $giveaway->id ) );
		self::assertSame( array( $this->nova, '🎁 Nova giver Stream tee væk! Skriv !enter inden for 45 sekunder for at være med i lodtrækningen.' ), $this->sent[0] ?? null );
		self::assertSame( Gift::ENTERING, $this->gifts->find( $gift )?->status );
		self::assertSame( 'not_waiting', $this->giveaways->start( $this->nova, $giveaway->id ), 'Its window is open already.' );

		// Three winners did not claim it: the creator gets the link.
		$this->execute( "UPDATE %i SET status = 'won', draws = 3, winner_twitch_id = '99', winner_login = 'viewer', claim_token = 'x', claim_deadline = %s WHERE id = %d", $this->table( Tables::GIFTS ), gmdate( 'Y-m-d H:i:s', time() - 60 ), $gift );
		$this->draws->expire( $gift );

		$link = $this->gifts->find( $gift );
		self::assertTrue( $link?->open_link() );
		self::assertNotSame( 'x', $link->claim_token, 'A new token: the old winner\'s link no longer works.' );
		self::assertContains( array( 'giveaway_link', $this->nova ), $this->notified );
		self::assertSame( Giveaway::READY, $this->repository->find( $giveaway->id )?->status );
	}

	public function test_cancelling_releases_the_money_and_only_the_creator_can_do_it(): void {
		$giveaway = $this->giveaways->create( $this->nova, 77, Giveaway::BOT, Giveaway::BALANCE, 60 );

		try {
			$this->giveaways->cancel( $this->orion, $giveaway->id );
			self::fail( 'Another creator\'s giveaway.' );
		} catch ( GiveawayException $e ) {
			self::assertSame( GiveawayException::NOT_FOUND, $e->getMessage() );
		}

		$this->giveaways->cancel( $this->nova, $giveaway->id );

		self::assertSame( Giveaway::CANCELLED, $this->repository->find( $giveaway->id )?->status );
		self::assertSame( Gift::CANCELLED, $this->gifts->find( (int) $giveaway->gift_id )?->status );
		self::assertSame( SalesAmount::parse( '500' ), $this->available() );
		self::assertSame( 'giveaway_cancelled', $this->last_audit() );

		try {
			$this->giveaways->cancel( $this->nova, $giveaway->id );
			self::fail( 'Already cancelled.' );
		} catch ( GiveawayException $e ) {
			self::assertSame( GiveawayException::NOT_CANCELLABLE, $e->getMessage() );
		}
	}

	public function test_a_giveaway_paid_in_the_shop_is_ready_when_its_order_is_paid(): void {
		$this->cost = '600.0000';
		$giveaway   = $this->giveaways->create( $this->nova, 77, Giveaway::MANUAL, Giveaway::SHOP, 60 );

		self::assertSame( array( Giveaway::AWAITING_PAYMENT, null ), array( $giveaway->status, $giveaway->gift_id ) );
		self::assertSame( SalesAmount::parse( '500' ), $this->available(), 'Paid in the shop: nothing is set aside.' );

		$this->giveaways->paid( $giveaway->id, 801 );
		$ready = $this->repository->find( $giveaway->id );

		self::assertSame( array( Giveaway::READY, 801 ), array( $ready?->status, $ready?->order_id ) );
		self::assertTrue( $this->gifts->find( (int) $ready?->gift_id )?->open_link() );

		// Used: only marked, never charged from the balance.
		$this->giveaways->charge( $giveaway->id, 802 );
		self::assertSame( Giveaway::USED, $this->repository->find( $giveaway->id )?->status );
		self::assertSame( array( 'DKK' => SalesAmount::parse( '500' ) ), $this->adjustments->totals( $this->nova ) );

		// A refunded order of another giveaway cancels it while nobody has it.
		$other = $this->giveaways->create( $this->nova, 77, Giveaway::MANUAL, Giveaway::SHOP, 60 );
		$this->giveaways->paid( $other->id, 803 );
		$this->giveaways->order_cancelled( $other->id, 803 );

		$cancelled = $this->repository->find( $other->id );

		self::assertNotNull( $cancelled );
		self::assertSame( Giveaway::CANCELLED, $cancelled->status );
		self::assertSame( Gift::CANCELLED, $this->gifts->find( (int) $cancelled->gift_id )?->status );
	}

	public function test_nothing_is_created_while_giveaways_are_switched_off_or_for_another_product(): void {
		try {
			$this->giveaways->create( $this->nova, 78, Giveaway::MANUAL, Giveaway::BALANCE, 60 );
			self::fail( 'Not the creator\'s product.' );
		} catch ( GiveawayException $e ) {
			self::assertSame( GiveawayException::PRODUCT, $e->getMessage() );
		}

		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_GIVEAWAYS_ENABLED => false ) ) );

		try {
			$this->giveaways->create( $this->nova, 77, Giveaway::MANUAL, Giveaway::BALANCE, 60 );
			self::fail( 'Switched off.' );
		} catch ( GiveawayException $e ) {
			self::assertSame( GiveawayException::DISABLED, $e->getMessage() );
		}

		self::assertSame( array(), $this->repository->latest( $this->nova ) );
	}

	/**
	 * A positive payout adjustment as the creator's balance.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $amount     Amount.
	 */
	private function credit( int $creator_id, string $amount ): void {
		$this->payouts->with_creator_lock(
			$creator_id,
			fn(): int => $this->adjustments->insert_fee( $creator_id, 'DKK', SalesAmount::parse( $amount ), 1, null, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), $this->payouts->lock_connection( $creator_id ) )
		);
	}

	/**
	 * Nova's available balance in DKK (units).
	 */
	private function available(): ?int {
		return $this->balances->in_currency( $this->nova, 'DKK' )->available_units();
	}

	/**
	 * The latest audit action.
	 */
	private function last_audit(): string {
		return (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT action FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ) ) );
	}
}
