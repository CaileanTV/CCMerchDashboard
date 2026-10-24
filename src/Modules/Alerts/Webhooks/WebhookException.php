<?php
/**
 * A refused change of a webhook.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Webhooks;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Why a webhook was not changed; the reason is the outcome code of the
 * REST answer or the admin page.
 */
final class WebhookException extends RuntimeException {

	public const NOT_AUTHORIZED         = 'not_authorized';
	public const DISABLED               = 'api_disabled';
	public const ENCRYPTION_UNAVAILABLE = 'encryption_unavailable';
	public const INVALID_NAME           = 'invalid_name';
	public const INVALID_FORMAT         = 'invalid_format';
	public const INVALID_URL            = 'invalid_url';
	public const INVALID_SECRET         = 'invalid_secret';
	public const INVALID_TEMPLATE       = 'invalid_template';
	public const LIMIT                  = 'webhook_limit';
	public const UNKNOWN                = 'unknown_webhook';
	public const NO_ACCESS              = 'no_access';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'The webhook was not changed: ' . $reason . '.' );
	}

	/**
	 * The reason (outcome code).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
