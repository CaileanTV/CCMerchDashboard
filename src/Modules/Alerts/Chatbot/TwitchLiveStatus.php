<?php
/**
 * Whether a creator's Twitch channel is live.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 1: asks Twitch (`helix/streams`, app access token) whether a
 * channel is live and keeps the answer for 60 seconds in a transient, so a
 * busy product page asks at most once a minute. A refused app token is
 * renewed once; when Twitch cannot be asked, the channel counts as not live
 * (no gifts are sold then), and that answer is kept for 60 seconds too.
 */
final class TwitchLiveStatus {

	/**
	 * Seconds an answer is kept.
	 */
	public const TTL = 60;

	/**
	 * Transient prefix.
	 */
	private const PREFIX = 'merchboard_twitch_live_';

	/**
	 * Constructor.
	 *
	 * @param TwitchAccount $account The Twitch app.
	 * @param TwitchApi     $api     Twitch.
	 */
	public function __construct( private TwitchAccount $account, private TwitchApi $api ) {
	}

	/**
	 * Whether a channel is live (an answer of the last minute, or Twitch's).
	 *
	 * @param string $broadcaster_id Channel.
	 * @param bool   $fresh          Whether to ask Twitch even with a recent answer.
	 */
	public function live( string $broadcaster_id, bool $fresh = false ): bool {
		$key    = self::PREFIX . md5( $broadcaster_id );
		$cached = $fresh ? false : get_transient( $key );

		if ( 'yes' === $cached || 'no' === $cached ) {
			return 'yes' === $cached;
		}

		$live = $this->ask( $broadcaster_id );
		set_transient( $key, $live ? 'yes' : 'no', self::TTL );

		return $live;
	}

	/**
	 * Twitch's answer; false when it cannot be asked.
	 *
	 * @param string $broadcaster_id Channel.
	 */
	private function ask( string $broadcaster_id ): bool {
		if ( ! $this->account->has_app() ) {
			return false;
		}

		foreach ( array( false, true ) as $fresh ) {
			try {
				return $this->api->stream_live( $this->account->client_id(), $this->account->app_token( $fresh ), $broadcaster_id );
			} catch ( TwitchException $e ) {
				// Only a refused app token is worth one more try with a new one.
				if ( $fresh || TwitchException::UNAUTHORIZED !== $e->reason() ) {
					return false;
				}
			}
		}

		return false;
	}
}
