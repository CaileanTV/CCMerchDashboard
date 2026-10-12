<?php
/**
 * Facts about one gift card, without its code.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * What an adapter read about a card (ADR-057). Amounts are units (1/10 000)
 * read exactly from the plugin; a card whose amount or balance cannot be
 * read exactly is never a snapshot (GiftCardProviderException
 * `invalid_data`), so a snapshot never carries a guessed 0. The code is
 * deliberately not part of it.
 */
final readonly class GiftCardSnapshot {

	/**
	 * Constructor.
	 *
	 * @param string                 $external_id     The card's id in the plugin.
	 * @param int                    $amount_units    Original amount in units.
	 * @param int                    $balance_units   Current balance in units.
	 * @param string                 $currency        Currency.
	 * @param GiftCardStatus         $status          Status.
	 * @param DateTimeImmutable|null $expires_at      Expiry (UTC), null for none.
	 * @param bool                   $can_reveal_code Whether reveal_code() can return the code.
	 */
	public function __construct(
		public string $external_id,
		public int $amount_units,
		public int $balance_units,
		public string $currency,
		public GiftCardStatus $status,
		public ?DateTimeImmutable $expires_at,
		public bool $can_reveal_code
	) {
	}
}
