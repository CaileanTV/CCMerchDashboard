<?php
/**
 * One sales ledger row.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_sales` (docs/DATABASE.md, ADR-037, ADR-038), either
 * to be written (`id` and `created_at` null) or as stored.
 *
 * The constructor enforces the row invariants of the schema, not the ledger
 * rules that decide which rows to write (that is the SalesRecorder):
 *
 * - `event_key` is the event identity; the same key is the same event.
 * - quantity and amount follow the sign of the type (SalesEntryType::sign()).
 * - a `correction` has a reason, other types have none.
 * - `refund_id` is set exactly for refunds and refund-related corrections.
 * - `net_amount` is a decimal string with at most 4 decimals, never a float.
 */
final readonly class SalesEntry {

	/**
	 * Maximum length of `event_key` (VARCHAR(64)).
	 */
	public const EVENT_KEY_MAX_LENGTH = 64;

	/**
	 * Maximum length of `item_name` (VARCHAR(191)); longer names are cut.
	 */
	public const ITEM_NAME_MAX_LENGTH = 191;

	/**
	 * Order line name, cut to ITEM_NAME_MAX_LENGTH characters.
	 *
	 * @var string
	 */
	public string $item_name;

	/**
	 * Constructor.
	 *
	 * @param string                     $event_key         Deterministic event identity.
	 * @param int                        $creator_id        Creator snapshot.
	 * @param int                        $order_id          WooCommerce order.
	 * @param int                        $order_item_id     Original order line.
	 * @param int                        $cycle             Sales cycle of the order (≥ 1).
	 * @param int                        $refund_id         Refund, or 0.
	 * @param int                        $product_id        Parent product.
	 * @param int                        $variation_id      Variation, or 0.
	 * @param string                     $item_name         Order line name snapshot.
	 * @param SalesEntryType             $type              Row type.
	 * @param SalesCorrectionReason|null $correction_reason Reason of a correction, else null.
	 * @param int                        $quantity          Signed quantity.
	 * @param string                     $net_amount        Signed decimal amount, e.g. "-12.5000".
	 * @param string                     $currency          ISO 4217 code of the order.
	 * @param DateTimeImmutable          $occurred_at       When the event happened (stored as UTC).
	 * @param int|null                   $id                Row id; null before insert.
	 * @param DateTimeImmutable|null     $created_at        Insert time (UTC); null before insert.
	 * @throws InvalidArgumentException When a value breaks a row invariant.
	 */
	public function __construct(
		public string $event_key,
		public int $creator_id,
		public int $order_id,
		public int $order_item_id,
		public int $cycle,
		public int $refund_id,
		public int $product_id,
		public int $variation_id,
		string $item_name,
		public SalesEntryType $type,
		public ?SalesCorrectionReason $correction_reason,
		public int $quantity,
		public string $net_amount,
		public string $currency,
		public DateTimeImmutable $occurred_at,
		public ?int $id = null,
		public ?DateTimeImmutable $created_at = null
	) {
		if ( 1 !== preg_match( '/^[a-z0-9:_-]+$/', $event_key ) || strlen( $event_key ) > self::EVENT_KEY_MAX_LENGTH ) {
			throw new InvalidArgumentException( 'Invalid sales event key.' );
		}

		foreach ( array( $creator_id, $order_id, $order_item_id, $cycle, $product_id ) as $positive ) {
			if ( $positive < 1 ) {
				throw new InvalidArgumentException( 'Sales entry ids and cycle must be positive integers.' );
			}
		}

		if ( $refund_id < 0 || $variation_id < 0 || ( null !== $id && $id < 1 ) ) {
			throw new InvalidArgumentException( 'Sales entry ids must not be negative.' );
		}

		if ( ( SalesEntryType::Correction === $type ) !== ( null !== $correction_reason ) ) {
			throw new InvalidArgumentException( 'A correction needs a reason; other sales entries have none.' );
		}

		$concerns_refund = SalesEntryType::Refund === $type || ( null !== $correction_reason && $correction_reason->concerns_refund() );

		if ( ( $refund_id > 0 ) !== $concerns_refund ) {
			throw new InvalidArgumentException( 'The refund id does not match the sales entry type.' );
		}

		if ( 1 !== preg_match( '/^-?\d{1,15}(?:\.\d{1,4})?$/', $net_amount ) ) {
			throw new InvalidArgumentException( 'Invalid sales amount.' );
		}

		$sign = $type->sign();

		if ( 0 !== $sign && ( $quantity * $sign < 0 || self::amount_sign( $net_amount ) * $sign < 0 ) ) {
			throw new InvalidArgumentException( 'Quantity and amount do not match the sign of the sales entry type.' );
		}

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new InvalidArgumentException( 'Invalid currency code.' );
		}

		$this->item_name = mb_substr( $item_name, 0, self::ITEM_NAME_MAX_LENGTH );
	}

	/**
	 * Sign of a validated decimal string: -1, 0 or 1 (no float conversion).
	 *
	 * @param string $amount Decimal string.
	 */
	public static function amount_sign( string $amount ): int {
		if ( '' === trim( str_replace( array( '-', '.', '0' ), '', $amount ) ) ) {
			return 0;
		}

		return str_starts_with( $amount, '-' ) ? -1 : 1;
	}
}
