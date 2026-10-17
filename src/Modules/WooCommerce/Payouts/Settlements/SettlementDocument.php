<?php
/**
 * One issued settlement document.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_settlement_documents` (ADR-060): never changed after
 * it was issued. Amounts are units (1/10 000). For a settlement document:
 * closing = opening + commission + adjustment − payout, where the adjustment
 * is the sum of the payout adjustments it settled (ADR-063; 0 for documents
 * without any). A correction document carries no amounts (D11: amounts are
 * corrected forward through the ledger).
 */
final readonly class SettlementDocument {

	public const TYPE_SETTLEMENT = 'settlement';

	public const TYPE_CORRECTION = 'correction';

	/**
	 * Constructor.
	 *
	 * @param int                    $id                   Internal id.
	 * @param string                 $number               Document number, e.g. AFR-000001.
	 * @param string                 $type                 TYPE_SETTLEMENT or TYPE_CORRECTION.
	 * @param int                    $creator_id           Creator.
	 * @param int|null               $payout_id            The payout it belongs to.
	 * @param int|null               $corrects_document_id The document a correction corrects.
	 * @param string                 $currency             Currency.
	 * @param DateTimeImmutable|null $period_from          Start of the period (UTC), null = the whole history.
	 * @param DateTimeImmutable      $period_to            End of the period (UTC): the time of issue.
	 * @param int                    $profit_units         Profit of the settled rows.
	 * @param int                    $commission_units     Payout (commission) of the settled rows (ADR-049).
	 * @param int                    $opening_units        Balance carried from the previous document.
	 * @param int                    $payout_units         Amount paid out with this document.
	 * @param int                    $closing_units        Balance carried to the next document.
	 * @param bool|null              $vat_registered       The creator's VAT status at issue, null = unknown.
	 * @param string                 $calculation_version  Calculation rules it was made with.
	 * @param int                    $template_version     Template version.
	 * @param array<string, mixed>   $snapshot             Parties, payout and notes as they were at issue.
	 * @param int|null               $issued_by            WordPress user.
	 * @param DateTimeImmutable      $issued_at            Time of issue (UTC).
	 * @param int                    $adjustment_units     Payout adjustments it settled (ADR-063).
	 */
	public function __construct(
		public int $id,
		public string $number,
		public string $type,
		public int $creator_id,
		public ?int $payout_id,
		public ?int $corrects_document_id,
		public string $currency,
		public ?DateTimeImmutable $period_from,
		public DateTimeImmutable $period_to,
		public int $profit_units,
		public int $commission_units,
		public int $opening_units,
		public int $payout_units,
		public int $closing_units,
		public ?bool $vat_registered,
		public string $calculation_version,
		public int $template_version,
		public array $snapshot,
		public ?int $issued_by,
		public DateTimeImmutable $issued_at,
		public int $adjustment_units = 0
	) {
	}
}
