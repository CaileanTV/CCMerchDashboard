<?php
/**
 * A creator's chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\AlertEvent;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's side of the chatbot (Alerts → Chatbot, phase 25 step 8).
 * Callers pass the creator that CreatorContext resolved.
 *
 * - **Channel:** the creator adds the bot to the own channel (TwitchOAuth)
 *   and can remove it again; both are audited with the channel's login.
 * - **Messages:** on or off and the template, not audited (like the other
 *   alert settings).
 * - **Test message:** a sample purchase ("TestFan") sent right away, in the
 *   format the administrator chose.
 */
final class CreatorChatbot {

	/**
	 * Username of a test message.
	 */
	public const TEST_USERNAME = 'TestFan';

	/**
	 * Constructor.
	 *
	 * @param TwitchAccount            $account       App and bot.
	 * @param ChatbotChannelRepository $channels      The creators' channels.
	 * @param ChatMessageRepository    $log           Message log.
	 * @param ChatbotFormat            $format        Message format.
	 * @param ChatMessages             $messages      Message texts.
	 * @param ChatSubscriptions        $subscriptions Chat subscriptions.
	 * @param ChatSender               $sender        Sending.
	 * @param AuditTrail               $audit         Audit trail.
	 * @param Transaction              $transaction   Database transaction.
	 */
	public function __construct(
		private TwitchAccount $account,
		private ChatbotChannelRepository $channels,
		private ChatMessageRepository $log,
		private ChatbotFormat $format,
		private ChatMessages $messages,
		private ChatSubscriptions $subscriptions,
		private ChatSender $sender,
		private AuditTrail $audit,
		private Transaction $transaction
	) {
	}

	/**
	 * What the dashboard shows.
	 *
	 * @param int $creator_id Creator.
	 * @return array{available: bool, bot_login: string, mode: string, item_format: string, separator: string, variables: list<string>, enabled: bool, template: string, default_template: string, channel: array{login: string, connected_at: string}|null, last_message: array{status: string, reason: string, at: string}|null}
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function describe( int $creator_id ): array {
		$channel = $this->channels->find( $creator_id );
		$format  = $this->format->get();
		$mode    = $format['mode'];
		$bot     = $this->account->bot();
		$last    = $this->log->latest( $creator_id );

		return array(
			'available'        => $this->account->ready(),
			'bot_login'        => null === $bot ? '' : $bot['login'],
			'mode'             => $mode,
			'item_format'      => $format['item'],
			'separator'        => $format['separator'],
			'variables'        => 'order' === $mode ? ChatMessages::ORDER_VARIABLES : ChatMessages::LINE_VARIABLES,
			'enabled'          => $channel->enabled,
			'template'         => $channel->template,
			'default_template' => 'order' === $mode ? ChatbotChannel::DEFAULT_ORDER_TEMPLATE : ChatbotChannel::DEFAULT_TEMPLATE,
			'channel'          => $channel->connected() ? array(
				'login'        => $channel->broadcaster_login,
				'connected_at' => gmdate( 'Y-m-d\TH:i:s\Z', $channel->authorized_at ),
			) : null,
			'last_message'     => null === $last ? null : array(
				'status' => $last['status'],
				'reason' => $last['reason'],
				'at'     => gmdate( 'Y-m-d\TH:i:s\Z', $last['at'] ),
			),
		);
	}

	/**
	 * Saves whether purchases are announced and the template.
	 *
	 * @param int    $creator_id Creator.
	 * @param bool   $enabled    Messages on or off.
	 * @param string $template   Template ('' for the default); plain text, one line, at most 500 characters.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( int $creator_id, bool $enabled, string $template ): void {
		$template = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $template ) ) );

		$this->channels->save_settings( $creator_id, $enabled, mb_substr( $template, 0, ChatbotChannel::TEMPLATE_MAX ) );
	}

	/**
	 * Removes the bot from the creator's channel.
	 *
	 * @param int $creator_id Creator.
	 * @return bool Whether a channel was removed.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function disconnect( int $creator_id ): bool {
		$channel = $this->channels->find( $creator_id );

		if ( ! $channel->connected() ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $creator_id, $channel ): void {
				$this->audit->record( AuditAction::AlertChatbotChannelDisconnected, $creator_id, array( 'login' => $channel->broadcaster_login ) );
				$this->channels->disconnect( $creator_id );
			}
		);
		$this->subscriptions->unsubscribe( $channel );

		return true;
	}

	/**
	 * Sends a test message to the creator's channel.
	 *
	 * @param int $creator_id Creator.
	 * @return array{result: string, reason: string, message: string}
	 * @throws TwitchException When the bot or the channel is not set up, or Twitch refuses the app.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function test( int $creator_id ): array {
		$channel = self::require_channel( $this->channels->find( $creator_id ) );
		$format  = $this->format->get();
		$event   = new AlertEvent( $creator_id, 0, '0001', 0, 0, 0, __( 'Test product', 'merchboard' ), '', 1, 0, array(), self::TEST_USERNAME, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		$message = 'order' === $format['mode']
			? $this->messages->order( $channel->template_for( 'order' ), array( $event ), $format )
			: $this->messages->line( $channel->template_for( 'line' ), $event );
		$result  = $this->sender->send_now( $channel->broadcaster_id, $message );

		return array(
			'result'  => $result['result'],
			'reason'  => $result['reason'],
			'message' => $message,
		);
	}

	/**
	 * Requires a channel with the bot.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @throws TwitchException When the creator has not added the bot.
	 */
	private static function require_channel( ChatbotChannel $channel ): ChatbotChannel {
		if ( ! $channel->connected() ) {
			throw new TwitchException( TwitchException::NOT_CONFIGURED );
		}

		return $channel;
	}
}
