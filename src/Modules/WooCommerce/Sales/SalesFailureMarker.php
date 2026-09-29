<?php
/**
 * Contract for the persistent sales ledger failure marker.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * The order meta `_merchboard_ledger_failure` (ADR-038 pkt. 20): set or updated
 * when a synchronization fails, removed after a successful one. Holds no
 * amounts and no personal data. There is no automatic retry.
 */
interface SalesFailureMarker {

	/**
	 * Sets or updates the marker.
	 *
	 * @param int               $order_id Order id.
	 * @param SalesFailureCode  $code     Failure code.
	 * @param DateTimeImmutable $at       When it failed.
	 */
	public function record( int $order_id, SalesFailureCode $code, DateTimeImmutable $at ): void;

	/**
	 * Removes the marker, if there is one.
	 *
	 * @param int $order_id Order id.
	 */
	public function clear( int $order_id ): void;
}
