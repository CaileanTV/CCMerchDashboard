<?php
/**
 * The bot's answer to !merch in the chat.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchCommand;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the chat of Twitch (`merchboard_twitch_chat_message`) and Kick
 * (`merchboard_kick_chat_message`), both from signed webhooks (ADR-113 pkt.
 * 5). When anyone writes `!merch` and the creator has the command on, the
 * bot answers with the creator's text and the link to the creator's
 * collection, at most once per cooldown per channel.
 */
final class MerchCommand {

	/**
	 * The cooldown's transient prefix.
	 */
	public const COOLDOWN_PREFIX = 'merchboard_merch_cd_';

	/**
	 * The link to a creator's collection.
	 *
	 * @var Closure(int): string
	 */
	private Closure $link;

	/**
	 * Sends a message in a creator's channel on a platform now.
	 *
	 * @var Closure(int, ChatPlatform, string): bool
	 */
	private Closure $send;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): string $link
	 * @phpstan-param Closure(int, ChatPlatform, string): bool $send
	 *
	 * @param MerchCommandRepository $settings The creators' commands.
	 * @param Closure                $link     A creator's collection URL.
	 * @param Closure                $send     Sends a chat message; whether it was sent.
	 * @param Logger                 $logger   Technical log.
	 */
	public function __construct( private MerchCommandRepository $settings, Closure $link, Closure $send, private Logger $logger ) {
		$this->link = $link;
		$this->send = $send;
	}

	/**
	 * Hooks the chat of both platforms.
	 */
	public function register(): void {
		add_action( 'merchboard_twitch_chat_message', array( $this, 'twitch' ), 20, 4 );
		add_action( 'merchboard_kick_chat_message', array( $this, 'kick' ), 20, 4 );
	}

	/**
	 * A Twitch chat message.
	 *
	 * @param mixed $creator_id Creator.
	 * @param mixed $chatter_id Chatter's id.
	 * @param mixed $login      Chatter's login.
	 * @param mixed $text       Message.
	 */
	public function twitch( mixed $creator_id, mixed $chatter_id = '', mixed $login = '', mixed $text = '' ): void {
		$this->chat( ChatPlatform::Twitch, $creator_id, $text );
	}

	/**
	 * A Kick chat message.
	 *
	 * @param mixed $creator_id Creator.
	 * @param mixed $chatter_id Chatter's id.
	 * @param mixed $login      Chatter's login.
	 * @param mixed $text       Message.
	 */
	public function kick( mixed $creator_id, mixed $chatter_id = '', mixed $login = '', mixed $text = '' ): void {
		$this->chat( ChatPlatform::Kick, $creator_id, $text );
	}

	/**
	 * Answers `!merch`.
	 *
	 * @param ChatPlatform $platform   Platform.
	 * @param mixed        $creator_id Creator.
	 * @param mixed        $text       Message.
	 * @return bool Whether the bot answered.
	 */
	public function chat( ChatPlatform $platform, mixed $creator_id, mixed $text ): bool {
		if ( ! is_int( $creator_id ) || $creator_id < 1 || ! is_string( $text ) || ! self::is_command( $text ) ) {
			return false;
		}

		try {
			$settings = $this->settings->find( $creator_id );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The !merch command could not be read.', $e->context() );

			return false;
		}

		$key = self::COOLDOWN_PREFIX . $platform->value . '_' . $creator_id;

		if ( ! $settings->enabled || false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, $settings->cooldown );

		return ( $this->send )( $creator_id, $platform, MerchCommandSettings::message( $settings->text, ( $this->link )( $creator_id ) ) );
	}

	/**
	 * Whether a message is the command (`!merch`, any case, maybe followed
	 * by more words).
	 *
	 * @param string $text Message.
	 */
	public static function is_command( string $text ): bool {
		return 1 === preg_match( '/^!merch(\s|$)/i', trim( $text ) );
	}
}
