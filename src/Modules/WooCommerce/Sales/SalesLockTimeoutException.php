<?php
/**
 * The sales ledger lock of an order could not be taken in time.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Another connection holds the order's ledger lock longer than the timeout
 * (ADR-038 pkt. 18). Nothing was read or written; there is no automatic
 * retry. The message is generic and safe to log.
 */
final class SalesLockTimeoutException extends RuntimeException {
}
