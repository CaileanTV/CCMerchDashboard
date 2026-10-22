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
