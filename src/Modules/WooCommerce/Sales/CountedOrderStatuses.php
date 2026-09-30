<?php
/**
 * Order statuses that count as sales.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use Closure;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that decides which WooCommerce order statuses count
 * (ADR-037 pkt. 4, ADR-038 status note "ROADMAP trin 6", S1-S14).
 *
 * The list is the setting `counted_order_statuses` in `merchboard_settings`,
 * read on every call, so a changed list applies from the next
 * synchronization on (no backfill, no recalculation). The fixed deny list is
 * enforced here as well (S8): a status on it never counts, whatever is
 * stored. If nothing allowed remains, the default applies (S9), so corrupt
 * option data can never make every order non-counting. A stored status that
 * WooCommerce no longer registers still counts (S10).
 *
 * WooCommerce' `wc_get_is_paid_statuses()` is not used: it can be changed by
 * a filter.
 */
final class CountedOrderStatuses {

	/**
	 * Default counted statuses, without the `wc-` prefix.
	 */
	public const DEFAULT = Settings::DEFAULT_COUNTED_ORDER_STATUSES;

	/**
	 * Statuses that never count (S3, S8), without the `wc-` prefix.
	 */
	public const DENIED = array( 'pending', 'failed', 'cancelled', 'refunded', 'checkout-draft', 'trash' );

	/**
	 * Source of the stored list.
	 *
	 * @var Closure(): list<string>
	 */
	private Closure $source;

	/**
	 * Constructor.
	 *
	 * @param (Closure(): list<string>)|null $source Stored list; defaults to the setting.
	 */
	public function __construct( ?Closure $source = null ) {
		$this->source = $source ?? static fn(): array => Settings::counted_order_statuses();
	}

	/**
	 * Whether a status is on the fixed deny list.
	 *
	 * @param string $status Status slug without `wc-`.
	 */
	public static function denied( string $status ): bool {
		return in_array( $status, self::DENIED, true );
	}

	/**
	 * The statuses that count now: the stored list without denied statuses,
	 * or the default when nothing allowed remains.
	 *
	 * @return list<string>
	 */
	public function statuses(): array {
		$statuses = array_values( array_filter( ( $this->source )(), static fn( string $status ): bool => ! self::denied( $status ) ) );

		return array() === $statuses ? self::DEFAULT : $statuses;
	}

	/**
	 * Whether an order status counts.
	 *
	 * @param string $status Order status as WooCommerce' `get_status( 'edit' )` returns it (no `wc-` prefix).
	 */
	public function counts( string $status ): bool {
		return in_array( $status, $this->statuses(), true );
	}
}
