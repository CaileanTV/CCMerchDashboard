<?php
/**
 * The database connection changed while the ledger lock was held.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by SalesRepository when the connection or the lock holder is no
 * longer the one that took the order's lock (wpdb reconnected silently; ADR-038
 * pkt. 18, fund B). The write was rolled back or never reached the database;
 * after COMMIT its outcome is unknown and the next synchronization converges.
 * A type of its own so it maps to `connection_changed` without parsing
 * messages (ADR-038, Q2). The message is generic and safe to log.
 */
final class SalesConnectionChangedException extends RuntimeException {
}
