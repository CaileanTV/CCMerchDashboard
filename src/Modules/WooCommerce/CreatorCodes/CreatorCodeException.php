<?php
/**
 * A refused registration or ending of a creator's discount code.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Business rule violations of CreatorCodeService (ADR-068, K1, K4, K5). The
 * reason is a fixed code that the admin page maps to a message; it never
 * carries input or personal data.
 */
final class CreatorCodeException extends DomainException {

	public const NOT_AUTHORIZED       = 'not_authorized';
	public const CREATOR_NOT_FOUND    = 'creator_not_found';
	public const INVALID_CODE         = 'invalid_code';
	public const COUPON_NOT_FOUND     = 'coupon_not_found';
	public const GIFT_CARD_CODE       = 'gift_card_code';
	public const GIFT_CARD_UNVERIFIED = 'gift_card_unverified';
	public const CODE_TAKEN           = 'code_taken';
	public const UNCHANGED            = 'code_unchanged';
	public const NO_ACTIVE_CODE       = 'no_active_code';
	public const CODE_ENDED           = 'code_ended';
	public const BUSY                 = 'busy';
	public const COUPON_NOT_CREATED   = 'coupon_not_created';
	public const COUPON_NOT_RETIRED   = 'coupon_not_retired';
	public const RESET_LIMIT          = 'reset_limit';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Creator code request rejected: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
