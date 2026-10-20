<?php
/**
 * A Twitch chatbot operation that failed.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Twitch's failures (ADR-066), with the reason codes of ChatbotException.
 */
final class TwitchException extends ChatbotException {

	protected const PLATFORM_LABEL = 'Twitch';
}
