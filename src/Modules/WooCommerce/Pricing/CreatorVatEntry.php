<?php
/**
 * One registration of a creator's VAT status.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_creator_vat_history` (ADR-062 K06): the status, the
 * date it applies from, the date it was checked, the documentation reference
 * and the reason, by whom and when it was recorded. Never changed after it
 * was recorded; a correction is a new registration.
 */
final readonly class CreatorVatEntry {

	/**
	 * Constructor.
	 *
	 * @param int               $id             Internal id (order of recording).
	 * @param int               $creator_id     Creator.
	 * @param bool              $vat_registered VAT registered.
	 * @param string            $effective_from Date the status applies from (Y-m-d, site calendar).
	 * @param string            $checked_on     Date the status was checked (Y-m-d).
	 * @param string            $reference      Documentation reference.
	 * @param string            $reason         Reason.
	 * @param int|null          $recorded_by    WordPress user.
	 * @param DateTimeImmutable $recorded_at    Time of recording (UTC).
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public bool $vat_registered,
		public string $effective_from,
		public string $checked_on,
		public string $reference,
		public string $reason,
		public ?int $recorded_by,
		public DateTimeImmutable $recorded_at
	) {
	}

	/**
	 * Whether another registration would record the same.
	 *
	 * @param bool   $vat_registered VAT registered.
	 * @param string $effective_from Date the status applies from.
	 * @param string $checked_on     Date the status was checked.
	 * @param string $reference      Documentation reference.
	 * @param string $reason         Reason.
	 */
	public function records( bool $vat_registered, string $effective_from, string $checked_on, string $reference, string $reason ): bool {
		return $this->vat_registered === $vat_registered
			&& $this->effective_from === $effective_from
			&& $this->checked_on === $checked_on
			&& $this->reference === $reference
			&& $this->reason === $reason;
	}
}
