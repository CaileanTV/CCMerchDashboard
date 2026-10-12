<?php
/**
 * One stored payout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_payouts` (ADR-055). The amount is in units (1/10 000)
 * with at most the store's price decimals at request time. Only a masked
 * hint of the destination is part of it; the full details are encrypted in
 * `merchboard_payout_secrets` (ADR-056).
 */
final readonly class Payout {

	/**
	 * Constructor.
	 *
	 * @param int                    $id                    Payout id.
	 * @param int                    $creator_id            Creator.
	 * @param string                 $currency              Currency.
	 * @param int                    $amount_units          Amount in units.
	 * @param int                    $price_decimals        Store price decimals at request time.
	 * @param PayoutMethod           $method                Method.
	 * @param PayoutStatus           $status                Status.
	 * @param string                 $destination_hint      Masked destination.
	 * @param string                 $idempotency_key       The creator's request key.
	 * @param int|null               $requested_by          WordPress user who requested it.
	 * @param DateTimeImmutable      $requested_at          Request time (UTC).
	 * @param int|null               $decided_by            User who completed, rejected or cancelled it.
	 * @param DateTimeImmutable|null $decided_at            Time of that (UTC).
	 * @param string|null            $decision_reason       Reason of a rejection.
	 * @param string|null            $reference             Bank or PayPal reference of a completion.
	 * @param string|null            $gift_card_provider    Provider id of a registered gift card (ADR-057).
	 * @param string|null            $gift_card_external_id The card's id in the provider (never its code).
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public string $currency,
		public int $amount_units,
		public int $price_decimals,
		public PayoutMethod $method,
		public PayoutStatus $status,
		public string $destination_hint,
		public string $idempotency_key,
		public ?int $requested_by,
		public DateTimeImmutable $requested_at,
		public ?int $decided_by,
		public ?DateTimeImmutable $decided_at,
		public ?string $decision_reason,
		public ?string $reference,
		public ?string $gift_card_provider = null,
		public ?string $gift_card_external_id = null
	) {
	}
}
