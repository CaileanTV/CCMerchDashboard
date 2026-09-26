<?php
/**
 * Business rule violations for the product → creator link.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * A request that violates a product link rule. The reason is a fixed code
 * that callers map to a user-facing message; no user input or database
 * detail is ever part of it.
 */
final class ProductCreatorLinkException extends DomainException {

	public const NOT_AUTHORIZED        = 'not_authorized';
	public const PRODUCT_NOT_FOUND     = 'product_not_found';
	public const VARIATION             = 'variation';
	public const PRODUCT_NOT_SUPPORTED = 'product_not_supported';
	public const CREATOR_NOT_FOUND     = 'creator_not_found';
	public const CREATOR_INACTIVE      = 'creator_inactive';
	public const ALREADY_LINKED        = 'already_linked';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Product link request rejected: ' . $reason . '.' );
	}

	/**
	 * Reason code (one of the class constants).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
