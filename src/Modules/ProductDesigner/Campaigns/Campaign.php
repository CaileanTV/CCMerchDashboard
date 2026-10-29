<?php
/**
 * One of the company's campaigns.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Campaigns;

use DateTimeImmutable;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A campaign of the company (ADR-080 pkt. 9): in [starts_at, ends_at) a
 * designer line is covered when the order used one of the campaign's coupon
 * codes, or, with `sale_prices`, when the line was sold at a sale price. In a
 * covered line the discount is shared between the company and the creator
 * in proportion to base price and profit; outside campaigns the company
 * keeps the base price. Times are UTC; codes are normalized (CouponCode).
 */
final readonly class Campaign {

	/**
	 * Most coupon codes per campaign.
	 */
	public const MAX_CODES = 100;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<string> $coupon_codes
	 *
	 * @param int|null          $id           Id; null before insert.
	 * @param string            $name         Name (shown to administrators only).
	 * @param DateTimeImmutable $starts_at    Start (inclusive, UTC).
	 * @param DateTimeImmutable $ends_at      End (exclusive, UTC).
	 * @param string[]          $coupon_codes Normalized coupon codes, unique.
	 * @param bool              $sale_prices  Whether lines sold at a sale price are covered.
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	public function __construct(
		public ?int $id,
		public string $name,
		public DateTimeImmutable $starts_at,
		public DateTimeImmutable $ends_at,
		public array $coupon_codes,
		public bool $sale_prices
	) {
		if ( ( null !== $id && $id < 1 ) || '' === $name || mb_strlen( $name ) > 191 || $starts_at >= $ends_at ) {
			throw new InvalidArgumentException( 'Invalid campaign.' );
		}

		if ( count( $coupon_codes ) > self::MAX_CODES || count( $coupon_codes ) !== count( array_unique( $coupon_codes ) ) || in_array( '', $coupon_codes, true ) ) {
			throw new InvalidArgumentException( 'Invalid campaign coupon codes.' );
		}

		if ( array() === $coupon_codes && ! $sale_prices ) {
			throw new InvalidArgumentException( 'A campaign covers coupon codes, sale prices or both.' );
		}
	}

	/**
	 * Whether the campaign runs at a time.
	 *
	 * @param DateTimeImmutable $at Time.
	 */
	public function runs_at( DateTimeImmutable $at ): bool {
		return $this->starts_at <= $at && $at < $this->ends_at;
	}

	/**
	 * Whether the campaign covers a line bought at a time.
	 *
	 * @phpstan-param list<string> $order_codes
	 *
	 * @param DateTimeImmutable $at          When the line was bought (its snapshot).
	 * @param string[]          $order_codes The order's normalized coupon codes.
	 * @param bool              $on_sale     Whether the line was sold at a sale price.
	 */
	public function covers( DateTimeImmutable $at, array $order_codes, bool $on_sale ): bool {
		if ( ! $this->runs_at( $at ) ) {
			return false;
		}

		return ( $this->sale_prices && $on_sale ) || array() !== array_intersect( $this->coupon_codes, $order_codes );
	}
}
