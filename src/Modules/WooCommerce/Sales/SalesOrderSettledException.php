<?php
/**
 * An order's history cannot be deleted: it is settled.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A settlement document settled a ledger row of the order, so its history
 * is kept (D5, ADR-060). Nothing was deleted.
 */
final class SalesOrderSettledException extends RuntimeException {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'The order is part of a settlement document.' );
	}
}
