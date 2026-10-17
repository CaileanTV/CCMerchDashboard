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
	 * VAT status history (ADR-062 K06): the date the status applies from is
	 * not a valid date between 2000-01-01 and today.
	 */
	public const INVALID_EFFECTIVE_DATE = 'invalid_vat_effective_date';

	/**
	 * VAT status history: the check date is not a valid date between
	 * 2000-01-01 and today.
	 */
	public const INVALID_CHECK_DATE = 'invalid_vat_check_date';

	/**
	 * VAT status history: the documentation reference is empty, too long or
	 * contains control characters.
	 */
	public const INVALID_REFERENCE = 'invalid_vat_reference';

	/**
	 * VAT status history: the reason is empty, too long or contains control
	 * characters.
	 */
	public const INVALID_REASON = 'invalid_vat_reason';

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
