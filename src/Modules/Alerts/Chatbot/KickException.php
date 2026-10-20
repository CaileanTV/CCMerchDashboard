<?php
/**
 * A Kick chatbot operation that failed.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Kick's failures (ADR-069), with the reason codes of ChatbotException.
 */
final class KickException extends ChatbotException {

	protected const PLATFORM_LABEL = 'Kick';
}
