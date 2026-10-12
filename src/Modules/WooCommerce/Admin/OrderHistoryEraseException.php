<?php
/**
 * A rejected deletion of an order's stored history.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Why an order's history was not deleted (ADR-052). The code is fixed and
 * mapped to a message by the page; no user input or database detail is part
 * of it.
 */
final class OrderHistoryEraseException extends DomainException {

	public const NOT_AUTHORIZED = 'not_authorized';
	public const ORDER_ACTIVE   = 'order_active';
	public const NO_HISTORY     = 'no_history';
	public const BUSY           = 'busy';
	public const FAILED         = 'failed';
	public const SETTLED        = 'settled';

	/**
	 * Constructor.
	 *
	 * @param string $code_name One of the class constants.
	 */
	public function __construct( private string $code_name ) {
		parent::__construct( 'Order history deletion rejected: ' . $code_name . '.' );
	}

	/**
	 * Code (one of the class constants).
	 */
	public function reason(): string {
		return $this->code_name;
	}
}
