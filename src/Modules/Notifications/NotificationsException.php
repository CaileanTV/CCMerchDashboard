<?php
/**
 * A refused notification operation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable reason code for the admin page and the REST API.
 */
final class NotificationsException extends RuntimeException {

	public const NOT_AUTHORIZED = 'not_authorized';

	public const UNKNOWN_TYPE = 'unknown_type';

	public const NO_RECIPIENT = 'no_recipient';

	public const NOT_SENT = 'not_sent';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Notification request rejected: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
