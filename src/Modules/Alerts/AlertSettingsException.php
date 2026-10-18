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
