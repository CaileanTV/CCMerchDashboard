<?php
/**
 * One order line of a creator whose payout is unknown, and what filling in
 * its pricing snapshot would do.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A line of SalesLinePricingBackfill's plan (ADR-090). The outcome is
 * VAT (the snapshot has a share but no VAT status: only the status is
 * filled in), SNAPSHOT (no snapshot: one is made with the share that applies
 * now), WITHDRAWN (a snapshot of ADR-047's withdrawn model without a share:
 * it is replaced) or SKIP with the reason nothing is done.
 */
final readonly class PricingBackfillLine {

	public const VAT = 'vat';

	public const SNAPSHOT = 'snapshot';

	public const WITHDRAWN = 'withdrawn';

	public const SKIP = 'skip';

	/**
	 * Why a line is skipped.
	 */
	public const SETTLED = 'settled';

	public const NO_TAX = 'no_tax';

	public const DESIGNER = 'designer';

	public const MISMATCH = 'mismatch';

	public const NO_MARKUP = 'no_markup';

	public const UNREADABLE = 'unreadable';

	public const OTHER = 'other';

	/**
	 * Constructor.
	 *
	 * @param int                    $order_item_id Order item.
	 * @param int                    $order_id      Order.
	 * @param int                    $product_id    Parent product.
	 * @param int                    $variation_id  Variation, 0 for a simple product.
	 * @param string                 $item_name     Order line name.
	 * @param string                 $currency      Currency of the line's ledger rows.
	 * @param DateTimeImmutable|null $sold_at       When it was sold (UTC); null when no sale row exists.
	 * @param int                    $net_units     The sale's amount excl. VAT.
	 * @param int                    $tax_units     The sale's VAT.
	 * @param string                 $outcome       VAT, SNAPSHOT, WITHDRAWN or SKIP.
	 * @param string|null            $reason        Why it is skipped; null otherwise.
	 * @param int|null               $share_bps     The share the snapshot has or gets.
	 * @param MarkupSource|null      $share_source  Where the share comes from.
	 * @param int|null               $price_decimals Store price decimals of the snapshot.
	 * @param bool|null              $history_vat   The creator's VAT status by the history on the sale date; null when none is registered.
	 */
	public function __construct(
		public int $order_item_id,
		public int $order_id,
		public int $product_id,
		public int $variation_id,
		public string $item_name,
		public string $currency,
		public ?DateTimeImmutable $sold_at,
		public int $net_units,
		public int $tax_units,
		public string $outcome,
		public ?string $reason = null,
		public ?int $share_bps = null,
		public ?MarkupSource $share_source = null,
		public ?int $price_decimals = null,
		public ?bool $history_vat = null
	) {
	}

	/**
	 * Whether filling in changes this line.
	 *
	 * @phpstan-assert-if-true !null $this->share_bps
	 * @phpstan-assert-if-true !null $this->share_source
	 * @phpstan-assert-if-true !null $this->price_decimals
	 */
	public function acts(): bool {
		return self::SKIP !== $this->outcome && null !== $this->share_bps && null !== $this->share_source && null !== $this->price_decimals;
	}

	/**
	 * The VAT status filled in: the chosen one, else the history's, else not
	 * VAT registered (the owner's choice 2026-11-04).
	 *
	 * @param bool|null $chosen The administrator's choice for every line; null: by the history.
	 */
	public function vat( ?bool $chosen ): bool {
		return $chosen ?? $this->history_vat ?? false;
	}

	/**
	 * The sale's payout after filling in, or null when the line is skipped.
	 *
	 * @param bool|null $chosen The administrator's choice for every line; null: by the history.
	 */
	public function payout_units( ?bool $chosen ): ?int {
		return $this->acts() ? CreatorShare::payout( $this->net_units, $this->tax_units, $this->share_bps, $this->vat( $chosen ), $this->price_decimals ) : null;
	}
}
