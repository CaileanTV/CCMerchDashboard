<?php
/**
 * One registration of a creator's own discount code.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_creator_codes` (ADR-068, K1): the creator, the
 * normalized code, the WooCommerce coupon's id and the period [valid_from,
 * valid_to) in UTC. Active while valid_to is null. Registrations are never
 * changed except for being ended, and never deleted.
 */
final readonly class CreatorCode {

	/**
	 * Constructor.
	 *
	 * @param int                    $id         Registration id.
	 * @param int                    $creator_id Creator.
	 * @param string                 $code       Normalized coupon code.
	 * @param int                    $coupon_id  WooCommerce coupon id at registration.
	 * @param DateTimeImmutable      $valid_from Start (UTC, inclusive).
	 * @param DateTimeImmutable|null $valid_to   End (UTC, exclusive), null while active.
	 * @param int|null               $created_by User who registered it.
	 * @param int|null               $ended_by   User who ended it.
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public string $code,
		public int $coupon_id,
		public DateTimeImmutable $valid_from,
		public ?DateTimeImmutable $valid_to,
		public ?int $created_by,
		public ?int $ended_by
	) {
	}

	/**
	 * Whether the registration is active.
	 */
	public function is_active(): bool {
		return null === $this->valid_to;
	}

	/**
	 * Whether a time (an order's WooCommerce creation) lies in [valid_from, valid_to).
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	public function covers( DateTimeImmutable $time ): bool {
		return $time >= $this->valid_from && ( null === $this->valid_to || $time < $this->valid_to );
	}
}
