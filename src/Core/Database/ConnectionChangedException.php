<?php
/**
 * A connection-guarded write found another connection.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a write guarded by `WHERE CONNECTION_ID() = <lock connection>`
 * changed no row: the statement ran in another session (wpdb reconnected
 * silently), so it wrote nothing and the transaction it belonged to is gone
 * (E3.5(b)/(c)). A type of its own so callers map it to `connection_changed`
 * without parsing messages. The message is generic and safe to log.
 */
final class ConnectionChangedException extends RuntimeException {
}
