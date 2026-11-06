<?php
/**
 * A refused sign-up.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Signup;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a machine-readable reason; nothing was stored.
 */
final class SignupException extends RuntimeException {

	public const CLOSED         = 'signup_closed';
	public const EXPIRED_FORM   = 'form_expired';
	public const TOO_FAST       = 'too_fast';
	public const TOO_MANY       = 'too_many';
	public const NAME           = 'invalid_name';
	public const EMAIL          = 'invalid_email';
	public const EMAIL_TAKEN    = 'email_taken';
	public const USERNAME       = 'invalid_username';
	public const USERNAME_TAKEN = 'username_taken';
	public const CHANNEL        = 'invalid_channel';
	public const ADULT          = 'adult_required';
	public const AGREEMENT      = 'agreement_required';
	public const OUTDATED       = 'agreement_outdated';
	public const ALREADY        = 'already_creator';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Sign-up rejected: ' . $reason . '.' );
	}

	/**
	 * Reason code (one of the class constants).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
