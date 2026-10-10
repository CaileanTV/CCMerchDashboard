<?php
/**
 * A rejected pricing operation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * A pricing configuration request that violates a rule. The code is fixed
 * and mapped to a user-facing message by callers; no user input or database
 * detail is part of it.
 */
final class PricingException extends DomainException {

	public const NOT_AUTHORIZED      = 'not_authorized';
	public const PRODUCT_NOT_FOUND   = 'product_not_found';
	public const CREATOR_NOT_FOUND   = 'creator_not_found';
	public const INVALID_MARKUP      = 'invalid_markup';
	public const NO_OWNER            = 'no_owner';
	public const NOT_OWNED           = 'not_owned';
	public const UNSUPPORTED_PRODUCT = 'unsupported_product';

	/**
	 * Constructor.
	 *
	 * @param string $code_name One of the class constants.
	 */
	public function __construct( private string $code_name ) {
		parent::__construct( 'Pricing request rejected: ' . $code_name . '.' );
	}

	/**
	 * Code (one of the class constants).
	 */
	public function reason(): string {
		return $this->code_name;
	}
}
