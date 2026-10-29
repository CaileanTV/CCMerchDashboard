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
use MerchBoard\Tests\Unit\Support\Photo;
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
#[CoversClass( CatalogImport::class )]
#[CoversClass( PrintfulMockups::class )]
#[CoversClass( SignedPrintFiles::class )]
final class PrintfulTest extends IntegrationTestCase {

	private const TOKEN = 'pf_test_token_1234567890abcdef';

	private const ASSETS = 'https://shop.example/wp-content/plugins/merchboard/assets/designer/';

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
				$answer        = is_callable( $answer ) ? $answer( $args ) : $answer;

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
		delete_transient( PrintfulCatalog::CACHE );
		delete_transient( PrintfulCatalog::CATEGORIES );
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
			$this->api->printfiles( 71 );
			self::fail( 'Switched off.' );
		} catch ( PrintfulException $e ) {
			self::assertSame( PrintfulException::NOT_CONFIGURED, $e->reason() );
		}

		self::assertCount( $before, $this->calls );
	}

	public function test_the_catalog_is_searched_by_words_and_category_and_cached(): void {
		$this->token->set( self::TOKEN );
		$catalog = new PrintfulCatalog( $this->api );

		self::assertSame( array( 71 ), array_column( $catalog->search( 'gildan TEE' ), 'id' ) );
		self::assertSame( array( 71, 72 ), array_column( $catalog->search( '', 6 ), 'id' ), 'A category with its subcategories.' );
		self::assertSame( array(), $catalog->search( 'poster' ), 'Discontinued products are left out.' );
		self::assertSame( array( '' ), array_column( $catalog->search( 'hoodie' ), 'image' ), 'Only https images are shown.' );
		self::assertSame( array( 'Clothing', 'Mugs', 'T-shirts' ), array_column( $catalog->categories(), 'title' ) );

		$before = count( $this->calls );
		$catalog->search( 'tee', 24 );
		self::assertCount( $before, $this->calls, 'The catalog and its categories are cached.' );

		$choice = $catalog->choice( '71' );
		self::assertSame( array( 'front', 'back' ), array_column( $choice['sides'], 'placement' ), 'A placement without a print file is not offered.' );
		self::assertSame( array( 76, 102, 100 ), array( $choice['sides'][0]['width_mm'], $choice['sides'][0]['height_mm'], $choice['sides'][0]['dpi'] ), '300 × 400 px at 100 dpi.' );

		try {
			$this->catalog()->create( 'printful', '71', array( 'colors' => array( 'Black' ), 'sizes' => array( 'XL' ), 'sides' => array( 'front' ), 'base_price' => '149,95' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			self::fail( 'No variant in a ticked size.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::BASE_PICK, $e->reason() );
		}

		self::assertSame( array(), $this->bases->all(), 'Nothing is created.' );
	}

	public function test_a_picked_catalog_product_is_imported_with_a_photo_per_colour_and_a_measured_print_area(): void {
		$this->token->set( self::TOKEN );
		$base = $this->bases->find( $this->import() );

		self::assertNotNull( $base );
		self::assertSame( array( BaseProduct::DRAFT, 'printful', 'Gildan tee', 1499500 ), array( $base->status, $base->source, $base->name, $base->base_units ) );
		self::assertSame( array( array( 'black', 'Black', '#111111' ), array( 'white', 'White', '#ffffff' ) ), array_map( static fn( array $color ): array => array( $color['key'], $color['name'], $color['hex'] ), $base->colors ) );
		self::assertSame( array( 'S', 'M' ), $base->sizes );
		self::assertSame( 'ready', $this->bases->import( (int) $base->id )['state'] ?? null );

		$view = $base->views[0];
		self::assertCount( 1, $base->views );
		self::assertSame( array( 'front-print', 'Front print', 200, 200 ), array( $view->key, $view->name, $view->width, $view->height ) );
		self::assertSame( array( 'background', 'background:white' ), array_keys( $view->layers ), 'The black photo is the background, the white one the white colour\'s own.' );

		foreach ( array( 70, 50, 60, 80 ) as $index => $expected ) {
			self::assertEqualsWithDelta( $expected, array_values( $view->area )[ $index ], 2, 'Where the marker showed on the black photo.' );
		}

		self::assertSame( array( 76, 102, 100 ), array( $view->width_mm, $view->height_mm, $view->dpi ) );
		self::assertSame(
			array(
				'source'     => 'printful',
				'ref'        => '71',
				'placements' => array( 'front-print' => 'front' ),
				'variants'   => array(
					'black' => array(
						'S' => '101',
						'M' => '102',
					),
					'white' => array(
						'S' => '201',
						'M' => '202',
					),
				),
				'slots'      => array( 'front-print' => 'front' ),
			),
			$this->bases->provider( (int) $base->id )
		);

		$tasks = $this->calls_to( 'POST', 'https://api.printful.com/mockup-generator/create-task/71' );
		self::assertCount( 2, $tasks, 'One blank and one marker render.' );
		$blank  = json_decode( (string) ( $tasks[0][2]['body'] ?? '' ), true );
		$marker = json_decode( (string) ( $tasks[1][2]['body'] ?? '' ), true );
		self::assertSame( array( array( 101, 201 ), 'front', self::ASSETS . 'blank.png', 300, 400 ), array( $blank['variant_ids'] ?? null, $blank['files'][0]['placement'] ?? null, $blank['files'][0]['image_url'] ?? null, $blank['files'][0]['position']['area_width'] ?? null, $blank['files'][0]['position']['area_height'] ?? null ) );
		self::assertSame( array( array( 101 ), self::ASSETS . 'marker-1.png' ), array( $marker['variant_ids'] ?? null, $marker['files'][0]['image_url'] ?? null ), 'The first colour with the first side\'s marker.' );
		self::assertCount( 2, (array) glob( DesignerFiles::directory() . '/*.png' ), 'Only the two photos are kept.' );
		self::assertSame( array(), $this->notified );
	}

	public function test_the_admin_page_searches_picks_and_shows_a_failed_import_with_the_provider_s_answer(): void {
		$this->token->set( self::TOKEN );
		$this->answers['https://api.printful.com/mockup-generator/task?task_key=tk-blank'] = self::answer( 200, array( 'result' => array( 'status' => 'failed', 'error' => '<b>Gone</b>' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$catalog = $this->catalog();
		$page    = new BaseProductsPage( $this->bases, new DesignerAssetRepository( $this->wpdb ), 2, 'DKK', '', '', true, false, $catalog, new PrintfulCatalog( $this->api ) );
		$render  = static function ( array $query ) use ( $page ): string {
			$_GET = $query;
			ob_start();
			$page->render();

			return (string) ob_get_clean();
		};

		$search = $render( array( 'view' => 'new', 'source' => 'printful', 'q' => 'tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'Gildan tee', $search );
		self::assertStringContainsString( 'pick=71', $search );
		self::assertStringContainsString( '<option value="24"', $search, 'The categories to narrow the search.' );

		$pick = $render( array( 'view' => 'new', 'source' => 'printful', 'pick' => '71' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringContainsString( 'name="do" value="catalog_create"', $pick );
		self::assertStringContainsString( 'name="colors[]" value="Black" checked', $pick );
		self::assertStringContainsString( 'name="sides[]" value="front" checked', $pick );
		self::assertStringContainsString( 'name="sides[]" value="back" checked', $pick, 'Front and back are ticked by default.' );

		$id = $this->import();
		self::assertSame( 'failed', $this->bases->import( $id )['state'] ?? null );

		$failed = $render( array( 'base' => (string) $id ) );
		self::assertStringContainsString( 'name="do" value="import_retry"', $failed );
		self::assertStringContainsString( '<code>blank: Gone</code>', $failed, 'The provider message is stored as plain text.' );
		self::assertStringContainsString( 'diagnose=1', $failed );

		$diagnosis = $render(
			array(
				'base'     => (string) $id,
				'diagnose' => '1',
			)
		);
		self::assertStringContainsString( 'GET /mockup-generator/printfiles/71', $diagnosis );
		self::assertStringNotContainsString( self::TOKEN, $diagnosis, 'Never the token.' );
		$_GET = array();
	}

	public function test_a_failed_render_fails_the_import_and_it_can_be_tried_again(): void {
		$this->token->set( self::TOKEN );
		$marker = $this->answers['https://api.printful.com/mockup-generator/task?task_key=tk-marker'];
		$this->answers['https://api.printful.com/mockup-generator/task?task_key=tk-marker'] = self::answer(
			200,
			array(
				'result' => array(
					'status' => 'failed',
					'error'  => 'Placement not available',
				),
			)
		);
		$id     = $this->import();
		$import = $this->bases->import( $id );

		self::assertSame( 'failed', $import['state'] ?? null );
		self::assertSame( array( DesignerException::IMPORT_RENDER, 'marker: Placement not available' ), array( $import['data']['error'] ?? null, $import['data']['detail'] ?? null ) );
		self::assertSame( array( DesignerNotifications::IMPORT_FAILED ), $this->notified );
		self::assertSame( array(), $this->bases->find( $id )->views ?? null );
		self::assertSame( array(), (array) glob( DesignerFiles::directory() . '/*.png' ), 'No photo is left behind.' );

		$this->answers['https://api.printful.com/mockup-generator/task?task_key=tk-marker'] = $marker;
		$catalog = $this->catalog();
		$catalog->retry( $id );
		$this->drain_import( $catalog );

		self::assertSame( 'ready', $this->bases->import( $id )['state'] ?? null );
		self::assertCount( 1, $this->bases->find( $id )->views ?? array() );
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

		$tasks = $this->calls_to( 'POST', 'https://api.printful.com/mockup-generator/create-task/71' );
		$task  = end( $tasks );
		self::assertNotFalse( $task, 'The design task comes after the import renders.' );
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
	 * Picks product 71 (black and white, S and M, the front) at 149,95 and
	 * runs its import.
	 */
	private function import(): int {
		$catalog = $this->catalog();
		$id      = $catalog->create(
			'printful',
			'71',
			array(
				'colors'     => array( 'Black', 'White' ),
				'sizes'      => array( 'S', 'M' ),
				'sides'      => array( 'front' ),
				'base_price' => '149,95',
			)
		);

		$this->drain_import( $catalog );

		return $id;
	}

	/**
	 * The catalog import.
	 */
	private function catalog(): CatalogImport {
		$service = new BaseProductService( $this->bases, new DesignerAssetRepository( $this->wpdb ), $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );

		return new CatalogImport( $this->wpdb, $this->bases, $service, $this->files, $this->queue, $this->service( Logger::class ), 2, self::ASSETS, new PrintfulCatalog( $this->api ) );
	}

	/**
	 * Runs the queued import rounds.
	 *
	 * @param CatalogImport $catalog The import.
	 */
	private function drain_import( CatalogImport $catalog ): void {
		$this->queue->drain(
			static function ( string $hook, array $args ) use ( $catalog ): void {
				$catalog->run( $args[0] ?? 0 );
			},
			50
		);
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
	 * Every call of a method and URL, in order.
	 *
	 * @param string $method Method.
	 * @param string $url    URL.
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function calls_to( string $method, string $url ): array {
		return array_values( array_filter( $this->calls, static fn( array $call ): bool => $method === $call[0] && $url === $call[1] ) );
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
			$api . '/products'                           => self::answer(
				200,
				array(
					'result' => array(
						array( 'id' => 71, 'title' => 'Gildan tee', 'type_name' => 'T-Shirt', 'brand' => 'Gildan', 'model' => '64000', 'image' => 'https://files.cdn.printful.com/71.jpg', 'main_category_id' => 24 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'id' => 72, 'title' => 'Hoodie', 'type_name' => 'Hoodie', 'brand' => 'Cotton Heritage', 'model' => 'M2580', 'image' => 'http://insecure.example/72.jpg', 'main_category_id' => 6 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'id' => 73, 'title' => 'Poster', 'is_discontinued' => true, 'main_category_id' => 55 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'id' => 74, 'title' => 'Mug', 'main_category_id' => 30 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				)
			),
			$api . '/categories'                         => self::answer(
				200,
				array(
					'result' => array(
						'categories' => array(
							array( 'id' => 24, 'parent_id' => 6, 'title' => 'T-shirts' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'id' => 6, 'parent_id' => 0, 'title' => 'Clothing' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'id' => 30, 'parent_id' => 0, 'title' => 'Mugs' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						),
					),
				)
			),
			$api . '/mockup-generator/printfiles/71'     => self::answer(
				200,
				array(
					'result' => array(
						'available_placements' => array(
							'front'         => 'Front print',
							'back'          => 'Back print',
							'label_outside' => 'Outside label',
						),
						'printfiles'           => array(
							array( 'printfile_id' => 5, 'width' => 300, 'height' => 400, 'dpi' => 100 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'printfile_id' => 6, 'width' => 300, 'height' => 400, 'dpi' => 100 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						),
						'variant_printfiles'   => array(
							array(
								'variant_id' => 101,
								'placements' => array(
									'front' => 5,
									'back'  => 6,
								),
							),
						),
					),
				)
			),
			// The import's blank and marker renders, and then the design's task.
			$api . '/mockup-generator/create-task/71'    => static function ( array $args ): array {
				$url = (string) ( json_decode( (string) ( $args['body'] ?? '' ), true )['files'][0]['image_url'] ?? '' );
				$key = str_ends_with( $url, 'blank.png' ) ? 'tk-blank' : ( str_contains( $url, 'marker-' ) ? 'tk-marker' : 'tk1' );

				return self::answer( 200, array( 'result' => array( 'task_key' => $key, 'status' => 'pending' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			},
			$api . '/mockup-generator/task?task_key=tk-blank' => self::answer(
				200,
				array(
					'result' => array(
						'status'  => 'completed',
						'mockups' => array(
							array( 'placement' => 'front', 'variant_ids' => array( 101, 102 ), 'mockup_url' => 'https://cdn.example/blank-black.png' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'placement' => 'front', 'variant_ids' => array( 201, 202 ), 'mockup_url' => 'https://cdn.example/blank-white.png' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						),
					),
				)
			),
			$api . '/mockup-generator/task?task_key=tk-marker' => self::answer(
				200,
				array(
					'result' => array(
						'status'  => 'completed',
						'mockups' => array(
							array( 'placement' => 'front', 'variant_ids' => array( 101 ), 'mockup_url' => 'https://cdn.example/marker-black.png' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						),
					),
				)
			),
			'https://cdn.example/blank-black.png'        => self::file( Photo::png( 200, 200, array( 240, 240, 240 ), array( array( 40, 30, 120, 150, array( 17, 17, 17 ) ) ) ) ),
			'https://cdn.example/blank-white.png'        => self::file( Photo::png( 200, 200, array( 240, 240, 240 ), array( array( 40, 30, 120, 150, array( 252, 252, 252 ) ) ) ) ),
			'https://cdn.example/marker-black.png'       => self::file( Photo::png( 200, 200, array( 240, 240, 240 ), array( array( 40, 30, 120, 150, array( 17, 17, 17 ) ), array( 70, 50, 60, 80, array( 230, 20, 225 ) ) ) ) ),
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
