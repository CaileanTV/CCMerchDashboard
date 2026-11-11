<?php
/**
 * Creators' giveaways.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Giveaways;

use Closure;
use DateTimeImmutable;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Community\Gifting\GiftCodes;
use MerchBoard\Modules\Community\Gifting\GiftDraws;
use MerchBoard\Modules\Community\Gifting\GiftingSettings;
use MerchBoard\Modules\Community\Gifting\GiftRepository;
use MerchBoard\Modules\Community\Gifting\GiftTexts;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutAdjustmentRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * Giveaway links (ADR-110): a creator gives a product away, drawn by the bot
 * in the Twitch chat or as a link the creator passes on.
 *
 * - **Price:** the base price plus 50 kr. of shipping (GiveawayCost), fixed
 *   when it is created.
 * - **From the balance** (only when the available balance covers it): the
 *   cost is set aside (`merchboard_payout_held`) and charged as a negative
 *   payout adjustment when the code is used in a paid order; an expired or
 *   cancelled giveaway costs nothing.
 * - **In the shop:** paid at once in a checkout of the base price
 *   (GiveawayCheckout); the giveaway is ready when the order is paid.
 * - **Ready:** its gift (a row of `merchboard_gifts`) waits for "Start in
 *   the chat" (bot), or is an open link for 30 days (manual).
 *
 * The charge is a job, never part of a WooCommerce order save.
 */
final class Giveaways {

	/**
	 * Job: charges a giveaway whose code was used (args: giveaway id, order id).
	 */
	public const CHARGE_HOOK = 'merchboard_giveaway_charge';

	/**
	 * Job: a redeemed code ran out unused (args: giveaway id).
	 */
	public const EXPIRE_HOOK = 'merchboard_giveaway_expire';

	/**
	 * Open giveaways per creator at most.
	 */
	public const MAX_OPEN = 20;

	/**
	 * Enqueues a job.
	 *
	 * @var Closure(string, array<int, int>, int): bool
	 */
	private Closure $enqueue;

	/**
	 * Current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * A creator's name.
	 *
	 * @var Closure(int): string
	 */
	private Closure $creator_name;

	/**
	 * A product's name.
	 *
	 * @var Closure(int): string
	 */
	private Closure $product_name;

	/**
	 * The chat language of a creator's bot (`da` or `en`).
	 *
	 * @var Closure(int): string
	 */
	private Closure $language;

	/**
	 * The price of giving a product away now (GiveawayCost::quote()).
	 *
	 * @var Closure(int, int): (array{variation_id: int, unit_price: string, base_price: string, shipping: string, cost: string, currency: string}|null)
	 */
	private Closure $quote;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int, int): (array{variation_id: int, unit_price: string, base_price: string, shipping: string, cost: string, currency: string}|null) $quote
	 * @phpstan-param Closure(string, array<int, int>, int): bool $enqueue
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 * @phpstan-param Closure(int): string $creator_name
	 * @phpstan-param Closure(int): string $product_name
	 * @phpstan-param Closure(int): string $language
	 *
	 * @param GiveawayRepository         $giveaways    Giveaways.
	 * @param GiftRepository             $gifts        Their gifts.
	 * @param GiftDraws                  $draws        The draws in the chat.
	 * @param Closure                    $quote        The price of a product (product, creator), GiveawayCost::quote().
	 * @param PayoutBalances             $balances     Balances.
	 * @param PayoutRepository           $payouts      The creator's payout lock.
	 * @param PayoutAdjustmentRepository $adjustments  Charges.
	 * @param Transaction                $transaction  Transaction.
	 * @param AuditTrail                 $audit        Audit.
	 * @param Closure                    $enqueue      Enqueues a job (hook, args, delay).
	 * @param Closure                    $clock        Current time.
	 * @param Closure                    $creator_name A creator's name.
	 * @param Closure                    $product_name A product's name.
	 * @param Closure                    $language     A creator's chat language.
	 * @param Logger                     $logger       Technical log.
	 */
	public function __construct(
		private GiveawayRepository $giveaways,
		private GiftRepository $gifts,
		private GiftDraws $draws,
		Closure $quote,
		private PayoutBalances $balances,
		private PayoutRepository $payouts,
		private PayoutAdjustmentRepository $adjustments,
		private Transaction $transaction,
		private AuditTrail $audit,
		Closure $enqueue,
		Closure $clock,
		Closure $creator_name,
		Closure $product_name,
		Closure $language,
		private Logger $logger
	) {
		$this->enqueue      = $enqueue;
		$this->clock        = $clock;
		$this->creator_name = $creator_name;
		$this->product_name = $product_name;
		$this->language     = $language;
		$this->quote        = $quote;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'merchboard_payout_held', array( $this, 'held' ), 10, 2 );
		add_action( GiftCodes::USED_HOOK, array( $this, 'used' ), 10, 2 );
		add_action( GiftDraws::EVENT_HOOK, array( $this, 'gift_event' ), 10, 3 );
		add_action( self::CHARGE_HOOK, array( $this, 'charge' ), 10, 2 );
		add_action( self::EXPIRE_HOOK, array( $this, 'expire' ) );
	}

	/**
	 * Creates a giveaway: from the balance (set aside now) or to be paid in
	 * the shop.
	 *
	 * @param int    $creator_id Creator.
	 * @param int    $product_id Parent product.
	 * @param string $mode       `bot` or `manual`.
	 * @param string $funding    `balance` or `shop`.
	 * @param int    $window     The `!enter` window of a bot giveaway, in seconds.
	 * @throws GiveawayException When it is refused.
	 * @throws PayoutException When the creator's payout lock is busy or the creator is gone.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function create( int $creator_id, int $product_id, string $mode, string $funding, int $window ): Giveaway {
		if ( ! Settings::giveaways_enabled() ) {
			self::refuse( GiveawayException::DISABLED );
		}

		if ( $this->giveaways->count_open( $creator_id ) >= self::MAX_OPEN ) {
			self::refuse( GiveawayException::LIMIT );
		}

		$quote = ( $this->quote )( $product_id, $creator_id );

		if ( null === $quote ) {
			self::refuse( GiveawayException::PRODUCT );
		}

		$mode     = Giveaway::MANUAL === $mode ? Giveaway::MANUAL : Giveaway::BOT;
		$funding  = Giveaway::SHOP === $funding ? Giveaway::SHOP : Giveaway::BALANCE;
		$window   = max( GiftingSettings::MIN_WINDOW, min( GiftingSettings::MAX_WINDOW, $window ) );
		$giveaway = $this->payouts->with_creator_lock(
			$creator_id,
			function () use ( $creator_id, $product_id, $mode, $funding, $window, $quote ): Giveaway {
				// Under the lock, so two giveaways or a payout request cannot use the same money.
				if ( Giveaway::BALANCE === $funding ) {
					$available = $this->balances->in_currency( $creator_id, $quote['currency'] )->available_units();

					if ( null === $available || $available < SalesAmount::parse( $quote['cost'] ) ) {
						self::refuse( GiveawayException::BALANCE );
					}
				}

				return $this->giveaways->create( $creator_id, $product_id, $mode, $funding, $quote['unit_price'], $quote['shipping'], $quote['cost'], $quote['currency'], $window );
			}
		);

		$this->audit->record(
			AuditAction::GiveawayCreated,
			$creator_id,
			array(
				'giveaway_id' => $giveaway->id,
				'product_id'  => $product_id,
				'mode'        => $mode,
				'funding'     => $funding,
				'cost'        => $giveaway->cost,
				'currency'    => $giveaway->currency,
			)
		);

		return $this->activate( $giveaway );
	}

	/**
	 * Gives a ready giveaway its gift (once): an open link for 30 days, or a
	 * gift waiting for "Start in the chat".
	 *
	 * @param Giveaway $giveaway Giveaway.
	 * @return Giveaway The giveaway as it is now.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function activate( Giveaway $giveaway ): Giveaway {
		if ( Giveaway::READY !== $giveaway->status || null !== $giveaway->gift_id ) {
			return $giveaway;
		}

		$manual = Giveaway::MANUAL === $giveaway->mode;
		$gift   = $this->gifts->create_for_giveaway(
			$giveaway->creator_id,
			$giveaway->id,
			$giveaway->product_id,
			$giveaway->unit_price,
			$giveaway->shipping_credit,
			$giveaway->currency,
			$manual ? gmdate( 'Y-m-d H:i:s', time() + GiftCodes::GIVEAWAY_DAYS * DAY_IN_SECONDS ) : null
		);

		$this->giveaways->attach( $giveaway->id, array( 'gift_id' => $gift->id ) );

		if ( $manual ) {
			// GiftDraws ends the link when nobody redeemed it.
			( $this->enqueue )( GiftDraws::EXPIRE_HOOK, array( $gift->id ), GiftCodes::GIVEAWAY_DAYS * DAY_IN_SECONDS + 5 );
		}

		return $this->giveaways->find( $giveaway->id ) ?? $giveaway;
	}

	/**
	 * A creator's giveaway, or an exception.
	 *
	 * @param int $creator_id  Creator.
	 * @param int $giveaway_id Giveaway.
	 * @throws GiveawayException When the creator has no such giveaway.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function own( int $creator_id, int $giveaway_id ): Giveaway {
		$giveaway = $this->giveaways->find( $giveaway_id );

		if ( null === $giveaway || $creator_id !== $giveaway->creator_id ) {
			self::refuse( GiveawayException::NOT_FOUND );
		}

		return $giveaway;
	}

	/**
	 * Starts a bot giveaway's draw in the chat.
	 *
	 * @param int $creator_id  Creator.
	 * @param int $giveaway_id Giveaway.
	 * @return string `started`, `not_live`, `busy` or `not_waiting` (GiftDraws::start_giveaway()).
	 * @throws GiveawayException When it is refused.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function start( int $creator_id, int $giveaway_id ): string {
		if ( ! Settings::giveaways_enabled() ) {
			self::refuse( GiveawayException::DISABLED );
		}

		$giveaway = $this->activate( $this->own( $creator_id, $giveaway_id ) );
		$gift     = Giveaway::BOT === $giveaway->mode && Giveaway::READY === $giveaway->status ? $this->gifts->find_by_giveaway( $giveaway->id ) : null;

		if ( null === $gift ) {
			return 'not_waiting';
		}

		$text = GiftTexts::giveaway( ( $this->language )( $creator_id ), ( $this->creator_name )( $creator_id ), ( $this->product_name )( $giveaway->product_id ), $giveaway->window_seconds );

		return $this->draws->start_giveaway( $gift, $giveaway->window_seconds, $text );
	}

	/**
	 * Cancels a giveaway before anybody can get it: one awaiting payment, or
	 * one from the balance whose gift waits for a draw or whose link nobody
	 * redeemed. Its money is no longer set aside.
	 *
	 * @param int    $creator_id  Creator.
	 * @param int    $giveaway_id Giveaway.
	 * @param string $reason      For the audit: `creator` or `creator_deleted`.
	 * @throws GiveawayException When it cannot be cancelled.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function cancel( int $creator_id, int $giveaway_id, string $reason = 'creator' ): void {
		$giveaway = $this->own( $creator_id, $giveaway_id );

		if ( Giveaway::AWAITING_PAYMENT === $giveaway->status ) {
			if ( ! $this->giveaways->move( $giveaway->id, Giveaway::AWAITING_PAYMENT, Giveaway::CANCELLED ) ) {
				self::refuse( GiveawayException::NOT_CANCELLABLE );
			}
		} elseif ( Giveaway::READY !== $giveaway->status || Giveaway::BALANCE !== $giveaway->funding || ! $this->stop_gift( $giveaway ) ) {
			// A giveaway paid in the shop is paid; only cancelling its order cancels it.
			self::refuse( GiveawayException::NOT_CANCELLABLE );
		} elseif ( ! $this->giveaways->move( $giveaway->id, Giveaway::READY, Giveaway::CANCELLED ) ) {
			self::refuse( GiveawayException::NOT_CANCELLABLE );
		}

		$this->audit->record(
			AuditAction::GiveawayCancelled,
			$creator_id,
			array(
				'giveaway_id' => $giveaway->id,
				'reason'      => $reason,
			)
		);
	}

	/**
	 * Cancels a deleted creator's giveaways that nobody can get yet (the
	 * others stay, so a code given out still works).
	 *
	 * @param int $creator_id Creator.
	 * @return int Cancelled giveaways.
	 * @throws DatabaseException When they cannot be read or stored.
	 */
	public function end_for_creator( int $creator_id ): int {
		$ended = 0;

		foreach ( $this->giveaways->latest( $creator_id, 500 ) as $giveaway ) {
			if ( ! $giveaway->open() ) {
				continue;
			}

			try {
				$this->cancel( $creator_id, $giveaway->id, 'creator_deleted' );
				++$ended;
			} catch ( GiveawayException ) {
				continue;
			}
		}

		return $ended;
	}

	/**
	 * The shop order of a giveaway was paid: the giveaway is ready.
	 *
	 * @param int $giveaway_id Giveaway.
	 * @param int $order_id    The paid order.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function paid( int $giveaway_id, int $order_id ): void {
		$giveaway = $this->giveaways->find( $giveaway_id );

		if ( null === $giveaway || Giveaway::SHOP !== $giveaway->funding ) {
			return;
		}

		if ( $this->giveaways->move( $giveaway->id, Giveaway::AWAITING_PAYMENT, Giveaway::READY, array( 'order_id' => $order_id ) ) ) {
			$fresh = $this->giveaways->find( $giveaway->id );

			if ( null !== $fresh ) {
				$this->activate( $fresh );
			}
		}
	}

	/**
	 * The shop order of a giveaway was cancelled or refunded: the giveaway
	 * is cancelled unless its gift was drawn or redeemed.
	 *
	 * @param int $giveaway_id Giveaway.
	 * @param int $order_id    The order.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function order_cancelled( int $giveaway_id, int $order_id ): void {
		$giveaway = $this->giveaways->find( $giveaway_id );

		if ( null === $giveaway || Giveaway::SHOP !== $giveaway->funding || ( null !== $giveaway->order_id && $order_id !== $giveaway->order_id ) ) {
			return;
		}

		$from = $giveaway->status;

		if ( Giveaway::READY === $from && ! $this->stop_gift( $giveaway ) ) {
			$this->logger->warning( 'A giveaway whose order was cancelled stays, as its gift was drawn or redeemed.', array( 'giveaway_id' => $giveaway->id ) );

			return;
		}

		if ( in_array( $from, array( Giveaway::AWAITING_PAYMENT, Giveaway::READY ), true ) && $this->giveaways->move( $giveaway->id, $from, Giveaway::CANCELLED ) ) {
			$this->audit->record_system(
				AuditAction::GiveawayCancelled,
				$giveaway->creator_id,
				array(
					'giveaway_id' => $giveaway->id,
					'reason'      => 'order',
				)
			);
		}
	}

	/**
	 * `merchboard_payout_held`: the cost of the ready giveaways from the
	 * balance is set aside.
	 *
	 * @param mixed $held       Units per currency so far.
	 * @param mixed $creator_id Creator.
	 * @return array<string, int>
	 * @throws DatabaseException When it cannot be read (the balance is then unknown, never too high).
	 */
	public function held( mixed $held, mixed $creator_id ): array {
		$held = is_array( $held ) ? $held : array();

		if ( ! is_int( $creator_id ) ) {
			return $held;
		}

		foreach ( $this->giveaways->held( $creator_id ) as $currency => $units ) {
			$held[ $currency ] = SalesAmount::add( is_int( $held[ $currency ] ?? null ) ? $held[ $currency ] : 0, $units );
		}

		return $held;
	}

	/**
	 * `merchboard_gift_code_used`: a giveaway's code was used in a paid order;
	 * a job charges it.
	 *
	 * @param mixed $gift     The gift.
	 * @param mixed $order_id The order.
	 */
	public function used( mixed $gift, mixed $order_id ): void {
		if ( ! $gift instanceof Gift || ! $gift->giveaway() || ! is_int( $order_id ) ) {
			return;
		}

		if ( ! ( $this->enqueue )( self::CHARGE_HOOK, array( (int) $gift->giveaway_id, $order_id ), 0 ) ) {
			$this->logger->error( 'The charge of a giveaway could not be queued.', array( 'giveaway_id' => (int) $gift->giveaway_id ) );
		}
	}

	/**
	 * Job: charges a giveaway whose code was used. One from the balance gets
	 * its negative payout adjustment (once, under the creator's payout lock);
	 * one paid in the shop is only marked used.
	 *
	 * @param mixed $giveaway_id Giveaway.
	 * @param mixed $order_id    The order the code was used in.
	 */
	public function charge( mixed $giveaway_id, mixed $order_id = 0 ): void {
		$id       = is_numeric( $giveaway_id ) ? (int) $giveaway_id : 0;
		$order_id = is_numeric( $order_id ) ? (int) $order_id : 0;

		try {
			$giveaway = $id > 0 ? $this->giveaways->find( $id ) : null;

			// An expired code used in an order paid later still costs the giveaway.
			if ( null === $giveaway || ! in_array( $giveaway->status, array( Giveaway::READY, Giveaway::EXPIRED ), true ) ) {
				return;
			}

			if ( Giveaway::SHOP === $giveaway->funding ) {
				if ( $this->giveaways->move( $giveaway->id, $giveaway->status, Giveaway::USED ) ) {
					$this->notify( 'giveaway_used', $giveaway );
				}

				return;
			}

			$charged = $this->payouts->with_creator_lock(
				$giveaway->creator_id,
				fn(): bool => (bool) $this->transaction->run(
					function () use ( $giveaway, $order_id ): bool {
						$current = $this->giveaways->find( $giveaway->id );

						if ( null === $current || null !== $current->adjustment_id || ! in_array( $current->status, array( Giveaway::READY, Giveaway::EXPIRED ), true ) ) {
							return false;
						}

						$connection = $this->payouts->lock_connection( $current->creator_id );
						$adjustment = $this->adjustments->insert_giveaway( $current->creator_id, $current->currency, -SalesAmount::parse( $current->cost ), $current->id, ( $this->clock )(), $connection );

						if ( ! $this->giveaways->move( $current->id, $current->status, Giveaway::USED, array( 'adjustment_id' => $adjustment ) ) ) {
							throw new DatabaseException( 'The giveaway changed while it was charged.', array( 'giveaway_id' => $current->id ) );
						}

						$this->audit->record_system(
							AuditAction::GiveawayCharged,
							$current->creator_id,
							array(
								'giveaway_id'   => $current->id,
								'adjustment_id' => $adjustment,
								'order_id'      => $order_id,
								'amount'        => $current->cost,
								'currency'      => $current->currency,
							)
						);

						return true;
					}
				)
			);

			if ( $charged ) {
				$this->notify( 'giveaway_used', $giveaway );
			}
		} catch ( PayoutException | DatabaseException $e ) {
			// A busy lock or a failed write: try again in a minute (the charge happens once).
			$this->logger->error( 'A giveaway could not be charged; it is tried again.', array( 'giveaway_id' => $id ) + ( $e instanceof DatabaseException ? $e->context() : array() ) );
			( $this->enqueue )( self::CHARGE_HOOK, array( $id, $order_id ), MINUTE_IN_SECONDS );
		}
	}

	/**
	 * `merchboard_gift_event`: a giveaway's link ended unused, the creator got
	 * a link after the last draw, or the code was redeemed (it runs out after
	 * 30 days).
	 *
	 * @param mixed $event Event.
	 * @param mixed $gift  The gift.
	 * @param mixed $data  Event data.
	 */
	public function gift_event( mixed $event, mixed $gift, mixed $data = array() ): void {
		unset( $data );

		if ( ! $gift instanceof Gift || ! $gift->giveaway() ) {
			return;
		}

		try {
			$giveaway = $this->giveaways->find( (int) $gift->giveaway_id );

			if ( null === $giveaway ) {
				return;
			}

			if ( 'giveaway_expired' === $event ) {
				if ( $this->giveaways->move( $giveaway->id, Giveaway::READY, Giveaway::EXPIRED ) ) {
					$this->notify( 'giveaway_expired', $giveaway );
				}
			} elseif ( 'giveaway_link' === $event ) {
				$this->notify( 'giveaway_link', $giveaway );
			} elseif ( 'claimed' === $event ) {
				( $this->enqueue )( self::EXPIRE_HOOK, array( $giveaway->id ), GiftCodes::GIVEAWAY_DAYS * DAY_IN_SECONDS + MINUTE_IN_SECONDS );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A giveaway could not follow its gift.', $e->context() );
		}
	}

	/**
	 * Job: a redeemed code that was not used has run out; the money is no
	 * longer set aside. (A used code has made the giveaway `used`; one used in
	 * an order paid later is charged all the same, see charge().)
	 *
	 * @param mixed $giveaway_id Giveaway.
	 */
	public function expire( mixed $giveaway_id ): void {
		try {
			$giveaway = is_numeric( $giveaway_id ) ? $this->giveaways->find( (int) $giveaway_id ) : null;
			$gift     = null === $giveaway ? null : $this->gifts->find_by_giveaway( $giveaway->id );

			if ( null === $giveaway || null === $gift || Giveaway::READY !== $giveaway->status || Gift::CLAIMED !== $gift->status ) {
				return;
			}

			if ( $this->giveaways->move( $giveaway->id, Giveaway::READY, Giveaway::EXPIRED ) ) {
				$this->notify( 'giveaway_expired', $giveaway );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A giveaway could not expire.', $e->context() );
		}
	}

	/**
	 * A creator's latest giveaways with their gifts.
	 *
	 * @param int $creator_id Creator.
	 * @return list<array{giveaway: Giveaway, gift: Gift|null}>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function latest( int $creator_id ): array {
		$rows = array();

		foreach ( $this->giveaways->latest( $creator_id ) as $giveaway ) {
			// A ready giveaway whose gift could not be stored gets it now.
			$giveaway = $this->activate( $giveaway );
			$rows[]   = array(
				'giveaway' => $giveaway,
				'gift'     => null === $giveaway->gift_id ? null : $this->gifts->find( $giveaway->gift_id ),
			);
		}

		return $rows;
	}

	/**
	 * Stops a giveaway's gift before anybody can get it: one waiting for a
	 * draw, or an open link nobody redeemed.
	 *
	 * @param Giveaway $giveaway Giveaway.
	 * @return bool Whether it is stopped (or there is none).
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function stop_gift( Giveaway $giveaway ): bool {
		$gift = $this->gifts->find_by_giveaway( $giveaway->id );

		if ( null === $gift || Gift::CANCELLED === $gift->status ) {
			return true;
		}

		if ( Gift::WAITING === $gift->status ) {
			return $this->gifts->cancel( $gift->id );
		}

		return $gift->open_link() && $this->gifts->expire_link( $gift->id );
	}

	/**
	 * Refuses an action.
	 *
	 * @param string $code GiveawayException code.
	 * @throws GiveawayException Always.
	 */
	private static function refuse( string $code ): never {
		throw new GiveawayException( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A fixed code, never shown as HTML.
	}

	/**
	 * The creator's bell (and e-mail) about a giveaway.
	 *
	 * @param string   $type     Notification type.
	 * @param Giveaway $giveaway Giveaway.
	 */
	private function notify( string $type, Giveaway $giveaway ): void {
		do_action(
			'merchboard_notify',
			$type,
			$giveaway->creator_id,
			array(
				'product' => ( $this->product_name )( $giveaway->product_id ),
				'amount'  => $giveaway->cost,
			),
			'giveaway:' . $giveaway->id . ':' . $type
		);
	}
}
