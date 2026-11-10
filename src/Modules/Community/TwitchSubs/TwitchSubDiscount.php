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
 * ADR-106 pkt. 4–5: a line qualifies when an active campaign of its parent
 * product's creator covers the product (a selection before "all"), the
 * creator's Twitch channel is connected, the customer is logged in with
 * Twitch and has a tier on that channel that the campaign gives a percent
 * (gifted subscriptions as the creator chose), and the creator's own
 * creator code is not in the cart (ADR-068: those lines are excluded).
 *
 * The customer's subscription is asked once per creator in a request, and
 * Twitch's answers are kept for 10 minutes in the customer's session. When
 * Twitch cannot be reached, the creator's lines get no discount (and the
 * answer "none" is kept, so the cart does not ask again on every page).
 * Anything that cannot be read gives no discount (fails closed).
 */
final class TwitchSubDiscount {

	/**
	 * Subscriptions asked in this request, by creator (null: none).
	 *
	 * @var array<int, array{tier: int, gifted: bool}|null>
	 */
	private array $subs = array();

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
	 * Today in the site's time zone (Y-m-d).
	 *
	 * @var Closure(): string
	 */
	private Closure $today;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?int $creator_of
	 * @phpstan-param Closure(int): ?string $broadcaster_of
	 * @phpstan-param Closure(): list<int> $coded_creators
	 * @phpstan-param (Closure(): string)|null $today
	 *
	 * @param TwitchSubDiscountRepository $settings       The creators' settings.
	 * @param TwitchCustomerLogin         $login          The customer's Twitch login.
	 * @param Closure                     $creator_of     The creator of a parent product, or null.
	 * @param Closure                     $broadcaster_of A creator's connected Twitch channel id, or null.
	 * @param Closure                     $coded_creators The creators whose own code is in the cart.
	 * @param Logger                      $logger         Technical log.
	 * @param Closure|null                $today          Today (Y-m-d) in the site's time zone (default: wp_date()).
	 */
	public function __construct( private TwitchSubDiscountRepository $settings, private TwitchCustomerLogin $login, Closure $creator_of, Closure $broadcaster_of, Closure $coded_creators, private Logger $logger, ?Closure $today = null ) {
		$this->creator_of     = $creator_of;
		$this->broadcaster_of = $broadcaster_of;
		$this->coded_creators = $coded_creators;
		$this->today          = $today ?? static fn(): string => (string) wp_date( 'Y-m-d' );
	}

	/**
	 * The creator and the campaign offered on a parent product today
	 * (whatever the customer's subscription), or null.
	 *
	 * @param int $product_id Parent product.
	 * @return array{creator_id: int, campaign: TwitchSubCampaign}|null
	 * @throws DatabaseException When the settings cannot be read.
	 */
	public function offer( int $product_id ): ?array {
		$creator_id = ( $this->creator_of )( $product_id );

		if ( null === $creator_id || null === ( $this->broadcaster_of )( $creator_id ) ) {
			return null;
		}

		$campaign = $this->settings->find( $creator_id )->campaign_for( $product_id, ( $this->today )() );

		return null === $campaign ? null : array(
			'creator_id' => $creator_id,
			'campaign'   => $campaign,
		);
	}

	/**
	 * The discount of a parent product in the cart: its creator, campaign,
	 * the customer's tier and the basis points; null when it gets none.
	 *
	 * @param int $product_id Parent product.
	 * @return array{creator_id: int, campaign_id: string, tier: int, bps: int, gifted: bool}|null
	 */
	public function rate( int $product_id ): ?array {
		try {
			$offer = $this->offer( $product_id );
			$coded = null === $offer ? array() : ( $this->coded_creators )();
		} catch ( DatabaseException $e ) {
			// Fails closed: no discount when it cannot be checked.
			$this->logger->error( 'The Twitch subscriber discount could not be checked; it does not apply.', $e->context() );

			return null;
		}

		if ( null === $offer || in_array( $offer['creator_id'], $coded, true ) ) {
			return null;
		}

		$sub = $this->subscription( $offer['creator_id'], false );

		if ( null === $sub || ! $this->settings->find( $offer['creator_id'] )->counts( $sub['gifted'] ) ) {
			return null;
		}

		$bps = $offer['campaign']->bps( $sub['tier'] );

		return $bps > 0 ? array(
			'creator_id'  => $offer['creator_id'],
			'campaign_id' => $offer['campaign']->id,
			'tier'        => $sub['tier'],
			'bps'         => $bps,
			'gifted'      => $sub['gifted'],
		) : null;
	}

	/**
	 * The highest percent offered on a set of parent products today (for the
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
				$offer = $this->offer( $product_id );

				if ( null !== $offer ) {
					$highest = max( $highest, intdiv( max( $offer['campaign']->tiers ), 100 ) );
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
	 * creator whose campaigns cover the given products, and tells whether
	 * any line's rate differs from the one the cart was priced with.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[] $product_ids Parent products in the cart.
	 */
	public function recheck( array $product_ids ): bool {
		$before = array();

		foreach ( $product_ids as $product_id ) {
			$before[ $product_id ] = $this->rate( $product_id )['bps'] ?? 0;
		}

		try {
			$creators = array();

			foreach ( $product_ids as $product_id ) {
				$offer = $this->offer( $product_id );

				if ( null !== $offer ) {
					$creators[ $offer['creator_id'] ] = $offer['creator_id'];
				}
			}
		} catch ( DatabaseException $e ) {
			// The discount cannot be checked: it changes (to none; rate() fails closed too).
			$this->logger->error( 'The Twitch subscriber discount could not be checked before the order.', $e->context() );

			return true;
		}

		foreach ( $creators as $creator_id ) {
			unset( $this->subs[ $creator_id ] );
			$this->subscription( $creator_id, true );
		}

		foreach ( $product_ids as $product_id ) {
			if ( ( $this->rate( $product_id )['bps'] ?? 0 ) !== $before[ $product_id ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The customer's subscription to a creator's channel, or null.
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $fresh      Whether to ask Twitch again.
	 * @return array{tier: int, gifted: bool}|null
	 */
	private function subscription( int $creator_id, bool $fresh ): ?array {
		if ( array_key_exists( $creator_id, $this->subs ) ) {
			return $this->subs[ $creator_id ];
		}

		$broadcaster = ( $this->broadcaster_of )( $creator_id );
		$sub         = null;

		if ( null !== $broadcaster ) {
			try {
				$sub = $this->login->subscription( $broadcaster, $fresh );
			} catch ( TwitchException $e ) {
				$this->logger->warning( 'Twitch could not be asked about a subscription; the discount does not apply.', array( 'reason' => $e->reason() ) );
				$this->login->forget_subscription( $broadcaster );
			}
		}

		$this->subs[ $creator_id ] = null !== $sub && $sub['tier'] > 0 ? $sub : null;

		return $this->subs[ $creator_id ];
	}
}
