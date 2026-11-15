<?php
/**
 * The Twitch Extension panel (phase 49, ADR-112).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Community;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Community\Rest\MeTwitchExtensionController;
use MerchBoard\Modules\Community\Rest\TwitchExtensionController;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionApp;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionPanel;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionRepository;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The creator's panel settings, what a channel's panel shows, the public
 * endpoint with the extension's JWT, the creator's `/me` route and the
 * administrator's setup of the extension.
 */
final class TwitchExtensionTest extends IntegrationTestCase {

	private const CHANNEL = '4711';

	private const NOW = 1_800_000_000;

	/**
	 * Twitch's secret (base64), as the developer console shows it.
	 */
	private const SECRET = 'c2VjcmV0LWtleS1mb3ItdGhlLXRlc3RzLTEyMzQ1Njc4';

	private int $nova;

	private int $orion;

	private int $user;

	private TwitchExtensionRepository $settings;

	private TwitchExtensionPanel $panel;

	private TwitchExtensionApp $app;

	/**
	 * Products by id: [owner, name, published].
	 *
	 * @var array<int, array{0: int, 1: string, 2: bool}>
	 */
	private array $products = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_TWITCH_EXTENSION_ENABLED => true ) ) );
		delete_option( TwitchExtensionApp::OPTION );

		$this->user = $this->create_user( 'twitch-extension-nova' );
		wp_set_current_user( 1 );
		$this->nova  = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->user )->id;
		$this->orion = ( new CreatorRepository( $this->wpdb ) )->create( 'Orion', CreatorStatus::Active )->id;
		( new ChatbotChannelRepository( $this->wpdb ) )->connect( $this->nova, ChatPlatform::Twitch, self::CHANNEL, 'nova' );

		$this->products = array(
			501 => array( $this->nova, 'Stream Hoodie', true ),
			502 => array( $this->nova, 'Mug & Cap', true ),
			503 => array( $this->nova, 'Hidden draft', false ),
			601 => array( $this->orion, 'Orion tee', true ),
		);
		$products       = &$this->products;
		$this->settings = new TwitchExtensionRepository(
			$this->service( CreatorMetaStore::class ),
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			static fn( int $creator_id, int $product_id ): bool => ( $products[ $product_id ][0] ?? null ) === $creator_id
		);
		$channels       = new ChatbotChannelRepository( $this->wpdb );
		$this->panel    = new TwitchExtensionPanel(
			$this->settings,
			static function ( string $channel_id ) use ( $channels ): ?int {
				$channel = $channels->find_by_broadcaster( ChatPlatform::Twitch, $channel_id );

				return null !== $channel && $channel->connected() ? $channel->creator_id : null;
			},
			fn( int $creator_id ): string => ( new CreatorRepository( $this->wpdb ) )->find( $creator_id )->display_name ?? '',
			static fn( int $creator_id, int $limit ): array => array_slice( array_keys( array_filter( $products, static fn( array $p ): bool => $p[0] === $creator_id && $p[2] ) ), 0, $limit ),
			static fn( int $id ): ?array => isset( $products[ $id ] ) && $products[ $id ][2] ? array(
				'name'  => $products[ $id ][1],
				'image' => 'https://shop.example/' . $id . '.jpg',
			) : null
		);
		$this->app      = new TwitchExtensionApp( new ChatCipher( 'twitch-extension' ), $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
	}

	protected function tearDown(): void {
		delete_option( TwitchExtensionApp::OPTION );
		delete_transient( TwitchExtensionRepository::CACHE_PREFIX . $this->nova );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_the_creator_chooses_own_products_in_order_and_changes_are_audited(): void {
		self::assertFalse( $this->settings->find( $this->nova )->enabled, 'Off until the creator switches it on.' );

		foreach ( array(
			array( array( 'product_ids' => array() ), array( 'enabled' => 'required' ) ),
			array(
				array(
					'enabled'     => true,
					'product_ids' => array( 601 ),
				),
				array( 'product_ids' => 'not_owned' ),
			),
			array(
				array(
					'enabled'     => true,
					'product_ids' => range( 1, 13 ),
				),
				array( 'product_ids' => 'too_many' ),
			),
			array(
				array(
					'enabled'     => true,
					'product_ids' => 'all',
				),
				array( 'product_ids' => 'invalid' ),
			),
		) as [ $values, $fields ] ) {
			try {
				$this->settings->save( $this->nova, $values );
				self::fail( 'Invalid values are refused.' );
			} catch ( TwitchSubDiscountInvalid $e ) {
				self::assertSame( $fields, $e->fields() );
			}
		}

		$saved = $this->settings->save(
			$this->nova,
			array(
				'enabled'     => true,
				'product_ids' => array( 502, 501 ),
			)
		);

		self::assertTrue( $saved->enabled );
		self::assertSame( array( 502, 501 ), $this->settings->find( $this->nova )->product_ids );
		$audit = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT action, context FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ) ), ARRAY_A );
		self::assertSame( 'twitch_extension_changed', $audit['action'] ?? null );
		self::assertSame( array( 'changed_fields' => array( 'enabled', 'product_ids' ) ), json_decode( (string) ( $audit['context'] ?? '' ), true ) );
	}

	public function test_a_channel_shows_its_creators_products_without_price_or_link(): void {
		self::assertSame( array( 'enabled' => false, 'creator' => '', 'products' => array() ), $this->panel->for_channel( self::CHANNEL ), 'Not switched on by the creator.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact expectation.

		$this->settings->save(
			$this->nova,
			array(
				'enabled'     => true,
				'product_ids' => array(),
			)
		);
		$panel = $this->panel->for_channel( self::CHANNEL );

		self::assertTrue( $panel['enabled'] );
		self::assertSame( 'Nova', $panel['creator'] );
		self::assertSame( array( 501, 502 ), array_column( $panel['products'], 'id' ), 'None chosen: the newest published products.' );
		self::assertSame( array( 'id', 'name', 'image' ), array_keys( $panel['products'][0] ), 'Never a price, link or supplier.' );
		self::assertSame( 'Mug & Cap', $panel['products'][1]['name'] );

		$this->settings->save(
			$this->nova,
			array(
				'enabled'     => true,
				'product_ids' => array( 503, 502 ),
			)
		);
		self::assertSame( array( 502 ), array_column( $this->panel->for_channel( self::CHANNEL )['products'], 'id' ), 'The save clears the cache; an unpublished product is left out.' );

		self::assertFalse( $this->panel->for_channel( '9999' )['enabled'], 'A channel that is no creator\'s.' );

		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_TWITCH_EXTENSION_ENABLED => false ) ) );
		self::assertSame( array(), $this->panel->for_channel( self::CHANNEL )['products'], 'Switched off for the shop.' );
	}

	public function test_the_public_endpoint_needs_the_extensions_signed_token(): void {
		$controller = new TwitchExtensionController( $this->app, $this->panel, new RecordingLogger(), static fn(): int => self::NOW );
		$this->settings->save(
			$this->nova,
			array(
				'enabled'     => true,
				'product_ids' => array( 501 ),
			)
		);

		self::assertSame( 503, $this->http_status( $controller->panel( $this->request( $this->token( self::CHANNEL ) ) ) ), 'Not set up yet.' );

		self::assertNotNull( $this->app->save( 'abcdefghij0123456789klmnop', self::SECRET, '' ) );

		self::assertSame( 401, $this->http_status( $controller->panel( $this->request( null ) ) ), 'No token.' );
		self::assertSame( 401, $this->http_status( $controller->panel( $this->request( $this->token( self::CHANNEL, 'another-secret-another-secret' ) ) ) ), 'Signed with another secret.' );
		self::assertSame( 401, $this->http_status( $controller->panel( $this->request( $this->token( self::CHANNEL, null, self::NOW - 1 ) ) ) ), 'Expired.' );
		self::assertSame( 401, $this->http_status( $controller->panel( $this->request( $this->token( self::CHANNEL, null, null, 'none' ) ) ) ), 'Unsigned (alg none).' );

		$response = $controller->panel( $this->request( $this->token( self::CHANNEL ) ) );
		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array(
				'enabled'  => true,
				'creator'  => 'Nova',
				'products' => array(
					array(
						'id'    => 501,
						'name'  => 'Stream Hoodie',
						'image' => 'https://shop.example/501.jpg',
					),
				),
			),
			$response->get_data()
		);
	}

	public function test_the_administrator_sets_the_extension_up_and_the_secret_stays_hidden(): void {
		self::assertFalse( $this->app->ready() );
		self::assertNull( $this->app->save( 'Not a client id', '', '' ), 'An invalid Client ID.' );
		self::assertNull( $this->app->save( 'abcdefghij0123456789klmnop', 'not base64 !!', '' ), 'An invalid secret.' );
		self::assertNull( $this->app->save( 'abcdefghij0123456789klmnop', '', 'https://example.com/ext' ), 'An install link outside Twitch.' );
		self::assertFalse( get_option( TwitchExtensionApp::OPTION ), 'Nothing kept.' );

		self::assertSame( array( 'client_id', 'install_url', 'secret' ), $this->app->save( 'abcdefghij0123456789klmnop', self::SECRET, 'https://dashboard.twitch.tv/extensions/abcdefghij0123456789klmnop-0.0.1' ) );
		self::assertTrue( $this->app->ready() );
		self::assertSame( base64_decode( self::SECRET ), $this->app->key() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.

		$stored = (string) wp_json_encode( get_option( TwitchExtensionApp::OPTION ) );
		self::assertStringNotContainsString( self::SECRET, $stored, 'The secret is stored encrypted.' );

		$audit = (string) wp_json_encode( $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT context FROM %i', $this->table( Tables::AUDIT_LOG ) ) ) );
		self::assertStringContainsString( 'twitch_extension_secret', $audit );
		self::assertStringNotContainsString( self::SECRET, $audit, 'The audit never holds the secret.' );

		self::assertSame( array(), $this->app->save( 'abcdefghij0123456789klmnop', '', 'https://dashboard.twitch.tv/extensions/abcdefghij0123456789klmnop-0.0.1' ), 'An empty secret keeps the stored one.' );
		self::assertTrue( $this->app->ready() );
	}

	public function test_the_creators_page_is_only_there_while_the_shop_has_it_on(): void {
		$controller = new MeTwitchExtensionController(
			$this->service( CreatorContext::class ),
			$this->settings,
			$this->panel,
			$this->app,
			static fn(): string => 'nova',
			static fn(): bool => true,
			new RecordingLogger()
		);
		wp_set_current_user( $this->user );

		self::assertTrue( $controller->permission() );
		$request = new WP_REST_Request( 'POST', '/merchboard/v1' . MeTwitchExtensionController::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'enabled'     => true,
					'product_ids' => array( 502 ),
				)
			)
		);
		$saved = $controller->save( $request );
		self::assertInstanceOf( WP_REST_Response::class, $saved );
		$data = $saved->get_data();
		self::assertIsArray( $data );
		self::assertTrue( $data['enabled'] );
		self::assertSame( array( 502 ), $data['product_ids'] );
		self::assertSame( array( 502 ), array_column( $data['preview'], 'id' ) );
		self::assertSame( array( 'login' => 'nova' ), $data['channel'] );
		self::assertFalse( $data['ready'], 'The shop has not set the extension up.' );
		self::assertNull( $data['install_url'] );

		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_TWITCH_EXTENSION_ENABLED => false ) ) );
		$denied = $controller->permission();
		self::assertInstanceOf( WP_Error::class, $denied );
		self::assertSame( 'merchboard_community_disabled', $denied->get_error_code() );
	}

	/**
	 * A request to the public endpoint.
	 *
	 * @param string|null $token JWT, or null for none.
	 */
	private function request( ?string $token ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/merchboard/v1' . TwitchExtensionController::ROUTE );

		if ( null !== $token ) {
			$request->set_header( 'Authorization', 'Bearer ' . $token );
		}

		return $request;
	}

	/**
	 * A token as Twitch signs it.
	 *
	 * @param string      $channel Channel id.
	 * @param string|null $secret  Raw key; null for the extension's.
	 * @param int|null    $expires Expiry; null for in an hour.
	 * @param string      $alg     Algorithm in the header.
	 */
	private function token( string $channel, ?string $secret = null, ?int $expires = null, string $alg = 'HS256' ): string {
		$encode = static fn( string $value ): string => rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A JWT for the test.
		$head   = $encode( (string) wp_json_encode( array( 'alg' => $alg, 'typ' => 'JWT' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		$body   = $encode(
			(string) wp_json_encode(
				array(
					'exp'            => $expires ?? self::NOW + 3600,
					'opaque_user_id' => 'UAnon',
					'channel_id'     => $channel,
					'role'           => 'viewer',
				)
			)
		);
		$key    = $secret ?? (string) base64_decode( self::SECRET ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.

		return $head . '.' . $body . '.' . ( 'none' === $alg ? '' : $encode( hash_hmac( 'sha256', $head . '.' . $body, $key, true ) ) );
	}

	/**
	 * The HTTP status of an answer.
	 *
	 * @param WP_REST_Response|WP_Error $answer Answer.
	 */
	private function http_status( WP_REST_Response|WP_Error $answer ): int {
		if ( $answer instanceof WP_REST_Response ) {
			return $answer->get_status();
		}

		$data = $answer->get_error_data();

		return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	}
}
