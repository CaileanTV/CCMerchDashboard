<?php
/**
 * A refused change of the alert API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Api;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Why an API key or the API switch was not changed; the reason is the
 * outcome code of the REST answer or the admin page.
 */
final class AlertApiException extends RuntimeException {

	public const NOT_AUTHORIZED = 'not_authorized';
	public const DISABLED       = 'api_disabled';
	public const INVALID_NAME   = 'invalid_name';
	public const KEY_LIMIT      = 'key_limit';
	public const UNKNOWN_KEY    = 'unknown_key';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'The alert API was not changed: ' . $reason . '.' );
	}

	/**
	 * The reason (outcome code).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
