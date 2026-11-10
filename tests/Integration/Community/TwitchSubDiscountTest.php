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
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCampaign;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscount;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountRepository;
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
		$percents       = array(
			501 => 2500,
			502 => 1667,
			601 => 3000,
		);
		$this->settings = new TwitchSubDiscountRepository(
			$this->service( CreatorMetaStore::class ),
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			static fn( int $creator_id, int $product_id ): bool => ( $owners[ $product_id ] ?? null ) === $creator_id,
			static fn( int $creator_id ): bool => $channels->find( $creator_id, ChatPlatform::Twitch )->connected(),
			static function ( int $creator_id, ?array $product_ids ) use ( $owners, $percents ): array {
				$own = array_keys( array_filter( $owners, static fn( int $owner ): bool => $owner === $creator_id ) );

				return array_intersect_key( $percents, array_flip( $product_ids ?? $own ) );
			}
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
		$account->save_app( 'clientcustomer01', 'secretcustomer01' );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		delete_option( TwitchAccount::OPTION );
		parent::tearDown();
	}

	public function test_the_creator_saves_campaigns_and_the_audit_names_only_fields(): void {
		$saved = $this->settings->save( $this->nova, $this->input( array( self::campaign() ) ) );
		$id    = $saved->campaigns[0]->id;

		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $id );
		self::assertSame(
			array(
				1 => 1000,
				2 => 1500,
				3 => 1667,
			),
			$this->settings->find( $this->nova )->campaigns[0]->tiers
		);

		$audit = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT action, context FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ) ), ARRAY_A );
		self::assertSame( 'twitch_sub_discount_changed', $audit['action'] ?? null );
		self::assertStringNotContainsString( '1500', (string) ( $audit['context'] ?? '' ), 'Field names only.' );

		$before = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( Tables::AUDIT_LOG ) ) );
		$again  = $this->settings->save( $this->nova, $this->input( array( self::campaign( array( 'id' => $id ) ) ) ) );
		self::assertSame( $id, $again->campaigns[0]->id, 'The id is kept.' );
		self::assertSame( $before, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( Tables::AUDIT_LOG ) ) ), 'Nothing changed, nothing audited.' );
	}

	public function test_invalid_campaigns_are_refused_per_field(): void {
		$this->refused( array( 'campaigns.0.tier_two' => 'invalid' ), $this->nova, array( self::campaign( array( 'tiers' => array( 1 => 10, 2 => 101, 3 => 0 ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		$this->refused( array( 'campaigns.0.tier_one' => 'required' ), $this->nova, array( self::campaign( array( 'tiers' => array( 1 => 0, 2 => 0, 3 => 0 ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		$this->refused( array( 'campaigns.0.name' => 'required' ), $this->nova, array( self::campaign( array( 'name' => ' ' ) ) ) );
		$this->refused( array( 'campaigns.0.product_ids' => 'invalid' ), $this->nova, array( self::selection( array( 601 ) ) ) );
		$this->refused( array( 'campaigns.0.product_ids' => 'required' ), $this->nova, array( self::selection( array() ) ) );
		$this->refused( array( 'campaigns.0.ends' => 'before_start' ), $this->nova, array( self::campaign( array( 'starts' => '2026-11-20', 'ends' => '2026-11-19' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		$this->refused( array( 'campaigns.0.enabled' => 'not_connected' ), $this->orion, array( self::campaign() ) );

		$off = $this->settings->save( $this->orion, $this->input( array( self::campaign( array( 'enabled' => false ) ) ) ) );
		self::assertFalse( $off->campaigns[0]->enabled, 'Without a channel it can be saved switched off.' );
	}

	public function test_no_percent_above_the_lowest_profit_percent_of_the_campaigns_products(): void {
		// Nova's products: 501 at 25 %, 502 at 16.67 %. "All" may give at most 16.67 %.
		$this->refused( array( 'campaigns.0.tier_three' => 'too_high' ), $this->nova, array( self::campaign( array( 'tiers' => array( 1 => 10, 2 => 15, 3 => 16.68 ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		$this->refused( array( 'campaigns.0.tier_one' => 'invalid' ), $this->nova, array( self::campaign( array( 'tiers' => array( 1 => 10.555, 2 => 15, 3 => 16 ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( 1667, $this->settings->save( $this->nova, $this->input( array( self::campaign() ) ) )->campaigns[0]->tiers[3], 'Exactly the lowest profit, with decimals.' );

		// A selection of 501 only may give up to 25 %.
		$saved = $this->settings->save( $this->nova, $this->input( array( self::selection( array( 501 ), array( 'tiers' => array( 1 => 25, 2 => 25, 3 => 25 ) ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( 2500, $saved->campaigns[0]->tiers[3] );
		self::assertSame( array( 501 => 2500, 502 => 1667 ), $this->settings->profit_percents( $this->nova ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
	}

	public function test_a_product_is_in_one_selection_and_one_all_campaign_at_a_time(): void {
		$this->refused( array( 'campaigns.1.product_ids' => 'taken' ), $this->nova, array( self::selection( array( 501 ) ), self::selection( array( 501, 502 ) ) ) );
		$this->refused( array( 'campaigns.1.scope' => 'taken' ), $this->nova, array( self::campaign(), self::campaign() ) );

		// Periods that do not meet, or a campaign that is off, are fine.
		$saved = $this->settings->save(
			$this->nova,
			$this->input(
				array(
					self::campaign( array( 'ends' => '2026-11-30' ) ),
					self::campaign( array( 'starts' => '2026-12-01' ) ),
					self::selection( array( 501 ) ),
					self::selection( array( 501 ), array( 'enabled' => false ) ),
				)
			)
		);
		self::assertCount( 4, $saved->campaigns );
	}

	public function test_lines_get_the_campaign_of_the_product_and_the_customers_tier(): void {
		$this->settings->save(
			$this->nova,
			$this->input(
				array(
					self::campaign( array( 'tiers' => array( 1 => 5, 2 => 10, 3 => 15 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
					self::selection( array( 501 ), array( 'tiers' => array( 1 => 10, 2 => 20, 3 => 25 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
				)
			)
		);
		$selection = $this->settings->find( $this->nova )->campaigns[1]->id;

		self::assertNull( $this->discount->rate( 501 ), 'Not logged in with Twitch.' );
		self::assertSame( 2500, $this->discount->highest_offer( array( 501, 601 ) ), 'Offered anyway (up to 25 %, in basis points).' );

		$this->log_in();
		$this->answer( self::SUB_URL, 200, array( 'data' => array( array( 'tier' => '2000', 'is_gift' => false ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		$this->discount = $this->discount();

		self::assertSame(
			array(
				'creator_id'  => $this->nova,
				'campaign_id' => $selection,
				'tier'        => 2,
				'bps'         => 2000,
				'gifted'      => false,
			),
			$this->discount->rate( 501 ),
			'The selection goes before "all".'
		);
		self::assertSame( 1000, $this->discount->rate( 502 )['bps'] ?? null, '"All" covers the rest.' );
		self::assertNull( $this->discount->rate( 601 ), 'Another creator without campaigns.' );

		$this->coded    = array( $this->nova );
		$this->discount = $this->discount();
		self::assertNull( $this->discount->rate( 501 ), 'The creator\'s own code is in the cart (ADR-068).' );
	}

	public function test_a_campaign_outside_its_period_gives_nothing(): void {
		$this->settings->save( $this->nova, $this->input( array( self::campaign( array( 'starts' => '2026-11-14', 'ends' => '2026-11-15' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		$this->log_in();
		$this->answer( self::SUB_URL, 200, array( 'data' => array( array( 'tier' => '1000', 'is_gift' => false ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.

		self::assertNull( $this->discount( '2026-11-13' )->rate( 501 ) );
		self::assertSame( 1000, $this->discount( '2026-11-14' )->rate( 501 )['bps'] ?? null );
	}

	public function test_gifted_subscriptions_follow_the_creators_choice_and_the_check_before_the_order_sees_changes(): void {
		$this->settings->save( $this->nova, $this->input( array( self::campaign() ), false ) );
		$this->log_in();
		$this->answer( self::SUB_URL, 200, array( 'data' => array( array( 'tier' => '1000', 'is_gift' => true ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.

		self::assertNull( $this->discount->rate( 501 ), 'A gift does not count.' );

		$this->settings->save( $this->nova, $this->input( array( self::campaign() ) ) );
		$this->discount = $this->discount();
		self::assertSame( 1000, $this->discount->rate( 501 )['bps'] ?? null, 'Reused answer: tier 1, gift counts now.' );

		$this->answer( self::SUB_URL, 404, array() );
		self::assertTrue( $this->discount->recheck( array( 501 ) ), 'The subscription ended before the order.' );
		self::assertNull( $this->discount->rate( 501 ) );

		$this->answer( self::SUB_URL, 404, array() );
		self::assertFalse( $this->discount->recheck( array( 501 ) ), 'The next attempt goes through.' );
	}

	public function test_twitch_not_answering_gives_no_discount(): void {
		$this->settings->save( $this->nova, $this->input( array( self::campaign() ) ) );
		$this->log_in();
		$this->answer( self::SUB_URL, 500, array() );

		self::assertNull( $this->discount->rate( 501 ) );
		self::assertNull( $this->discount()->rate( 501 ), 'Kept as none for a while.' );
	}

	/**
	 * A fresh discount service (its answers are per request).
	 *
	 * @param string $today Today (Y-m-d).
	 */
	private function discount( string $today = '2026-11-10' ): TwitchSubDiscount {
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
			new RecordingLogger(),
			static fn(): string => $today
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
	 * The creator's form.
	 *
	 * @param list<array<string, mixed>> $campaigns Campaigns.
	 * @param bool                       $gifted    Whether gifted subscriptions count.
	 * @return array<string, mixed>
	 */
	private function input( array $campaigns, bool $gifted = true ): array {
		return array(
			'gifted'    => $gifted,
			'campaigns' => $campaigns,
		);
	}

	/**
	 * A campaign of all products (10/15/16.67 %), on, without a period.
	 *
	 * @param array<string, mixed> $changes Changed fields.
	 * @return array<string, mixed>
	 */
	private static function campaign( array $changes = array() ): array {
		return $changes + array(
			'id'          => '',
			'name'        => 'Subs',
			'enabled'     => true,
			'tiers'       => array(
				1 => 10,
				2 => 15,
				3 => 16.67,
			),
			'scope'       => TwitchSubCampaign::ALL,
			'product_ids' => array(),
			'starts'      => '',
			'ends'        => '',
		);
	}

	/**
	 * A campaign of selected products.
	 *
	 * @phpstan-param list<int> $products
	 *
	 * @param int[]                $products Products.
	 * @param array<string, mixed> $changes  Changed fields.
	 * @return array<string, mixed>
	 */
	private static function selection( array $products, array $changes = array() ): array {
		return self::campaign(
			$changes + array(
				'scope'       => TwitchSubCampaign::SELECTED,
				'product_ids' => $products,
			)
		);
	}

	/**
	 * Asserts a refusal with exactly these field codes.
	 *
	 * @param array<string, string>      $fields     Expected codes.
	 * @param int                        $creator_id Creator.
	 * @param list<array<string, mixed>> $campaigns  Campaigns.
	 */
	private function refused( array $fields, int $creator_id, array $campaigns ): void {
		try {
			$this->settings->save( $creator_id, $this->input( $campaigns ) );
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
