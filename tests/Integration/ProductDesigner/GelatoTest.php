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
use MerchBoard\Modules\ProductDesigner\Providers\GelatoAccount;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoApi;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoException;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoMockups;
use MerchBoard\Modules\ProductDesigner\Providers\ProviderTaskRepository;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
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
#[CoversClass( GelatoMockups::class )]
final class GelatoTest extends IntegrationTestCase {

	private const KEY = 'gelato-test-key-1234567890:abcdef';

	private const STORE = '0f8e2c1a-1b2c-4d3e-8f90-123456789abc';

	private const API = 'https://ecommerce.gelatoapis.com/v1';

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
				$answer        = is_callable( $answer ) ? $answer() : $answer;

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

	public function test_a_template_becomes_a_base_product_draft(): void {
		$this->account->set( self::KEY, self::STORE );
		$base = $this->bases->find( $this->import() );

		self::assertNotNull( $base );
		self::assertSame( array( BaseProduct::DRAFT, 'gelato', 'Classic tee', 1499500 ), array( $base->status, $base->source, $base->name, $base->base_units ) );
		self::assertSame( array( array( 'black', 'Black', '#ffffff' ), array( 'white', 'White', '#ffffff' ) ), array_map( static fn( array $color ): array => array( $color['key'], $color['name'], $color['hex'] ), $base->colors ), 'Gelato gives no colour codes.' );
		self::assertSame( array( 'S', 'M' ), $base->sizes );

		$view = $base->views[0];
		self::assertSame( array( 'front', 'Front', 200, 200 ), array( $view->key, $view->name, $view->width, $view->height ) );
		self::assertSame( array( 50, 33, 100, 133 ), array( $view->area['x'], $view->area['y'], $view->area['width'], $view->area['height'] ), 'Half the preview\'s width in the middle, in the print size\'s proportions.' );
		self::assertSame( array( 30, 40, 300 ), array( $view->width_mm, $view->height_mm, $view->dpi ) );
		self::assertArrayHasKey( 'background', $view->layers );
		self::assertSame(
			array(
				'source'     => 'gelato',
				'ref'        => 'tpl-1',
				'placements' => array( 'front' => 'ImageFront' ),
			),
			$this->bases->provider( (int) $base->id )
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

		$create = $this->call( 'POST', self::API . '/stores/' . self::STORE . '/products:create-from-template' );
		self::assertNotNull( $create );
		$body = json_decode( (string) ( $create[2]['body'] ?? '' ), true );
		self::assertSame( array( 'tpl-1', 'Stream tee', false ), array( $body['templateId'] ?? null, $body['title'] ?? null, $body['isVisibleInTheOnlineStore'] ?? null ) );
		self::assertSame( array( 'tv-b-s', 'tv-w-s' ), array_column( $body['variants'] ?? array(), 'templateVariantId' ), 'A template variant per chosen colour, the first chosen size.' );
		self::assertSame( 'ImageFront', $body['variants'][0]['imagePlaceholders'][0]['name'] ?? null );
		self::assertStringContainsString( '/merchboard/v1/designer-print/' . $version . '/front', urldecode( (string) ( $body['variants'][0]['imagePlaceholders'][0]['fileUrl'] ?? '' ) ) );
		self::assertSame( 'gp-1', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT provider_ref FROM %i WHERE id = %d', Tables::name( $this->wpdb, Tables::DESIGNER_VERSIONS ), $version ) ), 'Gelato\'s product is stored on the version.' );

		$files = SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null );
		self::assertSame( 'ready', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( array( 'black', 'white' ), array_keys( $files['mockups']['front'] ) );
		self::assertSame( array( 300, 300 ), array( $files['mockups']['front']['white']['width'], $files['mockups']['front']['white']['height'] ), 'The image tied to the white variant.' );
		self::assertSame( array( 320, 320 ), array( $files['mockups']['front']['black']['width'], $files['mockups']['front']['black']['height'] ), 'The product preview for the first colour.' );

		foreach ( $before as $mockup ) {
			self::assertNull( $this->files->path( $mockup['file'] ), 'The browser\'s mockups are deleted.' );
		}

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
	 * Imports template tpl-1 at 149,95.
	 */
	private function import(): int {
		return ( new GelatoCatalog( $this->api, $this->base_service(), $this->bases ) )->import( 'tpl-1', '149,95' );
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
			'variantOptions'    => array(
				array(
					'name'  => 'Color',
					'value' => $color,
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
			),
		);

		$this->answers = array(
			self::API . '/templates/tpl-1'           => self::answer(
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
			'https://cdn.example/gelato-preview.png' => self::file( AlertImagesTest::png( 200, 200 ) ),
			self::API . '/stores/' . self::STORE . '/products:create-from-template' => self::answer(
				200,
				array(
					'id'     => 'gp-1',
					'status' => 'created',
				)
			),
			self::API . '/stores/' . self::STORE . '/products/gp-1' => static function () use ( &$polls, $variant ): array {
				++$polls;

				return 1 === $polls
					? self::answer(
						200,
						array(
							'id'     => 'gp-1',
							'status' => 'publishing',
						)
					)
					: self::answer(
						200,
						array(
							'id'            => 'gp-1',
							'status'        => 'active',
							'previewUrl'    => 'https://cdn.example/gelato-black.png',
							'variants'      => array( array_merge( $variant( 'pv-b', 'Black', 'S' ), array( 'id' => 'pv-b' ) ), array_merge( $variant( 'pv-w', 'White', 'S' ), array( 'id' => 'pv-w' ) ) ),
							'productImages' => array(
								array(
									'fileUrl'           => 'https://cdn.example/gelato-white.png',
									'productVariantIds' => array( 'pv-w' ),
								),
							),
						)
					);
			},
			'https://cdn.example/gelato-black.png'   => self::file( AlertImagesTest::png( 320, 320 ) ),
			'https://cdn.example/gelato-white.png'   => self::file( AlertImagesTest::png( 300, 300 ) ),
		);
	}

	/**
	 * A JSON answer.
	 *
	 * @param int                   $status  Status.
	 * @param array<string, mixed>  $body    Body.
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
