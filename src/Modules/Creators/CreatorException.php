<?php
/**
 * Business rule violations for creator administration.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * A request that violates a creator business rule. The reason is a fixed
 * code that the admin UI maps to a user-facing message; no user input or
 * database detail is ever part of it.
 */
final class CreatorException extends DomainException {

	public const NOT_AUTHORIZED       = 'not_authorized';
	public const NOT_FOUND            = 'not_found';
	public const INVALID_DISPLAY_NAME = 'invalid_display_name';
	public const INVALID_STATUS       = 'invalid_status';
	public const USER_NOT_FOUND       = 'user_not_found';
	public const USER_ALREADY_LINKED  = 'user_already_linked';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Creator request rejected: ' . $reason . '.' );
	}

	/**
	 * Reason code (one of the class constants).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
