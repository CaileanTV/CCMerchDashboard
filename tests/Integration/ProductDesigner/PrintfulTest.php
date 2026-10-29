<?php
/**
 * Printful as catalog and mockup generator, with a fake HTTP (step 30.12).
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
use MerchBoard\Modules\Creators\Creator;
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
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulApi;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulException;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulMockups;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulToken;
use MerchBoard\Modules\ProductDesigner\Providers\ProviderTaskRepository;
use MerchBoard\Modules\ProductDesigner\Providers\SignedPrintFiles;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_Error;
use WP_REST_Request;

/**
 * Only the approved endpoints are called, with the encrypted token as
 * Bearer, 15 s and no redirects; a catalog product becomes a base product
 * draft; a submitted design gets one mockup task per side with a signed,
 * short-lived print file URL, and Printful's mockups replace the browser's.
 * Nothing is called while Printful is off.
 */
#[CoversClass( PrintfulApi::class )]
#[CoversClass( PrintfulToken::class )]
#[CoversClass( PrintfulCatalog::class )]
#[CoversClass( PrintfulMockups::class )]
#[CoversClass( SignedPrintFiles::class )]
final class PrintfulTest extends IntegrationTestCase {

	private const TOKEN = 'pf_test_token_1234567890abcdef';

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
	 * Notifications fired.
	 *
	 * @var list<string>
	 */
	private array $notified = array();

	/**
	 * The notification listener.
	 *
	 * @var callable
	 */
	private $listener;

	private PrintfulToken $token;

	private PrintfulApi $api;

	private DesignerFiles $files;

	private BaseProductRepository $bases;

	private DesignerProductRepository $products;

	private FakeJobQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		DesignerFiles::remove_all();
		wp_set_current_user( 1 );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => true, Settings::PRINTFUL_ENABLED => true ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->token    = new PrintfulToken( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$this->api      = new PrintfulApi(
			$this->token,
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
		$this->listener = function ( mixed $type ): void {
			$this->notified[] = (string) $type;
		};
		add_action( 'merchboard_notify', $this->listener, 1, 1 );
		$this->catalog_answers();
	}

	protected function tearDown(): void {
		remove_action( 'merchboard_notify', $this->listener, 1 );
		delete_option( PrintfulToken::OPTION );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => false, Settings::PRINTFUL_ENABLED => false ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server after the test.
		DesignerFiles::remove_all();
		parent::tearDown();
	}

	public function test_the_token_is_encrypted_and_never_audited(): void {
		foreach ( array( 'short', 'with spaces in it but long enough' ) as $invalid ) {
			try {
				$this->token->set( $invalid );
				self::fail( 'Invalid token.' );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::PRINTFUL_TOKEN, $e->reason() );
			}
		}

		$this->token->set( '  ' . self::TOKEN . ' ' );

		self::assertSame( self::TOKEN, $this->token->get() );
		self::assertStringNotContainsString( self::TOKEN, (string) get_option( PrintfulToken::OPTION ) );
		self::assertContains( $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $this->wpdb->options, PrintfulToken::OPTION ) ), array( 'no', 'off' ) );
		self::assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );

		$this->token->clear();
		self::assertNull( $this->token->get() );
		self::assertFalse( $this->api->available() );
	}

	public function test_only_approved_calls_with_the_token_no_redirects_and_respect_for_429(): void {
		try {
			$this->api->product( 71 );
			self::fail( 'No token: no call.' );
		} catch ( PrintfulException $e ) {
			self::assertSame( PrintfulException::NOT_CONFIGURED, $e->reason() );
		}

		self::assertSame( array(), $this->calls );
		$this->token->set( self::TOKEN );

		self::assertSame( 'Gildan tee', $this->api->product( 71 )['product']['title'] ?? null );
		[ $method, $url, $args ] = $this->call( 'GET', 'https://api.printful.com/products/71' ) ?? self::fail( 'No call.' );
		self::assertSame( array( 'GET', 'https://api.printful.com/products/71', 15, 0, 'Bearer ' . self::TOKEN ), array( $method, $url, $args['timeout'] ?? null, $args['redirection'] ?? null, $args['headers']['Authorization'] ?? null ) );

		$this->answers['https://api.printful.com/products/72'] = self::answer( 429, array(), array( 'retry-after' => '30' ) );
		$this->answers['https://api.printful.com/products/73'] = self::answer( 401, array() );

		foreach ( array(
			72 => array( PrintfulException::RATE_LIMITED, 30 ),
			73 => array( PrintfulException::UNAUTHORIZED, 0 ),
			74 => array( PrintfulException::FAILED, 0 ),
		) as $id => [ $reason, $wait ] ) {
			try {
				$this->api->product( $id );
				self::fail( 'Refused: ' . $id );
			} catch ( PrintfulException $e ) {
				self::assertSame( array( $reason, $wait ), array( $e->reason(), $e->retry_after ) );
			}
		}

		try {
			$this->api->download( 'http://cdn.example/t.png' );
			self::fail( 'Only https.' );
		} catch ( PrintfulException $e ) {
			self::assertSame( PrintfulException::INVALID, $e->reason() );
		}

		Settings::save( array_merge( Settings::all(), array( Settings::PRINTFUL_ENABLED => false ) ) );
		$before = count( $this->calls );

		try {
			$this->api->templates( 71 );
			self::fail( 'Switched off.' );
		} catch ( PrintfulException $e ) {
			self::assertSame( PrintfulException::NOT_CONFIGURED, $e->reason() );
		}

		self::assertCount( $before, $this->calls );
	}

	public function test_a_catalog_product_becomes_a_base_product_draft(): void {
		$this->token->set( self::TOKEN );
		$base = $this->bases->find( $this->import() );

		self::assertNotNull( $base );
		self::assertSame( array( BaseProduct::DRAFT, 'printful', 'Gildan tee', 1499500 ), array( $base->status, $base->source, $base->name, $base->base_units ) );
		self::assertSame( array( array( 'black', 'Black', '#111111' ), array( 'white', 'White', '#ffffff' ) ), array_map( static fn( array $color ): array => array( $color['key'], $color['name'], $color['hex'] ), $base->colors ) );
		self::assertSame( array( 'S', 'M' ), $base->sizes );

		$view = $base->views[0];
		self::assertSame( array( 'front-print', 'Front print', 200, 200 ), array( $view->key, $view->name, $view->width, $view->height ) );
		self::assertSame( array( 50, 30, 100, 130 ), array( $view->area['x'], $view->area['y'], $view->area['width'], $view->area['height'] ), 'The template\'s print area, scaled to the image.' );
		self::assertSame( array( 76, 102, 100 ), array( $view->width_mm, $view->height_mm, $view->dpi ), '300 × 400 px at 100 dpi.' );
		self::assertArrayHasKey( 'background', $view->layers );
		self::assertSame( array( 'front-print' => 'front' ), $this->bases->provider( (int) $base->id )['placements'] ?? null );
	}

	public function test_printful_makes_the_mockups_of_a_submitted_design(): void {
		$this->token->set( self::TOKEN );
		[ , $product, $version ] = $this->submitted();
		$mockups                 = $this->mockups();
		$review                  = new DesignerReview( $this->products, $this->bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->queue, 2, static function (): void {} );
		$before                  = SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null )['mockups']['front-print']['black']['file'] ?? '';

		$mockups->submitted( $version );

		self::assertSame( 'running', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( array( array( PrintfulMockups::HOOK, array( $version ) ) ), array_map( static fn( array $job ): array => array( $job['hook'], $job['args'] ), $this->queue->pending ) );

		try {
			$review->approve( $version );
			self::fail( 'Not while Printful makes the mockups.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::MOCKUPS_PENDING, $e->reason() );
		}

		$this->drain( $mockups );

		$task = $this->call( 'POST', 'https://api.printful.com/mockup-generator/create-task/71' );
		self::assertNotNull( $task );
		$body = json_decode( (string) ( $task[2]['body'] ?? '' ), true );
		self::assertSame( array( 101, 201 ), $body['variant_ids'] ?? null, 'A variant per chosen colour, the first chosen size.' );
		self::assertSame( 'front', $body['files'][0]['placement'] ?? null );
		$url = (string) ( $body['files'][0]['image_url'] ?? '' );
		self::assertStringContainsString( '/merchboard/v1/designer-print/' . $version . '/front-print', urldecode( $url ) );

		$files = SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null );
		self::assertSame( 'ready', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( array( 'black', 'white' ), array_keys( $files['mockups']['front-print'] ) );
		self::assertNotSame( $before, $files['mockups']['front-print']['black']['file'], 'Printful\'s mockup replaced the browser\'s.' );
		self::assertNull( $this->files->path( $before ), 'The browser\'s mockup is deleted.' );
		self::assertSame( array( 300, 300 ), array( $files['mockups']['front-print']['white']['width'], $files['mockups']['front-print']['white']['height'] ) );

		// The signed URL serves the print file; a tampered or expired one does not.
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $parsed );
		$query  = array(
			'expires'   => is_string( $parsed['expires'] ?? null ) ? $parsed['expires'] : '',
			'signature' => is_string( $parsed['signature'] ?? null ) ? $parsed['signature'] : '',
		);
		$signed = new SignedPrintFiles( $this->products, $this->files );
		self::assertSame( 200, $signed->serve( $this->print_request( $version, 'front-print', $query ) )->get_status() );
		self::assertSame( 404, $signed->serve( $this->print_request( $version, 'front-print', array_merge( $query, array( 'signature' => str_repeat( 'a', 64 ) ) ) ) )->get_status() );
		self::assertFalse( SignedPrintFiles::valid( $version, 'front-print', (int) $query['expires'], (string) $query['signature'], (int) $query['expires'] + 1 ), 'Expired.' );
		self::assertSame( array(), array_values( array_intersect( array( DesignerNotifications::MOCKUPS_FAILED ), $this->notified ) ) );

		$review->approve( $version );
		self::assertSame( 'approved', $this->products->version( $version )['status'] ?? null );
		self::assertSame( 200, $signed->serve( $this->print_request( $version, 'front-print', $query ) )->get_status(), 'Approved: still served.' );
		self::assertSame( 404, $signed->serve( $this->print_request( $version + 1, 'front-print', $query ) )->get_status(), 'The signature names one version.' );
		self::assertGreaterThan( 0, $product );
	}

	public function test_a_failed_task_keeps_the_browser_s_mockups_and_tells_the_administrator(): void {
		$this->token->set( self::TOKEN );
		[ , , $version ] = $this->submitted();
		$this->answers['https://api.printful.com/mockup-generator/task?task_key=tk1'] = self::answer(
			200,
			array(
				'result' => array(
					'status' => 'failed',
					'error'  => 'Bad image',
				),
			)
		);
		$before = SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null )['mockups'];

		$mockups = $this->mockups();
		$mockups->submitted( $version );
		$this->drain( $mockups );

		self::assertSame( 'failed', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( $before, SubmissionFiles::decode( $this->products->version( $version )['files'] ?? null )['mockups'] );
		self::assertContains( DesignerNotifications::MOCKUPS_FAILED, $this->notified );
	}

	public function test_mockups_never_hold_up_an_approval_for_good_and_leave_no_files_behind(): void {
		$this->token->set( self::TOKEN );
		[ , , $version ] = $this->submitted();
		$review          = new DesignerReview( $this->products, $this->bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->queue, 2, static function (): void {} );
		$files           = static fn(): int => count( (array) glob( DesignerFiles::directory() . '/*.{png,jpg,webp}', GLOB_BRACE ) );

		// The white mockup cannot be downloaded: the black one already stored is deleted again.
		$this->answers['https://cdn.example/white.png'] = self::answer( 404, array() );
		$before  = $files();
		$mockups = $this->mockups();
		$mockups->submitted( $version );
		$this->drain( $mockups );

		self::assertSame( 'failed', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( $before, $files(), 'No downloaded mockup is left behind.' );

		// A job that died leaves the mockups running: after half an hour they no longer hold up the approval.
		$this->products->set_mockup_state( $version, PrintfulMockups::RUNNING );

		try {
			$review->approve( $version );
			self::fail( 'Not while the mockups are being made.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::MOCKUPS_PENDING, $e->reason() );
		}

		$this->wpdb->update( $this->table( Tables::DESIGNER_PROVIDER_TASKS ), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DesignerReview::STALE_MOCKUPS - 5 ) ), array( 'version_id' => $version ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: the job stopped long ago.
		$review->approve( $version );
		self::assertSame( 'approved', $this->products->version( $version )['status'] ?? null );
	}

	public function test_a_queue_that_refuses_marks_the_mockups_failed(): void {
		$this->token->set( self::TOKEN );
		[ , , $version ]     = $this->submitted();
		$this->queue->refuse = true;

		$this->mockups()->submitted( $version );

		self::assertSame( 'failed', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertContains( DesignerNotifications::MOCKUPS_FAILED, $this->notified );
	}

	public function test_nothing_is_sent_to_printful_while_it_is_switched_off(): void {
		$this->token->set( self::TOKEN );
		[ , , $version ] = $this->submitted();
		Settings::save( array_merge( Settings::all(), array( Settings::PRINTFUL_ENABLED => false ) ) );
		$before = count( $this->calls );

		$this->mockups()->submitted( $version );

		self::assertSame( 'none', $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( array(), $this->queue->pending );
		self::assertCount( $before, $this->calls );
	}

	/**
	 * Imports product 71 at 149,95.
	 */
	private function import(): int {
		$service = new BaseProductService( $this->bases, new DesignerAssetRepository( $this->wpdb ), $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );

		return ( new PrintfulCatalog( $this->api, $service, $this->bases ) )->import( 71, '149,95' );
	}

	/**
	 * An active Printful base product with a submitted design (black and white).
	 *
	 * @return array{0: Creator, 1: int, 2: int} Creator, designer product, version.
	 */
	private function submitted(): array {
		$base    = $this->import();
		$service = new BaseProductService( $this->bases, new DesignerAssetRepository( $this->wpdb ), $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );
		$service->set_active( $base, true );

		$user        = $this->create_user( 'printful-creator' );
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
						'front-print' => array(
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
		$submissions->attach( $creator, $product, 'print:front-print', AlertImagesTest::upload( AlertImagesTest::png( 299, 402 ), 'print.png' ) );

		foreach ( array( 'black', 'white' ) as $color ) {
			$submissions->attach( $creator, $product, 'mockup:front-print:' . $color, AlertImagesTest::upload( AlertImagesTest::png( 200, 200 ), 'mockup.png' ) );
		}

		$submissions->submit( $creator, $product );

		return array( $creator, $product, (int) ( $this->products->pending( $product )['id'] ?? 0 ) );
	}

	/**
	 * The mockup service.
	 */
	private function mockups(): PrintfulMockups {
		return new PrintfulMockups( $this->wpdb, $this->products, $this->bases, $this->api, new ProviderTaskRepository( $this->wpdb ), $this->files, $this->queue, $this->service( Logger::class ) );
	}

	/**
	 * Runs the queued jobs.
	 *
	 * @param PrintfulMockups $mockups The service.
	 */
	private function drain( PrintfulMockups $mockups ): void {
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
	 * A request of the signed print file route.
	 *
	 * @param int                  $version Version.
	 * @param string               $side    Side.
	 * @param array<string, mixed> $query   Query.
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function print_request( int $version, string $side, array $query ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/merchboard/v1/designer-print/' . $version . '/' . $side );
		$request->set_url_params(
			array(
				'version' => (string) $version,
				'side'    => $side,
			)
		);
		$request->set_query_params( $query );

		return $request;
	}

	/**
	 * Printful's catalog, templates, tasks and files for product 71.
	 */
	private function catalog_answers(): void {
		$api   = 'https://api.printful.com';
		$polls = 0;

		$this->answers = array(
			$api . '/products/71'                        => self::answer(
				200,
				array(
					'result' => array(
						'product'  => array(
							'id'          => 71,
							'title'       => 'Gildan tee',
							'description' => '<b>Soft</b> cotton.',
						),
						'variants' => array(
							array( 'id' => 101, 'color' => 'Black', 'color_code' => '#111111', 'size' => 'S' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'id' => 102, 'color' => 'Black', 'color_code' => '#111111', 'size' => 'M' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'id' => 201, 'color' => 'White', 'color_code' => '#ffffff', 'size' => 'S' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'id' => 202, 'color' => 'White', 'color_code' => '#ffffff', 'size' => 'M' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						),
					),
				)
			),
			$api . '/mockup-generator/printfiles/71'     => self::answer(
				200,
				array(
					'result' => array(
						'available_placements' => array( 'front' => 'Front print' ),
						'printfiles'           => array( array( 'printfile_id' => 5, 'width' => 300, 'height' => 400, 'dpi' => 100 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				)
			),
			$api . '/mockup-generator/templates/71'      => self::answer(
				200,
				array(
					'result' => array(
						'variant_mapping' => array( array( 'variant_id' => 101, 'templates' => array( array( 'placement' => 'front', 'template_id' => 9 ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						'templates'       => array(
							array(
								'template_id'       => 9,
								'image_url'         => 'https://cdn.example/template.png',
								'printfile_id'      => 5,
								'template_width'    => 400,
								'template_height'   => 400,
								'print_area_width'  => 200,
								'print_area_height' => 260,
								'print_area_top'    => 60,
								'print_area_left'   => 100,
							),
						),
					),
				)
			),
			'https://cdn.example/template.png'           => self::file( AlertImagesTest::png( 200, 200 ) ),
			$api . '/mockup-generator/create-task/71'    => self::answer( 200, array( 'result' => array( 'task_key' => 'tk1', 'status' => 'pending' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$api . '/mockup-generator/task?task_key=tk1' => static function () use ( &$polls ): array {
				++$polls;

				return 1 === $polls
					? self::answer( 200, array( 'result' => array( 'status' => 'pending' ) ) )
					: self::answer(
						200,
						array(
							'result' => array(
								'status'  => 'completed',
								'mockups' => array(
									array( 'placement' => 'front', 'variant_ids' => array( 101, 102 ), 'mockup_url' => 'https://cdn.example/black.png' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
									array( 'placement' => 'front', 'variant_ids' => array( 201 ), 'extra' => array( array( 'url' => 'https://cdn.example/white.png' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
								),
							),
						)
					);
			},
			'https://cdn.example/black.png'              => self::file( AlertImagesTest::png( 300, 300 ) ),
			'https://cdn.example/white.png'              => self::file( AlertImagesTest::png( 300, 300 ) ),
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
			'body'     => (string) wp_json_encode( array_merge( array( 'code' => $status ), $body ) ),
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
