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
 * A creator's master data (ADR-104), read by BillingDetailsReview::state().
 */
final readonly class BillingDetailsState {

	/**
	 * Constructor.
	 *
	 * @param string                     $status         BillingDetailsReview::MISSING or ::CONFIRMED.
	 * @param CreatorBillingDetails|null $confirmed      Saved master data, null for none.
	 * @param string|null                $confirmed_at   When saved (UTC), null when unknown or none.
	 * @param bool                       $required       Whether the first payout still needs master data.
	 * @param bool                       $payout_waiting Whether a pending first payout waits for them.
	 * @param bool                       $cpr_required   Whether the creator is paid as a private person.
	 * @param bool                       $cpr_saved      Whether an encrypted CPR number is stored.
	 */
	public function __construct(
		public string $status,
		public ?CreatorBillingDetails $confirmed,
		public ?string $confirmed_at,
		public bool $required,
		public bool $payout_waiting,
		public bool $cpr_required = false,
		public bool $cpr_saved = false
	) {
	}
}
