<?php
/**
 * Integration tests for the Twitch chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Admin\ChatbotAdminActions;
use MerchBoard\Modules\Alerts\Admin\ChatbotAdminTab;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\ChatLogRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessageRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessages;
use MerchBoard\Modules\Alerts\Chatbot\ChatSender;
use MerchBoard\Modules\Alerts\Chatbot\ChatSubscriptions;
use MerchBoard\Modules\Alerts\Chatbot\CreatorChatbot;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchApi;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCipher;
use MerchBoard\Modules\Alerts\Chatbot\TwitchException;
use MerchBoard\Modules\Alerts\Chatbot\TwitchOAuth;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Rest\MeChatbotController;
use MerchBoard\Modules\Alerts\Rest\TwitchController;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Phase 25 step 8 (ADR-066, status note 2026-10-20): the bot sends with the
 * app access token (chatbot badge), the creator adds it to the own channel
 * with `channel:bot`, messages are sent once per order line or order through
 * the queue, Twitch's rate limit is respected, and the chat window is fed by
 * a signed EventSub webhook. Twitch itself is a fake transport.
 */
#[CoversClass( TwitchApi::class )]
#[CoversClass( TwitchAccount::class )]
#[CoversClass( TwitchCipher::class )]
#[CoversClass( TwitchOAuth::class )]
#[CoversClass( ChatSender::class )]
#[CoversClass( ChatSubscriptions::class )]
#[CoversClass( ChatMessages::class )]
#[CoversClass( ChatbotFormat::class )]
#[CoversClass( CreatorChatbot::class )]
#[CoversClass( ChatbotChannelRepository::class )]
#[CoversClass( ChatMessageRepository::class )]
#[CoversClass( ChatLogRepository::class )]
#[CoversClass( MeChatbotController::class )]
#[CoversClass( TwitchController::class )]
#[CoversClass( ChatbotAdminActions::class )]
#[CoversClass( ChatbotAdminTab::class )]
final class ChatbotTest extends IntegrationTestCase {

	private const CLIENT_ID = 'abcdefghij0123456789';
	private const SECRET    = 'zyxwvutsrq9876543210';
	private const APP_TOKEN = 'apptoken0001';
	private const BOT_ID    = '900';

	private const TOKEN_URL         = 'POST https://id.twitch.tv/oauth2/token';
	private const USERS_URL         = 'GET https://api.twitch.tv/helix/users';
	private const MESSAGES_URL      = 'POST https://api.twitch.tv/helix/chat/messages';
	private const SUBSCRIBE_URL     = 'POST https://api.twitch.tv/helix/eventsub/subscriptions';
	private const SUBSCRIPTIONS_URL = 'GET https://api.twitch.tv/helix/eventsub/subscriptions';

	/**
	 * Requests sent to Twitch.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: array<mixed>|string|null}>
	 */
	private array $requests = array();

	/**
	 * Twitch's answers by "METHOD URL without query", in order.
	 *
	 * @var array<string, list<array{status: int, headers: array<string, string>, body: string}>>
	 */
	private array $answers = array();

	/**
	 * The last answer used per URL; it repeats when no new one is queued.
	 *
	 * @var array<string, array{status: int, headers: array<string, string>, body: string}>
	 */
	private array $last = array();

	private TwitchApi $api;

	private TwitchAccount $account;

	private ChatbotChannelRepository $channels;

	private ChatMessageRepository $log;

	private ChatLogRepository $chat;

	private ChatbotFormat $format;

	private AlertEventRepository $events;

	private FakeJobQueue $queue;

	private ChatSubscriptions $subscriptions;

	private ChatSender $sender;

	private TwitchOAuth $oauth;

	private TwitchController $twitch;

	/**
	 * Registers the routes on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->enable_alerts();

		$audit       = $this->service( AuditTrail::class );
		$transaction = $this->service( Transaction::class );
		$logger      = $this->service( Logger::class );

		$this->api           = new TwitchApi( $this->transport() );
		$this->account       = new TwitchAccount( $this->api, new TwitchCipher(), $audit, $transaction );
		$this->channels      = new ChatbotChannelRepository( $this->wpdb );
		$this->log           = new ChatMessageRepository( $this->wpdb );
		$this->chat          = new ChatLogRepository( $this->wpdb );
		$this->format        = new ChatbotFormat( $audit, $transaction );
		$this->events        = new AlertEventRepository( $this->wpdb );
		$this->queue         = new FakeJobQueue();
		$this->subscriptions = new ChatSubscriptions( $this->account, $this->api, $this->channels, $this->chat );
		$this->sender        = new ChatSender( $this->account, $this->api, $this->channels, $this->log, $this->format, new ChatMessages( new AlertTemplate() ), $this->events, new AlertsAvailability( static fn(): bool => false ), $this->queue, $logger );
		$this->oauth         = new TwitchOAuth( $this->account, $this->api, $this->channels, $this->subscriptions, $audit, $transaction, static fn( string $path ): string => home_url( '/creator-dashboard/' . $path . '/' ) );
		$this->twitch        = new TwitchController( $this->oauth, $this->account, $this->channels, $this->chat, $logger );
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		remove_all_filters( 'rest_pre_serve_request' );
		remove_all_filters( 'rest_url' );
		delete_option( TwitchAccount::OPTION );
		delete_option( ChatbotFormat::OPTION );
		delete_option( Settings::OPTION );
		$this->execute( 'DELETE FROM %i WHERE option_name LIKE %s', $this->wpdb->options, '%merchboard_twitch_state_%' );
		wp_cache_flush();
		parent::tearDown();
	}

	public function test_the_app_is_checked_at_twitch_and_its_secrets_are_stored_encrypted(): void {
		try {
			$this->account->save_app( 'not valid!', self::SECRET );
			self::fail( 'Expected TwitchException.' );
		} catch ( TwitchException $e ) {
			self::assertSame( TwitchException::INVALID_CREDENTIALS, $e->reason() );
		}

		$this->answer( self::TOKEN_URL, 400, array( 'message' => 'invalid client' ) );

		try {
			$this->account->save_app( self::CLIENT_ID, self::SECRET );
			self::fail( 'Expected TwitchException.' );
		} catch ( TwitchException $e ) {
			self::assertSame( TwitchException::INVALID_CREDENTIALS, $e->reason() );
		}

		self::assertFalse( $this->account->has_app(), 'Nothing is saved when Twitch refuses it.' );

		$this->set_up_app();

		self::assertTrue( $this->account->has_app() );
		self::assertSame( self::APP_TOKEN, $this->account->app_token(), 'The cached token is used.' );
		self::assertSame( 'client_credentials', $this->requests[1]['body']['grant_type'] ?? null );

		$stored = (string) wp_json_encode( get_option( TwitchAccount::OPTION ) );
		self::assertStringNotContainsString( self::SECRET, $stored );
		self::assertStringNotContainsString( self::APP_TOKEN, $stored );
		self::assertSame( 'off', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $this->wpdb->options, TwitchAccount::OPTION ) ) );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'alert_twitch_app_changed' ), array_column( $audit, 'action' ) );
		self::assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $audit ) );

		// Without the capability nothing changes.
		wp_set_current_user( $this->create_user( 'visitor' ) );

		try {
			$this->account->remove_app();
			self::fail( 'Expected TwitchException.' );
		} catch ( TwitchException $e ) {
			self::assertSame( TwitchException::NOT_AUTHORIZED, $e->reason() );
		}

		self::assertTrue( $this->account->has_app() );
	}

	public function test_the_bot_logs_in_once_and_its_user_token_is_never_stored(): void {
		$this->set_up_app();
		$url   = $this->oauth->start_bot();
		$query = $this->query( $url );

		self::assertStringStartsWith( TwitchApi::AUTHORIZE_URL . '?', $url );
		self::assertSame( 'user:read:chat user:write:chat user:bot', $query['scope'] ?? null );
		self::assertSame( 'true', $query['force_verify'] ?? null, 'The administrator chooses the bot account every time.' );
		self::assertSame( TwitchAccount::redirect_uri(), $query['redirect_uri'] ?? null );

		// Twitch's redirect: the browser of the administrator, without a REST nonce.
		wp_set_current_user( 0 );
		$this->answer( self::TOKEN_URL, 200, array( 'access_token' => 'usertoken-bot' ) );
		$this->answer(
			self::USERS_URL,
			200,
			array(
				'data' => array(
					array(
						'id'    => self::BOT_ID,
						'login' => 'Merch_Bot',
					),
				),
			)
		);
		$location = $this->oauth->finish( $query['state'] ?? '', 'code-1', '' );

		self::assertStringContainsString( 'merchboard_alerts_admin_result=bot_connected', $location );
		self::assertSame( 0, get_current_user_id(), 'The user is restored.' );
		self::assertSame( array( self::BOT_ID, 'merch_bot' ), array( $this->account->bot()['id'] ?? null, $this->account->bot()['login'] ?? null ) );
		self::assertSame( 'Bearer usertoken-bot', end( $this->requests )['headers']['Authorization'] ?? null );
		self::assertStringNotContainsString( 'usertoken-bot', (string) wp_json_encode( get_option( TwitchAccount::OPTION ) ) );
		self::assertSame( home_url( '/' ), $this->oauth->finish( $query['state'] ?? '', 'code-1', '' ), 'A state is used once.' );
		self::assertSame( home_url( '/' ), $this->oauth->finish( str_repeat( 'a', 32 ), 'code-1', '' ) );
	}

	public function test_a_creator_adds_the_bot_to_the_own_channel(): void {
		$this->set_up_bot();
		$nova = $this->creator( 'nova' );

		$query = $this->query( $this->oauth->start_channel( $nova ) );
		self::assertSame( 'channel:bot', $query['scope'] ?? null );
		self::assertSame( 'false', $query['force_verify'] ?? null );

		wp_set_current_user( 0 );
		$this->answer( self::TOKEN_URL, 200, array( 'access_token' => 'usertoken-nova' ) );
		$this->answer(
			self::USERS_URL,
			200,
			array(
				'data' => array(
					array(
						'id'    => '500',
						'login' => 'Nova',
					),
				),
			)
		);
		$location = $this->oauth->finish( $query['state'] ?? '', 'code-2', '' );

		self::assertSame( add_query_arg( 'twitch', 'connected', home_url( '/creator-dashboard/alerts/chatbot/' ) ), $location );
		$channel = $this->channels->find( $nova );
		self::assertSame( array( '500', 'nova', ChatSubscriptions::NO_WEBHOOKS ), array( $channel->broadcaster_id, $channel->broadcaster_login, $channel->subscription_status ), 'The test site is not reachable over HTTPS.' );
		self::assertContains( 'alert_chatbot_channel_connected', array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );
		self::assertStringNotContainsString( 'usertoken-nova', (string) wp_json_encode( $this->rows( Tables::ALERT_CHATBOT ) ) );

		// Refused at Twitch.
		wp_set_current_user( $this->user_of( $nova ) );
		$denied = $this->query( $this->oauth->start_channel( $nova ) );
		self::assertStringEndsWith( 'twitch=denied', $this->oauth->finish( $denied['state'] ?? '', '', 'access_denied' ) );
	}

	public function test_the_bot_cannot_be_added_to_its_own_channel(): void {
		$this->set_up_bot();
		$orion = $this->creator( 'orion' );
		$query = $this->query( $this->oauth->start_channel( $orion ) );

		$this->answer( self::TOKEN_URL, 200, array( 'access_token' => 'usertoken-bot' ) );
		$this->answer(
			self::USERS_URL,
			200,
			array(
				'data' => array(
					array(
						'id'    => self::BOT_ID,
						'login' => 'merch_bot',
					),
				),
			)
		);

		self::assertStringEndsWith( 'twitch=bot_is_broadcaster', $this->oauth->finish( $query['state'] ?? '', 'code-3', '' ) );
		self::assertFalse( $this->channels->find( $orion )->connected() );
	}

	public function test_the_chat_subscription_uses_the_app_token_and_reuses_an_existing_one(): void {
		$this->set_up_bot();
		// A public HTTPS site (the test site is a local .test host).
		add_filter( 'rest_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://shop.example.com', $url ) );
		$nova = $this->creator( 'nova' );
		$this->channels->connect( $nova, '500', 'nova' );

		$this->answer(
			self::SUBSCRIBE_URL,
			202,
			array(
				'data' => array(
					array(
						'id'     => 'sub-1',
						'status' => 'webhook_callback_verification_pending',
					),
				),
			)
		);
		self::assertSame( 'webhook_callback_verification_pending', $this->subscriptions->subscribe( $this->channels->find( $nova ) ) );

		$request = end( $this->requests );
		self::assertIsArray( $request );
		self::assertSame( 'Bearer ' . self::APP_TOKEN, $request['headers']['Authorization'] ?? null, 'The app access token (chat identity, ADR-066).' );
		self::assertIsArray( $request['body'] );
		self::assertSame( 'channel.chat.message', $request['body']['type'] ?? null );
		self::assertSame(
			array(
				'broadcaster_user_id' => '500',
				'user_id'             => self::BOT_ID,
			),
			$request['body']['condition'] ?? null
		);
		self::assertSame( TwitchAccount::webhook_url(), $request['body']['transport']['callback'] ?? null );
		self::assertStringStartsWith( 'https://', TwitchAccount::webhook_url() );
		self::assertSame( $this->account->webhook_secret(), $request['body']['transport']['secret'] ?? null );
		self::assertSame( 'sub-1', $this->channels->find( $nova )->subscription_id );

		// Already subscribed (409): the existing subscription is looked up.
		$this->answer( self::SUBSCRIBE_URL, 409, array( 'message' => 'subscription already exists' ) );
		$this->answer(
			self::SUBSCRIPTIONS_URL,
			200,
			array(
				'data' => array(
					array(
						'id'        => 'sub-other',
						'status'    => 'enabled',
						'condition' => array( 'broadcaster_user_id' => '501' ),
					),
					array(
						'id'        => 'sub-1',
						'status'    => 'enabled',
						'condition' => array( 'broadcaster_user_id' => '500' ),
					),
				),
			)
		);
		self::assertSame( 1, $this->subscriptions->subscribe_all() );
		self::assertSame( array( 'sub-1', 'enabled' ), array( $this->channels->find( $nova )->subscription_id, $this->channels->find( $nova )->subscription_status ) );

		// Without `channel:bot` Twitch refuses it.
		$this->answer( self::SUBSCRIBE_URL, 403, array( 'message' => 'forbidden' ) );
		self::assertSame( TwitchException::FORBIDDEN, $this->subscriptions->subscribe( $this->channels->find( $nova ) ) );
	}

	public function test_one_message_per_order_line_with_the_app_token_never_twice(): void {
		$nova = $this->connected_creator( 'nova' );
		$this->alert( $nova, 100, 1, 'Hoodie' );
		$this->alert( $nova, 100, 2, 'Mug' );
		$this->answer(
			self::MESSAGES_URL,
			200,
			array(
				'data' => array(
					array(
						'message_id' => 'm',
						'is_sent'    => true,
					),
				),
			),
			array(
				'ratelimit-limit'     => '20',
				'ratelimit-remaining' => '19',
				'ratelimit-reset'     => (string) ( time() + 30 ),
			)
		);

		$this->sender->order_paid( 100 );
		$this->sender->order_paid( '100' );
		self::assertCount( 1, $this->queue->pending, 'One job per creator and order.' );

		$this->run_jobs();

		$sent = $this->sent();
		self::assertSame( array( '🔔 nova_fan just bought Hoodie!', '🔔 nova_fan just bought Mug!' ), array_column( $sent, 'message' ) );
		self::assertSame( array( '500', self::BOT_ID ), array( $sent[0]['broadcaster_id'], $sent[0]['sender_id'] ) );
		self::assertSame( 'Bearer ' . self::APP_TOKEN, $this->requests_to( self::MESSAGES_URL )[0]['headers']['Authorization'] ?? null, 'Sent with the app access token: the chatbot badge.' );
		self::assertSame( 1, $this->queue->queued[1]['delay'] ?? null, 'The next line follows a second later.' );
		self::assertSame(
			array(
				'limit'     => 20,
				'remaining' => 19,
			),
			array_intersect_key( $this->account->rate_limit(), array_flip( array( 'limit', 'remaining' ) ) )
		);

		// Paid again (processing → completed): nothing twice.
		$this->sender->order_paid( 100 );
		$this->run_jobs();
		self::assertCount( 2, $this->sent() );

		// Resent from the history: only the chosen line.
		self::assertTrue( $this->sender->resend( $nova, 100, 2 ) );
		$this->run_jobs();
		$sent = $this->sent();
		self::assertCount( 3, $sent );
		self::assertSame( '🔔 nova_fan just bought Mug!', $sent[2]['message'] ?? null );
	}

	public function test_one_message_per_order_in_the_administrators_format(): void {
		$nova = $this->connected_creator( 'nova' );
		self::assertTrue( $this->format->save( 'order', '$Quantity× $Product', ' + ' ) );
		$this->channels->save_settings( $nova, true, 'Thanks $Name for $Products ($ItemCount)$Amount' );
		$this->alert( $nova, 200, 1, 'Hoodie' );
		$this->alert( $nova, 200, 2, '$UserName Mug' );
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );

		$this->sender->order_paid( 200 );
		$this->run_jobs();

		self::assertSame( array( 'Thanks nova_fan for 1× Hoodie + 1× $UserName Mug (2)' ), array_column( $this->sent(), 'message' ), 'A product name is never read as a variable; $Amount is never shown.' );
		self::assertSame( 'alert_chatbot_format_changed', array_column( $this->rows( Tables::AUDIT_LOG ), 'action' )[2] ?? null );
	}

	public function test_the_rate_limit_is_waited_for_and_failures_are_retried_a_few_times(): void {
		$nova = $this->connected_creator( 'nova' );
		$this->alert( $nova, 300, 1, 'Hoodie' );
		$reset = time() + 20;
		$this->answer(
			self::MESSAGES_URL,
			429,
			array( 'message' => 'Too Many Requests' ),
			array(
				'ratelimit-limit'     => '20',
				'ratelimit-remaining' => '0',
				'ratelimit-reset'     => (string) $reset,
			)
		);

		$this->sender->order_paid( 300 );
		$this->run_jobs( 1 );

		self::assertSame( 'waiting', $this->log->latest( $nova )['status'] ?? null );
		self::assertEqualsWithDelta( $reset - time() + 1, $this->queue->pending[0]['delay'] ?? 0, 1, 'Retried after Twitch\'s reset.' );
		self::assertGreaterThan( 0, $this->account->wait_seconds() );

		// While Twitch's window is used up, the job only waits.
		$this->run_jobs( 1 );
		self::assertCount( 1, $this->sent() );

		// A server error is tried again, at most MAX_ATTEMPTS times in all.
		$this->account->note_rate_limit( 20, 20, time() );
		$this->answer( self::MESSAGES_URL, 503, array() );
		$this->run_jobs();
		self::assertCount( ChatSender::MAX_ATTEMPTS, $this->sent() );
		self::assertSame( array( 'failed', 'failed' ), array( $this->log->latest( $nova )['status'] ?? null, $this->log->latest( $nova )['reason'] ?? null ) );
		self::assertSame( array(), $this->queue->pending );
	}

	public function test_dropped_messages_are_not_retried_and_a_refused_app_token_is_renewed_once(): void {
		$nova = $this->connected_creator( 'nova' );
		$this->alert( $nova, 400, 1, 'Hoodie' );
		$this->answer(
			self::MESSAGES_URL,
			200,
			array(
				'data' => array(
					array(
						'is_sent'     => false,
						'drop_reason' => array(
							'code'    => 'msg_duplicate',
							'message' => 'x',
						),
					),
				),
			)
		);

		$this->sender->order_paid( 400 );
		$this->run_jobs();
		self::assertSame( array( 'failed', 'msg_duplicate' ), array( $this->log->latest( $nova )['status'] ?? null, $this->log->latest( $nova )['reason'] ?? null ) );
		self::assertCount( 1, $this->sent() );

		$this->answer( self::MESSAGES_URL, 401, array( 'message' => 'invalid token' ) );
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );
		$this->answer(
			self::TOKEN_URL,
			200,
			array(
				'access_token' => 'apptoken0002',
				'expires_in'   => 5000000,
			)
		);

		self::assertSame( 'sent', $this->sender->send_now( '500', 'Hi' )['result'] );
		$requests = $this->requests_to( self::MESSAGES_URL );
		self::assertSame( 'Bearer apptoken0002', end( $requests )['headers']['Authorization'] ?? null );
		self::assertSame( 'apptoken0002', $this->account->app_token() );
	}

	public function test_nothing_is_sent_while_alerts_or_the_creators_messages_are_off(): void {
		$nova = $this->connected_creator( 'nova' );
		$this->alert( $nova, 500, 1, 'Hoodie' );
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );

		$this->channels->save_settings( $nova, false, '' );
		$this->sender->order_paid( 500 );
		$this->run_jobs();
		self::assertSame( array(), $this->sent() );
		self::assertFalse( $this->sender->resend( $nova, 500 ) );

		$this->channels->save_settings( $nova, true, '' );
		$this->enable_alerts( false );
		$this->sender->order_paid( 500 );
		self::assertSame( array(), $this->queue->pending, 'The kill switch stops the queue too.' );
	}

	public function test_the_webhook_accepts_only_signed_fresh_events_and_feeds_the_chat_window(): void {
		$nova = $this->connected_creator( 'nova' );
		$this->routes();

		// Twitch's verification: answered with the plain challenge.
		$response = $this->event( 'webhook_callback_verification', array( 'challenge' => 'pogchamp-kappa-360noscope-vohiyo' ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'pogchamp-kappa-360noscope-vohiyo', $response->get_data() );
		self::assertSame( 'enabled', $this->channels->find( $nova )->subscription_status );

		ob_start();
		$served = $this->twitch->serve_plain( false, $response, new WP_REST_Request( 'POST', '/merchboard/v1/alerts/twitch/eventsub' ) );
		self::assertTrue( $served );
		self::assertSame( 'pogchamp-kappa-360noscope-vohiyo', ob_get_clean() );

		// Chat messages, each stored once.
		$message = array(
			'message_id'         => 'msg-a',
			'chatter_user_login' => 'viewer1',
			'chatter_user_name'  => 'Viewer1',
			'color'              => '#FF4500',
			'message'            => array( 'text' => '<b>hype</b>' ),
		);
		self::assertSame( 204, $this->event( 'notification', array( 'event' => $message ) )->get_status() );
		self::assertSame( 204, $this->event( 'notification', array( 'event' => $message ), 'delivery-2' )->get_status() );
		self::assertSame(
			204,
			$this->event(
				'notification',
				array(
					'event' => array(
						'message_id' => 'msg-b',
						'color'      => 'red',
						'message'    => array( 'text' => 'second' ),
					),
				)
			)->get_status()
		);

		$this->as_admin();
		$chat = $this->call( 'GET', '/merchboard/v1/alerts/twitch/chat/' . $nova );
		self::assertSame( 200, $chat->get_status() );
		$messages = $chat->get_data()['messages'];
		self::assertSame( array( '<b>hype</b>', 'second' ), array_column( $messages, 'text' ), 'Stored as text; the window inserts it as text.' );
		self::assertSame( array( '#ff4500', '' ), array_column( $messages, 'color' ) );
		self::assertSame( array( 'second' ), array_column( $this->call( 'GET', '/merchboard/v1/alerts/twitch/chat/' . $nova, array( 'after' => $messages[0]['id'] ) )->get_data()['messages'], 'text' ) );

		// Forged, stale or unsigned events are refused.
		self::assertSame( 403, $this->event( 'notification', array( 'event' => $message ), 'x', gmdate( 'Y-m-d\TH:i:s\Z', time() - 700 ) )->get_status() );
		self::assertSame( 403, $this->event( 'notification', array( 'event' => $message ), 'x', null, 'wrong-secret-0123456789' )->get_status() );
		self::assertSame( 2, $this->count_rows( Tables::ALERT_CHAT_LOG ) );

		// Revocation (the creator removed the bot at Twitch).
		self::assertSame( 204, $this->event( 'revocation', array(), 'r', null, null, 'authorization_revoked' )->get_status() );
		self::assertSame( 'authorization_revoked', $this->channels->find( $nova )->subscription_status );

		// Only alert administrators read the chat.
		wp_set_current_user( $this->create_user( 'subscriber_only' ) );
		self::assertSame( 403, $this->call( 'GET', '/merchboard/v1/alerts/twitch/chat/' . $nova )->get_status() );
	}

	public function test_the_chat_log_keeps_only_a_day_and_two_hundred_messages_per_channel(): void {
		for ( $i = 1; $i <= ChatLogRepository::KEEP_PER_CHANNEL + 5; $i++ ) {
			$this->chat->add( '500', 'id-' . $i, 'viewer', 'Viewer', '', 'message ' . $i );
		}

		$this->chat->add( '501', 'other-1', 'viewer', 'Viewer', '', 'other channel' );
		self::assertSame( ChatLogRepository::KEEP_PER_CHANNEL, count( $this->chat->after( '500', 0, 500 ) ) );
		self::assertSame( 'message 6', $this->chat->after( '500', 0, 500 )[0]['text'] );

		$this->execute( 'UPDATE %i SET created_at = %s WHERE broadcaster_id = %s', $this->table( Tables::ALERT_CHAT_LOG ), gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ), '501' );
		$this->chat->add( '500', 'id-new', 'viewer', 'Viewer', '', 'new' );
		self::assertSame( array(), $this->chat->after( '501', 0 ), 'Older than 24 hours.' );
	}

	public function test_the_creator_manages_the_chatbot_only_for_the_own_channel(): void {
		$this->routes();
		$nova = $this->creator( 'nova' );

		$state = $this->call( 'GET', '/merchboard/v1/me/alerts/chatbot' );
		self::assertSame( 200, $state->get_status() );
		self::assertFalse( $state->get_data()['available'] );
		self::assertSame( 409, $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/connect' )->get_status() );

		$this->set_up_bot( $nova );
		$connect = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/connect' );
		self::assertSame( 200, $connect->get_status() );
		self::assertSame( 'channel:bot', $this->query( $connect->get_data()['url'] )['scope'] ?? null );

		$this->channels->connect( $nova, '500', 'nova' );
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );
		$test = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/test' );
		self::assertSame(
			array(
				'result'  => 'sent',
				'reason'  => '',
				'message' => '🔔 TestFan just bought Test product!',
			),
			$test->get_data()
		);

		$saved = $this->call(
			'PATCH',
			'/merchboard/v1/me/alerts/chatbot',
			array(
				'enabled'  => false,
				'template' => "<b>Tak</b>  \$Name\n for \$Product!",
			)
		);
		self::assertSame( 200, $saved->get_status() );
		self::assertSame( array( false, 'Tak $Name for $Product!' ), array( $saved->get_data()['enabled'], $saved->get_data()['template'] ) );
		self::assertSame( array( 'login' => 'nova' ), array_intersect_key( (array) $saved->get_data()['channel'], array( 'login' => true ) ) );
		self::assertSame( 400, $this->call( 'PATCH', '/merchboard/v1/me/alerts/chatbot', array( 'enabled' => 'yes' ) )->get_status() );

		$removed = $this->call( 'DELETE', '/merchboard/v1/me/alerts/chatbot/channel' );
		self::assertNull( $removed->get_data()['channel'] );
		self::assertContains( 'alert_chatbot_channel_disconnected', array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );
		self::assertSame( 409, $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/test' )->get_status() );

		wp_set_current_user( $this->create_user( 'subscriber_only' ) );
		self::assertSame( 403, $this->call( 'GET', '/merchboard/v1/me/alerts/chatbot' )->get_status() );
	}

	public function test_the_administrator_sends_as_the_bot_and_sees_the_channels(): void {
		$nova    = $this->connected_creator( 'nova' );
		$actions = new ChatbotAdminActions( $this->account, $this->oauth, $this->format, $this->subscriptions, $this->channels, $this->sender, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( Logger::class ) );
		$this->as_admin();
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );

		$url = $actions->process(
			array(
				'action'   => ChatbotAdminActions::SEND_ACTION,
				'creator'  => (string) $nova,
				'message'  => 'Hello chat',
				'_wpnonce' => wp_create_nonce( ChatbotAdminActions::nonce_action( ChatbotAdminActions::SEND_ACTION, $nova ) ),
			)
		);
		self::assertStringContainsString( 'merchboard_alerts_admin_result=message_sent', $url );
		self::assertSame( array( 'Hello chat' ), array_column( $this->sent(), 'message' ) );
		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( 'alert_chatbot_message_sent', end( $audit )['action'] ?? null );
		self::assertStringNotContainsString( 'Hello chat', (string) wp_json_encode( $audit ), 'The text is not audited.' );

		$invalid = $actions->process(
			array(
				'action'    => ChatbotAdminActions::APP_ACTION,
				'client_id' => 'x',
				'secret'    => 'y',
				'_wpnonce'  => wp_create_nonce( ChatbotAdminActions::nonce_action( ChatbotAdminActions::APP_ACTION ) ),
			)
		);
		self::assertStringContainsString( 'merchboard_alerts_admin_result=app_invalid_credentials', $invalid );

		// A stand-in build, so the test does not depend on `npm run build` (CI runs without it).
		$plugin = trailingslashit( get_temp_dir() ) . 'merchboard-chatbot-test-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $plugin . 'build' );
		file_put_contents( $plugin . 'build/alerts-admin.js', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $plugin . 'build/alerts-admin.asset.php', "<?php return array( 'dependencies' => array(), 'version' => 'test' );" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$tab = new ChatbotAdminTab( $this->account, $this->format, $this->channels, $this->log, new CreatorRepository( $this->wpdb ), $plugin, 'https://shop.example.com/wp-content/plugins/merchboard/' );
		ob_start();
		$tab->render( 0 );
		$page = (string) ob_get_clean();
		self::assertStringContainsString( esc_html( TwitchAccount::redirect_uri() ), $page );
		self::assertStringContainsString( 'merch_bot', $page );
		self::assertStringContainsString( 'nova', $page );

		ob_start();
		$tab->render( $nova );
		$chat = (string) ob_get_clean();
		self::assertStringContainsString( 'https://www.twitch.tv/embed/nova/chat?parent=', $chat, 'Without webhooks: Twitch\'s embedded chat.' );
		self::assertStringContainsString( 'https://www.twitch.tv/popout/nova/chat', $chat );
		self::assertStringNotContainsString( 'data-merchboard-chat', $chat );

		$this->channels->set_subscription( $nova, 'sub-1', 'enabled' );
		ob_start();
		$tab->render( $nova );
		self::assertStringContainsString( 'data-merchboard-chat', (string) ob_get_clean(), 'With the subscription: our own window.' );
		self::assertTrue( wp_script_is( 'merchboard-alerts-admin', 'enqueued' ) );

		// Without the built script the page falls back to Twitch's chat.
		wp_delete_file( $plugin . 'build/alerts-admin.js' );
		wp_dequeue_script( 'merchboard-alerts-admin' );
		ob_start();
		$tab->render( $nova );
		self::assertStringNotContainsString( 'data-merchboard-chat', (string) ob_get_clean() );
		wp_delete_file( $plugin . 'build/alerts-admin.asset.php' );
		rmdir( $plugin . 'build' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
		rmdir( $plugin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
	}

	/**
	 * The fake Twitch: records each request and answers from $answers.
	 *
	 * @return Closure(string, string, array<string, string>, string|null): array{status: int, headers: array<string, string>, body: string}
	 */
	private function transport(): Closure {
		return function ( string $method, string $url, array $headers, ?string $body ): array {
			$decoded = null === $body ? null : json_decode( $body, true );

			if ( null !== $body && ! is_array( $decoded ) ) {
				parse_str( $body, $decoded );
			}

			$this->requests[] = array(
				'method'  => $method,
				'url'     => $url,
				'headers' => $headers,
				'body'    => $decoded,
			);
			$key              = $method . ' ' . strtok( $url, '?' );
			$next             = array() === ( $this->answers[ $key ] ?? array() ) ? null : array_shift( $this->answers[ $key ] );

			if ( null !== $next ) {
				$this->last[ $key ] = $next;
			}

			return $this->last[ $key ] ?? array(
				'status'  => 0,
				'headers' => array(),
				'body'    => '',
			);
		};
	}

	/**
	 * Queues Twitch's answer to a request.
	 *
	 * @param string                $key     "METHOD URL".
	 * @param int                   $status  HTTP status.
	 * @param array<mixed>          $body    JSON body.
	 * @param array<string, string> $headers Lowercase headers.
	 */
	private function answer( string $key, int $status, array $body, array $headers = array() ): void {
		$this->answers[ $key ][] = array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * The requests sent to a URL.
	 *
	 * @param string $key "METHOD URL".
	 * @return list<array{method: string, url: string, headers: array<string, string>, body: array<mixed>|string|null}>
	 */
	private function requests_to( string $key ): array {
		return array_values( array_filter( $this->requests, static fn( array $request ): bool => $request['method'] . ' ' . strtok( $request['url'], '?' ) === $key ) );
	}

	/**
	 * The chat messages sent (Send Chat Message bodies).
	 *
	 * @return list<array<mixed>>
	 */
	private function sent(): array {
		return array_map( static fn( array $request ): array => (array) $request['body'], $this->requests_to( self::MESSAGES_URL ) );
	}

	/**
	 * Runs queued chat jobs.
	 *
	 * @param int $limit Jobs at most.
	 */
	private function run_jobs( int $limit = 20 ): void {
		$run = function ( string $hook, array $args ): void {
			self::assertSame( ChatSender::HOOK, $hook );
			$this->sender->run( ...$args );
		};

		// Each job may queue the next.
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( ! $this->queue->run_next( $run ) ) {
				return;
			}
		}
	}

	/**
	 * Sets the Twitch app up (as an administrator).
	 */
	private function set_up_app(): void {
		$current = get_current_user_id();
		$this->as_admin();
		$this->answer(
			self::TOKEN_URL,
			200,
			array(
				'access_token' => self::APP_TOKEN,
				'expires_in'   => 5000000,
			)
		);
		$this->account->save_app( self::CLIENT_ID, self::SECRET );
		wp_set_current_user( $current );
	}

	/**
	 * Sets the app and the bot up; afterwards the given user is current.
	 *
	 * @param int $creator_id Creator whose user is current afterwards; 0 for the administrator.
	 */
	private function set_up_bot( int $creator_id = 0 ): void {
		$this->set_up_app();
		$this->as_admin();
		$this->account->connect_bot( self::BOT_ID, 'merch_bot' );

		if ( 0 !== $creator_id ) {
			wp_set_current_user( $this->user_of( $creator_id ) );
		}
	}

	/**
	 * A creator whose channel has the bot.
	 *
	 * @param string $login Login.
	 */
	private function connected_creator( string $login ): int {
		$this->set_up_bot();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( ucfirst( $login ), CreatorStatus::Active )->id;
		$this->channels->connect( $creator, '500', $login );

		return $creator;
	}

	/**
	 * A logged-in active creator.
	 *
	 * @param string $login Login.
	 */
	private function creator( string $login ): int {
		$user = $this->create_user( $login );
		$this->as_admin();
		$creator = $this->service( CreatorService::class )->create( ucfirst( $login ), CreatorStatus::Active, $user )->id;
		wp_set_current_user( $user );

		return $creator;
	}

	/**
	 * The user of a creator.
	 *
	 * @param int $creator_id Creator.
	 */
	private function user_of( int $creator_id ): int {
		return (int) ( new CreatorRepository( $this->wpdb ) )->find( $creator_id )?->user_id;
	}

	/**
	 * The administrator is the current user.
	 */
	private function as_admin(): void {
		wp_set_current_user( 1 );
	}

	/**
	 * Switches alerts on or off.
	 *
	 * @param bool $enabled Whether alerts are on.
	 */
	private function enable_alerts( bool $enabled = true ): void {
		$settings                             = Settings::all();
		$settings[ Settings::ALERTS_ENABLED ] = $enabled;
		Settings::save( $settings );
	}

	/**
	 * Stores an alert of an order line.
	 *
	 * @param int    $creator_id Creator.
	 * @param int    $order_id   Order.
	 * @param int    $item_id    Order line.
	 * @param string $product    Product name.
	 */
	private function alert( int $creator_id, int $order_id, int $item_id, string $product ): void {
		$this->events->insert( new AlertEvent( $creator_id, $order_id, (string) ( 1000 + $order_id ), $item_id, 70, 0, $product, '', 1, 0, array(), 'nova_fan', new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) );
	}

	/**
	 * The query arguments of a URL.
	 *
	 * @param string $url URL.
	 * @return array<string, string>
	 */
	private function query( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$strings = array();

		foreach ( $query as $name => $value ) {
			if ( is_string( $value ) ) {
				$strings[ (string) $name ] = $value;
			}
		}

		return $strings;
	}

	/**
	 * Posts a signed EventSub message to the webhook.
	 *
	 * @param string       $type      Message type.
	 * @param array<mixed> $payload   Payload besides the subscription.
	 * @param string       $id        Message id.
	 * @param string|null  $timestamp Timestamp; null for now (with nanoseconds, like Twitch).
	 * @param string|null  $secret    Signing secret; null for the real one.
	 * @param string       $status    Subscription status.
	 */
	private function event( string $type, array $payload, string $id = 'delivery-1', ?string $timestamp = null, ?string $secret = null, string $status = 'enabled' ): WP_REST_Response {
		$timestamp ??= gmdate( 'Y-m-d\TH:i:s' ) . '.123456789Z';
		$body        = (string) wp_json_encode(
			array_merge(
				array(
					'subscription' => array(
						'id'        => 'sub-1',
						'type'      => 'channel.chat.message',
						'status'    => $status,
						'condition' => array(
							'broadcaster_user_id' => '500',
							'user_id'             => self::BOT_ID,
						),
					),
				),
				$payload
			)
		);
		$request     = new WP_REST_Request( 'POST', '/merchboard/v1/alerts/twitch/eventsub' );
		$request->set_header( 'Twitch-Eventsub-Message-Id', $id );
		$request->set_header( 'Twitch-Eventsub-Message-Timestamp', $timestamp );
		$request->set_header( 'Twitch-Eventsub-Message-Type', $type );
		$request->set_header( 'Twitch-Eventsub-Message-Signature', 'sha256=' . hash_hmac( 'sha256', $id . $timestamp . $body, $secret ?? $this->account->webhook_secret() ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );

		return rest_do_request( $request );
	}

	/**
	 * Registers the chatbot routes on a fresh REST server.
	 */
	private function routes(): void {
		$logger         = $this->service( Logger::class );
		$creator        = new CreatorChatbot( $this->account, $this->channels, $this->log, $this->format, new ChatMessages( new AlertTemplate() ), $this->subscriptions, $this->sender, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$me             = new MeChatbotController( $this->service( CreatorContext::class ), $creator, $this->oauth, $logger );
		$twitch         = $this->twitch;
		$this->register = static function () use ( $me, $twitch ): void {
			$me->register_routes();
			$twitch->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	/**
	 * Dispatches a request; GET arguments go to the query.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $body   JSON body or query.
	 */
	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} elseif ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}
}
