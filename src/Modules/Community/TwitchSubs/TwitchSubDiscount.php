<?php
/**
 * Which cart lines get the Twitch subscriber discount, and how much.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerLogin;
use MerchBoard\Modules\Alerts\Chatbot\TwitchException;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106 pkt. 4–5: a line qualifies when its parent product's creator has
 * the discount on and covering the product, the creator's Twitch channel is
 * connected, the customer is logged in with Twitch and has a tier on that
 * channel that gives a percent (gifted subscriptions as the creator chose),
 * and the creator's own creator code is not in the cart (ADR-068: those
 * lines are excluded anyway).
 *
 * Answers are kept per creator for the request; Twitch's answers for 10
 * minutes in the customer's session. When Twitch cannot be reached, the
 * creator's lines get no discount (and the answer "none" is kept, so the
 * cart does not ask again on every page).
 */
final class TwitchSubDiscount {

	/**
	 * Rates decided in this request, by creator (null: none).
	 *
	 * @var array<int, array{tier: int, bps: int, gifted: bool}|null>
	 */
	private array $rates = array();

	/**
	 * The creator of a parent product.
	 *
	 * @var Closure(int): ?int
	 */
	private Closure $creator_of;

	/**
	 * A creator's connected Twitch channel id.
	 *
	 * @var Closure(int): ?string
	 */
	private Closure $broadcaster_of;

	/**
	 * The creators whose own creator code is in the cart.
	 *
	 * @var Closure(): list<int>
	 */
	private Closure $coded_creators;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?int $creator_of
	 * @phpstan-param Closure(int): ?string $broadcaster_of
	 * @phpstan-param Closure(): list<int> $coded_creators
	 *
	 * @param TwitchSubDiscountRepository $settings       The creators' settings.
	 * @param TwitchCustomerLogin         $login          The customer's Twitch login.
	 * @param Closure                     $creator_of     The creator of a parent product, or null.
	 * @param Closure                     $broadcaster_of A creator's connected Twitch channel id, or null.
	 * @param Closure                     $coded_creators The creators whose own code is in the cart.
	 * @param Logger                      $logger         Technical log.
	 */
	public function __construct( private TwitchSubDiscountRepository $settings, private TwitchCustomerLogin $login, Closure $creator_of, Closure $broadcaster_of, Closure $coded_creators, private Logger $logger ) {
		$this->creator_of     = $creator_of;
		$this->broadcaster_of = $broadcaster_of;
		$this->coded_creators = $coded_creators;
	}

	/**
	 * The creator of a parent product whose discount is active and covers
	 * it, or null (whatever the customer's subscription).
	 *
	 * @param int $product_id Parent product.
	 */
	public function offering_creator( int $product_id ): ?int {
		$creator_id = ( $this->creator_of )( $product_id );

		if ( null === $creator_id ) {
			return null;
		}

		$settings = $this->settings->find( $creator_id );

		return $settings->active() && $settings->covers( $product_id ) && null !== ( $this->broadcaster_of )( $creator_id ) ? $creator_id : null;
	}

	/**
	 * The discount of a parent product in the cart: its creator, the
	 * customer's tier and the basis points; null when it gets none.
	 *
	 * @param int $product_id Parent product.
	 * @return array{creator_id: int, tier: int, bps: int, gifted: bool}|null
	 */
	public function rate( int $product_id ): ?array {
		try {
			$creator_id = $this->offering_creator( $product_id );
			$coded      = null === $creator_id ? array() : ( $this->coded_creators )();
		} catch ( DatabaseException $e ) {
			// Fails closed: no discount when it cannot be checked.
			$this->logger->error( 'The Twitch subscriber discount could not be checked; it does not apply.', $e->context() );

			return null;
		}

		if ( null === $creator_id || in_array( $creator_id, $coded, true ) ) {
			return null;
		}

		$rate = $this->creator_rate( $creator_id, false );

		return null === $rate ? null : array( 'creator_id' => $creator_id ) + $rate;
	}

	/**
	 * The highest percent offered on a set of parent products (for the
	 * shop's card), 0 when none is offered.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[] $product_ids Parent products.
	 */
	public function highest_offer( array $product_ids ): int {
		$highest = 0;

		try {
			foreach ( $product_ids as $product_id ) {
				$creator_id = $this->offering_creator( $product_id );

				if ( null !== $creator_id ) {
					$highest = max( $highest, $this->settings->find( $creator_id )->highest_percent() );
				}
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The Twitch subscriber discount could not be checked; it is not offered.', $e->context() );

			return 0;
		}

		return $highest;
	}

	/**
	 * The check before the order (pkt. 5): asks Twitch again for every
	 * creator whose discount applies to the given products, and tells
	 * whether any rate differs from the one the cart was priced with.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[] $product_ids Parent products in the cart.
	 */
	public function recheck( array $product_ids ): bool {
		$changed  = false;
		$creators = array();

		try {
			foreach ( $product_ids as $product_id ) {
				$creator_id = $this->offering_creator( $product_id );

				if ( null !== $creator_id ) {
					$creators[ $creator_id ] = $creator_id;
				}
			}
		} catch ( DatabaseException $e ) {
			// The discount cannot be checked: it changes (to none, rate() fails closed too).
			$this->logger->error( 'The Twitch subscriber discount could not be checked before the order.', $e->context() );

			return true;
		}

		foreach ( $creators as $creator_id ) {
			$before = $this->creator_rate( $creator_id, false );
			unset( $this->rates[ $creator_id ] );
			$after = $this->creator_rate( $creator_id, true );

			if ( ( $before['bps'] ?? 0 ) !== ( $after['bps'] ?? 0 ) ) {
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * The customer's rate on a creator's channel, or null.
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $fresh      Whether to ask Twitch again.
	 * @return array{tier: int, bps: int, gifted: bool}|null
	 */
	private function creator_rate( int $creator_id, bool $fresh ): ?array {
		if ( array_key_exists( $creator_id, $this->rates ) ) {
			return $this->rates[ $creator_id ];
		}

		$broadcaster = ( $this->broadcaster_of )( $creator_id );
		$rate        = null;

		if ( null !== $broadcaster ) {
			try {
				$sub = $this->login->subscription( $broadcaster, $fresh );
			} catch ( TwitchException $e ) {
				$this->logger->warning( 'Twitch could not be asked about a subscription; the discount does not apply.', array( 'reason' => $e->reason() ) );
				$this->login->forget_subscription( $broadcaster );
				$sub = null;
			}

			if ( null !== $sub ) {
				$bps  = $this->settings->find( $creator_id )->bps_for( $sub['tier'], $sub['gifted'] );
				$rate = $bps > 0 ? array(
					'tier'   => $sub['tier'],
					'bps'    => $bps,
					'gifted' => $sub['gifted'],
				) : null;
			}
		}

		$this->rates[ $creator_id ] = $rate;

		return $rate;
	}
}
