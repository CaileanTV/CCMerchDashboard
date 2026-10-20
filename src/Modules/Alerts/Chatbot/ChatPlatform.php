<?php
/**
 * The streaming platforms of the alert chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Twitch (ADR-066) and Kick (ADR-069). The value is stored in the
 * `platform` columns of the chatbot tables (schema version 21) and used in
 * routes and job arguments.
 */
enum ChatPlatform: string {

	case Twitch = 'twitch';
	case Kick   = 'kick';

	/**
	 * The platform's name (a brand, not translated).
	 */
	public function label(): string {
		return match ( $this ) {
			self::Twitch => 'Twitch',
			self::Kick   => 'Kick',
		};
	}

	/**
	 * The URL of a channel.
	 *
	 * @param string $login Channel login (Twitch) or slug (Kick).
	 */
	public function channel_url( string $login ): string {
		return match ( $this ) {
			self::Twitch => 'https://www.twitch.tv/' . rawurlencode( $login ),
			self::Kick   => 'https://kick.com/' . rawurlencode( $login ),
		};
	}

	/**
	 * A platform from a value; null for an unknown one.
	 *
	 * @param mixed $value Value from a request or a row.
	 */
	public static function parse( mixed $value ): ?self {
		return is_string( $value ) ? self::tryFrom( $value ) : null;
	}
}
