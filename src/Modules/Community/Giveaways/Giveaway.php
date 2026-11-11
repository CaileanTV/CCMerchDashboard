<?php
/**
 * A product a creator gives away.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Giveaways;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `merchboard_giveaways` (ADR-110). The draw or the link is the
 * giveaway's gift (`gift_id`, a row of `merchboard_gifts`). Statuses:
 *
 * - `awaiting_payment`: to be paid in the shop (the balance did not cover it);
 * - `ready`: paid (or reserved in the balance); its gift waits for a draw
 *   or its link can be redeemed;
 * - `used`: the code was used in a paid order (charged from the balance then);
 * - `expired`: the link ran out unused (nothing is charged);
 * - `cancelled`: cancelled before the code was used (nothing is charged).
 */
final readonly class Giveaway {

	public const AWAITING_PAYMENT = 'awaiting_payment';
	public const READY            = 'ready';
	public const USED             = 'used';
	public const EXPIRED          = 'expired';
	public const CANCELLED        = 'cancelled';

	/**
	 * Drawn by the bot in the creator's Twitch chat.
	 */
	public const BOT = 'bot';

	/**
	 * A link the creator passes on (the creator's own giveaway system).
	 */
	public const MANUAL = 'manual';

	/**
	 * Paid from the creator's balance when the code is used.
	 */
	public const BALANCE = 'balance';

	/**
	 * Paid in the shop when it was created.
	 */
	public const SHOP = 'shop';

	/**
	 * Constructor.
	 *
	 * @param int      $id              Id.
	 * @param int      $creator_id      Creator.
	 * @param int      $product_id      Parent product.
	 * @param string   $mode            `bot` or `manual`.
	 * @param string   $funding         `balance` or `shop`.
	 * @param string   $status          Status.
	 * @param string   $unit_price      The price the code covers (the cheapest variant), incl. VAT (decimal).
	 * @param string   $shipping_credit Shipping the code covers (decimal).
	 * @param string   $cost            What the creator pays: the base price plus the shipping (decimal).
	 * @param string   $currency        Currency.
	 * @param int      $window_seconds  The `!enter` window of a bot giveaway.
	 * @param int|null $order_id        The shop order that paid it.
	 * @param int|null $gift_id         Its gift.
	 * @param int|null $adjustment_id   The balance charge.
	 * @param string   $created_at      UTC.
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public int $product_id,
		public string $mode,
		public string $funding,
		public string $status,
		public string $unit_price,
		public string $shipping_credit,
		public string $cost,
		public string $currency,
		public int $window_seconds,
		public ?int $order_id,
		public ?int $gift_id,
		public ?int $adjustment_id,
		public string $created_at
	) {
	}

	/**
	 * Whether the creator still has it going (paid or reserved, not used).
	 */
	public function open(): bool {
		return in_array( $this->status, array( self::AWAITING_PAYMENT, self::READY ), true );
	}

	/**
	 * A giveaway from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function from_row( array $row ): self {
		$id = static fn( mixed $value ): ?int => is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['creator_id'] ?? 0 ),
			(int) ( $row['product_id'] ?? 0 ),
			self::MANUAL === ( $row['mode'] ?? '' ) ? self::MANUAL : self::BOT,
			self::SHOP === ( $row['funding'] ?? '' ) ? self::SHOP : self::BALANCE,
			(string) ( $row['status'] ?? self::CANCELLED ),
			(string) ( $row['unit_price'] ?? '0' ),
			(string) ( $row['shipping_credit'] ?? '0' ),
			(string) ( $row['cost'] ?? '0' ),
			(string) ( $row['currency'] ?? '' ),
			(int) ( $row['window_seconds'] ?? 60 ),
			$id( $row['order_id'] ?? null ),
			$id( $row['gift_id'] ?? null ),
			$id( $row['adjustment_id'] ?? null ),
			(string) ( $row['created_at'] ?? '' )
		);
	}
}
