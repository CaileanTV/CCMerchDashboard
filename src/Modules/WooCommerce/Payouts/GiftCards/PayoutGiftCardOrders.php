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
 * Which orders were paid (wholly or partly) with a gift card that Merch
 * Dashboard registered on a completed payout, and whether a coupon code is
 * such a card (report 16.3, G1). Read only; nothing is stored.
 *
 * Only the gift card plugin's own registration counts (the adapter's
 * orders_paid_with()); no amounts, times or coupon texts are compared. When
 * a registration cannot be read (plugin unavailable, card gone, data not
 * exact), the answer is "unknown", never "not paid with a payout card".
 *
 * The commission basis of these orders is not decided (G1, R1–R4): the
 * ledger, the balances and the settlement documents treat them like every
 * other order. This class only makes them visible.
 *
 * A code is looked up only to answer the question and is never stored or
 * logged.
 */
final class PayoutGiftCardOrders {

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
	 * Whether a code is a card registered on a completed payout. A card that
	 * cannot be read exactly counts as one (fail closed): the caller refuses
	 * the code rather than let an unknown card through.
	 *
	 * @param string $code Coupon code as entered.
	 * @throws DatabaseException When the payouts cannot be read.
	 */
	public function is_payout_card( string $code ): bool {
		if ( '' === trim( $code ) ) {
			return false;
		}

		foreach ( $this->providers->all() as $provider ) {
			if ( ! $provider->availability()->available ) {
				continue;
			}

			try {
				$card = $provider->find_by_code( $code );
			} catch ( GiftCardProviderException $e ) {
				$this->logger->warning(
					'A coupon code could not be checked against the payout gift cards.',
					array(
						'provider' => $provider->id(),
						'reason'   => $e->reason(),
					)
				);

				return true;
			}

			if ( null !== $card && null !== $this->payouts->find_by_gift_card( $provider->id(), $card->external_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The payouts whose card paid each of the orders.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int[] $order_ids Orders.
	 * @return array{paid: array<int, list<int>>, unknown: bool} Payout ids by order; unknown when a registration could not be read.
	 * @throws DatabaseException When the payouts cannot be read.
	 */
	public function for_orders( array $order_ids ): array {
		$wanted  = array_flip( $order_ids );
		$paid    = array();
		$unknown = false;

		foreach ( $this->registrations() as $card ) {
			if ( null === $card['orders'] ) {
				$unknown = true;
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
			'paid'    => array_map( static fn( array $ids ): array => array_values( array_unique( $ids ) ), $paid ),
			'unknown' => $unknown,
		);
	}

	/**
	 * The orders paid with the card of one completed payout; null when they
	 * cannot be read or the payout has no registered card.
	 *
	 * @param int $payout_id Payout.
	 * @return list<int>|null
	 * @throws DatabaseException When the payouts cannot be read.
	 */
	public function for_payout( int $payout_id ): ?array {
		foreach ( $this->registrations() as $card ) {
			if ( $card['payout_id'] === $payout_id ) {
				return $card['orders'];
			}
		}

		return null;
	}

	/**
	 * Every registered card with its orders (null = unknown), read once per
	 * provider.
	 *
	 * @return list<array{payout_id: int, orders: list<int>|null}>
	 * @throws DatabaseException When the payouts cannot be read.
	 */
	private function registrations(): array {
		$cards = $this->payouts->registered_gift_cards();
		$read  = array();

		foreach ( array_unique( array_column( $cards, 'provider' ) ) as $provider_id ) {
			$provider = $this->providers->by_id( $provider_id );

			if ( null === $provider || ! $provider->availability()->available ) {
				continue;
			}

			$ids = array_column( array_filter( $cards, static fn( array $card ): bool => $card['provider'] === $provider_id ), 'external_id' );

			try {
				$read[ $provider_id ] = $provider->orders_paid_with( $ids );
			} catch ( GiftCardProviderException $e ) {
				$this->logger->warning(
					'The orders paid with payout gift cards could not be read.',
					array(
						'provider' => $provider_id,
						'reason'   => $e->reason(),
					)
				);
			}
		}

		return array_map(
			static fn( array $card ): array => array(
				'payout_id' => $card['payout_id'],
				'orders'    => $read[ $card['provider'] ][ $card['external_id'] ] ?? null,
			),
			$cards
		);
	}
}
