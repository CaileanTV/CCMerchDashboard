<?php
/**
 * Outcome of planning one order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * Either the rows to append (possibly none) or a failure, never both.
 */
final readonly class SalesPlanResult {

	/**
	 * Constructor.
	 *
	 * @param SalesLedgerWrite|null $write   Rows to append, when planning succeeded.
	 * @param SalesPlanFailure|null $failure Failure, when it did not.
	 */
	private function __construct( public ?SalesLedgerWrite $write, public ?SalesPlanFailure $failure ) {
	}

	/**
	 * A successful plan.
	 *
	 * @param SalesLedgerWrite $write Rows to append.
	 */
	public static function planned( SalesLedgerWrite $write ): self {
		return new self( $write, null );
	}

	/**
	 * A failed plan: nothing may be written.
	 *
	 * @param SalesPlanFailure $failure Why.
	 */
	public static function failed( SalesPlanFailure $failure ): self {
		return new self( null, $failure );
	}
}
