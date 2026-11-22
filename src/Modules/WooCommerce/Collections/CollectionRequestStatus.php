<?php
/**
 * The states of a request to have designs collected.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120: pending → approved → ready, or declined with a note from either of
 * the first two. Pending and approved are open.
 */
enum CollectionRequestStatus: string {

	case Pending  = 'pending';
	case Approved = 'approved';
	case Ready    = 'ready';
	case Declined = 'declined';

	/**
	 * Whether the request still waits for something.
	 */
	public function open(): bool {
		return self::Pending === $this || self::Approved === $this;
	}
}
