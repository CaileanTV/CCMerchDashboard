<?php
/**
 * The creator's Twitch subscriber discount: settings and which lines get it (ADR-106).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Community;

use Closure;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\CustomerSessionStore;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchApi;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerLogin;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscount;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountRepository;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountSettings;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * Twitch is a recorded transport; the customer's session is an array. Nova
 * owns products 501 and 502 and has a connected channel; Orion owns 601 and
 * has none.
 */
final class TwitchSubDiscountTest extends IntegrationTestCase {

	private const CHANNEL = '4711';

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
	 * The customer's session.
	 *
	 * @var array<string, mixed>
	 */
	public array $stored = array();

	/**
	 * Creators whose own code is in the cart.
	 *
	 * @var list<int>
	 */
	private array $coded = array();

	private string $cookie = '';

	private int $nova;

	private int $orion;

	private TwitchSubDiscountRepository $settings;

	private TwitchSubDiscount $discount;

	private TwitchCustomerLogin $login;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators    = new CreatorRepository( $this->wpdb );
		$this->nova  = $creators->create( 'Nova', CreatorStatus::Active )->id;
		$this->orion = $creators->create( 'Orion', CreatorStatus::Active )->id;
		$channels    = new ChatbotChannelRepository( $this->wpdb );
		$channels->connect( $this->nova, ChatPlatform::Twitch, self::CHANNEL, 'nova' );

		$owners         = array(
			501 => $this->nova,
			502 => $this->nova,
			601 => $this->orion,
		);
		$this->settings = new TwitchSubDiscountRepository(
			$this->service( CreatorMetaStore::class ),
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			static fn( int $creator_id, int $product_id ): bool => ( $owners[ $product_id ] ?? null ) === $creator_id,
			static fn( int $creator_id ): bool => $channels->find( $creator_id, ChatPlatform::Twitch )->connected()
		);

		$api     = new TwitchApi( $this->transport() );
		$account = new TwitchAccount( $api, new ChatCipher( ChatPlatform::Twitch ), $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$store   = new class( $this ) implements CustomerSessionStore {
			/**
			 * Constructor.
			 *
			 * @param TwitchSubDiscountTest $test The test.
			 */
			public function __construct( private TwitchSubDiscountTest $test ) {
			}

			/**
			 * A stored value.
			 *
			 * @param string $key Key.
			 */
			public function get( string $key ): mixed {
				return $this->test->stored[ $key ] ?? null;
			}

			/**
			 * Stores a value.
			 *
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 */
			public function set( string $key, mixed $value ): void {
				$this->test->stored[ $key ] = $value;
			}
		};

		$this->login    = new TwitchCustomerLogin(
			$account,
			$api,
			new TwitchCustomerSession( $store, new ChatCipher( 'shop-twitch-customer' ) ),
			static fn( string $target ): string => home_url( '/' . $target . '/' ),
			function ( string $value ): void {
				$this->cookie = $value;
			}
		);
		$this->discount = $this->discount();

		wp_set_current_user( 1 );
		$this->answer(
			self::TOKEN_URL,
			200,
			array(
				'access_token' => 'app-token',
				'expires_in'   => 5000000,
			)
		);
		$account->save_app( 'client-c', 'secret-c' );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		delete_option( TwitchAccount::OPTION );
		parent::tearDown();
	}

	public function test_the_creator_saves_percents_per_tier_and_the_audit_names_only_fields(): void {
		$saved = $this->settings->save( $this->nova, $this->input() );

		self::assertTrue( $saved->active() );
		self::assertSame(
			array(
				1 => 1000,
				2 => 1500,
				3 => 2000,
			),
			$this->settings->find( $this->nova )->tiers
		);

		$audit = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT action, context FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ) ), ARRAY_A );
		self::assertSame( 'twitch_sub_discount_changed', $audit['action'] ?? null );
		self::assertStringNotContainsString( '1500', (string) ( $audit['context'] ?? '' ), 'Field names only.' );

		$before = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( Tables::AUDIT_LOG ) ) );
		$this->settings->save( $this->nova, $this->input() );
		self::assertSame( $before, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( Tables::AUDIT_LOG ) ) ), 'Nothing changed, nothing audited.' );
	}

	public function test_invalid_settings_are_refused_per_field(): void {
		$this->refused(
			array( 'tier_2' => 'invalid' ),
			$this->nova,
			array(
				'tiers' => array(
					1 => 10,
					2 => 101,
					3 => 0,
				),
			)
		);
		$this->refused(
			array( 'tier_1' => 'required' ),
			$this->nova,
			array(
				'tiers' => array(
					1 => 0,
					2 => 0,
					3 => 0,
				),
			)
		);
		$this->refused(
			array( 'product_ids' => 'invalid' ),
			$this->nova,
			array(
				'scope'       => TwitchSubDiscountSettings::SELECTED,
				'product_ids' => array( 601 ),
			)
		);
		$this->refused(
			array( 'product_ids' => 'required' ),
			$this->nova,
			array(
				'scope'       => TwitchSubDiscountSettings::SELECTED,
				'product_ids' => array(),
			)
		);
		$this->refused( array( 'enabled' => 'not_connected' ), $this->orion, array() );

		$off = $this->settings->save( $this->orion, $this->input( array( 'enabled' => false ) ) );
		self::assertFalse( $off->enabled, 'Without a channel it can be saved switched off.' );
	}

	public function test_lines_get_the_discount_of_the_customers_tier_on_the_creators_channel(): void {
		$this->settings->save(
			$this->nova,
			$this->input(
				array(
					'scope'       => TwitchSubDiscountSettings::SELECTED,
					'product_ids' => array( 501 ),
				)
			)
		);

		self::assertNull( $this->discount->rate( 501 ), 'Not logged in with Twitch.' );
		self::assertSame( 20, $this->discount->highest_offer( array( 501, 601 ) ), 'Offered anyway (up to 20 %).' );

		$this->log_in();
		$this->answer(
			self::SUB_URL,
			200,
			array(
				'data' => array(
					array(
						'tier'    => '2000',
						'is_gift' => false,
					),
				),
			)
		);
		$this->discount = $this->discount();

		self::assertSame(
			array(
				'creator_id' => $this->nova,
				'tier'       => 2,
				'bps'        => 1500,
				'gifted'     => false,
			),
			$this->discount->rate( 501 )
		);
		self::assertNull( $this->discount->rate( 502 ), 'Not selected.' );
		self::assertNull( $this->discount->rate( 601 ), 'Another creator without the discount.' );

		$this->coded    = array( $this->nova );
		$this->discount = $this->discount();
		self::assertNull( $this->discount->rate( 501 ), 'The creator\'s own code is in the cart (ADR-068).' );
	}

	public function test_gifted_subscriptions_follow_the_creators_choice_and_the_check_before_the_order_sees_changes(): void {
		$this->settings->save( $this->nova, $this->input( array( 'gifted' => false ) ) );
		$this->log_in();
		$this->answer(
			self::SUB_URL,
			200,
			array(
				'data' => array(
					array(
						'tier'    => '1000',
						'is_gift' => true,
					),
				),
			)
		);

		self::assertNull( $this->discount->rate( 501 ), 'A gift does not count.' );

		$this->settings->save( $this->nova, $this->input() );
		$this->discount = $this->discount();
		self::assertSame( 1000, $this->discount->rate( 501 )['bps'] ?? null, 'Reused answer: tier 1, gift counts now.' );

		$this->answer( self::SUB_URL, 404, array() );
		self::assertTrue( $this->discount->recheck( array( 501 ) ), 'The subscription ended before the order.' );
		self::assertNull( $this->discount->rate( 501 ) );

		$this->answer( self::SUB_URL, 404, array() );
		self::assertFalse( $this->discount->recheck( array( 501 ) ), 'The next attempt goes through.' );
	}

	public function test_twitch_not_answering_gives_no_discount(): void {
		$this->settings->save( $this->nova, $this->input() );
		$this->log_in();
		$this->answer( self::SUB_URL, 500, array() );

		self::assertNull( $this->discount->rate( 501 ) );
		self::assertNull( $this->discount()->rate( 501 ), 'Kept as none for a while.' );
	}

	/**
	 * A fresh discount service (its answers are per request).
	 */
	private function discount(): TwitchSubDiscount {
		$owners = array(
			501 => $this->nova,
			502 => $this->nova,
			601 => $this->orion,
		);

		return new TwitchSubDiscount(
			$this->settings,
			$this->login,
			static fn( int $product_id ): ?int => $owners[ $product_id ] ?? null,
			fn( int $creator_id ): ?string => $creator_id === $this->nova ? self::CHANNEL : null,
			fn(): array => $this->coded,
			new RecordingLogger()
		);
	}

	/**
	 * Logs the customer in with Twitch.
	 */
	private function log_in(): void {
		parse_str( (string) wp_parse_url( $this->login->start( 'cart', 0 ), PHP_URL_QUERY ), $query );
		$this->answer(
			self::TOKEN_URL,
			200,
			array(
				'access_token' => 'user-token',
				'expires_in'   => 14400,
			)
		);
		$this->answer(
			self::USERS_URL,
			200,
			array(
				'data' => array(
					array(
						'id'    => '815',
						'login' => 'viewer',
					),
				),
			)
		);
		$this->login->finish( is_string( $query['state'] ?? null ) ? $query['state'] : '', 'code', '', $this->cookie, 0 );
	}

	/**
	 * The creator's form with changes.
	 *
	 * @param array<string, mixed> $changes Changed fields.
	 * @return array<string, mixed>
	 */
	private function input( array $changes = array() ): array {
		return $changes + array(
			'enabled'     => true,
			'tiers'       => array(
				1 => 10,
				2 => 15,
				3 => 20,
			),
			'gifted'      => true,
			'scope'       => TwitchSubDiscountSettings::ALL,
			'product_ids' => array(),
		);
	}

	/**
	 * Asserts a refusal with exactly these field codes.
	 *
	 * @param array<string, string> $fields     Expected codes.
	 * @param int                   $creator_id Creator.
	 * @param array<string, mixed>  $changes    Changed fields.
	 */
	private function refused( array $fields, int $creator_id, array $changes ): void {
		try {
			$this->settings->save( $creator_id, $this->input( $changes ) );
			self::fail( 'Refused expected.' );
		} catch ( TwitchSubDiscountInvalid $e ) {
			self::assertSame( $fields, $e->fields() );
		}
	}

	/**
	 * The recording transport.
	 */
	private function transport(): Closure {
		return function ( string $method, string $url ): array {
			$key = $method . ' ' . strtok( $url, '?' );

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
}
