<?php
/**
 * A shop customer's Twitch login and subscription check (ADR-106).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use Closure;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;
use MerchBoard\Modules\Alerts\Chatbot\ChatLogRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatforms;
use MerchBoard\Modules\Alerts\Chatbot\ChatSubscriptions;
use MerchBoard\Modules\Alerts\Chatbot\CustomerSessionStore;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchApi;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerLogin;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\Alerts\Chatbot\TwitchException;
use MerchBoard\Modules\Alerts\Chatbot\TwitchOAuth;
use MerchBoard\Modules\Alerts\Chatbot\TwitchPlatform;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Tests\Integration\IntegrationTestCase;

/**
 * Twitch is a recorded transport, as in ChatbotTest; the customer's session
 * is an array, the binding cookie is captured.
 */
final class TwitchCustomerLoginTest extends IntegrationTestCase {

	private const CLIENT_ID = 'clientcustomer01';

	private const SECRET = 'secretcustomer01';

	private const CHANNEL = '4711';

	private const CUSTOMER = '815';

	private const TOKEN_URL = 'POST https://id.twitch.tv/oauth2/token';

	private const USERS_URL = 'GET https://api.twitch.tv/helix/users';

	private const SUB_URL = 'GET https://api.twitch.tv/helix/subscriptions/user';

	/**
	 * Queued answers by "METHOD URL".
	 *
	 * @var array<string, list<array{status: int, headers: array<string, string>, body: string}>>
	 */
	private array $answers = array();

	/**
	 * Requests sent.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>}>
	 */
	private array $requests = array();

	/**
	 * The customer's session.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = array();

	private string $cookie = '';

	private int $now = 1_800_000_000;

	private TwitchAccount $account;

	private TwitchCustomerSession $session;

	private TwitchCustomerLogin $login;

	private TwitchOAuth $oauth;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$audit       = $this->service( AuditTrail::class );
		$transaction = $this->service( Transaction::class );
		$api         = new TwitchApi( $this->transport() );

		$this->account = new TwitchAccount( $api, new ChatCipher( ChatPlatform::Twitch ), $audit, $transaction );
		$store         = new class( $this ) implements CustomerSessionStore {
			/**
			 * Constructor.
			 *
			 * @param TwitchCustomerLoginTest $test The test.
			 */
			public function __construct( private TwitchCustomerLoginTest $test ) {
			}

			/**
			 * A stored value.
			 *
			 * @param string $key Key.
			 */
			public function get( string $key ): mixed {
				return $this->test->stored( $key );
			}

			/**
			 * Stores a value.
			 *
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 */
			public function set( string $key, mixed $value ): void {
				$this->test->store( $key, $value );
			}
		};
		$this->session = new TwitchCustomerSession( $store, new ChatCipher( 'shop-twitch-customer' ), fn(): int => $this->now );
		$this->login   = new TwitchCustomerLogin(
			$this->account,
			$api,
			$this->session,
			static fn( string $target ): string => home_url( '/' . $target . '/' ),
			function ( string $value ): void {
				$this->cookie = $value;
			}
		);
		$channels      = new ChatbotChannelRepository( $this->wpdb );
		$this->oauth   = new TwitchOAuth( $this->account, $api, $channels, new ChatSubscriptions( new ChatPlatforms( new TwitchPlatform( $this->account, $api ) ), $channels, new ChatLogRepository( $this->wpdb ) ), $audit, $transaction, static fn( string $path ): string => home_url( '/' . $path . '/' ), $this->service( CreatorContext::class ), $this->login );

		wp_set_current_user( 1 );
		$this->answer(
			self::TOKEN_URL,
			200,
			array(
				'access_token' => 'app-token',
				'expires_in'   => 5000000,
			)
		);
		$this->account->save_app( self::CLIENT_ID, self::SECRET );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		delete_option( TwitchAccount::OPTION );
		unset( $_COOKIE['merchboard_twitch_oauth'] );
		parent::tearDown();
	}

	/**
	 * The test session's value.
	 *
	 * @param string $key Key.
	 */
	public function stored( string $key ): mixed {
		return $this->stored[ $key ] ?? null;
	}

	/**
	 * Stores in the test session.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value; null removes it.
	 */
	public function store( string $key, mixed $value ): void {
		if ( null === $value ) {
			unset( $this->stored[ $key ] );
		} else {
			$this->stored[ $key ] = $value;
		}
	}

	public function test_a_guest_logs_in_and_the_token_stays_encrypted_in_the_session(): void {
		$url   = $this->login->start( 'checkout', 0 );
		$query = $this->query( $url );

		self::assertStringStartsWith( TwitchApi::AUTHORIZE_URL . '?', $url );
		self::assertSame( 'user:read:subscriptions', $query['scope'] ?? null );
		self::assertSame( TwitchAccount::redirect_uri(), $query['redirect_uri'] ?? null, 'The app\'s existing callback.' );
		self::assertSame( 'false', $query['force_verify'] ?? null, 'A returning customer is not asked again.' );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $this->cookie, 'The state is bound to this browser.' );

		$location = $this->finish( $query['state'] ?? '', $this->cookie );

		self::assertSame( home_url( '/checkout/' ) . '?twitch=connected', $location );
		self::assertSame(
			array(
				'id'    => self::CUSTOMER,
				'login' => 'viewer_one',
			),
			$this->session->account()
		);
		self::assertSame( 'user-token', $this->session->token() );
		self::assertStringNotContainsString( 'user-token', (string) wp_json_encode( $this->stored ), 'Encrypted in the session.' );
		self::assertSame( '', $this->cookie, 'The binding cookie is cleared.' );
		self::assertSame( 'Bearer user-token', end( $this->requests )['headers']['Authorization'] ?? null );
	}

	public function test_a_state_is_used_once_and_only_by_the_browser_that_started(): void {
		$state   = $this->query( $this->login->start( 'cart', 0 ) )['state'] ?? '';
		$binding = $this->cookie;

		self::assertSame( home_url( '/cart/' ) . '?twitch=denied', $this->finish( $state, str_repeat( 'b', 32 ) ), 'Another browser.' );
		self::assertNull( $this->session->account() );
		self::assertSame( home_url( '/cart/' ), $this->finish( $state, $binding ), 'Used once.' );

		$state = $this->query( $this->login->start( 'cart', 0 ) )['state'] ?? '';
		self::assertSame( home_url( '/cart/' ) . '?twitch=denied', $this->oauth_finish( $state, $this->cookie, '', 'access_denied' ), 'Refused at Twitch.' );
	}

	public function test_a_logged_in_customer_is_remembered_and_logging_out_forgets_both(): void {
		$user = $this->create_user( 'shopper43' );
		wp_set_current_user( $user );
		$state = $this->query( $this->login->start( 'cart', $user ) )['state'] ?? '';
		wp_set_current_user( 0 );

		self::assertSame( home_url( '/cart/' ) . '?twitch=denied', $this->finish( $state, $this->cookie ), 'The browser is not logged in as that user any more.' );

		$state = $this->query( $this->login->start( 'cart', $user ) )['state'] ?? '';
		$this->finish( $state, $this->cookie, $user );

		self::assertSame(
			array(
				'id'    => self::CUSTOMER,
				'login' => 'viewer_one',
			),
			$this->session->remembered( $user )
		);

		$this->session->forget( $user );
		self::assertNull( $this->session->account() );
		self::assertNull( $this->session->remembered( $user ) );
	}

	public function test_the_subscription_is_checked_with_the_customers_token_and_reused_briefly(): void {
		$this->logged_in_guest();
		$this->answer(
			self::SUB_URL,
			200,
			array(
				'data' => array(
					array(
						'tier'    => '2000',
						'is_gift' => true,
					),
				),
			)
		);

		self::assertSame(
			array(
				'tier'   => 2,
				'gifted' => true,
			),
			$this->login->subscription( self::CHANNEL )
		);
		$sub = end( $this->requests );
		self::assertSame( 'https://api.twitch.tv/helix/subscriptions/user?broadcaster_id=4711&user_id=815', $sub['url'] ?? null );
		self::assertSame( 'Bearer user-token', $sub['headers']['Authorization'] ?? null );

		$count = count( $this->requests );
		self::assertSame(
			array(
				'tier'   => 2,
				'gifted' => true,
			),
			$this->login->subscription( self::CHANNEL ),
			'Reused in the cart.'
		);
		self::assertCount( $count, $this->requests );

		$this->answer( self::SUB_URL, 404, array( 'error' => 'Not Found' ) );
		self::assertSame(
			array(
				'tier'   => 0,
				'gifted' => false,
			),
			$this->login->subscription( self::CHANNEL, true ),
			'The check before the order always asks again.'
		);
	}

	public function test_an_expired_or_refused_token_logs_out_and_an_unreachable_twitch_throws(): void {
		$this->logged_in_guest();
		$this->answer( self::SUB_URL, 500, array() );

		try {
			$this->login->subscription( self::CHANNEL, true );
			self::fail( 'Twitch unreachable.' );
		} catch ( TwitchException $e ) {
			self::assertSame( TwitchException::UNAVAILABLE, $e->reason() );
		}

		$this->answer( self::SUB_URL, 401, array() );
		self::assertNull( $this->login->subscription( self::CHANNEL, true ) );
		self::assertNull( $this->session->account(), 'A refused token logs the customer out.' );

		$this->logged_in_guest();
		$this->now += 4 * HOUR_IN_SECONDS;
		self::assertNull( $this->login->subscription( self::CHANNEL, true ), 'Expired.' );
		self::assertNull( $this->session->token() );
	}

	public function test_without_an_app_the_customer_goes_back_with_unavailable(): void {
		wp_set_current_user( 1 );
		$this->account->remove_app();
		wp_set_current_user( 0 );

		self::assertSame( home_url( '/cart/' ) . '?twitch=unavailable', $this->login->start( 'cart', 0 ) );
	}

	/**
	 * A guest logged in with Twitch.
	 */
	private function logged_in_guest(): void {
		$state = $this->query( $this->login->start( 'cart', 0 ) )['state'] ?? '';
		$this->finish( $state, $this->cookie );
	}

	/**
	 * Twitch's redirect handled by the login, with its token answers queued.
	 *
	 * @param string $state   State.
	 * @param string $binding The browser's binding cookie.
	 * @param int    $user    The user the browser is logged in as.
	 */
	private function finish( string $state, string $binding, int $user = 0 ): string {
		$this->answer(
			self::TOKEN_URL,
			200,
			array(
				'access_token'  => 'user-token',
				'refresh_token' => 'never-kept',
				'expires_in'    => 14400,
			)
		);
		$this->answer(
			self::USERS_URL,
			200,
			array(
				'data' => array(
					array(
						'id'    => self::CUSTOMER,
						'login' => 'Viewer_One',
					),
				),
			)
		);

		return $this->login->finish( $state, 'code-c', '', $binding, $user );
	}

	/**
	 * Twitch's redirect through the app's callback (TwitchOAuth).
	 *
	 * @param string $state   State.
	 * @param string $binding The browser's binding cookie.
	 * @param string $code    Code.
	 * @param string $error   Twitch's error.
	 */
	private function oauth_finish( string $state, string $binding, string $code, string $error ): string {
		$_COOKIE['merchboard_twitch_oauth'] = $binding;

		return $this->oauth->finish( $state, $code, $error );
	}

	/**
	 * The recording transport.
	 */
	private function transport(): Closure {
		return function ( string $method, string $url, array $headers, ?string $body ): array {
			unset( $body );
			$this->requests[] = array(
				'method'  => $method,
				'url'     => $url,
				'headers' => $headers,
			);
			$key              = $method . ' ' . strtok( $url, '?' );

			return array() === ( $this->answers[ $key ] ?? array() ) ? array(
				'status'  => 0,
				'headers' => array(),
				'body'    => '',
			) : array_shift( $this->answers[ $key ] );
		};
	}

	/**
	 * Queues Twitch's answer.
	 *
	 * @param string       $key    "METHOD URL".
	 * @param int          $status Status.
	 * @param array<mixed> $body   JSON body.
	 */
	private function answer( string $key, int $status, array $body ): void {
		$this->answers[ $key ][] = array(
			'status'  => $status,
			'headers' => array(),
			'body'    => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * A URL's query.
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
}
