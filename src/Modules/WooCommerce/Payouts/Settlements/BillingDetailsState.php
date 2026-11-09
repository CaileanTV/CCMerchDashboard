<?php
/**
 * Where a creator's master data stand.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

defined( 'ABSPATH' ) || exit;

/**
 * A creator's master data and their review (ADR-104), read by
 * BillingDetailsReview::state().
 */
final readonly class BillingDetailsState {

	/**
	 * Constructor.
	 *
	 * @param string                                                                                        $status          BillingDetailsReview::MISSING, ::SUBMITTED, ::CHANGES_REQUESTED or ::CONFIRMED.
	 * @param CreatorBillingDetails|null                                                                    $confirmed       Confirmed master data, null for none.
	 * @param string|null                                                                                   $confirmed_at    When confirmed (UTC), null when unknown (entered before phase 42) or none.
	 * @param array{values: CreatorBillingDetails, status: string, submitted_at: string, note: string}|null $submission      The waiting submission, null for none.
	 * @param string                                                                                        $version         The submission's version ('' for none).
	 * @param bool                                                                                          $required        Whether the first payout still needs confirmed master data.
	 * @param bool                                                                                          $payout_waiting  Whether a pending first payout waits for them.
	 * @param bool                                                                                          $cpr_required    Whether payout requests need the CPR number (paid as a private person).
	 */
	public function __construct(
		public string $status,
		public ?CreatorBillingDetails $confirmed,
		public ?string $confirmed_at,
		public ?array $submission,
		public string $version,
		public bool $required,
		public bool $payout_waiting,
		public bool $cpr_required = false
	) {
	}
}
