<?php
/**
 * The merch ad overlay and the !merch command (phase 50, ADR-113).
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
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use MerchBoard\Modules\Community\MerchAd\MerchAdFiles;
use MerchBoard\Modules\Community\MerchAd\MerchAdLink;
use MerchBoard\Modules\Community\MerchAd\MerchAdPage;
use MerchBoard\Modules\Community\MerchAd\MerchAdRepository;
use MerchBoard\Modules\Community\MerchAd\MerchAdView;
use MerchBoard\Modules\Community\MerchCommand\MerchCommand;
use MerchBoard\Modules\Community\MerchCommand\MerchCommandRepository;
use MerchBoard\Modules\Community\MerchCommand\MerchCommandSettings;
use MerchBoard\Modules\Community\Rest\MeMerchAdController;
use MerchBoard\Modules\Community\Rest\MerchAdStatusController;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The creator's ad (validation, audit, the OBS id, "show now", the
 * background-free images and what the overlay draws), the overlay's check
 * for changes, the creator's routes and the !merch command with cooldown.
 */
final class MerchAdTest extends IntegrationTestCase {

	/**
	 * A 16 × 16 PNG.
	 */
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAIklEQVR4nGOY6PafgRJMkebBa8B/AnjUgJFhwMCnRPoaAACO9BXfQFRadAAAAABJRU5ErkJggg==';

	private int $nova;

	private int $user;

	private string $dir;

	private MerchAdRepository $settings;

	private MerchAdFiles $files;

	private MerchAdView $view;

	/**
	 * Products by id: [owner, name, published].
	 *
	 * @var array<int, array{0: int, 1: string, 2: bool}>
	 */
	private array $products = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_MERCH_AD_ENABLED => true ) ) );

		$this->user = $this->create_user( 'merch-ad-nova' );
		wp_set_current_user( 1 );
		$this->nova = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->user )->id;
		$this->dir  = sys_get_temp_dir() . '/merchboard-merch-ad-test-' . bin2hex( random_bytes( 4 ) );

		$this->products = array(
			501 => array( $this->nova, 'Stream Hoodie', true ),
			502 => array( $this->nova, 'Mug', true ),
			503 => array( $this->nova, 'Draft', false ),
			601 => array( 999, 'Not Nova\'s', true ),
		);
		$products       = $this->products;
		$owns           = static fn( int $creator_id, int $product_id ): bool => ( $products[ $product_id ][0] ?? null ) === $creator_id;
		$this->settings = new MerchAdRepository( $this->service( CreatorMetaStore::class ), $this->service( AuditTrail::class ), $this->service( Transaction::class ), $owns );
		$this->files    = new MerchAdFiles( $this->dir, 'https://shop.example/uploads' );
		$this->view     = new MerchAdView(
			$this->settings,
			$this->files,
			static fn( int $creator_id, int $limit ): array => array_slice( array_keys( array_filter( $products, static fn( array $p ): bool => $p[0] === $creator_id && $p[2] ) ), 0, $limit ),
			static fn( int $id ): ?array => isset( $products[ $id ] ) && $products[ $id ][2] ? array(
				'name'  => $products[ $id ][1],
				'image' => 'https://shop.example/' . $id . '.jpg',
			) : null
		);
	}

	protected function tearDown(): void {
		$this->files->delete_all( $this->nova );
		delete_transient( MerchCommand::COOLDOWN_PREFIX . 'twitch_' . $this->nova );
		delete_transient( MerchCommand::COOLDOWN_PREFIX . 'kick_' . $this->nova );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_the_creators_form_is_validated_audited_and_gets_an_obs_id(): void {
		self::assertSame( '', $this->settings->find( $this->nova )->overlay_id, 'No link before the first save.' );

		try {
			$this->settings->save(
				$this->nova,
				$this->form(
					array(
						'design'      => 'banner',
						'every'       => 0,
						'seconds'     => 61,
						'enter'       => 'spin',
						'accent'      => 'red',
						'theme'       => 'neon',
						'product_ids' => array( 601 ),
						'note'        => 'Shop at ccmerch.dk',
					)
				)
			);
			self::fail( 'Invalid values are refused.' );
		} catch ( TwitchSubDiscountInvalid $e ) {
			self::assertSame(
				array(
					'design'      => 'invalid',
					'enter'       => 'invalid',
					'theme'       => 'invalid',
					'every'       => 'invalid',
					'seconds'     => 'invalid',
					'accent'      => 'invalid',
					'product_ids' => 'not_owned',
					'note'        => 'no_links',
				),
				$e->fields()
			);
		}

		$saved = $this->settings->save( $this->nova, $this->form( array( 'accent' => '#FF00AA' ) ) );

		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $saved->overlay_id );
		self::assertSame( '#ff00aa', $saved->accent );
		self::assertSame( $this->nova, $this->settings->creator_for( $saved->overlay_id ) );
		self::assertNull( $this->settings->creator_for( str_repeat( 'a', 32 ) ) );
		$audit = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT action, context FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ) ), ARRAY_A );
		self::assertSame( 'merch_ad_changed', $audit['action'] ?? null );
		self::assertContains( 'enabled', json_decode( (string) ( $audit['context'] ?? '' ), true )['changed_fields'] ?? array() );

		$old = $saved->overlay_id;
		self::assertNotSame( $old, $this->settings->regenerate( $this->nova )->overlay_id, 'A new link.' );
		self::assertNull( $this->settings->creator_for( $old ), 'The old link stops working.' );
		self::assertSame( 1_800_000_000, $this->settings->show_now( $this->nova, 1_800_000_000 )->show_at );
	}

	public function test_the_overlay_shows_background_free_images_and_falls_back_to_the_shop_image(): void {
		$this->settings->save( $this->nova, $this->form( array( 'product_ids' => array( 502, 503, 501 ) ) ) );
		$file = $this->files->store( $this->nova, 502, (string) base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.
		$this->settings->set_cutout( $this->nova, 502, $file );

		$overlay = $this->view->overlay( $this->nova );
		self::assertSame( array( 'over', 'large', false, 30 ), array( $overlay['pill_position'], $overlay['pill_size'], $overlay['show_names'], $overlay['stack_spread'] ) );

		self::assertSame( array( 'Mug', 'Stream Hoodie' ), array_column( $overlay['products'], 'name' ), 'In order; an unpublished product is left out.' );
		self::assertSame( 'https://shop.example/uploads/merchboard-merch-ad/' . $this->nova . '/' . $file, $overlay['products'][0]['image'] );
		self::assertTrue( $overlay['products'][0]['cutout'] );
		self::assertSame( 'https://shop.example/501.jpg', $overlay['products'][1]['image'] );
		self::assertFalse( $overlay['products'][1]['cutout'] );
		self::assertSame( array( 'name', 'image', 'cutout' ), array_keys( $overlay['products'][0] ), 'Never a price, link or supplier.' );
		self::assertFileExists( $this->dir . '/merchboard-merch-ad/' . $this->nova . '/' . $file );

		$version = $this->view->version( $this->nova );
		$this->settings->save( $this->nova, $this->form( array( 'product_ids' => array( 502, 503, 501 ), 'design' => 'showcase' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertNotSame( $version, $this->view->version( $this->nova ), 'The overlay reloads when the ad changes.' );

		foreach ( array(
			'not_png'   => 'not an image',
			'too_large' => str_repeat( 'x', MerchAdFiles::MAX_BYTES + 1 ),
		) as $code => $bytes ) {
			try {
				$this->files->store( $this->nova, 501, $bytes );
				self::fail( 'Refused: ' . $code );
			} catch ( TwitchSubDiscountInvalid $e ) {
				self::assertSame( array( 'png' => $code ), $e->fields() );
			}
		}
	}

	public function test_the_creators_routes_and_the_overlays_check(): void {
		$controller = new MeMerchAdController( $this->service( CreatorContext::class ), $this->settings, $this->view, $this->files, static fn( int $creator_id, int $product_id ): bool => in_array( $product_id, array( 501, 502, 503 ), true ), new RecordingLogger(), static fn(): int => 1_800_000_123 );
		wp_set_current_user( $this->user );

		self::assertTrue( $controller->permission() );
		$saved = $controller->save( $this->json( 'POST', $this->form( array( 'product_ids' => array( 501 ) ) ) ) );
		self::assertInstanceOf( WP_REST_Response::class, $saved );
		$data = (array) $saved->get_data();
		self::assertStringContainsString( '?merch_ad=', (string) $data['url'], 'The link name (default merch_ad).' );
		self::assertSame( 'over', $data['pill_position'] );
		self::assertFalse( $data['show_names'] );
		self::assertSame( 30, $data['stack_spread'] );
		$id = $this->settings->find( $this->nova )->overlay_id;
		self::assertSame( $id, MerchAdLink::id( array( 'merch_ad' => $id ) ) );
		self::assertSame( $id, MerchAdLink::id( array( MerchAdPage::QUERY => $id ) ), 'The first links keep working.' );
		self::assertFalse( MerchAdLink::valid( 'merchboard_gift_claim' ), 'Not the name of the gift links.' );
		self::assertStringContainsString( 'preview=1', (string) $data['preview_url'] );
		self::assertSame( array( 501 ), array_column( $data['products'], 'id' ) );
		self::assertNull( $data['products'][0]['cutout'] );

		$png = $controller->add_cutout( $this->json( 'POST', array( 'product_id' => 501, 'png' => 'data:image/png;base64,' . self::PNG ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertInstanceOf( WP_REST_Response::class, $png );
		self::assertNotNull( ( (array) $png->get_data() )['products'][0]['cutout'] );

		$other = $controller->add_cutout( $this->json( 'POST', array( 'product_id' => 601, 'png' => 'data:image/png;base64,' . self::PNG ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertInstanceOf( WP_Error::class, $other, 'Only own products.' );

		$removed = $controller->remove_cutout( $this->json( 'DELETE', array(), array( 'product' => '501' ) ) );
		self::assertInstanceOf( WP_REST_Response::class, $removed );
		self::assertNull( ( (array) $removed->get_data() )['products'][0]['cutout'] );

		$controller->show();
		$id     = $this->settings->find( $this->nova )->overlay_id;
		$status = new MerchAdStatusController( $this->settings, $this->view, new OverlayRateLimiter( null, 'merchboard_merchad_test_', 2 ), new RecordingLogger() );
		$answer = $status->status( $this->json( 'GET', array(), array( 'id' => $id ) ) );
		self::assertInstanceOf( WP_REST_Response::class, $answer );
		self::assertSame( 1_800_000_123, ( (array) $answer->get_data() )['show_at'] );
		self::assertSame( $this->view->version( $this->nova ), ( (array) $answer->get_data() )['version'] );
		$status->status( $this->json( 'GET', array(), array( 'id' => $id ) ) );
		$limited = $status->status( $this->json( 'GET', array(), array( 'id' => $id ) ) );
		self::assertInstanceOf( WP_Error::class, $limited );
		self::assertSame( 'merchboard_rate_limited', $limited->get_error_code() );
		delete_transient( 'merchboard_merchad_test_' . substr( hash( 'sha256', $id ), 0, 32 ) );

		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_MERCH_AD_ENABLED => false ) ) );
		self::assertInstanceOf( WP_Error::class, $controller->permission() );
		$off = $status->status( $this->json( 'GET', array(), array( 'id' => str_repeat( 'b', 32 ) ) ) );
		self::assertInstanceOf( WP_Error::class, $off );
	}

	public function test_merch_answers_with_the_collection_link_once_per_cooldown(): void {
		$commands = new MerchCommandRepository( $this->service( CreatorMetaStore::class ), $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$sent     = array();
		$merch    = new MerchCommand(
			$commands,
			static fn(): string => 'https://shop.example/c/nova/',
			static function ( int $creator_id, ChatPlatform $platform, string $message ) use ( &$sent ): bool {
				$sent[] = array( $creator_id, $platform->value, $message );

				return true;
			},
			new RecordingLogger()
		);

		self::assertFalse( $merch->chat( ChatPlatform::Twitch, $this->nova, '!merch' ), 'Off until the creator switches it on.' );

		foreach ( array(
			array( array( 'enabled' => 'yes', 'cooldown' => 5 ), array( 'enabled' => 'required', 'cooldown' => 'invalid' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
			array( array( 'enabled' => true, 'cooldown' => 30, 'text' => '!merch here' ), array( 'text' => 'is_command' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
			array( array( 'enabled' => true, 'cooldown' => 30, 'text' => str_repeat( 'a', 201 ) ), array( 'text' => 'too_long' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		) as [ $values, $fields ] ) {
			try {
				$commands->save( $this->nova, $values );
				self::fail( 'Invalid values are refused.' );
			} catch ( TwitchSubDiscountInvalid $e ) {
				self::assertSame( $fields, $e->fields() );
			}
		}

		$commands->save(
			$this->nova,
			array(
				'enabled'  => true,
				'text'     => 'Se min merch: $Link 🔥',
				'cooldown' => 30,
			)
		);
		$audit = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT action FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ) ), ARRAY_A );
		self::assertSame( 'merch_command_changed', $audit['action'] ?? null );

		self::assertFalse( $merch->chat( ChatPlatform::Twitch, $this->nova, 'I like the merch' ), 'Not the command.' );
		self::assertTrue( $merch->chat( ChatPlatform::Twitch, $this->nova, '  !MERCH please ' ) );
		self::assertFalse( $merch->chat( ChatPlatform::Twitch, $this->nova, '!merch' ), 'The cooldown.' );
		self::assertTrue( $merch->chat( ChatPlatform::Kick, $this->nova, '!merch' ), 'Each platform has its own cooldown.' );
		self::assertSame(
			array(
				array( $this->nova, 'twitch', 'Se min merch: https://shop.example/c/nova/ 🔥' ),
				array( $this->nova, 'kick', 'Se min merch: https://shop.example/c/nova/ 🔥' ),
			),
			$sent
		);

		self::assertSame( 'Merch https://x/', MerchCommandSettings::message( 'Merch', 'https://x/' ), 'Without $Link the link comes last.' );
		self::assertStringContainsString( 'https://x/', MerchCommandSettings::message( '', 'https://x/' ), 'The default text.' );
	}

	/**
	 * A valid form with changes.
	 *
	 * @param array<string, mixed> $changes Changed fields.
	 * @return array<string, mixed>
	 */
	private function form( array $changes = array() ): array {
		return $changes + array(
			'enabled'       => true,
			'design'        => 'spotlight',
			'product_ids'   => array(),
			'note'          => 'Skriv !merch i chatten',
			'every'         => 10,
			'seconds'       => 15,
			'enter'         => 'slide',
			'leave'         => 'fade',
			'accent'        => '#9146ff',
			'theme'         => 'dark',
			'pill_position' => 'over',
			'pill_size'     => 'large',
			'show_names'    => false,
			'stack_spread'  => 30,
		);
	}

	/**
	 * A request with a JSON body and URL parameters.
	 *
	 * @param string               $method Method.
	 * @param array<string, mixed> $body   JSON body.
	 * @param array<string, mixed> $params URL parameters.
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function json( string $method, array $body, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/merchboard/v1' . MeMerchAdController::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$request->set_url_params( $params );

		return $request;
	}
}
