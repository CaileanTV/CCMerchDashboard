<?php
/**
 * Orders paid with payout gift cards (G1).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a coupon code is a gift card that Merch Dashboard registered on a
 * completed payout, and which orders the gift card plugin itself recorded as
 * paid (wholly or partly) with such cards (report 16.3, G1). Read only;
 * nothing is stored.
 *
 * Owner decisions 2026-10-16:
 * - B2: what the plugin recorded is shown as the plugin's record, never as a
 *   confirmed payout status; the G1 assessment stays the authoritative
 *   source, and no payout, commission or other financial decision rests on
 *   this class.
 * - B3: neither the check nor the lookup depends on G1's activation or
 *   cut-off.
 * - B4/B5: the order lookup is the optional GiftCardOrderRegistry; an adapter
 *   without it, or whose lookup is unavailable, is "not supported", never a
 *   fatal error and never "not paid".
 * - The coupon check fails closed: when payout cards are registered and a
 *   card cannot be checked, is_payout_card() throws instead of answering
 *   "no". It does not use the optional lookup, so a plugin that lacks it
 *   neither weakens the check nor disables anything accepted for 23.7.
 *
 * A code is looked up only to answer the question and is never stored or
 * logged.
 */
final class PayoutGiftCardOrders {

	/**
	 * The adapter does not offer the order lookup (or the plugin lacks it).
	 */
	public const UNSUPPORTED = 'unsupported';

	/**
	 * The lookup exists but could not be done now.
	 */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param PayoutRepository  $payouts   The payouts with registered cards.
	 * @param GiftCardProviders $providers The registered adapters.
	 * @param Logger            $logger    Technical log (ids and reason codes only).
	 */
	public function __construct( private PayoutRepository $payouts, private GiftCardProviders $providers, private Logger $logger ) {
	}

	/**
	 * Whether a code is a card registered on a completed payout.
	 *
	 * Only the providers of the registered payout cards are asked; with no
	 * registered payout card no provider is contacted. A provider that cannot
	 * be identified or is not available, or a lookup that fails, makes the
	 * answer impossible: that is an exception, never "no" (fail closed).
	 *
	 * @param string $code Coupon code as entered.
	 * @throws DatabaseException When the payouts cannot be read.
	 * @throws GiftCardProviderException When a card cannot be checked (UNAVAILABLE for an unknown or unavailable provider).
	 */
	public function is_payout_card( string $code ): bool {
		if ( '' === trim( $code ) ) {
			return false;
		}

		$cards = $this->payouts->registered_gift_cards();

		foreach ( array_values( array_unique( array_column( $cards, 'provider' ) ) ) as $provider_id ) {
			$card = $this->available_provider( $provider_id )->find_by_code( $code );

			if ( null !== $card && null !== $this->payouts->find_by_gift_card( $provider_id, $card->external_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The provider of registered payout cards, when it can be asked.
	 *
	 * @param string $provider_id Provider id stored on the payouts.
	 * @throws GiftCardProviderException UNAVAILABLE when it is unknown or not available.
	 */
	private function available_provider( string $provider_id ): GiftCardProvider {
		$provider = $this->providers->by_id( $provider_id );

		if ( null === $provider || ! $provider->availability()->available ) {
			throw new GiftCardProviderException( GiftCardProviderException::UNAVAILABLE );
		}

		return $provider;
	}

	/**
	 * The payouts whose card the plugin recorded on each of the orders.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int[] $order_ids Orders.
	 * @return array{paid: array<int, list<int>>, unsupported: bool, failed: bool} Payout ids by order; whether a card's orders were not supported or could not be checked.
	 * @throws DatabaseException When the payouts cannot be read.
	 */
	public function for_orders( array $order_ids ): array {
		$wanted      = array_flip( $order_ids );
		$paid        = array();
		$unsupported = false;
		$failed      = false;

		foreach ( $this->registrations() as $card ) {
			if ( null === $card['orders'] ) {
				$unsupported = $unsupported || self::UNSUPPORTED === $card['reason'];
				$failed      = $failed || self::FAILED === $card['reason'];
				continue;
			}

			foreach ( $card['orders'] as $order_id ) {
				if ( isset( $wanted[ $order_id ] ) ) {
					$paid[ $order_id ][] = $card['payout_id'];
				}
			}
		}

		ksort( $paid );

		return array(
			'paid'        => array_map( static fn( array $ids ): array => array_values( array_unique( $ids ) ), $paid ),
			'unsupported' => $unsupported,
			'failed'      => $failed,
		);
	}

	/**
	 * The orders the plugin recorded on the card of one completed payout.
	 *
	 * @param int $payout_id Payout.
	 * @return array{orders: list<int>|null, reason: string|null} The orders, or null with UNSUPPORTED or FAILED (also when the payout has no registered card).
	 * @throws DatabaseException When the payouts cannot be read.
	 */
	public function for_payout( int $payout_id ): array {
		foreach ( $this->registrations() as $card ) {
			if ( $card['payout_id'] === $payout_id ) {
				return array(
					'orders' => $card['orders'],
					'reason' => $card['reason'],
				);
			}
		}

		return array(
			'orders' => null,
			'reason' => self::FAILED,
		);
	}

	/**
	 * Every registered card with its orders, read once per provider; null
	 * orders come with the reason (UNSUPPORTED or FAILED).
	 *
	 * @return list<array{payout_id: int, orders: list<int>|null, reason: string|null}>
	 * @throws DatabaseException When the payouts cannot be read.
	 */
	private function registrations(): array {
		$cards  = $this->payouts->registered_gift_cards();
		$read   = array();
		$reason = array();

		foreach ( array_unique( array_column( $cards, 'provider' ) ) as $provider_id ) {
			$provider = $this->providers->by_id( $provider_id );

			if ( null === $provider || ! $provider->availability()->available ) {
				$reason[ $provider_id ] = self::FAILED;
				continue;
			}

			if ( ! $provider instanceof GiftCardOrderRegistry || ! $provider->order_registry_availability()->available ) {
				$reason[ $provider_id ] = self::UNSUPPORTED;
				continue;
			}

			$ids = array_column( array_filter( $cards, static fn( array $card ): bool => $card['provider'] === $provider_id ), 'external_id' );

			try {
				$read[ $provider_id ] = $provider->orders_paid_with( $ids );
			} catch ( GiftCardProviderException $e ) {
				$reason[ $provider_id ] = self::FAILED;
				$this->logger->warning(
					'The orders recorded on payout gift cards could not be read.',
					array(
						'provider' => $provider_id,
						'reason'   => $e->reason(),
					)
				);
			}
		}

		return array_map(
			static function ( array $card ) use ( $read, $reason ): array {
				$orders = $read[ $card['provider'] ][ $card['external_id'] ] ?? null;

				return array(
					'payout_id' => $card['payout_id'],
					'orders'    => $orders,
					'reason'    => null !== $orders ? null : ( $reason[ $card['provider'] ] ?? self::FAILED ),
				);
			},
			$cards
		);
	}
}
