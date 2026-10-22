<?php
/**
 * A chatbot operation that failed, on any platform.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable reason code for the dashboard, the admin result notice
 * and the message log. Never contains a token or a secret. Each platform
 * throws its own subclass (TwitchException, KickException), so code that
 * works with any platform catches this class.
 */
class ChatbotException extends RuntimeException {

	public const NOT_CONFIGURED      = 'not_configured';
	public const INVALID_CREDENTIALS = 'invalid_credentials';
	public const UNAVAILABLE         = 'unavailable';
	public const UNAUTHORIZED        = 'unauthorized';
	public const FORBIDDEN           = 'forbidden';
	public const INVALID_STATE       = 'invalid_state';
	public const DENIED              = 'denied';
	public const BOT_IS_BROADCASTER  = 'bot_is_broadcaster';
	public const NOT_AUTHORIZED      = 'not_authorized';
	public const UNREADABLE          = 'unreadable';
	public const NO_WEBHOOKS         = 'no_webhooks';
	public const UNEXPECTED          = 'unexpected_answer';
	public const TERMS_REQUIRED      = 'terms_required';
	public const QUOTA_EXCEEDED      = 'quota_exceeded';
	public const LIVE_NOT_ALLOWED    = 'live_not_allowed';

	/**
	 * The platform in technical messages.
	 */
	protected const PLATFORM_LABEL = 'Alert';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 * @param int    $status The platform's HTTP status, for the technical log (0: none or a transport error).
	 * @param string $detail The platform's own error code and text, for the technical log ('' for none; never a token).
	 */
	public function __construct( private string $reason, private int $status = 0, private string $detail = '' ) {
		parent::__construct( static::PLATFORM_LABEL . ' chatbot: ' . $reason . '.' );
	}

	/**
	 * The platform's own error code and text ('' for none).
	 */
	public function detail(): string {
		return $this->detail;
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * The platform's HTTP status (0: none or a transport error).
	 */
	public function status(): int {
		return $this->status;
	}
}
