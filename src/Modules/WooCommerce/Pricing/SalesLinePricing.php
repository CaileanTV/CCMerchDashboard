<?php
/**
 * Immutable creator share snapshot of an order line.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_sales_line_pricing` (ADR-047, ADR-049). Captured once,
 * when WooCommerce creates the order line, and never changed: the owner, the
 * creator share that applied then (percentage and source), the store's
 * price decimals and the owner's VAT status. Later changes of the markup,
 * the product's share, the VAT status or the WooCommerce price never reach
 * it. Only ids, no foreign keys, so it outlives the order, the product and
 * the rules.
 *
 * The share (`share_bps`, `share_source`, `price_decimals`) is all known or
 * all unknown. It is unknown for rows written before schema version 8
 * (status `priced`/`unpriced`, ADR-047's withdrawn model) and is never
 * filled in afterwards. `vat_registered` is null for rows from before
 * schema version 7.
 *
 * Deliberately not stored: the quantity and the realized profit and payout.
 * Those come from the sales ledger row (RealizedProfit, RealizedPayout).
 *
 * A designer product (status `designer`, ADR-080 pkt. 8) also holds its base
 * price, the creator's profit and the list price per unit (incl. VAT), and
 * its effective share is profit ÷ list price (source `designer`, shown on
 * settlement documents; the amounts come from DesignerRowAmounts).
 */
final class SalesLinePricing {

	/**
	 * Constructor.
	 *
	 * @param int               $order_item_id  WooCommerce order item.
	 * @param int               $order_id       Order.
	 * @param int               $creator_id     Owner of the product when the line was created.
	 * @param int               $product_id     Parent product.
	 * @param int               $variation_id   Variation, 0 for a simple product.
	 * @param SnapshotStatus    $status         `share`, or a legacy status.
	 * @param string            $currency       Order currency.
	 * @param DateTimeImmutable $captured_at    Capture time (UTC).
	 * @param int|null          $share_bps      Creator share of the sale price in basis points; null when unknown.
	 * @param MarkupSource|null $share_source   Where the share came from; null when unknown.
	 * @param int|null          $price_decimals Store price decimals at capture time (0–4); null when unknown.
	 * @param bool|null         $vat_registered The owner's VAT status at capture time; null when unknown.
	 * @param int|null          $base_units     Designer product: base price per unit incl. VAT; else null.
	 * @param int|null          $profit_units   Designer product: the creator's profit per unit incl. VAT; else null.
	 * @param int|null          $price_units    Designer product: list price per unit (base + profit); else null.
	 * @throws InvalidArgumentException When the snapshot is inconsistent.
	 */
	public function __construct(
		public readonly int $order_item_id,
		public readonly int $order_id,
		public readonly int $creator_id,
		public readonly int $product_id,
		public readonly int $variation_id,
		public readonly SnapshotStatus $status,
		public readonly string $currency,
		public readonly DateTimeImmutable $captured_at,
		public readonly ?int $share_bps = null,
		public readonly ?MarkupSource $share_source = null,
		public readonly ?int $price_decimals = null,
		public readonly ?bool $vat_registered = null,
		public readonly ?int $base_units = null,
		public readonly ?int $profit_units = null,
		public readonly ?int $price_units = null
	) {
		if ( $order_item_id < 1 || $order_id < 1 || $creator_id < 1 || $product_id < 1 || $variation_id < 0 ) {
			throw new InvalidArgumentException( 'Invalid snapshot reference.' );
		}

		Currency::assert_valid( $currency );

		$known = array( null !== $share_bps, null !== $share_source, null !== $price_decimals );

		if ( count( array_unique( $known ) ) !== 1 ) {
			throw new InvalidArgumentException( 'The share is either fully known or unknown.' );
		}

		$designer = SnapshotStatus::Designer === $status;

		if ( ( SnapshotStatus::Share === $status || $designer ) !== $known[0] ) {
			throw new InvalidArgumentException( 'Exactly a share or designer snapshot holds a share.' );
		}

		if ( ( MarkupSource::Designer === $share_source ) !== $designer ) {
			throw new InvalidArgumentException( 'Exactly a designer snapshot has the designer source.' );
		}

		$units = array( null !== $base_units, null !== $profit_units, null !== $price_units );

		if ( array( $designer, $designer, $designer ) !== $units ) {
			throw new InvalidArgumentException( 'Exactly a designer snapshot holds base price, profit and list price.' );
		}

		if ( $designer && ( $base_units < 0 || $profit_units < 0 || $price_units < 1 || $base_units + $profit_units !== $price_units ) ) {
			throw new InvalidArgumentException( 'The list price is base price + profit, never below the base price.' );
		}

		if ( null !== $share_bps ) {
			new MarkupBps( $share_bps );
		}

		if ( null !== $price_decimals ) {
			PricingMath::step( $price_decimals );
		}
	}

	/**
	 * A snapshot of the share that applies now (ADR-049).
	 *
	 * @param int               $order_item_id  Order item.
	 * @param int               $order_id       Order.
	 * @param int               $product_id     Parent product.
	 * @param int               $variation_id   Variation, 0 for a simple product.
	 * @param string            $currency       Order currency.
	 * @param EffectiveMarkup   $markup         The product's share and owner now.
	 * @param int               $price_decimals Store price decimals now.
	 * @param bool|null         $vat_registered The owner's VAT status now; null when unknown.
	 * @param DateTimeImmutable $captured_at    Capture time (UTC).
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	public static function capture( int $order_item_id, int $order_id, int $product_id, int $variation_id, string $currency, EffectiveMarkup $markup, int $price_decimals, ?bool $vat_registered, DateTimeImmutable $captured_at ): self {
		return new self( $order_item_id, $order_id, $markup->creator_id, $product_id, $variation_id, SnapshotStatus::Share, $currency, $captured_at, $markup->markup->bps, $markup->source, $price_decimals, $vat_registered );
	}

	/**
	 * A snapshot of a designer product's price now (ADR-080 pkt. 8).
	 *
	 * @param int               $order_item_id  Order item.
	 * @param int               $order_id       Order.
	 * @param int               $product_id     Parent product.
	 * @param int               $variation_id   Variation, 0 for a simple product.
	 * @param string            $currency       Order currency.
	 * @param DesignerPrice     $price          Owner, base price and profit now.
	 * @param int               $price_decimals Store price decimals now.
	 * @param bool|null         $vat_registered The owner's VAT status now; null when unknown.
	 * @param DateTimeImmutable $captured_at    Capture time (UTC).
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	public static function capture_designer( int $order_item_id, int $order_id, int $product_id, int $variation_id, string $currency, DesignerPrice $price, int $price_decimals, ?bool $vat_registered, DateTimeImmutable $captured_at ): self {
		$list = $price->price_units();

		return new self( $order_item_id, $order_id, $price->creator_id, $product_id, $variation_id, SnapshotStatus::Designer, $currency, $captured_at, DesignerRowAmounts::share_bps( $price->profit_units, $list ), MarkupSource::Designer, $price_decimals, $vat_registered, $price->base_units, $price->profit_units, $list );
	}

	/**
	 * Whether this is a designer product's snapshot (ADR-080).
	 *
	 * @phpstan-assert-if-true !null $this->base_units
	 * @phpstan-assert-if-true !null $this->profit_units
	 * @phpstan-assert-if-true !null $this->price_units
	 * @phpstan-assert-if-true !null $this->share_bps
	 * @phpstan-assert-if-true !null $this->price_decimals
	 */
	public function is_designer(): bool {
		return SnapshotStatus::Designer === $this->status && null !== $this->base_units && null !== $this->profit_units && null !== $this->price_units && null !== $this->share_bps && null !== $this->price_decimals;
	}

	/**
	 * Whether the share is known (a snapshot of ADR-049).
	 *
	 * @phpstan-assert-if-true !null $this->share_bps
	 * @phpstan-assert-if-true !null $this->share_source
	 * @phpstan-assert-if-true !null $this->price_decimals
	 */
	public function has_share(): bool {
		return null !== $this->share_bps && null !== $this->share_source && null !== $this->price_decimals;
	}
}
