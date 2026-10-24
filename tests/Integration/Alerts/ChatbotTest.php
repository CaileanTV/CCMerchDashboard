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
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;
use MerchBoard\Modules\Alerts\Admin\ChatbotAdminActions;
use MerchBoard\Modules\Alerts\Admin\ChatbotAdminTab;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertMoney;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotException;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\ChatLogRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessageRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessages;
use MerchBoard\Modules\Alerts\Chatbot\ChatSender;
use MerchBoard\Modules\Alerts\Chatbot\ChatSubscriptions;
use MerchBoard\Modules\Alerts\Chatbot\CreatorChatbot;
use MerchBoard\Modules\Alerts\Chatbot\CreatorTokens;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchApi;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatforms;
use MerchBoard\Modules\Alerts\Chatbot\KickAccount;
use MerchBoard\Modules\Alerts\Chatbot\KickApi;
use MerchBoard\Modules\Alerts\Chatbot\KickException;
use MerchBoard\Modules\Alerts\Chatbot\KickOAuth;
use MerchBoard\Modules\Alerts\Chatbot\KickPlatform;
use MerchBoard\Modules\Alerts\Chatbot\KickWebhookVerifier;
use MerchBoard\Modules\Alerts\Chatbot\TwitchPlatform;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeAccount;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeApi;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeOAuth;
use MerchBoard\Modules\Alerts\Chatbot\YouTubePlatform;
use MerchBoard\Modules\Alerts\Chatbot\TwitchException;
use MerchBoard\Modules\Alerts\Chatbot\TwitchOAuth;
use MerchBoard\Modules\Alerts\Admin\AlertsSettingsActions;
use MerchBoard\Modules\Alerts\Admin\CompanyDetailsSection;
use MerchBoard\Modules\Alerts\AlertSettings;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\Legal\CompanyDetails;
use MerchBoard\Modules\Alerts\Legal\LegalPage;
use MerchBoard\Modules\Alerts\Legal\YouTubeLegal;
use MerchBoard\Modules\Alerts\Legal\YouTubeLegalDefaults;
use MerchBoard\Modules\Alerts\Legal\YouTubeTerms;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Rest\ChatbotChatController;
use MerchBoard\Modules\Alerts\Rest\KickController;
use MerchBoard\Modules\Alerts\Rest\MeChatbotController;
use MerchBoard\Modules\Alerts\Rest\TwitchController;
use MerchBoard\Modules\Alerts\Rest\YouTubeController;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use OpenSSLAsymmetricKey;
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
#[CoversClass( ChatCipher::class )]
#[CoversClass( TwitchPlatform::class )]
#[CoversClass( KickApi::class )]
#[CoversClass( KickAccount::class )]
#[CoversClass( CreatorTokens::class )]
#[CoversClass( KickPlatform::class )]
#[CoversClass( KickOAuth::class )]
#[CoversClass( KickWebhookVerifier::class )]
#[CoversClass( KickController::class )]
#[CoversClass( ChatbotChatController::class )]
#[CoversClass( YouTubeApi::class )]
#[CoversClass( YouTubeAccount::class )]
#[CoversClass( YouTubePlatform::class )]
#[CoversClass( YouTubeOAuth::class )]
#[CoversClass( YouTubeController::class )]
#[CoversClass( YouTubeLegal::class )]
#[CoversClass( YouTubeTerms::class )]
#[CoversClass( LegalPage::class )]
#[CoversClass( CompanyDetails::class )]
#[CoversClass( CompanyDetailsSection::class )]
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

	private const KICK_CLIENT   = '01JABCDEFGHJKMNPQRSTVWXYZ0';
	private const KICK_SECRET   = 'kicksecret0123456789abcdef';
	private const KICK_TOKEN    = 'POST https://id.kick.com/oauth/token';
	private const KICK_CHANNELS = 'GET https://api.kick.com/public/v1/channels';
	private const KICK_CHAT     = 'POST https://api.kick.com/public/v1/chat';
	private const KICK_KEY      = 'GET https://api.kick.com/public/v1/public-key';
	private const KICK_REVOKE   = 'POST https://id.kick.com/oauth/revoke';

	private const YT_CLIENT     = '123456789012-abcdefghij0123456789abcdefghij01.apps.googleusercontent.com';
	private const YT_SECRET     = 'GOCSPX-testsecret0123456789';
	private const YT_TOKEN      = 'POST https://oauth2.googleapis.com/token';
	private const YT_REVOKE     = 'POST https://oauth2.googleapis.com/revoke';
	private const YT_CHANNELS   = 'GET https://www.googleapis.com/youtube/v3/channels';
	private const YT_BROADCASTS = 'GET https://www.googleapis.com/youtube/v3/liveBroadcasts';
	private const YT_MESSAGES   = 'POST https://www.googleapis.com/youtube/v3/liveChat/messages';
	private const YT_BOT        = 'UCbotbotbotbotbotbotbot1';
	private const YT_CREATOR    = 'UCnovanovanovanovanova12';

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

	private KickApi $kick_api;

	private KickAccount $kick;

	private CreatorTokens $kick_tokens;

	private KickOAuth $kick_oauth;

	private ChatPlatforms $platforms;

	private YouTubeApi $youtube_api;

	private YouTubeAccount $youtube;

	private CreatorTokens $youtube_tokens;

	private YouTubePlatform $youtube_platform;

	private YouTubeOAuth $youtube_oauth;

	private YouTubeLegal $legal;

	private YouTubeTerms $youtube_terms;

	/**
	 * Registers the routes on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	/**
	 * The browser of the current user is logged in (the OAuth callback must
	 * come back in the browser that started it, 25.13 review).
	 */
	private function logged_in(): void {
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( get_current_user_id(), time() + HOUR_IN_SECONDS, 'logged_in' );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->enable_alerts();

		$audit       = $this->service( AuditTrail::class );
		$transaction = $this->service( Transaction::class );
		$logger      = $this->service( Logger::class );

		$this->api              = new TwitchApi( $this->transport() );
		$this->account          = new TwitchAccount( $this->api, new ChatCipher( ChatPlatform::Twitch ), $audit, $transaction );
		$this->channels         = new ChatbotChannelRepository( $this->wpdb );
		$this->log              = new ChatMessageRepository( $this->wpdb );
		$this->chat             = new ChatLogRepository( $this->wpdb );
		$this->format           = new ChatbotFormat( $audit, $transaction );
		$this->events           = new AlertEventRepository( $this->wpdb );
		$this->queue            = new FakeJobQueue();
		$this->kick_api         = new KickApi( $this->transport() );
		$this->kick             = new KickAccount( $this->kick_api, new ChatCipher( ChatPlatform::Kick ), $audit, $transaction );
		$kick_api               = $this->kick_api;
		$kick                   = $this->kick;
		$this->kick_tokens      = new CreatorTokens(
			ChatPlatform::Kick,
			new ChatCipher( ChatPlatform::Kick ),
			$this->channels,
			static fn( string $refresh ): array => $kick_api->refresh( $kick->client_id(), $kick->require_secret(), $refresh ),
			static function ( string $token, string $hint ) use ( $kick_api ): void {
				$kick_api->revoke( $token, $hint );
			}
		);
		$this->youtube_api      = new YouTubeApi( $this->transport() );
		$this->youtube          = new YouTubeAccount( $this->youtube_api, new ChatCipher( ChatPlatform::YouTube ), $audit, $transaction );
		$youtube_api            = $this->youtube_api;
		$youtube                = $this->youtube;
		$this->youtube_tokens   = new CreatorTokens(
			ChatPlatform::YouTube,
			new ChatCipher( ChatPlatform::YouTube ),
			$this->channels,
			static fn( string $refresh ): array => $youtube_api->refresh( $youtube->client_id(), $youtube->require_secret(), $refresh ),
			static function ( string $token ) use ( $youtube_api ): void {
				$youtube_api->revoke( $token );
			}
		);
		$this->legal            = new YouTubeLegal( $audit, $transaction, static fn( string $path ): string => home_url( '/creator-dashboard/' . $path . '/' ) );
		$this->youtube_terms    = new YouTubeTerms( $this->legal, $this->youtube, new CreatorRepository( $this->wpdb ), $audit, $transaction );
		$this->youtube_platform = new YouTubePlatform( $this->youtube, $this->youtube_api, $this->youtube_tokens, $this->channels, $this->youtube_terms, $logger );
		$this->platforms        = new ChatPlatforms( new TwitchPlatform( $this->account, $this->api ), new KickPlatform( $this->kick, $this->kick_api, $this->kick_tokens, $this->channels ), $this->youtube_platform );
		$this->subscriptions    = new ChatSubscriptions( $this->platforms, $this->channels, $this->chat );
		$this->sender           = new ChatSender( $this->platforms, $this->channels, $this->log, $this->format, new ChatMessages( new AlertTemplate() ), $this->events, new AlertsAvailability( static fn(): bool => false ), $this->queue, $logger );
		$this->oauth            = new TwitchOAuth( $this->account, $this->api, $this->channels, $this->subscriptions, $audit, $transaction, static fn( string $path ): string => home_url( '/creator-dashboard/' . $path . '/' ) );
		$this->twitch           = new TwitchController( $this->oauth, $this->account, $this->channels, $this->chat, $logger );
		$this->kick_oauth       = new KickOAuth( $this->kick, $this->kick_api, $this->kick_tokens, $this->channels, $this->subscriptions, $audit, $transaction, static fn( string $path ): string => home_url( '/creator-dashboard/' . $path . '/' ), $logger );
		$this->youtube_oauth    = new YouTubeOAuth( $this->youtube, $this->youtube_api, $this->youtube_tokens, $this->channels, $this->subscriptions, $audit, $transaction, static fn( string $path ): string => home_url( '/creator-dashboard/' . $path . '/' ), $logger, $this->youtube_terms );
	}

	protected function tearDown(): void {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		remove_all_filters( 'rest_pre_serve_request' );
		remove_all_filters( 'rest_url' );
		delete_option( TwitchAccount::OPTION );
		delete_option( KickAccount::OPTION );
		delete_option( YouTubeAccount::OPTION );
		delete_option( YouTubeLegal::OPTION );
		delete_metadata( 'user', 0, YouTubeTerms::META, '', true );
		$this->execute( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $this->wpdb->options, '%merchboard_youtube_%', '%merchboard_chat_flushed_%' );
		delete_transient( KickWebhookVerifier::KEY_TRANSIENT );
		delete_transient( 'merchboard_kick_public_key_refetch' );
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
		$url = $this->oauth->start_bot();
		$this->logged_in();
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
		$this->logged_in();
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

		self::assertSame(
			add_query_arg(
				array(
					'chatbot'  => 'connected',
					'platform' => 'twitch',
				),
				home_url( '/creator-dashboard/alerts/chatbot/' )
			),
			$location
		);
		$channel = $this->channels->find( $nova, ChatPlatform::Twitch );
		self::assertSame( array( '500', 'nova', ChatSubscriptions::NO_WEBHOOKS ), array( $channel->broadcaster_id, $channel->broadcaster_login, $channel->subscription_status ), 'The test site is not reachable over HTTPS.' );
		self::assertContains( 'alert_chatbot_channel_connected', array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );
		self::assertStringNotContainsString( 'usertoken-nova', (string) wp_json_encode( $this->rows( Tables::ALERT_CHATBOT ) ) );

		// Refused at Twitch.
		wp_set_current_user( $this->user_of( $nova ) );
		$denied = $this->query( $this->oauth->start_channel( $nova ) );
		$this->logged_in();
		self::assertStringContainsString( 'chatbot=denied&platform=twitch', $this->oauth->finish( $denied['state'] ?? '', '', 'access_denied' ) );

		// Nova's link finished in another login (e.g. sent to another streamer) connects nothing (25.13 review).
		$sent = $this->query( $this->oauth->start_channel( $nova ) );
		wp_set_current_user( $this->create_user( 'other_streamer' ) );
		$this->logged_in();
		self::assertStringContainsString( 'chatbot=denied&platform=twitch', $this->oauth->finish( $sent['state'] ?? '', 'code-x', '' ) );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( $this->user_of( $nova ) );
		$again = $this->query( $this->oauth->start_channel( $nova ) );
		self::assertStringContainsString( 'chatbot=denied&platform=twitch', $this->oauth->finish( $again['state'] ?? '', 'code-y', '' ), 'Not logged in at all.' );
	}

	public function test_the_bot_cannot_be_added_to_its_own_channel(): void {
		$this->set_up_bot();
		$orion = $this->creator( 'orion' );
		$query = $this->query( $this->oauth->start_channel( $orion ) );
		$this->logged_in();

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

		self::assertStringContainsString( 'chatbot=bot_is_broadcaster&platform=twitch', $this->oauth->finish( $query['state'] ?? '', 'code-3', '' ) );
		self::assertFalse( $this->channels->find( $orion, ChatPlatform::Twitch )->connected() );
	}

	public function test_the_chat_subscription_uses_the_app_token_and_reuses_an_existing_one(): void {
		$this->set_up_bot();
		// A public HTTPS site (the test site is a local .test host).
		add_filter( 'rest_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://shop.example.com', $url ) );
		$nova = $this->creator( 'nova' );
		$this->channels->connect( $nova, ChatPlatform::Twitch, '500', 'nova' );

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
		self::assertSame( 'webhook_callback_verification_pending', $this->subscriptions->subscribe( $this->channels->find( $nova, ChatPlatform::Twitch ) ) );

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
		self::assertSame( 'sub-1', $this->channels->find( $nova, ChatPlatform::Twitch )->subscription_id );

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
		self::assertSame( array( 'sub-1', 'enabled' ), array( $this->channels->find( $nova, ChatPlatform::Twitch )->subscription_id, $this->channels->find( $nova, ChatPlatform::Twitch )->subscription_status ) );

		// Without `channel:bot` Twitch refuses it.
		$this->answer( self::SUBSCRIBE_URL, 403, array( 'message' => 'forbidden' ) );
		self::assertSame( TwitchException::FORBIDDEN, $this->subscriptions->subscribe( $this->channels->find( $nova, ChatPlatform::Twitch ) ) );
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
		self::assertTrue( $this->format->save( 'order', '$Quantity× $Product ($LineTotal)', ' + ' ) );
		$this->channels->save_template( $nova, 'Thanks $Name for $Products ($ItemCount) $Amount$OrderNumber' );
		$this->alert( $nova, 200, 1, 'Hoodie', '249.0000', '328.0000' );
		$this->alert( $nova, 200, 2, '$UserName Mug', '79.0000', '328.0000' );
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );

		$this->sender->order_paid( 200 );
		$this->run_jobs();

		$money = static fn( string $amount ): string => AlertMoney::format( $amount, 'DKK' );
		self::assertSame( array( 'Thanks nova_fan for 1× Hoodie (' . $money( '249.0000' ) . ') + 1× $UserName Mug (' . $money( '79.0000' ) . ') (2) ' . $money( '328.0000' ) ), array_column( $this->sent(), 'message' ), 'A product name is never read as a variable; $Amount is the creator total; $OrderNumber shows nothing.' );
		self::assertSame( 'alert_chatbot_format_changed', array_column( $this->rows( Tables::AUDIT_LOG ), 'action' )[2] ?? null );

		// A message the channel dropped is finished: a later job of the order sends nothing again (25.13 review).
		$this->alert( $nova, 201, 3, 'Cap' );
		$this->answer(
			self::MESSAGES_URL,
			200,
			array(
				'data' => array(
					array(
						'is_sent'     => false,
						'drop_reason' => array(
							'code'    => 'msg_rejected',
							'message' => 'x',
						),
					),
				),
			)
		);
		$this->sender->order_paid( 201 );
		$this->run_jobs();
		$this->sender->run( $nova, 201, 'twitch' );
		self::assertCount( 2, $this->sent() );
		self::assertSame( 'failed', $this->log->latest( $nova, ChatPlatform::Twitch )['status'] ?? null );
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

		self::assertSame( 'waiting', $this->log->latest( $nova, ChatPlatform::Twitch )['status'] ?? null );
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
		self::assertSame( array( 'failed', 'failed' ), array( $this->log->latest( $nova, ChatPlatform::Twitch )['status'] ?? null, $this->log->latest( $nova, ChatPlatform::Twitch )['reason'] ?? null ) );
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
		self::assertSame( array( 'failed', 'msg_duplicate' ), array( $this->log->latest( $nova, ChatPlatform::Twitch )['status'] ?? null, $this->log->latest( $nova, ChatPlatform::Twitch )['reason'] ?? null ) );
		self::assertCount( 1, $this->sent() );

		// A dropped line does not hold up the order's next line.
		$this->alert( $nova, 401, 11, 'Hoodie' );
		$this->alert( $nova, 401, 12, 'Cap' );
		$this->answer(
			self::MESSAGES_URL,
			200,
			array(
				'data' => array(
					array(
						'is_sent'     => false,
						'drop_reason' => array(
							'code'    => 'msg_rejected',
							'message' => 'x',
						),
					),
				),
			)
		);
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );
		$this->sender->order_paid( 401 );
		$this->run_jobs();
		self::assertSame( array( '🔔 nova_fan just bought Hoodie!', '🔔 nova_fan just bought Cap!' ), array_slice( array_column( $this->sent(), 'message' ), 1 ), 'Each line once.' );
		self::assertSame( array( 'failed', 'sent' ), array_column( array_values( array_filter( $this->rows( Tables::ALERT_CHAT_MESSAGES ), static fn( array $row ): bool => '401' === (string) $row['order_id'] ) ), 'status' ) );
		self::assertSame( array(), $this->queue->pending );

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

		self::assertSame( 'sent', $this->sender->send_now( $this->channels->find( $nova, ChatPlatform::Twitch ), 'Hi' )['result'] );
		$requests = $this->requests_to( self::MESSAGES_URL );
		self::assertSame( 'Bearer apptoken0002', end( $requests )['headers']['Authorization'] ?? null );
		self::assertSame( 'apptoken0002', $this->account->app_token() );
	}

	public function test_nothing_is_sent_while_alerts_or_the_creators_messages_are_off(): void {
		$nova = $this->connected_creator( 'nova' );
		$this->alert( $nova, 500, 1, 'Hoodie' );
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );

		$this->channels->save_enabled( $nova, ChatPlatform::Twitch, false );
		$this->sender->order_paid( 500 );
		$this->run_jobs();
		self::assertSame( array(), $this->sent() );
		self::assertFalse( $this->sender->resend( $nova, 500 ) );

		$this->channels->save_enabled( $nova, ChatPlatform::Twitch, true );
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
		self::assertSame( 'enabled', $this->channels->find( $nova, ChatPlatform::Twitch )->subscription_status );

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
		$chat = $this->call( 'GET', '/merchboard/v1/alerts/chat/twitch/' . $nova );
		self::assertSame( 200, $chat->get_status() );
		$messages = $chat->get_data()['messages'];
		self::assertSame( array( '<b>hype</b>', 'second' ), array_column( $messages, 'text' ), 'Stored as text; the window inserts it as text.' );
		self::assertSame( array( '#ff4500', '' ), array_column( $messages, 'color' ) );
		self::assertSame( array( 'second' ), array_column( $this->call( 'GET', '/merchboard/v1/alerts/chat/twitch/' . $nova, array( 'after' => $messages[0]['id'] ) )->get_data()['messages'], 'text' ) );

		// Forged, stale or unsigned events are refused.
		self::assertSame( 403, $this->event( 'notification', array( 'event' => $message ), 'x', gmdate( 'Y-m-d\TH:i:s\Z', time() - 700 ) )->get_status() );
		self::assertSame( 403, $this->event( 'notification', array( 'event' => $message ), 'x', null, 'wrong-secret-0123456789' )->get_status() );
		self::assertSame( 2, $this->count_rows( Tables::ALERT_CHAT_LOG ) );

		// Revocation (the creator removed the bot at Twitch).
		self::assertSame( 204, $this->event( 'revocation', array(), 'r', null, null, 'authorization_revoked' )->get_status() );
		self::assertSame( 'authorization_revoked', $this->channels->find( $nova, ChatPlatform::Twitch )->subscription_status );

		// Only alert administrators read the chat.
		wp_set_current_user( $this->create_user( 'subscriber_only' ) );
		self::assertSame( 403, $this->call( 'GET', '/merchboard/v1/alerts/chat/twitch/' . $nova )->get_status() );
	}

	public function test_the_chat_log_keeps_only_a_day_and_two_hundred_messages_per_channel(): void {
		for ( $i = 1; $i <= ChatLogRepository::KEEP_PER_CHANNEL + 5; $i++ ) {
			$this->chat->add( ChatPlatform::Twitch, '500', 'id-' . $i, 'viewer', 'Viewer', '', 'message ' . $i );
		}

		$this->chat->add( ChatPlatform::Twitch, '501', 'other-1', 'viewer', 'Viewer', '', 'other channel' );
		self::assertSame( ChatLogRepository::KEEP_PER_CHANNEL, count( $this->chat->after( ChatPlatform::Twitch, '500', 0, 500 ) ) );
		self::assertSame( 'message 6', $this->chat->after( ChatPlatform::Twitch, '500', 0, 500 )[0]['text'] );

		$this->execute( 'UPDATE %i SET created_at = %s WHERE broadcaster_id = %s', $this->table( Tables::ALERT_CHAT_LOG ), gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ), '501' );
		$this->chat->add( ChatPlatform::Twitch, '500', 'id-new', 'viewer', 'Viewer', '', 'new' );
		self::assertSame( array(), $this->chat->after( ChatPlatform::Twitch, '501', 0 ), 'Older than 24 hours.' );
	}

	public function test_the_creator_manages_the_chatbot_only_for_the_own_channel(): void {
		$this->routes();
		$nova = $this->creator( 'nova' );

		$state = $this->call( 'GET', '/merchboard/v1/me/alerts/chatbot' );
		self::assertSame( 200, $state->get_status() );
		self::assertFalse( $state->get_data()['platforms'][0]['available'] );
		self::assertSame( array( 'twitch', 'kick', 'youtube' ), array_column( $state->get_data()['platforms'], 'platform' ) );
		self::assertSame( 409, $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/twitch/connect' )->get_status() );

		$this->set_up_bot( $nova );
		$connect = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/twitch/connect' );
		self::assertSame( 200, $connect->get_status() );
		self::assertSame( 'channel:bot', $this->query( $connect->get_data()['url'] )['scope'] ?? null );

		$this->channels->connect( $nova, ChatPlatform::Twitch, '500', 'nova' );
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );
		$test = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/twitch/test' );
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
				'enabled'  => array( 'twitch' => false ),
				'template' => "<b>Tak</b>  \$Name\n for \$Product!",
			)
		);
		self::assertSame( 200, $saved->get_status() );
		self::assertSame( array( false, true, 'Tak $Name for $Product!' ), array( $saved->get_data()['platforms'][0]['enabled'], $saved->get_data()['platforms'][1]['enabled'], $saved->get_data()['template'] ) );
		self::assertSame( array( 'login' => 'nova' ), array_intersect_key( (array) $saved->get_data()['platforms'][0]['channel'], array( 'login' => true ) ) );
		self::assertSame( 400, $this->call( 'PATCH', '/merchboard/v1/me/alerts/chatbot', array( 'enabled' => array( 'twitch' => 'yes' ) ) )->get_status() );

		$removed = $this->call( 'DELETE', '/merchboard/v1/me/alerts/chatbot/twitch/channel' );
		self::assertNull( $removed->get_data()['platforms'][0]['channel'] );
		self::assertContains( 'alert_chatbot_channel_disconnected', array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );
		self::assertSame( 409, $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/twitch/test' )->get_status() );

		wp_set_current_user( $this->create_user( 'subscriber_only' ) );
		self::assertSame( 403, $this->call( 'GET', '/merchboard/v1/me/alerts/chatbot' )->get_status() );
	}

	public function test_the_administrator_sends_as_the_bot_and_sees_the_channels(): void {
		$nova    = $this->connected_creator( 'nova' );
		$actions = new ChatbotAdminActions( $this->account, $this->kick, $this->youtube, $this->youtube_oauth, $this->oauth, $this->format, $this->subscriptions, $this->channels, $this->sender, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( Logger::class ), $this->legal, $this->youtube_terms );
		$this->as_admin();
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );

		$url = $actions->process(
			array(
				'action'   => ChatbotAdminActions::SEND_ACTION,
				'creator'  => (string) $nova,
				'message'  => 'Hello chat',
				'_wpnonce' => wp_create_nonce( ChatbotAdminActions::nonce_action( ChatbotAdminActions::SEND_ACTION, $nova, ChatPlatform::Twitch ) ),
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
		$tab = new ChatbotAdminTab( $this->account, $this->kick, $this->youtube, $this->youtube_platform, $this->format, $this->channels, $this->log, new CreatorRepository( $this->wpdb ), $plugin, 'https://shop.example.com/wp-content/plugins/merchboard/', $this->youtube_terms );
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

		$this->channels->set_subscription( $nova, ChatPlatform::Twitch, 'sub-1', 'enabled' );
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

	public function test_the_kick_app_is_checked_at_kick_and_its_secret_is_stored_encrypted(): void {
		$this->as_admin();

		foreach ( array( array( 'x', self::KICK_SECRET, '' ), array( self::KICK_CLIENT, self::KICK_SECRET, 'not a bot!' ) ) as [ $client, $secret, $bot ] ) {
			try {
				$this->kick->save_app( $client, $secret, $bot );
				self::fail( 'Expected KickException.' );
			} catch ( KickException $e ) {
				self::assertSame( KickException::INVALID_CREDENTIALS, $e->reason() );
			}
		}

		$this->answer( self::KICK_TOKEN, 401, array( 'error' => 'invalid_client' ) );

		try {
			$this->kick->save_app( self::KICK_CLIENT, self::KICK_SECRET, 'merchbot' );
			self::fail( 'Expected KickException.' );
		} catch ( KickException $e ) {
			self::assertSame( KickException::INVALID_CREDENTIALS, $e->reason() );
		}

		self::assertFalse( $this->kick->has_app() );

		$this->set_up_kick();

		self::assertTrue( $this->kick->has_app() );
		self::assertSame( 'merchbot', $this->kick->bot_name() );
		self::assertSame( 'client_credentials', $this->last_body( self::KICK_TOKEN )['grant_type'] ?? null );
		$stored = (string) wp_json_encode( get_option( KickAccount::OPTION ) );
		self::assertStringNotContainsString( self::KICK_SECRET, $stored );
		self::assertStringNotContainsString( 'kickapptoken', $stored );
		self::assertContains( 'alert_kick_app_changed', array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );
		self::assertStringNotContainsString( self::KICK_SECRET, (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );
	}

	public function test_a_creator_adds_the_bot_to_the_kick_channel_with_pkce(): void {
		$this->set_up_kick();
		$nova = $this->creator( 'nova' );
		$url  = $this->kick_oauth->start_channel( $nova );
		$this->logged_in();
		$query = $this->query( $url );

		self::assertStringStartsWith( KickApi::AUTHORIZE_URL . '?', $url );
		self::assertSame( 'channel:read chat:write events:subscribe', $query['scope'] ?? null );
		self::assertSame( 'S256', $query['code_challenge_method'] ?? null );
		self::assertSame( KickAccount::redirect_uri(), $query['redirect_uri'] ?? null );

		// Kick's redirect: the creator's browser, without a REST nonce.
		wp_set_current_user( 0 );
		$this->answer(
			self::KICK_TOKEN,
			200,
			array(
				'access_token'  => 'kick-access-1',
				'refresh_token' => 'kick-refresh-1',
				'expires_in'    => 7200,
				'token_type'    => 'Bearer',
			)
		);
		$this->answer(
			self::KICK_CHANNELS,
			200,
			array(
				'data' => array(
					array(
						'broadcaster_user_id' => 777,
						'slug'                => 'Nova_Kick',
					),
				),
			)
		);
		$location = $this->kick_oauth->finish( $query['state'] ?? '', 'code-k', '' );

		self::assertSame( add_query_arg( array( 'chatbot' => 'connected', 'platform' => 'kick' ), home_url( '/creator-dashboard/alerts/chatbot/' ) ), $location ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$body = $this->last_body( self::KICK_TOKEN );
		self::assertSame( 'authorization_code', $body['grant_type'] ?? null );
		self::assertSame( $query['code_challenge'] ?? null, KickApi::challenge( (string) ( $body['code_verifier'] ?? '' ) ), 'The verifier matches the challenge (PKCE).' );
		self::assertSame( 'Bearer kick-access-1', $this->requests_to( self::KICK_CHANNELS )[0]['headers']['Authorization'] ?? null );

		$channel = $this->channels->find( $nova, ChatPlatform::Kick );
		self::assertSame( array( '777', 'nova_kick', ChatSubscriptions::NO_WEBHOOKS ), array( $channel->broadcaster_id, $channel->broadcaster_login, $channel->subscription_status ) );
		self::assertFalse( $this->channels->find( $nova, ChatPlatform::Twitch )->connected(), 'Twitch is separate.' );
		self::assertNotNull( $this->channels->tokens( $nova, ChatPlatform::Kick ) );
		$rows = (string) wp_json_encode( $this->rows( Tables::ALERT_CHATBOT ) );
		self::assertStringNotContainsString( 'kick-access-1', $rows, 'Stored encrypted.' );
		self::assertStringNotContainsString( 'kick-refresh-1', $rows );
		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'login' => 'nova_kick', 'platform' => 'kick' ), json_decode( (string) ( $audit[ count( $audit ) - 1 ]['context'] ?? '' ), true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( 'kick-access-1', (string) wp_json_encode( $audit ) );
		self::assertSame( home_url( '/' ), $this->kick_oauth->finish( $query['state'] ?? '', 'code-k', '' ), 'A state is used once.' );
	}

	public function test_a_failed_kick_authorization_names_the_step_in_the_log_and_the_code_on_the_page(): void {
		$this->set_up_kick();
		$logger = new RecordingLogger();
		$oauth  = new KickOAuth( $this->kick, $this->kick_api, $this->kick_tokens, $this->channels, $this->subscriptions, $this->service( AuditTrail::class ), $this->service( Transaction::class ), static fn( string $path ): string => home_url( '/creator-dashboard/' . $path . '/' ), $logger );
		$nova   = $this->creator( 'nova' );

		// Kick refuses the code (e.g. a wrong redirect URL or secret).
		$this->answer( self::KICK_TOKEN, 400, array( 'error' => 'invalid_grant' ) );
		$state = $this->query( $oauth->start_channel( $nova ) )['state'] ?? '';
		$this->logged_in();
		$location = $oauth->finish( $state, 'secret-code-1', '' );

		self::assertStringContainsString( 'chatbot=invalid_credentials&platform=kick', $location );
		$warnings = $logger->at( 'warning' );
		self::assertSame( array( 'step' => 'token', 'reason' => 'invalid_credentials', 'status' => 400, 'detail' => '' ), $warnings[0]['context'] ?? null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// Kick answers the channel request in an unexpected shape.
		$this->answer(
			self::KICK_TOKEN,
			200,
			array(
				'access_token'  => 'kick-access-9',
				'refresh_token' => 'kick-refresh-9',
				'expires_in'    => 7200,
			)
		);
		$this->answer( self::KICK_CHANNELS, 200, array( 'data' => array() ) );
		$state = $this->query( $oauth->start_channel( $nova ) )['state'] ?? '';
		$this->logged_in();
		self::assertStringContainsString( 'chatbot=unexpected_answer&platform=kick', $oauth->finish( $state, 'secret-code-2', '' ) );
		self::assertSame( array( 'channel', 'unexpected_answer', 200 ), array( $logger->at( 'warning' )[1]['context']['step'] ?? null, $logger->at( 'warning' )[1]['context']['reason'] ?? null, $logger->at( 'warning' )[1]['context']['status'] ?? null ) );
		self::assertStringNotContainsString( 'secret-code', (string) wp_json_encode( $logger->entries ), 'Never the code or a token.' );
		self::assertStringNotContainsString( 'kick-access-9', (string) wp_json_encode( $logger->entries ) );
		self::assertFalse( $this->channels->find( $nova, ChatPlatform::Kick )->connected() );
	}

	public function test_a_purchase_is_announced_once_on_each_platform(): void {
		$nova = $this->connected_creator( 'nova' );
		$this->kick_creator( $nova, 7200 );
		$this->alert( $nova, 100, 1, 'Hoodie' );
		$this->answer( self::MESSAGES_URL, 200, array( 'data' => array( array( 'is_sent' => true ) ) ) );
		$this->answer(
			self::KICK_CHAT,
			200,
			array(
				'data'    => array(
					'is_sent'    => true,
					'message_id' => 'k-1',
				),
				'message' => 'OK',
			)
		);

		$this->sender->order_paid( 100 );
		self::assertSame( array( 'twitch', 'kick' ), array_map( static fn( array $job ): mixed => $job['args'][2] ?? null, $this->queue->pending ), 'One job per platform.' );

		$this->run_jobs();

		self::assertSame( array( '🔔 nova_fan just bought Hoodie!' ), array_column( $this->sent(), 'message' ) );
		$kick = $this->requests_to( self::KICK_CHAT );
		self::assertCount( 1, $kick );
		self::assertSame(
			array(
				'type'    => 'bot',
				'content' => '🔔 nova_fan just bought Hoodie!',
			),
			$kick[0]['body']
		);
		self::assertSame( 'Bearer kick-access-1', $kick[0]['headers']['Authorization'] ?? null, 'With the channel\'s own token (type "bot").' );
		self::assertSame( 'sent', $this->log->latest( $nova, ChatPlatform::Kick )['status'] ?? null );

		// Paid again: nothing twice on either platform.
		$this->sender->order_paid( 100 );
		$this->run_jobs();
		self::assertCount( 1, $this->sent() );
		self::assertCount( 1, $this->requests_to( self::KICK_CHAT ) );

		// Kick messages off: a resend goes to Twitch only.
		$this->channels->save_enabled( $nova, ChatPlatform::Kick, false );
		self::assertTrue( $this->sender->resend( $nova, 100 ) );
		self::assertSame( array( 'twitch' ), array_map( static fn( array $job ): mixed => $job['args'][2] ?? null, $this->queue->pending ) );
	}

	public function test_the_kick_token_is_renewed_and_a_revoked_access_forgets_the_channel(): void {
		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->kick_creator( $nova, 60 );
		$this->alert( $nova, 200, 1, 'Hoodie' );
		$this->answer(
			self::KICK_TOKEN,
			200,
			array(
				'access_token'  => 'kick-access-2',
				'refresh_token' => 'kick-refresh-2',
				'expires_in'    => 7200,
			)
		);
		$this->answer( self::KICK_CHAT, 200, array( 'data' => array( 'is_sent' => true ) ) );

		$this->sender->order_paid( 200 );
		$this->run_jobs();

		$refresh = $this->last_body( self::KICK_TOKEN );
		self::assertSame( array( 'refresh_token', 'kick-refresh-1' ), array( $refresh['grant_type'] ?? null, $refresh['refresh_token'] ?? null ), 'Renewed shortly before it expires.' );
		self::assertSame( 'Bearer kick-access-2', $this->requests_to( self::KICK_CHAT )[0]['headers']['Authorization'] ?? null );
		self::assertSame( 'kick-access-2', $this->kick_tokens->access( $nova ), 'The rotated pair was stored.' );

		// The creator revoked the access at Kick.
		$this->answer( self::KICK_CHAT, 401, array( 'message' => 'Unauthorized' ) );
		$this->answer( self::KICK_TOKEN, 400, array( 'error' => 'invalid_grant' ) );
		$result = $this->sender->send_now( $this->channels->find( $nova, ChatPlatform::Kick ), 'Hi' );

		self::assertSame( array( 'unauthorized', 'authorization_revoked' ), array( $result['result'], $result['reason'] ) );
		self::assertFalse( $this->channels->find( $nova, ChatPlatform::Kick )->connected(), 'The creator adds the bot again.' );
		self::assertNull( $this->channels->tokens( $nova, ChatPlatform::Kick ) );
	}

	public function test_kick_asks_to_wait_and_the_job_waits(): void {
		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->kick_creator( $nova, 7200 );
		$this->alert( $nova, 300, 1, 'Hoodie' );
		$this->answer( self::KICK_CHAT, 429, array( 'message' => 'Too Many Requests' ), array( 'retry-after' => '30' ) );

		$this->sender->order_paid( 300 );
		$this->run_jobs( 1 );

		self::assertSame( 'waiting', $this->log->latest( $nova, ChatPlatform::Kick )['status'] ?? null );
		self::assertSame( array( 30, 'kick' ), array( $this->queue->pending[0]['delay'] ?? null, $this->queue->pending[0]['args'][2] ?? null ) );
		self::assertGreaterThan( 0, $this->kick->wait_seconds() );

		$this->run_jobs( 1 );
		self::assertCount( 1, $this->requests_to( self::KICK_CHAT ), 'The job only waits.' );
	}

	public function test_a_token_renewal_that_cannot_reach_kick_is_tried_again(): void {
		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->kick_creator( $nova, 60 );
		$this->alert( $nova, 310, 1, 'Hoodie' );
		$this->answer( self::KICK_TOKEN, 503, array() );

		$this->sender->order_paid( 310 );
		$this->run_jobs( 1 );

		self::assertSame( array( 'waiting', 'unavailable' ), array( $this->log->latest( $nova, ChatPlatform::Kick )['status'] ?? null, $this->log->latest( $nova, ChatPlatform::Kick )['reason'] ?? null ), 'The message is not dropped (25.13 review).' );
		self::assertSame( 60, $this->queue->pending[0]['delay'] ?? null );
		self::assertSame( array(), $this->requests_to( self::KICK_CHAT ) );

		$this->answer(
			self::KICK_TOKEN,
			200,
			array(
				'access_token'  => 'kick-access-2',
				'refresh_token' => 'kick-refresh-2',
				'expires_in'    => 7200,
			)
		);
		$this->answer( self::KICK_CHAT, 200, array( 'data' => array( 'is_sent' => true ) ) );
		$this->run_jobs();

		self::assertSame( 'sent', $this->log->latest( $nova, ChatPlatform::Kick )['status'] ?? null );
		self::assertCount( 1, $this->requests_to( self::KICK_CHAT ) );
	}

	public function test_the_creator_removes_the_kick_bot_and_the_token_is_revoked(): void {
		$this->routes();
		$nova = $this->creator( 'nova' );
		$this->kick_creator( $nova, 7200 );
		wp_set_current_user( $this->user_of( $nova ) );

		$state = $this->call( 'GET', '/merchboard/v1/me/alerts/chatbot' );
		self::assertSame( array( 'login' => 'nova_kick' ), array_intersect_key( (array) $state->get_data()['platforms'][1]['channel'], array( 'login' => true ) ) );
		self::assertSame( 'https://kick.com/nova_kick', $state->get_data()['platforms'][1]['channel']['url'] ?? null );
		self::assertStringNotContainsString( 'kick-access-1', (string) wp_json_encode( $state->get_data() ), 'Tokens never reach the browser.' );

		$connect = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/kick/connect' );
		self::assertStringStartsWith( KickApi::AUTHORIZE_URL, (string) $connect->get_data()['url'] );

		$removed = $this->call( 'DELETE', '/merchboard/v1/me/alerts/chatbot/kick/channel' );
		self::assertNull( $removed->get_data()['platforms'][1]['channel'] );
		self::assertSame( array( 'kick-refresh-1', 'kick-access-1' ), array_map( fn( array $request ): string => (string) ( $this->query( $request['url'] )['token'] ?? '' ), $this->requests_to( self::KICK_REVOKE ) ), 'Both tokens are revoked at Kick.' );
		self::assertNull( $this->channels->tokens( $nova, ChatPlatform::Kick ) );
		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'login' => 'nova_kick', 'platform' => 'kick' ), json_decode( (string) ( $audit[ count( $audit ) - 1 ]['context'] ?? '' ), true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_the_kick_webhook_accepts_only_signed_events_and_feeds_the_chat_window(): void {
		$key     = self::rsa_key();
		$other   = self::rsa_key();
		$details = openssl_pkey_get_details( $key );
		self::assertIsArray( $details );

		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->kick_creator( $nova, 7200 );
		$this->answer(
			self::KICK_KEY,
			200,
			array(
				'data'    => array( 'public_key' => $details['key'] ),
				'message' => 'OK',
			)
		);
		$this->routes();
		$message = array(
			'message_id'  => 'kick-msg-1',
			'broadcaster' => array(
				'user_id'  => 777,
				'username' => 'nova_kick',
			),
			'sender'      => array(
				'user_id'      => 5,
				'username'     => 'Viewer5',
				'channel_slug' => 'viewer5',
				'identity'     => array( 'username_color' => '#00FF00' ),
			),
			'content'     => '<b>hype</b>',
		);

		self::assertSame( 200, $this->kick_event( $message, $key )->get_status() );
		self::assertSame( 200, $this->kick_event( $message, $key, 'chat.message.sent', 'delivery-2' )->get_status() );
		self::assertSame( 200, $this->kick_event( array( 'follower' => array() ), $key, 'channel.followed', 'delivery-3' )->get_status() );
		self::assertSame( 1, $this->count_rows( Tables::ALERT_CHAT_LOG ), 'Stored once; other events are ignored.' );

		$this->as_admin();
		$chat = $this->call( 'GET', '/merchboard/v1/alerts/chat/kick/' . $nova );
		self::assertSame(
			array(
				'login' => 'viewer5',
				'name'  => 'Viewer5',
				'color' => '#00ff00',
				'text'  => '<b>hype</b>',
			),
			array_intersect_key( $chat->get_data()['messages'][0], array_flip( array( 'login', 'name', 'color', 'text' ) ) )
		);
		self::assertSame( array(), $this->call( 'GET', '/merchboard/v1/alerts/chat/twitch/' . $nova )->get_data()['messages'], 'Twitch has no channel.' );

		// Forged (another key) or stale events are refused.
		self::assertSame( 403, $this->kick_event( array( 'message_id' => 'kick-msg-2' ) + $message, $other, 'chat.message.sent', 'delivery-4' )->get_status() );
		self::assertSame( 403, $this->kick_event( array( 'message_id' => 'kick-msg-3' ) + $message, $key, 'chat.message.sent', 'delivery-5', gmdate( 'Y-m-d\TH:i:s\Z', time() - 700 ) )->get_status() );
		self::assertSame( 1, $this->count_rows( Tables::ALERT_CHAT_LOG ) );
		self::assertLessThanOrEqual( 2, count( $this->requests_to( self::KICK_KEY ) ), 'The key is cached; a forged signature refetches it at most once.' );
	}

	public function test_the_youtube_bot_logs_in_at_google_with_pkce_and_offline_access(): void {
		$this->as_admin();

		try {
			$this->youtube->save_app( 'not-a-google-client', self::YT_SECRET );
			self::fail( 'Expected ChatbotException.' );
		} catch ( ChatbotException $e ) {
			self::assertSame( ChatbotException::INVALID_CREDENTIALS, $e->reason() );
		}

		$this->youtube->save_app( self::YT_CLIENT, self::YT_SECRET );
		self::assertTrue( $this->youtube->has_app() );
		self::assertFalse( $this->youtube->ready(), 'Not before the bot logs in.' );
		self::assertStringNotContainsString( self::YT_SECRET, (string) wp_json_encode( get_option( YouTubeAccount::OPTION ) ) );
		self::assertSame( array(), $this->requests, 'Google cannot check an app without a login.' );

		// Google requires the privacy policy and terms (ADR-071).
		try {
			$this->youtube_oauth->start_bot();
			$this->logged_in();
			self::fail( 'Expected ChatbotException.' );
		} catch ( ChatbotException $e ) {
			self::assertSame( ChatbotException::TERMS_REQUIRED, $e->reason() );
		}

		$this->publish_legal();
		$url = $this->youtube_oauth->start_bot();
		$this->logged_in();
		$query = $this->query( $url );
		self::assertStringStartsWith( YouTubeApi::AUTHORIZE_URL . '?', $url );
		self::assertSame( array( YouTubeAccount::BOT_SCOPE, 'offline', 'consent select_account', 'S256', YouTubeAccount::redirect_uri() ), array( $query['scope'] ?? null, $query['access_type'] ?? null, $query['prompt'] ?? null, $query['code_challenge_method'] ?? null, $query['redirect_uri'] ?? null ) );

		// Without a refresh token the bot could not send later.
		wp_set_current_user( 0 );
		$this->answer(
			self::YT_TOKEN,
			200,
			array(
				'access_token' => 'yt-bot-access-0',
				'expires_in'   => 3599,
			)
		);
		self::assertSame( AlertsAdminPage::url( 'chatbot', 'youtube_bot_' . ChatbotException::UNEXPECTED ), $this->youtube_oauth->finish( $query['state'] ?? '', 'code-0', '' ) );
		self::assertNull( $this->youtube->bot() );

		$this->as_admin();
		$query = $this->query( $this->youtube_oauth->start_bot() );
		$this->logged_in();
		wp_set_current_user( 0 );
		$this->answer(
			self::YT_TOKEN,
			200,
			array(
				'access_token'  => 'yt-bot-access-1',
				'refresh_token' => 'yt-bot-refresh-1',
				'expires_in'    => 3599,
			)
		);
		$this->answer( self::YT_CHANNELS, 200, self::youtube_channel( self::YT_BOT, 'Merch Bot', '@merchbot' ) );

		self::assertSame( AlertsAdminPage::url( 'chatbot', 'youtube_bot_connected' ), $this->youtube_oauth->finish( $query['state'] ?? '', 'code-1', '' ) );
		$body = $this->last_body( self::YT_TOKEN );
		self::assertSame( array( 'authorization_code', self::YT_CLIENT ), array( $body['grant_type'] ?? null, $body['client_id'] ?? null ) );
		self::assertSame( $query['code_challenge'] ?? null, KickApi::challenge( (string) ( $body['code_verifier'] ?? '' ) ), 'The verifier matches the challenge (PKCE).' );
		self::assertSame( array( 'Bearer yt-bot-access-1' ), array_map( static fn( array $request ): mixed => $request['headers']['Authorization'] ?? null, $this->requests_to( self::YT_CHANNELS ) ), 'The channel is looked up only with a refresh token.' );
		self::assertSame( array( self::YT_BOT, 'merchbot', false ), array( $this->youtube->bot()['id'] ?? null, $this->youtube->bot()['name'] ?? null, $this->youtube->bot()['invalid'] ?? null ) );
		self::assertTrue( $this->youtube->ready() );
		$stored = (string) wp_json_encode( get_option( YouTubeAccount::OPTION ) );
		self::assertStringNotContainsString( 'yt-bot-access-1', $stored, 'Stored encrypted.' );
		self::assertStringNotContainsString( 'yt-bot-refresh-1', $stored );
		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertContains( 'alert_youtube_app_changed', array_column( $audit, 'action' ) );
		self::assertSame( array( 'login' => 'merchbot', 'platform' => 'youtube' ), json_decode( (string) ( $audit[ count( $audit ) - 1 ]['context'] ?? '' ), true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( 'yt-bot-', (string) wp_json_encode( $audit ) );
		self::assertSame( home_url( '/' ), $this->youtube_oauth->finish( $query['state'] ?? '', 'code-1', '' ), 'A state is used once.' );

		$this->as_admin();
		$this->youtube->disconnect_bot();
		self::assertNull( $this->youtube->bot() );
		self::assertSame( array( 'yt-bot-refresh-1', 'yt-bot-access-1' ), array_map( static fn( array $request ): string => (string) ( ( (array) $request['body'] )['token'] ?? '' ), $this->requests_to( self::YT_REVOKE ) ), 'Both tokens are revoked at Google.' );
	}

	public function test_a_creator_adds_the_youtube_channel_and_the_bots_own_channel_is_refused(): void {
		$this->set_up_youtube();
		$this->routes();
		$nova  = $this->creator( 'nova' );
		$query = $this->query( $this->youtube_oauth->start_channel( $nova ) );
		$this->logged_in();
		self::assertSame( array( YouTubeAccount::CHANNEL_SCOPE, 'offline', 'consent' ), array( $query['scope'] ?? null, $query['access_type'] ?? null, $query['prompt'] ?? null ) );

		// The creator logged in with the bot's account.
		wp_set_current_user( 0 );
		$this->answer(
			self::YT_TOKEN,
			200,
			array(
				'access_token'  => 'yt-wrong-access',
				'refresh_token' => 'yt-wrong-refresh',
				'expires_in'    => 3599,
			)
		);
		$this->answer( self::YT_CHANNELS, 200, self::youtube_channel( self::YT_BOT, 'Merch Bot', '@merchbot' ) );
		self::assertStringContainsString( 'chatbot=' . ChatbotException::BOT_IS_BROADCASTER . '&platform=youtube', $this->youtube_oauth->finish( $query['state'] ?? '', 'code-w', '' ) );
		self::assertFalse( $this->channels->find( $nova, ChatPlatform::YouTube )->connected() );

		// Google's redirect reaches the callback route.
		wp_set_current_user( $this->user_of( $nova ) );
		$query = $this->query( $this->youtube_oauth->start_channel( $nova ) );
		$this->logged_in();
		wp_set_current_user( 0 );
		$this->answer(
			self::YT_TOKEN,
			200,
			array(
				'access_token'  => 'yt-nova-access-1',
				'refresh_token' => 'yt-nova-refresh-1',
				'expires_in'    => 3599,
			)
		);
		$this->answer( self::YT_CHANNELS, 200, self::youtube_channel( self::YT_CREATOR, 'Nova Live', '' ) );
		$callback = $this->call(
			'GET',
			'/merchboard/v1/alerts/youtube/callback',
			array(
				'state' => $query['state'] ?? '',
				'code'  => 'code-n',
			)
		);

		self::assertSame( 302, $callback->get_status() );
		self::assertSame( add_query_arg( array( 'chatbot' => 'connected', 'platform' => 'youtube' ), home_url( '/creator-dashboard/alerts/chatbot/' ) ), $callback->get_headers()['Location'] ?? null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$channel = $this->channels->find( $nova, ChatPlatform::YouTube );
		self::assertSame( array( self::YT_CREATOR, 'Nova Live', YouTubePlatform::EMBEDDED ), array( $channel->broadcaster_id, $channel->broadcaster_login, $channel->subscription_status ) );
		self::assertSame( 'yt-nova-access-1', $this->youtube_tokens->access( $nova ) );
		$rows = (string) wp_json_encode( $this->rows( Tables::ALERT_CHATBOT ) );
		self::assertStringNotContainsString( 'yt-nova-access-1', $rows, 'Stored encrypted.' );
		self::assertStringNotContainsString( 'yt-nova-refresh-1', $rows );

		wp_set_current_user( $this->user_of( $nova ) );
		$youtube = $this->call( 'GET', '/merchboard/v1/me/alerts/chatbot' )->get_data()['platforms'][2];
		self::assertSame( array( 'youtube', true, 'merchbot' ), array( $youtube['platform'], $youtube['available'], $youtube['bot_login'] ) );
		self::assertSame( array( 'Nova Live', 'https://www.youtube.com/channel/' . self::YT_CREATOR ), array( $youtube['channel']['login'] ?? null, $youtube['channel']['url'] ?? null ) );
	}

	public function test_a_youtube_purchase_is_checked_once_more_after_five_minutes_and_then_skipped(): void {
		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->youtube_creator( $nova );
		$this->alert( $nova, 400, 1, 'Hoodie' );
		$this->alert( $nova, 400, 2, 'Cap' );
		$this->answer( self::YT_BROADCASTS, 200, array( 'items' => array() ) );

		$this->sender->order_paid( 400 );
		self::assertSame( array( 'youtube' ), array_map( static fn( array $job ): mixed => $job['args'][2] ?? null, $this->queue->pending ) );

		// Not live when the order comes in: one more check after five minutes.
		$this->run_jobs( 1 );
		self::assertSame( array( 'waiting', 'not_live' ), array( $this->log->latest( $nova, ChatPlatform::YouTube )['status'] ?? null, $this->log->latest( $nova, ChatPlatform::YouTube )['reason'] ?? null ) );
		self::assertSame( array( 300 ), array_column( $this->queue->pending, 'delay' ) );

		// Still not live: the order's lines are skipped, without asking again.
		$this->not_live_any_more( $nova );
		$this->run_jobs();
		self::assertSame( array( 'skipped:not_live', 'skipped:not_live' ), array_map( static fn( array $row ): string => $row['status'] . ':' . $row['reason'], $this->rows( Tables::ALERT_CHAT_MESSAGES ) ) );
		self::assertSame( array(), $this->queue->pending );
		self::assertSame( array(), $this->requests_to( self::YT_MESSAGES ) );
		$lookups = $this->requests_to( self::YT_BROADCASTS );
		self::assertCount( 2, $lookups, 'At most two live checks per order.' );
		self::assertSame( array( 'active', 'Bearer yt-nova-access-1' ), array( $this->query( $lookups[0]['url'] )['broadcastStatus'] ?? null, $lookups[0]['headers']['Authorization'] ?? null ), 'With the creator\'s token.' );
	}

	public function test_a_live_youtube_creator_gets_the_message_and_the_waiting_ones_follow(): void {
		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->youtube_creator( $nova );
		$this->alert( $nova, 500, 1, 'Hoodie' );
		$this->answer( self::YT_BROADCASTS, 200, array( 'items' => array() ) );
		$this->sender->order_paid( 500 );
		$this->run_jobs( 1 );
		self::assertSame( 'waiting', $this->log->latest( $nova, ChatPlatform::YouTube )['status'] ?? null );

		// The retry is far ahead; the creator goes live and another purchase comes in.
		$this->queue->pending = array();
		$this->not_live_any_more( $nova );
		$this->live( 'video-1', 'chat-1' );
		$this->answer( self::YT_MESSAGES, 200, array( 'id' => 'message-1' ) );
		$this->alert( $nova, 501, 2, 'Cap' );
		$this->sender->order_paid( 501 );
		$this->run_jobs( 1 );

		self::assertSame( array( array( $nova, 500, 'youtube' ) ), array_column( $this->queue->pending, 'args' ), 'The waiting order follows right away.' );
		$this->run_jobs();

		$messages = $this->requests_to( self::YT_MESSAGES );
		self::assertSame(
			array( '🔔 nova_fan just bought Cap!', '🔔 nova_fan just bought Hoodie!' ),
			array_map( static fn( array $request ): mixed => ( (array) $request['body'] )['snippet']['textMessageDetails']['messageText'] ?? null, $messages )
		);
		self::assertSame( array( 'chat-1', 'textMessageEvent' ), array( ( (array) $messages[0]['body'] )['snippet']['liveChatId'] ?? null, ( (array) $messages[0]['body'] )['snippet']['type'] ?? null ) );
		self::assertSame( 'Bearer yt-bot-access-1', $messages[0]['headers']['Authorization'] ?? null, 'Written as the bot.' );
		self::assertCount( 2, $this->requests_to( self::YT_BROADCASTS ), 'The live chat is kept between messages.' );
		self::assertSame( 'video-1', $this->youtube_platform->live_video( $this->channels->find( $nova, ChatPlatform::YouTube ) ) );

		// Paid again: nothing twice.
		$this->sender->order_paid( 501 );
		$this->run_jobs();
		self::assertCount( 2, $this->requests_to( self::YT_MESSAGES ) );
	}

	public function test_youtube_messages_wait_for_the_quota_and_a_refused_bot_must_log_in_again(): void {
		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->youtube_creator( $nova );
		$this->live( 'video-1', 'chat-1' );
		$this->alert( $nova, 600, 1, 'Hoodie' );
		$this->answer( self::YT_MESSAGES, 403, array( 'error' => array( 'errors' => array( array( 'reason' => 'quotaExceeded' ) ) ) ) );

		$this->sender->order_paid( 600 );
		$this->run_jobs( 1 );

		self::assertSame( array( 'waiting', 'quota_exceeded' ), array( $this->log->latest( $nova, ChatPlatform::YouTube )['status'] ?? null, $this->log->latest( $nova, ChatPlatform::YouTube )['reason'] ?? null ) );
		self::assertGreaterThan( time(), $this->youtube->quota_until() );
		self::assertLessThanOrEqual( DAY_IN_SECONDS + 3700, $this->youtube->wait_seconds(), 'Until midnight Pacific Time.' );
		self::assertGreaterThan( 0, $this->queue->pending[0]['delay'] ?? 0 );
		$this->run_jobs( 1 );
		self::assertCount( 1, $this->requests_to( self::YT_MESSAGES ), 'The job only waits.' );

		// Google refuses the bot's token and then its refresh token.
		delete_option( YouTubeAccount::OPTION );
		$this->set_up_youtube();
		$this->answer( self::YT_MESSAGES, 401, array( 'error' => array( 'errors' => array( array( 'reason' => 'authError' ) ) ) ) );
		$this->answer( self::YT_TOKEN, 400, array( 'error' => 'invalid_grant' ) );

		try {
			$this->sender->send_now( $this->channels->find( $nova, ChatPlatform::YouTube ), 'Hi' );
			self::fail( 'Expected ChatbotException.' );
		} catch ( ChatbotException $e ) {
			self::assertSame( ChatbotException::UNAUTHORIZED, $e->reason() );
		}

		self::assertTrue( $this->youtube->bot()['invalid'] ?? false );
		self::assertFalse( $this->youtube_platform->ready(), 'Nothing is sent until the bot logs in again.' );
		self::assertSame( 'yt-bot-refresh-1', $this->last_body( self::YT_TOKEN )['refresh_token'] ?? null );
		self::assertTrue( $this->channels->find( $nova, ChatPlatform::YouTube )->connected(), 'The creator\'s channel stays.' );
	}

	public function test_a_refused_youtube_message_is_logged_with_googles_reason_and_explained(): void {
		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->youtube_creator( $nova );
		$this->live( 'video-1', 'chat-1' );

		// E.g. a private livestream: Google refuses the bot.
		$this->answer(
			self::YT_MESSAGES,
			403,
			array(
				'error' => array(
					'code'    => 403,
					'message' => 'You do not have the <b>permissions</b> required to create the specified message.',
					'errors'  => array( array( 'reason' => 'forbidden' ) ),
				),
			)
		);
		$logger   = new RecordingLogger();
		$platform = new YouTubePlatform( $this->youtube, $this->youtube_api, $this->youtube_tokens, $this->channels, $this->youtube_terms, $logger );

		self::assertSame(
			array(
				'result'      => 'forbidden',
				'reason'      => 'forbidden',
				'retry_after' => null,
			),
			$platform->send( $this->channels->find( $nova, ChatPlatform::YouTube ), 'Hi' )
		);
		self::assertSame(
			array(
				'creator_id' => $nova,
				'result'     => 'forbidden',
				'reason'     => 'forbidden',
				'status'     => 403,
				'detail'     => 'You do not have the permissions required to create the specified message.',
			),
			$logger->at( 'warning' )[0]['context'] ?? null
		);
		self::assertStringNotContainsString( 'yt-bot-access', (string) wp_json_encode( $logger->entries ), 'Never a token.' );

		$this->as_admin();
		$actions = new ChatbotAdminActions( $this->account, $this->kick, $this->youtube, $this->youtube_oauth, $this->oauth, $this->format, $this->subscriptions, $this->channels, $this->sender, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( Logger::class ), $this->legal, $this->youtube_terms );
		$send    = fn(): string => $actions->process(
			array(
				'action'   => ChatbotAdminActions::SEND_ACTION,
				'creator'  => (string) $nova,
				'platform' => 'youtube',
				'message'  => 'Hello chat',
				'_wpnonce' => wp_create_nonce( ChatbotAdminActions::nonce_action( ChatbotAdminActions::SEND_ACTION, $nova, ChatPlatform::YouTube ) ),
			)
		);
		self::assertStringContainsString( 'merchboard_alerts_admin_result=message_youtube_forbidden', $send() );

		$this->not_live_any_more( $nova );
		$this->answer( self::YT_BROADCASTS, 200, array( 'items' => array() ) );
		self::assertStringContainsString( 'merchboard_alerts_admin_result=message_youtube_not_live', $send() );
	}

	public function test_a_refused_live_check_is_logged_and_explained_and_a_used_up_quota_waits(): void {
		$this->routes();
		$nova = $this->creator( 'nova' );
		$this->youtube_creator( $nova );
		wp_set_current_user( $this->user_of( $nova ) );
		$logger   = new RecordingLogger();
		$platform = new YouTubePlatform( $this->youtube, $this->youtube_api, $this->youtube_tokens, $this->channels, $this->youtube_terms, $logger );
		$channel  = $this->channels->find( $nova, ChatPlatform::YouTube );
		$refusal  = static fn( string $reason, string $message ): array => array(
			'error' => array(
				'code'    => 403,
				'message' => $message,
				'errors'  => array( array( 'reason' => $reason ) ),
			),
		);

		// The account may not see its livestreams (e.g. not the owner of a brand account).
		$this->answer( self::YT_BROADCASTS, 403, $refusal( 'insufficientLivePermissions', 'The request is not authorized to retrieve the live broadcast.' ) );
		self::assertSame( array( 'forbidden', 'live_not_allowed' ), array_slice( array_values( $platform->send( $channel, 'Hi' ) ), 0, 2 ) );
		self::assertSame(
			array(
				'creator_id' => $nova,
				'reason'     => 'live_not_allowed',
				'status'     => 403,
				'detail'     => 'insufficientLivePermissions The request is not authorized to retrieve the live broadcast.',
			),
			$logger->at( 'warning' )[0]['context'] ?? null
		);
		self::assertStringNotContainsString( 'yt-nova-access', (string) wp_json_encode( $logger->entries ), 'Never a token.' );
		self::assertTrue( $this->channels->find( $nova, ChatPlatform::YouTube )->connected(), 'The channel stays.' );

		// The creator's test message gets an answer instead of a 409.
		$test = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/youtube/test' );
		self::assertSame( array( 200, 'forbidden', 'live_not_allowed' ), array( $test->get_status(), $test->get_data()['result'] ?? null, $test->get_data()['reason'] ?? null ) );

		$this->as_admin();
		$actions = new ChatbotAdminActions( $this->account, $this->kick, $this->youtube, $this->youtube_oauth, $this->oauth, $this->format, $this->subscriptions, $this->channels, $this->sender, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( Logger::class ), $this->legal, $this->youtube_terms );
		$send    = fn(): string => $actions->process(
			array(
				'action'   => ChatbotAdminActions::SEND_ACTION,
				'creator'  => (string) $nova,
				'platform' => 'youtube',
				'message'  => 'Hello chat',
				'_wpnonce' => wp_create_nonce( ChatbotAdminActions::nonce_action( ChatbotAdminActions::SEND_ACTION, $nova, ChatPlatform::YouTube ) ),
			)
		);
		self::assertStringContainsString( 'merchboard_alerts_admin_result=message_youtube_live_not_allowed', $send() );

		// The day's quota is used up already at the live check: the messages wait, with no further call.
		$this->answer( self::YT_BROADCASTS, 403, $refusal( 'quotaExceeded', 'The request cannot be completed because you have exceeded your quota.' ) );
		self::assertSame( 'quota_exceeded', $platform->send( $channel, 'Hi' )['reason'] );
		self::assertGreaterThan( time(), $this->youtube->quota_until() );
		$calls = count( $this->requests_to( self::YT_BROADCASTS ) );
		self::assertSame( array( 'rate_limited', 'quota_exceeded' ), array_slice( array_values( $platform->send( $channel, 'Hi' ) ), 0, 2 ) );
		self::assertCount( $calls, $this->requests_to( self::YT_BROADCASTS ), 'No call while the quota is used up.' );
		self::assertStringContainsString( 'merchboard_alerts_admin_result=message_youtube_quota', $send() );
	}

	public function test_a_revoked_youtube_access_forgets_the_channel_after_one_renewal(): void {
		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->youtube_creator( $nova );

		// An access token Google no longer accepts is renewed once.
		$this->answer( self::YT_BROADCASTS, 401, array( 'error' => array( 'errors' => array( array( 'reason' => 'authError' ) ) ) ) );
		$this->answer(
			self::YT_TOKEN,
			200,
			array(
				'access_token' => 'yt-nova-access-2',
				'expires_in'   => 3599,
			)
		);
		$this->answer( self::YT_BROADCASTS, 200, array( 'items' => array() ) );
		$result = $this->sender->send_now( $this->channels->find( $nova, ChatPlatform::YouTube ), 'Hi' );

		self::assertSame( array( 'not_live', 'not_live' ), array( $result['result'], $result['reason'] ) );
		self::assertSame( array( 'refresh_token', 'yt-nova-refresh-1' ), array( $this->last_body( self::YT_TOKEN )['grant_type'] ?? null, $this->last_body( self::YT_TOKEN )['refresh_token'] ?? null ) );
		self::assertSame( 'Bearer yt-nova-access-2', $this->requests_to( self::YT_BROADCASTS )[1]['headers']['Authorization'] ?? null );
		self::assertSame( 'yt-nova-access-2', $this->youtube_tokens->access( $nova ), 'Google kept the refresh token; the new access token is stored.' );

		// The creator revoked the access at Google.
		$this->not_live_any_more( $nova );
		$this->answer( self::YT_BROADCASTS, 401, array( 'error' => array( 'errors' => array( array( 'reason' => 'authError' ) ) ) ) );
		$this->answer( self::YT_TOKEN, 400, array( 'error' => 'invalid_grant' ) );
		$result = $this->sender->send_now( $this->channels->find( $nova, ChatPlatform::YouTube ), 'Hi' );

		self::assertSame( array( 'unauthorized', 'authorization_revoked' ), array( $result['result'], $result['reason'] ) );
		self::assertFalse( $this->channels->find( $nova, ChatPlatform::YouTube )->connected(), 'The creator adds the bot again.' );
		self::assertNull( $this->channels->tokens( $nova, ChatPlatform::YouTube ) );
	}

	public function test_the_creator_tests_youtube_and_removes_the_channel(): void {
		$this->routes();
		$nova = $this->creator( 'nova' );
		$this->youtube_creator( $nova );
		wp_set_current_user( $this->user_of( $nova ) );
		$this->answer( self::YT_BROADCASTS, 200, array( 'items' => array() ) );

		$test = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/youtube/test' );
		self::assertSame( array( 'not_live', 'not_live' ), array( $test->get_data()['result'], $test->get_data()['reason'] ), 'Not live: nothing is sent.' );
		self::assertSame( array(), $this->requests_to( self::YT_MESSAGES ) );

		// A purchase waits for the stream; a test message while live sends it.
		$this->log->note( $nova, ChatPlatform::YouTube, 700, 1, ChatMessageRepository::WAITING, 'not_live' );
		$this->not_live_any_more( $nova );
		$this->live( 'video-1', 'chat-1' );
		$this->answer( self::YT_MESSAGES, 200, array( 'id' => 'message-1' ) );
		$test = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/youtube/test' );
		self::assertSame( 'sent', $test->get_data()['result'] );
		self::assertSame( array( array( $nova, 700, 'youtube' ) ), array_column( $this->queue->pending, 'args' ) );

		$connect = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/youtube/connect', array( 'accept_terms' => true ) );
		self::assertStringStartsWith( YouTubeApi::AUTHORIZE_URL . '?', (string) $connect->get_data()['url'] );

		$removed = $this->call( 'DELETE', '/merchboard/v1/me/alerts/chatbot/youtube/channel' );
		self::assertNull( $removed->get_data()['platforms'][2]['channel'] );
		self::assertSame( array( 'yt-nova-refresh-1', 'yt-nova-access-1' ), array_map( static fn( array $request ): string => (string) ( ( (array) $request['body'] )['token'] ?? '' ), $this->requests_to( self::YT_REVOKE ) ), 'Both tokens are revoked at Google.' );
		self::assertNull( $this->channels->tokens( $nova, ChatPlatform::YouTube ) );
		self::assertFalse( get_transient( 'merchboard_youtube_live_' . $nova ), 'The live chat is forgotten.' );
	}

	public function test_the_administrator_sees_the_youtube_setup_and_the_embedded_live_chat(): void {
		$nova = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$this->youtube_creator( $nova );
		$this->as_admin();
		$tab = new ChatbotAdminTab( $this->account, $this->kick, $this->youtube, $this->youtube_platform, $this->format, $this->channels, $this->log, new CreatorRepository( $this->wpdb ), trailingslashit( get_temp_dir() ) . 'merchboard-no-build/', 'https://shop.example.com/wp-content/plugins/merchboard/', $this->youtube_terms );

		ob_start();
		$tab->render( 0 );
		$page = (string) ob_get_clean();
		self::assertStringContainsString( esc_html( YouTubeAccount::redirect_uri() ), $page );
		self::assertStringContainsString( 'merchbot', $page );
		self::assertStringNotContainsString( self::YT_SECRET, $page );

		$this->answer( self::YT_BROADCASTS, 200, array( 'items' => array() ) );
		ob_start();
		$tab->render( $nova, ChatPlatform::YouTube );
		self::assertStringNotContainsString( 'youtube.com/live_chat', (string) ob_get_clean(), 'Not live: no chat to show.' );

		$this->not_live_any_more( $nova );
		$this->live( 'video-1', 'chat-1' );
		ob_start();
		$tab->render( $nova, ChatPlatform::YouTube );
		self::assertStringContainsString( esc_url( 'https://www.youtube.com/live_chat?v=video-1&embed_domain=' . wp_parse_url( home_url(), PHP_URL_HOST ) ), (string) ob_get_clean() );
	}

	public function test_the_youtube_terms_are_a_draft_until_published_and_then_public(): void {
		$this->as_admin();
		$page = new LegalPage( new DashboardRoute(), $this->legal );

		self::assertFalse( $this->legal->published() );
		self::assertSame( YouTubeLegal::PLACEHOLDERS, $this->legal->placeholders() );
		self::assertNull( $page->render( 'privacy', 'en' ), 'A draft is not public (404).' );

		$drafts = array();

		foreach ( YouTubeLegal::DOCUMENTS as $document ) {
			foreach ( YouTubeLegal::LANGUAGES as $language ) {
				$drafts[ YouTubeLegal::key( $document, $language ) ] = $this->legal->text( $document, $language );
			}
		}

		self::assertSame( 'legal_draft', $this->legal->save( $drafts, false ) );
		self::assertSame( 0, $this->legal->version() );

		// Only these paths are public; every other dashboard path still requires a login.
		self::assertSame( array( 'privacy', 'en' ), LegalPage::page( 'legal/youtube-privacy' ) );
		self::assertSame( array( 'terms', 'da' ), LegalPage::page( 'legal/youtube-terms/da' ) );
		self::assertSame( array( 'home', 'en' ), LegalPage::page( 'legal/youtube' ) );

		foreach ( array( '', 'legal', 'legal/other', 'legal/youtube-privacy/de', 'alerts/chatbot' ) as $path ) {
			self::assertNull( LegalPage::page( $path ), $path );
		}

		$this->publish_legal();
		self::assertSame( array( 1, array() ), array( $this->legal->version(), $this->legal->placeholders() ) );

		$privacy = (string) $page->render( 'privacy', 'da' );
		self::assertStringContainsString( '<html lang="da">', $privacy );
		self::assertStringContainsString( 'Merch ApS', $privacy );
		self::assertStringContainsString( 'Google API Services User Data Policy', $privacy );
		self::assertStringContainsString( 'https://myaccount.google.com/permissions', $privacy );
		self::assertStringContainsString( esc_url( home_url( '/creator-dashboard/legal/youtube-privacy/' ) ), $privacy, 'The other language.' );
		self::assertStringNotContainsString( '<script', $privacy );
		$home = (string) $page->render( 'home', 'en' );
		self::assertStringContainsString( esc_url( home_url( '/creator-dashboard/legal/youtube-terms/' ) ), $home );
		self::assertStringContainsString( 'https://www.youtube.com/t/terms', $home );
		self::assertStringContainsString( "default-src 'none'", LegalPage::security_headers()['Content-Security-Policy'] );
		self::assertStringNotContainsString( 'script-src', LegalPage::security_headers()['Content-Security-Policy'] );

		// A correction keeps the version; scripts are removed.
		$texts               = $drafts;
		$texts['privacy_en'] = '<h2>Who we are</h2><p>Merch ApS, privacy@example.com</p><script>alert(1)</script>';
		$texts               = array_map( static fn( string $text ): string => str_replace( YouTubeLegal::PLACEHOLDERS, array( 'Merch ApS', '12345678', 'Merchvej 1', 'privacy@example.com' ), $text ), $texts );
		self::assertSame( 'legal_saved', $this->legal->save( $texts, false ) );
		self::assertSame( 1, $this->legal->version() );
		self::assertStringNotContainsString( '<script', $this->legal->text( 'privacy', 'en' ) );
		self::assertStringNotContainsString( 'script', (string) wp_json_encode( get_option( YouTubeLegal::OPTION ) ) );
		self::assertSame( 'no_changes', $this->legal->save( $texts, false ) );

		// Published texts never get placeholders back; every text needs content.
		self::assertSame( 'legal_placeholders', $this->legal->save( array( 'terms_da' => '[EMAIL]' ) + $texts, true ) );
		self::assertSame( 'legal_empty', $this->legal->save( array( 'terms_da' => '<p> </p>' ) + $texts, true ) );
		self::assertSame( 'legal_renewed', $this->legal->save( $texts, true ) );
		self::assertSame( 2, $this->legal->version() );
		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'version' => 2, 'renewed' => true ), json_decode( (string) ( $audit[ count( $audit ) - 1 ]['context'] ?? '' ), true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( 'Merchvej', (string) wp_json_encode( $audit ), 'The text is not audited.' );

		wp_set_current_user( $this->create_user( 'not_admin' ) );

		try {
			$this->legal->save( $texts, true );
			self::fail( 'Expected ChatbotException.' );
		} catch ( ChatbotException $e ) {
			self::assertSame( ChatbotException::NOT_AUTHORIZED, $e->reason() );
		}
	}

	public function test_the_placeholders_are_filled_from_the_company_details(): void {
		$this->as_admin();
		$drafts = array();

		foreach ( YouTubeLegal::DOCUMENTS as $document ) {
			foreach ( YouTubeLegal::LANGUAGES as $language ) {
				$drafts[ YouTubeLegal::key( $document, $language ) ] = YouTubeLegalDefaults::text( $document, $language );
			}
		}

		self::assertSame( 'legal_draft', $this->legal->save( $drafts, false ), 'No company details yet.' );

		$settings = new AlertSettings( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );

		try {
			$settings->set_company_details( 'Merch ApS', 'not an e-mail' );
			self::fail( 'Expected AlertSettingsException.' );
		} catch ( AlertSettingsException $e ) {
			self::assertSame( AlertSettingsException::INVALID_COMPANY_EMAIL, $e->reason() );
		}

		self::assertTrue( $settings->set_company_details( "  Merch\n ApS ", 'privacy@example.com' ) );
		self::assertSame( array( 'Merch ApS', 'privacy@example.com' ), array( Settings::company_name(), Settings::company_email() ) );
		self::assertFalse( $settings->set_company_details( 'Merch ApS', 'privacy@example.com' ), 'Unchanged.' );
		self::assertSame( array( '[CVR]', '[ADDRESS]' ), $this->legal->placeholders() );

		Settings::save( array( Settings::COMPANY_CVR => '12345678' ) + Settings::all() );
		$store = array(
			'woocommerce_store_address'   => 'Merchvej 1',
			'woocommerce_store_postcode'  => '1000',
			'woocommerce_store_city'      => 'København',
			'woocommerce_default_country' => 'DK',
		);

		foreach ( $store as $option => $value ) {
			update_option( $option, $value );
		}

		self::assertStringStartsWith( 'Merchvej 1, 1000 København, ', CompanyDetails::address() );
		self::assertSame( array(), $this->legal->placeholders() );
		self::assertSame( 'legal_published', $this->legal->save( $drafts, false ) );

		$page    = new LegalPage( new DashboardRoute(), $this->legal );
		$privacy = (string) $page->render( 'privacy', 'en' );
		self::assertStringContainsString( 'Merch ApS (CVR 12345678, Merchvej 1, 1000 København', $privacy );
		self::assertStringContainsString( 'privacy@example.com', $privacy );
		self::assertStringNotContainsString( '[COMPANY]', $privacy );
		self::assertStringContainsString( '[COMPANY]', $this->legal->text( 'privacy', 'en' ), 'The texts keep the placeholders.' );

		// A changed detail is shown at once, without a new version.
		self::assertTrue( $settings->set_company_details( 'Merch & Co ApS', 'privacy@example.com' ) );
		self::assertStringContainsString( 'Merch &amp; Co ApS', (string) $page->render( 'terms', 'da' ) );
		self::assertSame( 1, $this->legal->version() );
		$audit = array_values( array_filter( $this->rows( Tables::AUDIT_LOG ), static fn( array $row ): bool => 'settings_updated' === $row['action'] ) );
		self::assertSame( array( 'company_name', 'company_email', 'company_name' ), array_map( static fn( array $row ): mixed => json_decode( (string) $row['context'], true )['setting'] ?? null, $audit ) );

		// The settings screen shows every detail and saves through its action.
		ob_start();
		( new CompanyDetailsSection() )->render();
		$section = (string) ob_get_clean();
		self::assertStringContainsString( 'value="Merch &amp; Co ApS"', $section );
		self::assertStringContainsString( '12345678', $section );
		self::assertStringContainsString( 'Merchvej 1', $section );
		$actions = new AlertsSettingsActions( $settings, $this->service( Logger::class ) );
		$fields  = array(
			'_wpnonce'      => wp_create_nonce( AlertsSettingsActions::COMPANY_ACTION ),
			'company_name'  => 'Merch ApS',
			'company_email' => 'bad',
		);
		self::assertStringContainsString( 'merchboard_company_result=invalid_company_email', $actions->save_company( $fields ) );
		self::assertStringContainsString( 'merchboard_company_result=saved', $actions->save_company( array( 'company_email' => 'privacy@example.com' ) + $fields ) );
		self::assertSame( 'Merch ApS', Settings::company_name() );

		foreach ( array_keys( $store ) as $option ) {
			delete_option( $option );
		}
	}

	public function test_youtube_needs_the_terms_accepted_by_the_bot_and_the_creator(): void {
		$this->routes();
		$nova = $this->creator( 'nova' );

		$youtube = $this->call( 'GET', '/merchboard/v1/me/alerts/chatbot' )->get_data()['platforms'][2];
		self::assertSame( array( false, null ), array( $youtube['available'], $youtube['terms'] ), 'Not before the texts are published.' );

		$this->set_up_youtube();
		$youtube = $this->call( 'GET', '/merchboard/v1/me/alerts/chatbot' )->get_data()['platforms'][2];
		self::assertTrue( $youtube['available'] );
		self::assertSame(
			array(
				'privacy_url' => home_url( '/creator-dashboard/legal/youtube-privacy/' ),
				'terms_url'   => home_url( '/creator-dashboard/legal/youtube-terms/' ),
				'version'     => 1,
				'accepted'    => false,
			),
			$youtube['terms']
		);
		self::assertNull( $this->call( 'GET', '/merchboard/v1/me/alerts/chatbot' )->get_data()['platforms'][0]['terms'], 'Only YouTube.' );

		// The creator must tick the box before Google's login.
		$refused = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/youtube/connect' );
		self::assertSame( array( 400, 'merchboard_terms_required' ), array( $refused->get_status(), $refused->get_data()['code'] ?? null ) );
		self::assertFalse( $this->youtube_terms->creator_accepted( $nova ) );

		$connect = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/youtube/connect', array( 'accept_terms' => true ) );
		self::assertStringStartsWith( YouTubeApi::AUTHORIZE_URL . '?', (string) $connect->get_data()['url'] );
		self::assertTrue( $this->youtube_terms->creator_accepted( $nova ) );
		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'alert_youtube_terms_accepted', (string) $nova ), array( $audit[ count( $audit ) - 1 ]['action'] ?? null, (string) ( $audit[ count( $audit ) - 1 ]['creator_id'] ?? '' ) ) );
		self::assertSame( array( 'version' => 1, 'role' => 'creator' ), json_decode( (string) ( $audit[ count( $audit ) - 1 ]['context'] ?? '' ), true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// The channel is connected; a new version is published.
		$this->youtube_creator( $nova );
		wp_set_current_user( $this->user_of( $nova ) );
		$this->as_admin();
		$texts = array();

		foreach ( YouTubeLegal::DOCUMENTS as $document ) {
			foreach ( YouTubeLegal::LANGUAGES as $language ) {
				$texts[ YouTubeLegal::key( $document, $language ) ] = $this->legal->text( $document, $language );
			}
		}

		self::assertSame( 'legal_renewed', $this->legal->save( $texts, true ) );
		self::assertFalse( $this->youtube_platform->ready(), 'The bot waits for the administrator.' );
		self::assertFalse( $this->youtube_terms->creator_accepted( $nova ) );

		$this->alert( $nova, 800, 1, 'Hoodie' );
		$this->sender->order_paid( 800 );
		self::assertSame( array(), $this->queue->pending, 'Nothing is queued while the bot has not accepted.' );

		$actions = new ChatbotAdminActions( $this->account, $this->kick, $this->youtube, $this->youtube_oauth, $this->oauth, $this->format, $this->subscriptions, $this->channels, $this->sender, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( Logger::class ), $this->legal, $this->youtube_terms );
		$admin   = fn( string $action, array $fields = array() ): string => $actions->process( $fields + array( 'action' => $action, '_wpnonce' => wp_create_nonce( ChatbotAdminActions::nonce_action( $action ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'merchboard_alerts_admin_result=youtube_bot_terms_required', $admin( ChatbotAdminActions::YOUTUBE_TERMS_ACTION ) );
		self::assertStringContainsString( 'merchboard_alerts_admin_result=youtube_terms_accepted', $admin( ChatbotAdminActions::YOUTUBE_TERMS_ACTION, array( 'accept_terms' => '1' ) ) );
		self::assertTrue( $this->youtube_platform->ready() );
		self::assertStringContainsString( 'merchboard_alerts_admin_result=youtube_bot_terms_required', $admin( ChatbotAdminActions::YOUTUBE_BOT_CONNECT_ACTION ) );
		self::assertStringStartsWith( YouTubeApi::AUTHORIZE_URL . '?', $admin( ChatbotAdminActions::YOUTUBE_BOT_CONNECT_ACTION, array( 'accept_terms' => '1' ) ) );

		// The creator has not accepted version 2: the message is skipped without asking YouTube.
		$this->sender->order_paid( 800 );
		$this->run_jobs();
		self::assertSame( array( 'skipped', 'terms_not_accepted' ), array( $this->log->latest( $nova, ChatPlatform::YouTube )['status'] ?? null, $this->log->latest( $nova, ChatPlatform::YouTube )['reason'] ?? null ) );
		self::assertSame( array(), $this->requests_to( self::YT_BROADCASTS ) );
		self::assertSame( array(), $this->requests_to( self::YT_MESSAGES ) );

		// The creator accepts the new version.
		wp_set_current_user( $this->user_of( $nova ) );
		self::assertSame( 400, $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/youtube/terms' )->get_status() );
		self::assertSame( 400, $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/twitch/terms', array( 'accept' => true ) )->get_status() );
		$accepted = $this->call( 'POST', '/merchboard/v1/me/alerts/chatbot/youtube/terms', array( 'accept' => true ) );
		self::assertSame( array( 200, 2, true ), array( $accepted->get_status(), $accepted->get_data()['platforms'][2]['terms']['version'] ?? null, $accepted->get_data()['platforms'][2]['terms']['accepted'] ?? null ) );
		self::assertTrue( $this->youtube_terms->creator_accepted( $nova ) );

		// The administrator saves a correction through the tab.
		$this->as_admin();
		$texts['terms_en'] .= '<p>Corrected.</p><script>alert(1)</script>';
		$url                = $admin( ChatbotAdminActions::LEGAL_ACTION, $texts );
		self::assertStringContainsString( 'tab=youtube-legal', $url );
		self::assertStringContainsString( 'merchboard_alerts_admin_result=legal_saved', $url );
		self::assertSame( 2, $this->legal->version() );
		self::assertStringNotContainsString( '<script', $this->legal->text( 'terms', 'en' ) );
	}

	/**
	 * A YouTube `channels.list` answer.
	 *
	 * @param string $id     Channel id.
	 * @param string $title  Title.
	 * @param string $handle Handle ('' for none).
	 * @return array<mixed>
	 */
	private static function youtube_channel( string $id, string $title, string $handle ): array {
		return array(
			'items' => array(
				array(
					'id'      => $id,
					'snippet' => array_filter(
						array(
							'title'     => $title,
							'customUrl' => $handle,
						)
					),
				),
			),
		);
	}

	/**
	 * Publishes the privacy policy and terms (the drafts with the
	 * placeholders filled in), as an administrator.
	 */
	private function publish_legal(): void {
		if ( $this->legal->published() ) {
			return;
		}

		$current = get_current_user_id();
		$this->as_admin();
		$texts = array();

		foreach ( YouTubeLegal::DOCUMENTS as $document ) {
			foreach ( YouTubeLegal::LANGUAGES as $language ) {
				$texts[ YouTubeLegal::key( $document, $language ) ] = str_replace( YouTubeLegal::PLACEHOLDERS, array( 'Merch ApS', '12345678', 'Merchvej 1, 1000 København', 'privacy@example.com' ), YouTubeLegalDefaults::text( $document, $language ) );
			}
		}

		self::assertSame( 'legal_published', $this->legal->save( $texts, false ) );
		wp_set_current_user( $current );
	}

	/**
	 * Sets the Google app and the bot (merchbot) up, as an administrator who
	 * accepted the privacy policy and terms for the bot.
	 */
	private function set_up_youtube(): void {
		$this->publish_legal();
		$current = get_current_user_id();
		$this->as_admin();
		$this->youtube->save_app( self::YT_CLIENT, self::YT_SECRET );
		$this->youtube->connect_bot(
			array(
				'id'   => self::YT_BOT,
				'name' => 'merchbot',
			),
			array(
				'access'     => 'yt-bot-access-1',
				'refresh'    => 'yt-bot-refresh-1',
				'expires_in' => 3599,
			)
		);
		// After the app: a new app starts without an accepted version.
		$this->youtube_terms->accept_bot( 1 );
		wp_set_current_user( $current );
	}

	/**
	 * Adds the bot to a creator's YouTube channel with tokens; the creator
	 * (a user is linked when there is none) accepted the privacy policy and
	 * terms.
	 *
	 * @param int $creator_id Creator.
	 */
	private function youtube_creator( int $creator_id ): void {
		$this->set_up_youtube();
		$user = $this->user_of( $creator_id );

		if ( 0 === $user ) {
			$user = $this->create_user( 'creator' . $creator_id );
			$this->execute( 'UPDATE %i SET user_id = %d WHERE id = %d', $this->table( Tables::CREATORS ), $user, $creator_id );
		}

		$this->youtube_terms->accept_creator( $creator_id, $user );
		$this->channels->connect( $creator_id, ChatPlatform::YouTube, self::YT_CREATOR, 'Nova Live' );
		$this->channels->set_subscription( $creator_id, ChatPlatform::YouTube, '', YouTubePlatform::EMBEDDED );
		$this->youtube_tokens->store(
			$creator_id,
			array(
				'access'     => 'yt-nova-access-1',
				'refresh'    => 'yt-nova-refresh-1',
				'expires_in' => 3599,
			)
		);
	}

	/**
	 * Queues YouTube's answer: the creator is live.
	 *
	 * @param string $video Video id.
	 * @param string $chat  Live chat id.
	 */
	private function live( string $video, string $chat ): void {
		$this->answer(
			self::YT_BROADCASTS,
			200,
			array(
				'items' => array(
					array(
						'id'      => $video,
						'snippet' => array( 'liveChatId' => $chat ),
					),
				),
			)
		);
	}

	/**
	 * Forgets the looked-up livestream, as when its time is up.
	 *
	 * @param int $creator_id Creator.
	 */
	private function not_live_any_more( int $creator_id ): void {
		delete_transient( 'merchboard_youtube_live_' . $creator_id );
	}

	/**
	 * A new RSA key for signing test events. Some PHP builds have no
	 * openssl.cnf; a minimal one is used then.
	 */
	private static function rsa_key(): OpenSSLAsymmetricKey {
		$options = array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);
		$key     = openssl_pkey_new( $options );

		if ( false === $key ) {
			$config = wp_tempnam( 'merchboard-openssl' );
			file_put_contents( $config, "[req]\ndistinguished_name = dn\n[dn]\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			$key = openssl_pkey_new( $options + array( 'config' => $config ) );
			wp_delete_file( $config );
		}

		self::assertInstanceOf( OpenSSLAsymmetricKey::class, $key );

		return $key;
	}

	/**
	 * The body of the latest request to a URL.
	 *
	 * @param string $key "METHOD URL".
	 * @return array<mixed>
	 */
	private function last_body( string $key ): array {
		$requests = $this->requests_to( $key );

		return (array) ( $requests[ count( $requests ) - 1 ]['body'] ?? array() );
	}

	/**
	 * Sets the Kick app up (as an administrator).
	 */
	private function set_up_kick(): void {
		$current = get_current_user_id();
		$this->as_admin();
		$this->answer(
			self::KICK_TOKEN,
			200,
			array(
				'access_token' => 'kickapptoken',
				'token_type'   => 'Bearer',
				'expires_in'   => 5000000,
			)
		);
		$this->kick->save_app( self::KICK_CLIENT, self::KICK_SECRET, 'merchbot' );
		wp_set_current_user( $current );
	}

	/**
	 * Adds the bot to a creator's Kick channel (777, nova_kick) with tokens.
	 *
	 * @param int $creator_id Creator.
	 * @param int $expires_in Seconds until the access token expires.
	 */
	private function kick_creator( int $creator_id, int $expires_in ): void {
		$this->set_up_kick();
		$this->channels->connect( $creator_id, ChatPlatform::Kick, '777', 'nova_kick' );
		$this->kick_tokens->store(
			$creator_id,
			array(
				'access'     => 'kick-access-1',
				'refresh'    => 'kick-refresh-1',
				'expires_in' => $expires_in,
			)
		);
	}

	/**
	 * Posts a signed Kick event to the webhook.
	 *
	 * @param array<mixed>         $payload   Payload.
	 * @param OpenSSLAsymmetricKey $key       Private key that signs it.
	 * @param string               $type      Event type.
	 * @param string               $id        Delivery id.
	 * @param string|null          $timestamp Timestamp; null for now.
	 */
	private function kick_event( array $payload, OpenSSLAsymmetricKey $key, string $type = 'chat.message.sent', string $id = 'delivery-1', ?string $timestamp = null ): WP_REST_Response {
		$timestamp ??= gmdate( 'Y-m-d\TH:i:s\Z' );
		$body        = (string) wp_json_encode( $payload );
		$signature   = '';
		self::assertTrue( openssl_sign( $id . '.' . $timestamp . '.' . $body, $signature, $key, OPENSSL_ALGO_SHA256 ) );
		$request = new WP_REST_Request( 'POST', '/merchboard/v1/alerts/kick/webhook' );
		$request->set_header( 'Kick-Event-Message-Id', $id );
		$request->set_header( 'Kick-Event-Message-Timestamp', $timestamp );
		$request->set_header( 'Kick-Event-Signature', base64_encode( (string) $signature ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A signature, as Kick sends it.
		$request->set_header( 'Kick-Event-Type', $type );
		$request->set_header( 'Kick-Event-Version', '1' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );

		return rest_do_request( $request );
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
		$this->channels->connect( $creator, ChatPlatform::Twitch, '500', $login );

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
	 * @param int         $creator_id Creator.
	 * @param int         $order_id   Order.
	 * @param int         $item_id    Order line.
	 * @param string      $product       Product name.
	 * @param string|null $line_total    What was paid for the line.
	 * @param string|null $creator_total What was paid for the creator's lines.
	 */
	private function alert( int $creator_id, int $order_id, int $item_id, string $product, ?string $line_total = null, ?string $creator_total = null ): void {
		$this->events->insert( new AlertEvent( $creator_id, $order_id, (string) ( 1000 + $order_id ), $item_id, 70, 0, $product, '', 1, 0, array(), 'nova_fan', new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 0, $line_total, $creator_total, 'DKK' ) );
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
		$creator        = new CreatorChatbot( $this->platforms, $this->channels, $this->log, $this->format, new ChatMessages( new AlertTemplate() ), $this->subscriptions, $this->sender, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->youtube_terms );
		$me             = new MeChatbotController( $this->service( CreatorContext::class ), $creator, $this->oauth, $this->kick_oauth, $this->youtube_oauth, $logger );
		$chat           = new ChatbotChatController( $this->channels, $this->chat, $logger );
		$kick           = new KickController( $this->kick_oauth, new KickWebhookVerifier( $this->kick_api ), $this->channels, $this->chat, $logger );
		$youtube        = new YouTubeController( $this->youtube_oauth, $logger );
		$twitch         = $this->twitch;
		$this->register = static function () use ( $me, $twitch, $chat, $kick, $youtube ): void {
			$me->register_routes();
			$twitch->register_routes();
			$chat->register_routes();
			$kick->register_routes();
			$youtube->register_routes();
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
