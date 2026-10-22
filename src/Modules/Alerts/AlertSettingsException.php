<?php
/**
 * Rejected change of the alert settings.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable reason code for the admin result notice.
 */
final class AlertSettingsException extends DomainException {

	/**
	 * The current user lacks `merchboard_manage_alerts`.
	 */
	public const NOT_AUTHORIZED = 'not_authorized';

	/**
	 * The company name is not one line of at most 200 characters.
	 */
	public const INVALID_COMPANY_NAME = 'invalid_company_name';

	/**
	 * The contact e-mail is not an e-mail address.
	 */
	public const INVALID_COMPANY_EMAIL = 'invalid_company_email';

	/**
	 * The checkout username label is not one line of at most 100 characters.
	 */
	public const INVALID_CHECKOUT_LABEL = 'invalid_checkout_label';

	/**
	 * The checkout username help text is not one line of at most 300
	 * characters.
	 */
	public const INVALID_CHECKOUT_HELP = 'invalid_checkout_help';

	/**
	 * The largest sound file is not a whole number within 100 KB … 500 MB.
	 */
	public const INVALID_SOUND_SIZE = 'invalid_sound_size';

	/**
	 * The longest sound is not a whole number within 1 second … 1 hour.
	 */
	public const INVALID_SOUND_LENGTH = 'invalid_sound_length';

	/**
	 * The creator does not exist (a creator's own sound limits).
	 */
	public const CREATOR_NOT_FOUND = 'creator_not_found';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Alert settings rejected: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
