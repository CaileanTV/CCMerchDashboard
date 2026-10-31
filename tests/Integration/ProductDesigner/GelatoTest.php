<?php
/**
 * Gelato as catalog and mockups from templates, with a fake HTTP (step 30.13).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\ProductDesigner\Admin\BaseProductsPage;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerDrafts;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerReview;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerSubmissions;
use MerchBoard\Modules\ProductDesigner\Designs\DesignValidator;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Providers\CatalogImport;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoAccount;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoApi;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoException;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoMockups;
use MerchBoard\Modules\ProductDesigner\Providers\ProviderTaskRepository;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\Photo;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_Error;

/**
 * Only the approved endpoints are called, with the encrypted key as
 * X-API-KEY, 15 s and no redirects; a template becomes a base product
 * draft; a submitted design becomes a product from the template in the
 * store with the signed print file URLs, and Gelato's images replace the
 * browser's mockups. Nothing is called while Gelato is off.
 */
#[CoversClass( GelatoAccount::class )]
#[CoversClass( GelatoApi::class )]
#[CoversClass( GelatoCatalog::class )]
#[CoversClass( CatalogImport::class )]
#[CoversClass( GelatoMockups::class )]
final class GelatoTest extends IntegrationTestCase {

	private const KEY = 'gelato-test-key-1234567890:abcdef';

	private const STORE = '0f8e2c1a-1b2c-4d3e-8f90-123456789abc';

	private const API = 'https://ecommerce.gelatoapis.com/v1';

	private const ASSETS = 'https://shop.example/wp-content/plugins/merchboard/assets/designer/';

	private const STORE_API = self::API . '/stores/' . self::STORE;

	/**
	 * Calls made: [method, url, args].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $calls = array();

	/**
	 * Answers by URL (a callable for changing answers).
	 *
	 * @var array<string, mixed>
	 */
	private array $answers = array();

	/**
	 * Notifications fired: [type, data].
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $notified = array();

	/**
	 * The notification listener.
	 *
	 * @var callable
	 */
	private $listener;

	private GelatoAccount $account;

	private GelatoApi $api;

	private DesignerFiles $files;

	private BaseProductRepository $bases;

	private DesignerProductRepository $products;

	private FakeJobQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		DesignerFiles::remove_all();
		wp_set_current_user( 1 );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => true, Settings::GELATO_ENABLED => true ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->account  = new GelatoAccount( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$this->api      = new GelatoApi(
			$this->account,
			function ( string $method, string $url, array $args ): array|WP_Error {
				$this->calls[] = array( $method, $url, $args );
				$answer        = $this->answers[ $url ] ?? null;
				$answer        = is_callable( $answer ) ? $answer( $args ) : $answer;

				return is_array( $answer ) ? $answer : new WP_Error( 'http_request_failed', 'No route.' );
			}
		);
		$this->files    = new DesignerFiles( static fn( string $from, string $to ): bool => rename( $from, $to ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test files.
		$this->bases    = new BaseProductRepository( $this->wpdb );
		$this->products = new DesignerProductRepository( $this->wpdb );
		$this->queue    = new FakeJobQueue();
		$this->listener = function ( mixed $type, mixed $creator = null, mixed $data = null ): void {
			$this->notified[] = array( (string) $type, $data );
		};
		add_action( 'merchboard_notify', $this->listener, 1, 3 );
		$this->gelato_answers();
	}

	protected function tearDown(): void {
		remove_action( 'merchboard_notify', $this->listener, 1 );
		delete_option( GelatoAccount::KEY_OPTION );
		delete_option( GelatoAccount::STORE_OPTION );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => false, Settings::GELATO_ENABLED => false ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server after the test.
		DesignerFiles::remove_all();
		parent::tearDown();
	}

	public function test_the_key_is_encrypted_and_never_audited_and_the_store_is_a_uuid(): void {
		foreach ( array( array( 'short', '' ), array( 'with spaces in it but long enough', '' ), array( '', 'not-a-store' ) ) as [ $key, $store ] ) {
			try {
				$this->account->set( $key, $store );
				self::fail( 'Invalid key or store.' );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::GELATO_ACCOUNT, $e->reason() );
			}
		}

		self::assertTrue( $this->account->set( '  ' . self::KEY . ' ', strtoupper( self::STORE ) ) );
		self::assertFalse( $this->account->set( '', self::STORE ), 'Nothing new.' );

		self::assertSame( array( self::KEY, self::STORE, true ), array( $this->account->key(), $this->account->store(), $this->account->configured() ) );
		self::assertStringNotContainsString( self::KEY, (string) get_option( GelatoAccount::KEY_OPTION ) );
		self::assertContains( $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $this->wpdb->options, GelatoAccount::KEY_OPTION ) ), array( 'no', 'off' ) );
		self::assertStringNotContainsString( self::KEY, (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );

		$this->account->clear();
		self::assertSame( array( null, self::STORE ), array( $this->account->key(), $this->account->store() ), 'The store id stays.' );
		self::assertFalse( $this->api->available() );
	}

	public function test_only_approved_calls_with_the_key_no_redirects_and_respect_for_429(): void {
		try {
			$this->api->template( 'tpl-1' );
			self::fail( 'No key: no call.' );
		} catch ( GelatoException $e ) {
			self::assertSame( GelatoException::NOT_CONFIGURED, $e->reason() );
		}

		self::assertSame( array(), $this->calls );
		$this->account->set( self::KEY, self::STORE );

		self::assertSame( 'Classic tee', $this->api->template( 'tpl-1' )['title'] ?? null );
		[ $method, $url, $args ] = $this->call( 'GET', self::API . '/templates/tpl-1' ) ?? self::fail( 'No call.' );
		self::assertSame( array( 'GET', 15, 0, self::KEY ), array( $method, $args['timeout'] ?? null, $args['redirection'] ?? null, $args['headers']['X-API-KEY'] ?? null ) );
		self::assertSame( self::API . '/templates/tpl-1', $url );

		$this->answers[ self::API . '/templates/tpl-2' ] = self::answer( 429, array(), array( 'retry-after' => '30' ) );
		$this->answers[ self::API . '/templates/tpl-3' ] = self::answer( 403, array() );

		foreach ( array(
			'tpl-2'   => array( GelatoException::RATE_LIMITED, 30 ),
			'tpl-3'   => array( GelatoException::UNAUTHORIZED, 0 ),
			'tpl-4'   => array( GelatoException::FAILED, 0 ),
			'../tpl1' => array( GelatoException::INVALID, 0 ),
		) as $id => [ $reason, $wait ] ) {
			try {
				$this->api->template( $id );
				self::fail( 'Refused: ' . $id );
			} catch ( GelatoException $e ) {
				self::assertSame( array( $reason, $wait ), array( $e->reason(), $e->retry_after ) );
			}
		}

		try {
			$this->api->download( 'http://cdn.example/t.png' );
			self::fail( 'Only https.' );
		} catch ( GelatoException $e ) {
			self::assertSame( GelatoException::INVALID, $e->reason() );
		}

		Settings::save( array_merge( Settings::all(), array( Settings::GELATO_ENABLED => false ) ) );
		$before = count( $this->calls );

		try {
			$this->api->product( 'gp-1' );
			self::fail( 'Switched off.' );
		} catch ( GelatoException $e ) {
			self::assertSame( GelatoException::NOT_CONFIGURED, $e->reason() );
		}

		self::assertCount( $before, $this->calls );
	}

	public function test_a_template_offers_the_catalog_s_colours_and_sizes(): void {
		$this->account->set( self::KEY, self::STORE );
		$gelato = new GelatoCatalog( $this->api );

		$choice = $gelato->choice( 'tpl-1' );
		self::assertSame( array( 'Black', 'White' ), array_column( $choice['colors'], 'name' ), 'From the catalog\'s productUid, not the template\'s own option names.' );
		self::assertSame( array( 'S', 'M' ), $choice['sizes'] );
		self::assertSame( array( array( 'ImageFront', 'Front', 30, 40, 300 ), array( 'ImageBack', 'Back', 30, 40, 300 ) ), array_map( static fn( array $side ): array => array( $side['placement'], $side['name'], $side['width_mm'], $side['height_mm'], $side['dpi'] ), $choice['sides'] ) );
		self::assertSame(
			array(
				'S' => 'tv-b-s',
				'M' => 'tv-b-m',
			),
			$choice['variants']['Black']
		);
	}

	public function test_a_template_s_product_is_imported_with_a_product_per_colour_and_measured_colours(): void {
		$this->account->set( self::KEY, self::STORE );
		$id   = $this->import();
		$base = $this->bases->find( $id );

		self::assertNotNull( $base );
		self::assertSame( array( BaseProduct::DRAFT, 'gelato', 'Classic tee', 1499500 ), array( $base->status, $base->source, $base->name, $base->base_units ) );
		self::assertSame( array( array( 'black', 'Black', '#232323' ), array( 'white', 'White', '#fafafa' ) ), array_map( static fn( array $color ): array => array( $color['key'], $color['name'], $color['hex'] ), $base->colors ), 'Gelato names no colour codes: they are measured on the blank photos.' );
		self::assertSame( array( 'S', 'M' ), $base->sizes );
		self::assertSame( array( 'front' ), array_map( static fn( $view ): string => $view->key, $base->views ), 'The one preview shows the front.' );
		self::assertSame( array( 'Back' ), $this->bases->import( $id )['data']['skipped'] ?? null );

		$view = $base->views[0];
		self::assertSame( array( 200, 200, 30, 40, 300 ), array( $view->width, $view->height, $view->width_mm, $view->height_mm, $view->dpi ) );
		self::assertSame( array( 'background', 'background:white' ), array_keys( $view->layers ) );

		foreach ( array( 70, 50, 60, 80 ) as $field => $expected ) {
			self::assertEqualsWithDelta( $expected, array_values( $view->area )[ $field ], 2, 'Where the marker showed.' );
		}

		self::assertSame(
			array(
				'source'     => 'gelato',
				'ref'        => 'tpl-1',
				'placements' => array( 'front' => 'ImageFront' ),
				'variants'   => array(
					'black' => array(
						'S' => 'tv-b-s',
						'M' => 'tv-b-m',
					),
					'white' => array(
						'S' => 'tv-w-s',
						'M' => 'tv-w-m',
					),
				),
				'slots'      => array( 'front' => '0' ),
			),
			$this->bases->provider( $id )
		);

		$creates = array_map( static fn( array $call ): array => (array) json_decode( (string) ( $call[2]['body'] ?? '' ), true ), array_values( array_filter( $this->calls, static fn( array $call ): bool => 'POST' === $call[0] && str_ends_with( $call[1], 'products:create-from-template' ) ) ) );
		self::assertSame( array( array( 'tv-b-s' ), array( 'tv-b-s' ), array( 'tv-w-s' ) ), array_map( static fn( array $body ): array => array_column( $body['variants'] ?? array(), 'templateVariantId' ), $creates ), 'The marker product first, then a product per colour: a product has one preview.' );
		self::assertSame( array( self::ASSETS . 'marker-1.png', self::ASSETS . 'marker-2.png' ), array_column( $creates[0]['variants'][0]['imagePlaceholders'] ?? array(), 'fileUrl' ) );
		self::assertSame( array( array( 'ImageFront', self::ASSETS . 'blank.png', 'slice' ), array( 'ImageBack', self::ASSETS . 'blank.png', 'slice' ) ), array_map( static fn( array $image ): array => array_values( $image ), $creates[1]['variants'][0]['imagePlaceholders'] ?? array() ) );
		self::assertFalse( $creates[0]['isVisibleInTheOnlineStore'] ?? true );
	}

	public function test_a_list_of_images_gives_each_side_its_own_photo(): void {
		$this->account->set( self::KEY, self::STORE );
		$images = static fn( string $id, array $urls ): array => self::answer(
			200,
			array(
				'id'            => $id,
				'status'        => 'active',
				'productImages' => array_map( static fn( string $url ): array => array( 'fileUrl' => $url ), $urls ),
			)
		);

		$this->answers[ self::STORE_API . '/products/gp-blank-black' ] = $images( 'gp-blank-black', array( 'https://cdn.example/blank-b-front.png', 'https://cdn.example/blank-b-back.png' ) );
		$this->answers[ self::STORE_API . '/products/gp-blank-white' ] = $images( 'gp-blank-white', array( 'https://cdn.example/blank-w-front.png', 'https://cdn.example/blank-w-back.png' ) );
		$this->answers[ self::STORE_API . '/products/gp-marker' ]      = $images( 'gp-marker', array( 'https://cdn.example/marker-front.png', 'https://cdn.example/marker-back.png' ) );

		$id = $this->import();

		self::assertSame( 'ready', $this->bases->import( $id )['state'] ?? null );
		self::assertSame(
			array(
				'front' => '0',
				'back'  => '1',
			),
			$this->bases->provider( $id )['slots'] ?? null
		);
	}

	public function test_the_print_area_is_what_the_photo_shows_even_when_the_layer_says_otherwise(): void {
		$this->account->set( self::KEY, self::STORE );
		// A 30 × 40 mm layer of which the photo shows a wide band.
		$this->answers['https://cdn.example/marker-front.png'] = self::shirt( array( 35, 35, 35 ), array( array( 50, 80, 100, 30, array( 235, 15, 230 ) ) ) );

		$view = $this->bases->find( $this->import() )->views[0] ?? self::fail( 'No side.' );

		self::assertEqualsWithDelta( 100, $view->area['width'], 3, 'The whole width of the marker.' );
		self::assertEqualsWithDelta( 30, $view->area['height'], 3 );
		self::assertSame( array( 30, 9 ), array( $view->width_mm, $view->height_mm ), 'The width fills the marker: the height is what shows.' );
	}

	public function test_an_import_gives_a_side_the_chosen_embroidery_pack(): void {
		$this->account->set( self::KEY, self::STORE );

		foreach ( array( 'gelato' => 'gelato', 'unknown' => '' ) as $picked => $expected ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$catalog = $this->catalog();
			$id      = $catalog->create(
				'gelato',
				'tpl-1',
				array(
					'colors'     => array( 'Black', 'White' ),
					'sizes'      => array( 'S', 'M' ),
					'sides'      => array( 'ImageFront', 'ImageBack' ),
					'packs'      => array( 'ImageFront' => $picked ),
					'base_price' => '149,95',
				)
			);

			$this->drain_import( $catalog );

			self::assertSame( array( $expected ), array_map( static fn( $view ): string => $view->thread_pack, $this->bases->find( $id )->views ?? array() ) );
		}
	}

	public function test_an_active_product_is_waited_for_until_it_has_a_preview(): void {
		$this->account->set( self::KEY, self::STORE );
		$looks = 0;
		$this->answers[ self::STORE_API . '/products/gp-marker' ] = static function () use ( &$looks ): array {
			++$looks;

			return self::answer(
				200,
				array(
					'id'         => 'gp-marker',
					'status'     => 'active',
					'previewUrl' => $looks > 4 ? 'https://cdn.example/marker-front.png' : null,
				)
			);
		};

		$id = $this->import();

		self::assertGreaterThan( 4, $looks );
		self::assertSame( 'ready', $this->bases->import( $id )['state'] ?? null );
	}

	public function test_an_active_product_without_a_preview_is_given_up_after_five_minutes(): void {
		$this->account->set( self::KEY, self::STORE );
		$this->answers[ self::STORE_API . '/products/gp-marker' ] = self::answer(
			200,
			array(
				'id'     => 'gp-marker',
				'status' => 'active',
			)
		);

		$import = $this->bases->import( $this->import() );

		self::assertSame( array( 'failed', DesignerException::IMPORT_RENDER, 'marker: active without a preview; status=active, previewUrl=no, productImages=0' ), array( $import['state'] ?? null, $import['data']['error'] ?? null, $import['data']['detail'] ?? null ) );
	}

	public function test_no_colour_product_is_made_while_the_marker_product_has_no_preview(): void {
		$this->account->set( self::KEY, self::STORE );
		$this->answers[ self::STORE_API . '/products/gp-marker' ] = self::answer(
			200,
			array(
				'id'            => 'gp-marker',
				'status'        => 'active',
				'previewUrl'    => null,
				'productImages' => array(),
			)
		);

		$this->import();

		self::assertCount( 1, array_filter( $this->calls, static fn( array $call ): bool => 'POST' === $call[0] ), 'Every render stays in the store: none is made in vain.' );
	}

	public function test_a_product_that_never_goes_active_times_out_with_the_provider_s_state(): void {
		$this->account->set( self::KEY, self::STORE );
		$this->answers[ self::STORE_API . '/products/gp-blank-white' ] = self::answer(
			200,
			array(
				'id'     => 'gp-blank-white',
				'status' => 'publishing',
			)
		);

		$import = $this->bases->import( $this->import() );

		self::assertSame( array( 'failed', DesignerException::IMPORT_TIMEOUT, 'blank:white: status=publishing, previewUrl=no, productImages=0' ), array( $import['state'] ?? null, $import['data']['error'] ?? null, $import['data']['detail'] ?? null ) );
	}

	public function test_a_failed_import_keeps_what_the_renders_gave_for_the_diagnosis(): void {
		$this->account->set( self::KEY, self::STORE );
		$this->answers['https://cdn.example/marker-front.png'] = $this->answers['https://cdn.example/blank-b-front.png'];

		$id     = $this->import();
		$answer = $this->catalog()->diagnose( $id );

		self::assertSame( array( 0 ), array_keys( $answer['import']['data']['measured'] ?? array() ), 'What was measured.' );
		self::assertArrayHasKey( 'marker', $answer['import']['data']['images'] ?? array() );
		self::assertSame( array( 'gp-blank-black', 'gp-marker' ), array( $answer['render blank:black']['id'] ?? null, $answer['render marker']['id'] ?? null ), 'Gelato answers about the renders.' );
		self::assertArrayHasKey( 'GET /v1/templates/tpl-1', $answer );
		self::assertStringNotContainsString( self::KEY, (string) wp_json_encode( $answer ) );

		$this->catalog()->retry( $id );

		self::assertSame( array(), array_intersect_key( $this->bases->import( $id )['data'] ?? array(), array_flip( array( 'measured', 'images', 'renders', 'error' ) ) ), 'Try again starts over.' );
	}

	public function test_an_import_without_a_visible_marker_fails_with_the_reason(): void {
		$this->account->set( self::KEY, self::STORE );
		$this->answers['https://cdn.example/marker-front.png'] = $this->answers['https://cdn.example/blank-b-front.png'];

		$id = $this->import();

		self::assertSame( array( 'failed', DesignerException::IMPORT_NO_AREA ), array( $this->bases->import( $id )['state'] ?? null, $this->bases->import( $id )['data']['error'] ?? null ) );
		self::assertSame( array( DesignerNotifications::IMPORT_FAILED ), array_column( $this->notified, 0 ) );
		self::assertSame( array( 'Classic tee', DesignerException::IMPORT_NO_AREA, 'Gelato' ), array( $this->notified[0][1]['title'] ?? null, $this->notified[0][1]['reason'] ?? null, $this->notified[0][1]['provider'] ?? null ) );
	}

	public function test_the_status_shows_each_render_with_the_provider_s_state_to_the_administrator_only(): void {
		$this->account->set( self::KEY, self::STORE );
		$catalog = $this->catalog();
		$id      = $catalog->create(
			'gelato',
			'tpl-1',
			array(
				'colors'     => array( 'Black', 'White' ),
				'sizes'      => array( 'S' ),
				'sides'      => array( 'ImageFront' ),
				'base_price' => '149,95',
			)
		);
		$page    = new BaseProductsPage( $this->bases, new DesignerAssetRepository( $this->wpdb ), 2, 'DKK', '', '', false, true, $catalog, null, new GelatoCatalog( $this->api ) );
		$nonce   = wp_create_nonce( BaseProductsPage::STATUS_ACTION );
		$status  = static fn(): array => $page->import_status(
			array(
				'base'  => $id,
				'nonce' => $nonce,
			)
		);

		$this->drain_import( $catalog, 2 );
		$running = $status();

		self::assertSame( array( 200, 'running' ), array( $running['status'], $running['data']['state'] ?? null ) );
		self::assertStringContainsString( '<strong>The provider renders the product', $running['data']['html'] ?? '' );
		self::assertStringContainsString( 'Product with markers', $running['data']['html'] ?? '' );
		self::assertStringContainsString( 'look 1 of 40', $running['data']['html'] ?? '' );
		self::assertStringContainsString( 'status=active, previewUrl=yes, productImages=0', $running['data']['html'] ?? '' );

		$this->drain_import( $catalog );
		$ready = $status();

		self::assertSame( 'ready', $ready['data']['state'] ?? null );
		self::assertStringContainsString( '<img src="https://cdn.example/marker-front.png"', $ready['data']['html'] ?? '' );
		self::assertStringContainsString( 'Blank product: black', $ready['data']['html'] ?? '' );
		self::assertSame( 403, $page->import_status( array( 'base' => $id, 'nonce' => 'wrong' ) )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 404, $page->import_status( array( 'base' => 999, 'nonce' => $nonce ) )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->bases->set_import( $id, 'running', (string) wp_json_encode( array( 'updated' => time() - 660 ) ) );
		self::assertStringContainsString( 'has not run for 11 minutes', $status()['data']['html'] ?? '' );

		$subscriber = $this->create_user( 'gelato-subscriber' );
		wp_set_current_user( $subscriber );
		self::assertSame(
			403,
			$page->import_status(
				array(
					'base'  => $id,
					'nonce' => wp_create_nonce( BaseProductsPage::STATUS_ACTION ),
				)
			)['status']
		);
	}

	public function test_gelato_makes_the_mockups_of_a_submitted_design(): void {
		$this->account->set( self::KEY, self::STORE );
		$version = $this->submitted();
		$mockups = $this->mockups();
		$review  = new DesignerReview( $this->products, $this->bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->queue, 2, static function (): void {} );
		$before  = SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null )['mockups']['front'];

		$mockups->submitted( $version );

		self::assertSame( 'running', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( array( array( GelatoMockups::HOOK, array( $version ) ) ), array_map( static fn( array $job ): array => array( $job['hook'], $job['args'] ), $this->queue->pending ) );

		try {
			$review->approve( $version );
			self::fail( 'Not while Gelato makes the mockups.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::MOCKUPS_PENDING, $e->reason() );
		}

		$this->drain( $mockups );

		$creates = array_values( array_filter( $this->calls, static fn( array $call ): bool => 'POST' === $call[0] && str_ends_with( $call[1], 'products:create-from-template' ) ) );
		$create  = end( $creates );
		self::assertNotFalse( $create, 'The design product after the import products.' );
		$body = json_decode( (string) ( $create[2]['body'] ?? '' ), true );
		self::assertSame( array( 'tpl-1', 'Nova Classic tee Stream tee', false ), array( $body['templateId'] ?? null, $body['title'] ?? null, $body['isVisibleInTheOnlineStore'] ?? null ) );
		self::assertSame( array( 'tv-b-s', 'tv-w-s' ), array_column( $body['variants'] ?? array(), 'templateVariantId' ), 'A template variant per chosen colour, the first chosen size.' );
		self::assertSame( 'ImageFront', $body['variants'][0]['imagePlaceholders'][0]['name'] ?? null );
		self::assertStringContainsString( '/merchboard/v1/designer-print/' . $version . '/front', urldecode( (string) ( $body['variants'][0]['imagePlaceholders'][0]['fileUrl'] ?? '' ) ) );
		self::assertSame( 'gp-1', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT provider_ref FROM %i WHERE id = %d', Tables::name( $this->wpdb, Tables::DESIGNER_VERSIONS ), $version ) ), 'Gelato\'s product is stored on the version.' );

		$files = SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null );
		self::assertSame( 'ready', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( array( 'black', 'white' ), array_keys( $files['mockups']['front'] ) );
		self::assertSame( $before['white']['file'], $files['mockups']['front']['white']['file'], 'A product has one preview: the other colours keep the browser mockups.' );
		self::assertSame( array( 320, 320 ), array( $files['mockups']['front']['black']['width'], $files['mockups']['front']['black']['height'] ), 'The black variant first image is the front.' );

		self::assertNull( $this->files->path( $before['black']['file'] ), 'The replaced mockup is deleted.' );

		self::assertSame( array(), array_values( array_filter( $this->notified, static fn( array $n ): bool => DesignerNotifications::MOCKUPS_FAILED === $n[0] ) ) );

		$review->approve( $version );
		self::assertSame( 'approved', $this->products->version( $version )['status'] ?? null );
	}

	public function test_a_publishing_error_keeps_the_browser_s_mockups_and_tells_the_administrator(): void {
		$this->account->set( self::KEY, self::STORE );
		$version = $this->submitted();
		$this->answers[ self::API . '/stores/' . self::STORE . '/products/gp-1' ] = self::answer( 200, array( 'id' => 'gp-1', 'status' => 'publishing_error' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$before = SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null )['mockups'];

		$mockups = $this->mockups();
		$mockups->submitted( $version );
		$this->drain( $mockups );

		self::assertSame( 'failed', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( $before, SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null )['mockups'] );

		$failed = array_values( array_filter( $this->notified, static fn( array $n ): bool => DesignerNotifications::MOCKUPS_FAILED === $n[0] ) );
		self::assertCount( 1, $failed );
		self::assertSame( array( 'gelato_task', 'Gelato' ), array( $failed[0][1]['reason'] ?? null, $failed[0][1]['provider'] ?? null ) );
	}

	public function test_images_are_taken_only_from_an_active_product(): void {
		$this->account->set( self::KEY, self::STORE );
		$version = $this->submitted();
		$this->answers[ self::API . '/stores/' . self::STORE . '/products/gp-1' ] = self::answer(
			200,
			array(
				'id'         => 'gp-1',
				'status'     => 'created',
				'previewUrl' => 'https://cdn.example/gelato-black.png',
			)
		);
		$before  = SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null )['mockups'];
		$mockups = $this->mockups();
		$mockups->submitted( $version );
		$this->queue->drain(
			static function ( string $hook, array $args ) use ( $mockups ): void {
				$mockups->run( $args[0] ?? 0 );
			},
			3
		);

		self::assertSame( 'running', $this->products->version( $version )['mockup_state'] ?? null, 'A created product is waited for.' );
		self::assertSame( $before, SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null )['mockups'], 'Its preview is not taken.' );
	}

	public function test_nothing_is_sent_to_gelato_while_it_is_switched_off(): void {
		$this->account->set( self::KEY, self::STORE );
		$version = $this->submitted();
		Settings::save( array_merge( Settings::all(), array( Settings::GELATO_ENABLED => false ) ) );
		$before = count( $this->calls );

		$this->mockups()->submitted( $version );

		self::assertSame( 'none', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( array(), $this->queue->pending );
		self::assertCount( $before, $this->calls );
	}

	/**
	 * Picks template tpl-1 (black and white, S and M, front and back) at
	 * 149,95 and runs its import.
	 */
	private function import(): int {
		$catalog = $this->catalog();
		$id      = $catalog->create(
			'gelato',
			'tpl-1',
			array(
				'colors'     => array( 'Black', 'White' ),
				'sizes'      => array( 'S', 'M' ),
				'sides'      => array( 'ImageFront', 'ImageBack' ),
				'base_price' => '149,95',
			)
		);

		$this->drain_import( $catalog );

		return $id;
	}

	/**
	 * Runs the queued import rounds.
	 *
	 * @param CatalogImport $catalog The import.
	 * @param int           $limit   Most rounds.
	 */
	private function drain_import( CatalogImport $catalog, int $limit = 60 ): void {
		$this->queue->drain(
			static function ( string $hook, array $args ) use ( $catalog ): void {
				$catalog->run( $args[0] ?? 0 );
			},
			$limit
		);
	}

	/**
	 * The catalog import.
	 */
	private function catalog(): CatalogImport {
		return new CatalogImport( $this->wpdb, $this->bases, $this->base_service(), $this->files, $this->queue, $this->service( Logger::class ), 2, self::ASSETS, null, new GelatoCatalog( $this->api ) );
	}

	/**
	 * The base product service.
	 */
	private function base_service(): BaseProductService {
		return new BaseProductService( $this->bases, new DesignerAssetRepository( $this->wpdb ), $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );
	}

	/**
	 * An active Gelato base product with a submitted design (black and white).
	 *
	 * @return int The pending version.
	 */
	private function submitted(): int {
		$base = $this->import();
		$this->base_service()->set_active( $base, true );

		$user        = $this->create_user( 'gelato-creator' );
		$creator     = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $user );
		$drafts      = new DesignerDrafts( $this->products, $this->bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, $this->files );
		$submissions = new DesignerSubmissions( $this->products, $this->bases, $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2 );
		$product     = $drafts->create( $creator, $base );

		$drafts->save(
			$creator,
			$product,
			array(
				'title'        => 'Stream tee',
				'description'  => '',
				'colors'       => array( 'black', 'white' ),
				'sizes'        => array( 'S', 'M' ),
				'profit_kind'  => 'percent',
				'profit_value' => '20',
				'design'       => array(
					'version' => 1,
					'sides'   => array(
						'front' => array(
							array(
								'type'        => 'rect',
								'id'          => 'r1',
								'x'           => 50,
								'y'           => 50,
								'width'       => 40,
								'height'      => 40,
								'angle'       => 0,
								'scaleX'      => 1,
								'scaleY'      => 1,
								'opacity'     => 1,
								'fill'        => '#00ff00',
								'strokeWidth' => 0,
								'radius'      => 0,
							),
						),
					),
				),
			),
			new DesignValidator( static fn(): bool => false, array() )
		);
		// 30 × 40 mm at 300 dpi.
		$submissions->attach( $creator, $product, 'print:front', AlertImagesTest::upload( AlertImagesTest::png( 354, 472 ), 'print.png' ) );

		foreach ( array( 'black', 'white' ) as $color ) {
			$submissions->attach( $creator, $product, 'mockup:front:' . $color, AlertImagesTest::upload( AlertImagesTest::png( 200, 200 ), 'mockup.png' ) );
		}

		$submissions->submit( $creator, $product );

		return (int) ( $this->products->pending( $product )['id'] ?? 0 );
	}

	/**
	 * The mockup service.
	 */
	private function mockups(): GelatoMockups {
		return new GelatoMockups( $this->wpdb, $this->products, $this->bases, $this->api, new ProviderTaskRepository( $this->wpdb ), $this->files, $this->queue, $this->service( Logger::class ) );
	}

	/**
	 * Runs the queued jobs.
	 *
	 * @param GelatoMockups $mockups The service.
	 */
	private function drain( GelatoMockups $mockups ): void {
		$this->queue->drain(
			static function ( string $hook, array $args ) use ( $mockups ): void {
				$mockups->run( $args[0] ?? 0 );
			},
			20
		);
	}

	/**
	 * The first call of a method and URL.
	 *
	 * @param string $method Method.
	 * @param string $url    URL.
	 * @return array{0: string, 1: string, 2: array<string, mixed>}|null
	 */
	private function call( string $method, string $url ): ?array {
		foreach ( $this->calls as $call ) {
			if ( $method === $call[0] && $url === $call[1] ) {
				return $call;
			}
		}

		return null;
	}

	/**
	 * Gelato's template tpl-1, the product made from it and the images.
	 */
	private function gelato_answers(): void {
		$polls   = 0;
		$variant = static fn( string $id, string $color, string $size ): array => array(
			'id'                => $id,
			'title'             => $color . ' ' . $size,
			'productUid'        => 'apparel_product_gca_t-shirt_gsc_crewneck_gcu_unisex_gqa_classic_gsi_' . strtolower( $size ) . '_gco_' . strtolower( $color ),
			// The template author's own option names; the catalog's productUid wins.
			'variantOptions'    => array(
				array(
					'name'  => 'Color',
					'value' => 'Black' === $color ? 'Sort' : 'Hvid',
				),
				array(
					'name'  => 'Size',
					'value' => $size,
				),
			),
			'imagePlaceholders' => array(
				array(
					'name'      => 'ImageFront',
					'printArea' => 'front',
					'width'     => 30,
					'height'    => 40,
				),
				array(
					'name'      => 'ImageBack',
					'printArea' => 'back',
					'width'     => 30,
					'height'    => 40,
				),
			),
		);
		$product = self::product( ... );
		$shirt   = self::shirt( ... );
		$black   = array( 35, 35, 35 );

		$this->answers = array(
			self::API . '/templates/tpl-1'               => self::answer(
				200,
				array(
					'id'           => 'tpl-1',
					'templateName' => 'Tee template',
					'title'        => 'Classic tee',
					'description'  => '<p>Soft cotton.</p>',
					'previewUrl'   => 'https://cdn.example/gelato-preview.png',
					'variants'     => array( $variant( 'tv-b-s', 'Black', 'S' ), $variant( 'tv-b-m', 'Black', 'M' ), $variant( 'tv-w-s', 'White', 'S' ), $variant( 'tv-w-m', 'White', 'M' ) ),
				)
			),
			// The import's products (one per colour and the marker one), and then the design's.
			self::API . '/stores/' . self::STORE . '/products:create-from-template' => static function ( array $args ): array {
				$title = (string) ( json_decode( (string) ( $args['body'] ?? '' ), true )['title'] ?? '' );
				$id    = 1 === preg_match( '/\(([a-z]+)(?::([a-z]+))?\)$/', $title, $match ) ? 'gp-' . $match[1] . ( isset( $match[2] ) ? '-' . $match[2] : '' ) : 'gp-1';

				return self::answer(
					200,
					array(
						'id'     => $id,
						'status' => 'created',
					)
				);
			},
			self::STORE_API . '/products/gp-blank-black' => self::product( 'gp-blank-black', 'https://cdn.example/blank-b-front.png' ),
			self::STORE_API . '/products/gp-blank-white' => self::product( 'gp-blank-white', 'https://cdn.example/blank-w-front.png' ),
			self::STORE_API . '/products/gp-marker'      => self::product( 'gp-marker', 'https://cdn.example/marker-front.png' ),
			'https://cdn.example/blank-b-front.png'      => $shirt( $black ),
			'https://cdn.example/blank-b-back.png'       => $shirt( $black, array( array( 90, 40, 10, 10, array( 200, 200, 200 ) ) ) ),
			'https://cdn.example/blank-w-front.png'      => $shirt( array( 250, 250, 250 ) ),
			'https://cdn.example/blank-w-back.png'       => $shirt( array( 250, 250, 250 ) ),
			'https://cdn.example/marker-front.png'       => $shirt( $black, array( array( 70, 50, 60, 80, array( 235, 15, 230 ) ) ) ),
			'https://cdn.example/marker-back.png'        => $shirt( $black, array( array( 90, 40, 10, 10, array( 200, 200, 200 ) ), array( 80, 60, 45, 60, array( 10, 225, 235 ) ) ) ),
			self::STORE_API . '/products/gp-1'           => static function () use ( &$polls, $product ): array {
				++$polls;

				return 1 === $polls
					? self::answer(
						200,
						array(
							'id'     => 'gp-1',
							'status' => 'publishing',
						)
					)
					: $product( 'gp-1', 'https://cdn.example/gelato-black.png' );
			},
			'https://cdn.example/gelato-black.png'       => self::file( AlertImagesTest::png( 320, 320 ) ),
			'https://cdn.example/gelato-black-back.png'  => self::file( AlertImagesTest::png( 310, 310 ) ),
			'https://cdn.example/gelato-white.png'       => self::file( AlertImagesTest::png( 300, 300 ) ),
		);
	}

	/**
	 * An active product of the store as Gelato's reference shows it: one preview.
	 *
	 * @param string $id      Product.
	 * @param string $preview Preview URL.
	 * @return array<string, mixed>
	 */
	private static function product( string $id, string $preview ): array {
		return self::answer(
			200,
			array(
				'id'            => $id,
				'status'        => 'active',
				'previewUrl'    => $preview,
				'productImages' => array(),
				'variants'      => array(
					array(
						'id'    => 'v-' . $id,
						'title' => 'Variant',
					),
				),
			)
		);
	}

	/**
	 * A render of a shirt in a light studio, with markers or prints on it.
	 *
	 * @param array{0: int, 1: int, 2: int}                                                 $color  The shirt.
	 * @param list<array{0: int, 1: int, 2: int, 3: int, 4: array{0: int, 1: int, 2: int}}> $marker Rectangles on it.
	 * @return array<string, mixed>
	 */
	private static function shirt( array $color, array $marker = array() ): array {
		return self::file( Photo::png( 200, 200, array( 240, 240, 240 ), array_merge( array( array( 40, 30, 120, 150, $color ) ), $marker ) ) );
	}

	/**
	 * A JSON answer.
	 *
	 * @param int                   $status  Status.
	 * @param array<mixed>          $body    Body (an object or, like the catalogs, a list).
	 * @param array<string, string> $headers Headers.
	 * @return array<string, mixed>
	 */
	private static function answer( int $status, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * A file answer.
	 *
	 * @param string $bytes Bytes.
	 * @return array<string, mixed>
	 */
	private static function file( string $bytes ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => $bytes,
		);
	}
}
