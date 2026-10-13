<?php
/**
 * A registered state mutation of an order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Operations;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * What a caller asks the E3 frame to do, identified for F-5's registry:
 * the operation id (a lowercase UUID, the idempotency key), the kind, the
 * order it concerns and an opaque SHA-256 hex string of its payload (B-3:
 * which fields go into it is the caller's matter). A repeat with the same
 * id must carry the same kind, order and hash; anything else is a reused id
 * (`operation_mismatch`, D-2).
 */
final readonly class Operation {

	/**
	 * The kinds of F-5's registry. The activation does not use the registry
	 * (T3, B1), so `g1_activate` is not one of them.
	 */
	public const KINDS = array( 'g1_finalize', 'g1_decision', 'g1_batch', 'mark_24_5', 'unmark_24_5', 'approve_24_11', 'revoke_24_11' );

	/**
	 * Constructor.
	 *
	 * @param string $id           Operation id (lowercase UUID).
	 * @param string $kind         One of KINDS.
	 * @param int    $order_id     The order the operation concerns (`subject_id`).
	 * @param string $payload_hash SHA-256 of the payload, 64 lowercase hex characters.
	 * @throws InvalidArgumentException When a value is not valid.
	 */
	public function __construct(
		public string $id,
		public string $kind,
		public int $order_id,
		public string $payload_hash
	) {
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id ) ) {
			throw new InvalidArgumentException( 'The operation id must be a lowercase UUID.' );
		}

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new InvalidArgumentException( 'Unknown operation kind.' );
		}

		if ( $order_id < 1 ) {
			throw new InvalidArgumentException( 'The order id must be positive.' );
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $payload_hash ) ) {
			throw new InvalidArgumentException( 'The payload hash must be 64 lowercase hex characters.' );
		}
	}
}
