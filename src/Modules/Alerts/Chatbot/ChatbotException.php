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

	/**
	 * The platform in technical messages.
	 */
	protected const PLATFORM_LABEL = 'Alert';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( static::PLATFORM_LABEL . ' chatbot: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
