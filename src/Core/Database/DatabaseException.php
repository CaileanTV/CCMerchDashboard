<?php
/**
 * Database failure.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a query or schema change fails.
 *
 * Messages are generic and never contain SQL, `$wpdb->last_error` or values,
 * so they are safe to log and cannot leak database details to users.
 */
final class DatabaseException extends RuntimeException {
}
