<?php
/**
 * Printful's v2 catalog and mockups with v1 as the fallback, and all-over
 * products designed flat, with a fake HTTP whose answers follow real ones
 * (2026-10-31).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerDrafts;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerSubmissions;
use MerchBoard\Modules\ProductDesigner\Designs\DesignValidator;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Providers\CatalogImport;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoCatalog;
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
 * Only products sold in Europe are listed and only variants in stock there
 * are offered; v1 takes over when v2 fails, but never on a 429. An all-over
 * product is imported with its sewing patterns and no render, and one task
 * with every used part gives each side the photo of its mockup style.
 */
#[CoversClass( PrintfulApi::class )]
#[CoversClass( PrintfulCatalog::class )]
#[CoversClass( CatalogImport::class )]
#[CoversClass( PrintfulMockups::class )]
#[CoversClass( SignedPrintFiles::class )]
final class PrintfulV2Test extends IntegrationTestCase {

	private const API = 'https://api.printful.com';

	private const PAGE = 'limit=100&offset=0';

	private const REGION = 'selling_region_name=europe';

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

		$token = new PrintfulToken( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );

		$token->set( 'pf_test_token_1234567890abcdef' );

		$this->api      = new PrintfulApi(
			$token,
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
		$this->answers  = $this->regular_answers() + $this->flat_answers();
	}

	protected function tearDown(): void {
		delete_option( PrintfulToken::OPTION );
		delete_transient( PrintfulCatalog::CACHE );
		delete_transient( PrintfulCatalog::CATEGORIES );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => false, Settings::PRINTFUL_ENABLED => false ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		DesignerFiles::remove_all();
		parent::tearDown();
	}

	public function test_the_catalog_lists_what_is_sold_in_europe_and_can_reach_denmark(): void {
		$this->answers[ self::API . '/v2/catalog-products?' . self::REGION . '&destination_country=DK&' . self::PAGE ] = self::answer(
			array(
				'data'   => array(
					array( 'id' => 71, 'name' => 'Staple tee', 'type' => 'T-SHIRT', 'brand' => 'Bella', 'model' => '3001', 'image' => 'https://files.cdn.printful.com/71.jpg', 'main_category_id' => 24, 'techniques' => array( array( 'key' => 'dtg', 'is_default' => true ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 99, 'name' => 'Knitted beanie', 'type' => 'HAT', 'techniques' => array( array( 'key' => 'knitwear', 'is_default' => true ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
				'paging' => array( 'total' => 2 ),
			)
		);
		$this->answers[ self::API . '/v2/catalog-categories?' . self::PAGE ] = self::answer( array( 'data' => array( array( 'id' => 24, 'parent_id' => 0, 'title' => 'T-shirts' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$catalog = new PrintfulCatalog( $this->api );

		self::assertSame( array( 71 ), array_column( $catalog->search( 'tee' ), 'id' ) );
		self::assertSame( array(), $catalog->search( 'knitted' ), 'Knitwear cannot be designed yet.' );
		self::assertTrue( $catalog->limited_to_eu() );
		self::assertSame( array( 'T-shirts' ), array_column( $catalog->categories(), 'title' ) );
		self::assertNull( $this->call( 'GET', self::API . '/products' ), 'v1 is only the fallback.' );
	}

	public function test_v1_takes_over_when_v2_fails_and_says_so(): void {
		$this->answers[ self::API . '/products' ]   = self::answer( array( 'result' => array( array( 'id' => 71, 'title' => 'Old tee' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->answers[ self::API . '/categories' ] = self::answer( array( 'result' => array( 'categories' => array( array( 'id' => 6, 'parent_id' => 0, 'title' => 'Clothing' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$catalog = new PrintfulCatalog( $this->api );

		self::assertSame( array( 71 ), array_column( $catalog->search( 'tee' ), 'id' ) );
		self::assertFalse( $catalog->limited_to_eu() );
		self::assertSame( array( 'Clothing' ), array_column( $catalog->categories(), 'title' ) );
	}

	public function test_a_429_waits_and_never_goes_to_v1(): void {
		$this->answers[ self::API . '/v2/catalog-products/71?' . self::REGION ] = self::answer( array(), 429, array( 'retry-after' => '30' ) );

		try {
			( new PrintfulCatalog( $this->api ) )->choice( '71' );
			self::fail( 'A 429 stands.' );
		} catch ( PrintfulException $e ) {
			self::assertSame( PrintfulException::RATE_LIMITED, $e->reason() );
			self::assertSame( 30, $e->retry_after );
		}

		self::assertNull( $this->call( 'GET', self::API . '/products/71' ) );
	}

	public function test_only_variants_in_stock_in_europe_are_offered_with_sorted_sizes(): void {
		$choice = ( new PrintfulCatalog( $this->api ) )->choice( '71' );

		self::assertFalse( $choice['flat'] ?? null );
		self::assertSame( array( 'Black' ), array_column( $choice['colors'], 'name' ), 'White is not fulfillable in Europe.' );
		self::assertSame( array( 'S', 'M', '2XL' ), $choice['sizes'] );
		self::assertSame( array( 'front' ), array_column( $choice['sides'], 'placement' ), 'Neither the mockup placement nor dtfilm is offered.' );
		self::assertSame( 'dtg', $choice['sides'][0]['technique'] ?? null );
		self::assertSame( array( 305, 406, 1800, 2400, 150 ), array( $choice['sides'][0]['width_mm'], $choice['sides'][0]['height_mm'], $choice['sides'][0]['width'], $choice['sides'][0]['height'], $choice['sides'][0]['dpi'] ) );
		self::assertSame( 'Soft cotton, sewn tight.', str_replace( ' ,', ',', $choice['description'] ), 'A tag leaves a space, so words do not run together.' );
	}

	public function test_a_product_out_of_stock_in_europe_or_knitted_is_refused(): void {
		$this->answers[ self::API . '/v2/catalog-products/71/availability?' . self::REGION . '&' . self::PAGE ] = self::answer(
			array(
				'data'   => array( self::stock( 101, 'dtg', 'not fulfillable' ) ),
				'paging' => array( 'total' => 1 ),
			)
		);

		$catalog = new PrintfulCatalog( $this->api );

		try {
			$catalog->choice( '71' );
			self::fail( 'Nothing is in stock in Europe.' );
		} catch ( PrintfulException $e ) {
			self::assertSame( PrintfulException::NOT_IN_EU, $e->reason() );
		}

		$this->answers[ self::API . '/v2/catalog-products/99?' . self::REGION ] = self::answer( array( 'data' => array( 'name' => 'Knit', 'techniques' => array( array( 'key' => 'knitwear', 'is_default' => true ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		try {
			$catalog->choice( '99' );
			self::fail( 'Knitwear is refused.' );
		} catch ( PrintfulException $e ) {
			self::assertSame( PrintfulException::UNSUPPORTED, $e->reason() );
		}
	}

	public function test_v1_decides_by_the_variants_status_and_cannot_import_an_all_over_product(): void {
		foreach ( array( 71, 257 ) as $id ) {
			unset( $this->answers[ self::API . '/v2/catalog-products/' . $id . '?' . self::REGION ] );
		}

		$eu = array( array( 'region' => 'EU', 'status' => 'in_stock' ), array( 'region' => 'US', 'status' => 'in_stock' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$us = array( array( 'region' => 'US', 'status' => 'in_stock' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->answers[ self::API . '/products/71' ]                    = self::answer(
			array(
				'result' => array(
					'product'  => array( 'title' => 'Tee' ),
					'variants' => array(
						array( 'id' => 101, 'color' => 'Black', 'color_code' => '#111111', 'size' => 'S', 'availability_status' => $eu ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'id' => 201, 'color' => 'White', 'color_code' => '#ffffff', 'size' => 'S', 'availability_status' => $us ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				),
			)
		);
		$this->answers[ self::API . '/mockup-generator/printfiles/71' ] = self::answer(
			array(
				'result' => array(
					'available_placements' => array( 'front' => 'Front print' ),
					'printfiles'           => array( array( 'printfile_id' => 5, 'width' => 300, 'height' => 400, 'dpi' => 100 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					'variant_printfiles'   => array( array( 'variant_id' => 101, 'placements' => array( 'front' => 5 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
			)
		);
		$this->answers[ self::API . '/products/257' ]                   = self::answer( array( 'result' => array( 'product' => array( 'techniques' => array( array( 'key' => 'CUT-SEW' ) ) ), 'variants' => array() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$catalog = new PrintfulCatalog( $this->api );

		self::assertSame( array( 'Black' ), array_column( $catalog->choice( '71' )['colors'], 'name' ) );

		try {
			$catalog->choice( '257' );
			self::fail( 'An all-over product needs v2.' );
		} catch ( PrintfulException $e ) {
			self::assertSame( PrintfulException::V2_REQUIRED, $e->reason() );
		}
	}

	public function test_a_print_size_that_follows_the_size_splits_the_sizes_in_groups(): void {
		$this->answers[ self::API . '/v2/catalog-products/257/catalog-variants?' . self::PAGE ]                  = self::answer(
			array(
				'data'   => array(
					self::flat_variant( 501, 'S', 2, 3 ),
					self::flat_variant( 502, 'M', 2, 3 ),
					self::flat_variant( 503, 'L', 4, 5 ),
				),
				'paging' => array( 'total' => 3 ),
			)
		);
		$this->answers[ self::API . '/v2/catalog-products/257/availability?' . self::REGION . '&' . self::PAGE ] = self::answer(
			array(
				'data'   => array( self::stock( 501, 'cut-sew' ), self::stock( 502, 'cut-sew' ), self::stock( 503, 'cut-sew' ) ),
				'paging' => array( 'total' => 3 ),
			)
		);

		$catalog = new PrintfulCatalog( $this->api );
		$first   = $catalog->choice( '257' );

		self::assertSame( array( 'S, M', 'L' ), $first['groups'] ?? null );
		self::assertSame( array( 'S', 'M' ), $first['sizes'] );
		self::assertSame( array( 'L' ), $catalog->choice( '257', 1 )['sizes'] );
	}

	public function test_an_all_over_product_is_imported_with_its_patterns_and_no_render(): void {
		$id   = $this->import_flat();
		$base = $this->bases->find( $id );
		$view = $base->views[0] ?? null;

		self::assertSame( CatalogImport::READY, $this->bases->import( $id )['state'] ?? null, (string) wp_json_encode( $this->bases->import( $id ) ) );
		self::assertCount( 2, $base->views ?? array() );
		self::assertNotNull( $view );
		self::assertTrue( $view->flat );
		self::assertSame( array( 'background', 'shading' ), array_keys( $view->layers ) );
		self::assertSame( array( 'front-print', 40, 40 ), array( $view->key, $view->width, $view->height ) );
		self::assertSame( array( 'x' => 8, 'y' => 0, 'width' => 27, 'height' => 40 ), $view->area, 'The print area of the 3000 px template, scaled to the 40 px file.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 51, 76, 100 ), array( $view->width_mm, $view->height_mm, $view->dpi ) );
		self::assertSame( array(), $this->calls_starting( 'POST' ), 'Nothing is rendered: the design is sewn after the submission.' );

		$provider = $this->bases->provider( $id );

		self::assertNotNull( $provider );
		self::assertTrue( $provider['flat'] );
		self::assertSame( array( 'front-print' => 'default', 'back-print' => 'back' ), $provider['placements'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'front-print' => '15710', 'back-print' => '15711' ), $provider['styles'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( array( 'name' => 'stitch_color', 'value' => 'white' ) ), $provider['options'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assert_stays_flat( $id );
	}

	public function test_one_task_with_every_part_gives_each_side_the_photo_of_its_style(): void {
		[ $version ] = $this->submit_flat();
		$mockups     = $this->mockups();

		$mockups->submitted( $version );
		$this->drain( $mockups );

		$tasks = $this->calls_starting( 'POST' );

		self::assertCount( 1, $tasks );
		self::assertSame( self::API . '/v2/mockup-tasks', $tasks[0][1] );

		$body = json_decode( (string) ( $tasks[0][2]['body'] ?? '' ), true );
		$task = $body['products'][0] ?? array();

		self::assertSame( array( 'default', 'back' ), array_column( $task['placements'] ?? array(), 'placement' ) );
		self::assertSame( array( 'cut-sew', 'cut-sew' ), array_column( $task['placements'] ?? array(), 'technique' ) );
		self::assertSame( array( 15710, 15711 ), $task['mockup_style_ids'] ?? null );
		self::assertSame( array( array( 'name' => 'stitch_color', 'value' => 'white' ) ), $task['product_options'] ?? null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 257, $task['catalog_product_id'] ?? null );
		self::assertEqualsWithDelta( 2.008, $task['placements'][0]['layers'][0]['position']['width'] ?? 0, 0.001, 'Inches, in the print file\'s own shape.' );
		self::assertStringContainsString( '/designer-print/' . $version . '/front-print', urldecode( (string) ( $task['placements'][0]['layers'][0]['url'] ?? '' ) ) );

		$files = SubmissionFiles::decode( (string) ( $this->products->version( $version )['files'] ?? '' ) );

		self::assertSame( PrintfulMockups::READY, $this->products->version( $version )['mockup_state'] ?? null );
		self::assertSame( 'image/png', $files['mockups']['front-print']['standard']['mime'] ?? null );
		self::assertSame( array( 300, 300 ), array( $files['mockups']['front-print']['standard']['width'] ?? 0, $files['mockups']['front-print']['standard']['height'] ?? 0 ), 'The front style\'s photo.' );
		self::assertSame( array( 320, 320 ), array( $files['mockups']['back-print']['standard']['width'] ?? 0, $files['mockups']['back-print']['standard']['height'] ?? 0 ), 'The back style\'s photo, though v2 names it after the first placement.' );
	}

	public function test_a_refused_v2_task_goes_to_v1_with_the_same_files(): void {
		[ $version ] = $this->submit_flat();
		$mockups     = $this->mockups();

		$this->answers[ self::API . '/v2/mockup-tasks' ]                    = self::answer( array( 'data' => 'Refused' ), 400 );
		$this->answers[ self::API . '/mockup-generator/create-task/257' ]   = self::answer( array( 'result' => array( 'task_key' => 'tk1' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->answers[ self::API . '/mockup-generator/task?task_key=tk1' ] = self::answer(
			array(
				'result' => array(
					'status'  => 'completed',
					'mockups' => array(
						array( 'placement' => 'back', 'variant_ids' => array( 501 ), 'mockup_url' => 'https://cdn.example/back.png' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				),
			)
		);

		$mockups->submitted( $version );
		$this->drain( $mockups );

		$body = json_decode( (string) ( $this->call( 'POST', self::API . '/mockup-generator/create-task/257' )[2]['body'] ?? '' ), true );

		self::assertSame( array( 'default', 'back' ), array_column( $body['files'] ?? array(), 'placement' ) );
		self::assertSame( array( 'stitch_color' => 'white' ), $body['product_options'] ?? null );
		self::assertSame( PrintfulMockups::READY, $this->products->version( $version )['mockup_state'] ?? null );

		$files = SubmissionFiles::decode( (string) ( $this->products->version( $version )['files'] ?? '' ) );

		self::assertSame( 320, $files['mockups']['back-print']['standard']['width'] ?? 0 );
		self::assertSame( 40, $files['mockups']['front-print']['standard']['width'] ?? 0, 'A side without a photo keeps the browser\'s image.' );
	}

	public function test_a_429_on_a_task_waits_and_never_goes_to_v1(): void {
		[ $version ] = $this->submit_flat();
		$mockups     = $this->mockups();

		$this->answers[ self::API . '/v2/mockup-tasks' ] = self::answer( array(), 429, array( 'retry-after' => '60' ) );

		$mockups->submitted( $version );
		$this->queue->drain(
			static function ( string $hook, array $args ) use ( $mockups ): void {
				$mockups->run( $args[0] ?? 0 );
			},
			1
		);

		self::assertNull( $this->call( 'POST', self::API . '/mockup-generator/create-task/257' ) );
		self::assertSame( PrintfulMockups::RUNNING, $this->products->version( $version )['mockup_state'] ?? null );
	}

	public function test_a_flat_side_s_jpeg_print_file_is_served_as_a_jpeg(): void {
		[ $version ] = $this->submit_flat();

		parse_str( (string) wp_parse_url( SignedPrintFiles::url( $version, 'front-print' ), PHP_URL_QUERY ), $query );

		$request = new WP_REST_Request( 'GET', '/merchboard/v1/designer-print/' . $version . '/front-print' );

		$request->set_url_params(
			array(
				'version' => (string) $version,
				'side'    => 'front-print',
			)
		);
		$request->set_query_params( $query );

		$response = ( new SignedPrintFiles( $this->products, $this->files ) )->serve( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'image/jpeg', $response->get_headers()['Content-Type'] ?? null );
	}

	public function test_gelato_descriptions_keep_their_words_apart(): void {
		self::assertSame( 'General To maintain', PrintfulCatalog::description( '<h3>General</h3><p>To maintain</p>' ) );
		self::assertSame( GelatoCatalog::STANDARD_COLOR, array_column( ( new PrintfulCatalog( $this->api ) )->choice( '84' )['colors'], 'name' )[0] ?? null, 'A variant without a colour.' );
	}

	/**
	 * Saving a flat side again keeps it flat.
	 *
	 * @param int $id Base product.
	 */
	private function assert_stays_flat( int $id ): void {
		$base = $this->bases->find( $id );

		self::assertNotNull( $base );

		$this->base_service()->save(
			$id,
			array(
				'name'             => $base->name,
				'description'      => '',
				'base_price'       => '100',
				'extra_side_price' => '0',
				'category_id'      => '0',
				'colors'           => 'Standard #ffffff',
				'sizes'            => 'S, M',
			),
			array_map(
				static fn( $view ): array => array(
					'key'       => $view->key,
					'name'      => $view->name,
					'x'         => (string) $view->area['x'],
					'y'         => (string) $view->area['y'],
					'width'     => (string) $view->area['width'],
					'height'    => (string) $view->area['height'],
					'width_mm'  => (string) $view->width_mm,
					'height_mm' => (string) $view->height_mm,
					'dpi'       => (string) $view->dpi,
				),
				$base->views
			)
		);

		self::assertTrue( $this->bases->find( $id )->views[0]->flat ?? null );
	}

	/**
	 * The base product service.
	 */
	private function base_service(): BaseProductService {
		return new BaseProductService( $this->bases, new DesignerAssetRepository( $this->wpdb ), $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );
	}

	/**
	 * Imports the all-over tee with both its parts.
	 */
	private function import_flat(): int {
		$catalog = new CatalogImport( $this->wpdb, $this->bases, $this->base_service(), $this->files, $this->queue, $this->service( Logger::class ), 2, 'https://shop.example/assets/', new PrintfulCatalog( $this->api ) );
		$id      = $catalog->create(
			'printful',
			'257',
			array(
				'colors'           => array( 'Standard' ),
				'sizes'            => array( 'S', 'M' ),
				'sides'            => array( 'default', 'back' ),
				'base_price'       => '199',
				'extra_side_price' => '0',
			)
		);

		$this->queue->drain(
			static function ( string $hook, array $args ) use ( $catalog ): void {
				$catalog->run( $args[0] ?? 0 );
			},
			20
		);

		return $id;
	}

	/**
	 * An active all-over base product with a design on both parts, submitted.
	 *
	 * @return array{0: int} The version.
	 */
	private function submit_flat(): array {
		$base = $this->import_flat();

		$this->base_service()->set_active( $base, true );

		$user        = $this->create_user( 'flat-creator' );
		$creator     = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $user );
		$drafts      = new DesignerDrafts( $this->products, $this->bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, $this->files );
		$submissions = new DesignerSubmissions( $this->products, $this->bases, $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2 );
		$product     = $drafts->create( $creator, $base );
		$rect        = array(
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
		);

		$drafts->save(
			$creator,
			$product,
			array(
				'title'        => 'All over',
				'description'  => '',
				'colors'       => array( 'standard' ),
				'sizes'        => array( 'S', 'M' ),
				'profit_kind'  => 'percent',
				'profit_value' => '20',
				'design'       => array(
					'version' => 1,
					'sides'   => array(
						'front-print' => $rect,
						'back-print'  => $rect,
					),
				),
			),
			new DesignValidator( static fn(): bool => false, array() )
		);

		foreach ( $this->bases->find( $base )->views ?? array() as $view ) {
			[ $width, $height ] = $view->print_pixels();

			$submissions->attach( $creator, $product, 'print:' . $view->key, AlertImagesTest::upload( self::jpeg( $width, $height ), 'print.jpg' ) );
			$submissions->attach( $creator, $product, 'mockup:' . $view->key . ':standard', AlertImagesTest::upload( AlertImagesTest::png( 40, 40 ), 'mockup.png' ) );
		}

		$submissions->submit( $creator, $product );

		return array( (int) ( $this->products->pending( $product )['id'] ?? 0 ) );
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
	 * Every call of a method.
	 *
	 * @param string $method Method.
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function calls_starting( string $method ): array {
		return array_values( array_filter( $this->calls, static fn( array $call ): bool => $method === $call[0] ) );
	}

	/**
	 * A stock row.
	 *
	 * @param int    $variant      Variant.
	 * @param string $technique    Technique.
	 * @param string $availability Availability.
	 * @return array<string, mixed>
	 */
	private static function stock( int $variant, string $technique, string $availability = 'in stock' ): array {
		return array(
			'catalog_variant_id' => $variant,
			'techniques'         => array(
				array(
					'technique'       => $technique,
					'selling_regions' => array(
						array(
							'name'         => 'europe',
							'availability' => $availability,
						),
					),
				),
			),
		);
	}

	/**
	 * A variant of the all-over tee, 2 × 3 inches on its parts by default.
	 *
	 * @param int    $id     Variant.
	 * @param string $size   Size.
	 * @param int    $width  Print width in inches.
	 * @param int    $height Print height in inches.
	 * @return array<string, mixed>
	 */
	private static function flat_variant( int $id, string $size, int $width = 2, int $height = 3 ): array {
		return array(
			'id'                   => $id,
			'size'                 => $size,
			'color'                => '',
			'color_code'           => null,
			'placement_dimensions' => array(
				array( 'placement' => 'default', 'width' => $width, 'height' => $height ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'placement' => 'back', 'width' => $width, 'height' => $height ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
		);
	}

	/**
	 * Product 71: a printed tee.
	 *
	 * @return array<string, mixed>
	 */
	private function regular_answers(): array {
		$dimensions = array( array( 'placement' => 'front', 'width' => 12, 'height' => 16 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		return array(
			self::API . '/v2/catalog-products/71?' . self::REGION => self::answer(
				array(
					'data' => array(
						'name'        => 'Staple tee',
						'description' => '<p>Soft cotton</p>, sewn tight.',
						'image'       => 'https://files.cdn.printful.com/71.jpg',
						'techniques'  => array(
							array(
								'key'        => 'dtg',
								'is_default' => true,
							),
						),
					),
				)
			),
			self::API . '/v2/catalog-products/71/catalog-variants?' . self::PAGE => self::answer(
				array(
					'data'   => array(
						array( 'id' => 103, 'size' => '2XL', 'color' => 'Black', 'color_code' => '#111111', 'placement_dimensions' => $dimensions ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'id' => 102, 'size' => 'M', 'color' => 'Black', 'color_code' => '#111111', 'placement_dimensions' => $dimensions ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'id' => 101, 'size' => 'S', 'color' => 'Black', 'color_code' => '#111111', 'placement_dimensions' => $dimensions ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'id' => 201, 'size' => 'S', 'color' => 'White', 'color_code' => '#ffffff', 'placement_dimensions' => $dimensions ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
					'paging' => array( 'total' => 4 ),
				)
			),
			self::API . '/v2/catalog-products/71/availability?' . self::REGION . '&' . self::PAGE => self::answer(
				array(
					'data'   => array( self::stock( 101, 'dtg' ), self::stock( 102, 'dtg' ), self::stock( 103, 'dtg' ), self::stock( 201, 'dtg', 'not fulfillable' ) ),
					'paging' => array( 'total' => 4 ),
				)
			),
			self::API . '/v2/catalog-products/71/mockup-styles?' . self::REGION . '&' . self::PAGE => self::answer(
				array(
					'data'   => array(
						array( 'placement' => 'front', 'display_name' => 'Front print', 'technique' => 'dtg', 'print_area_width' => 12, 'print_area_height' => 16, 'print_area_type' => 'simple', 'dpi' => 150, 'mockup_styles' => array() ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'placement' => 'front', 'display_name' => 'Front print', 'technique' => 'dtfilm', 'print_area_width' => 12, 'print_area_height' => 16, 'print_area_type' => 'simple', 'dpi' => 150, 'mockup_styles' => array() ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'placement' => 'mockup', 'display_name' => 'Mockup', 'technique' => 'dtg', 'print_area_width' => 12, 'print_area_height' => 16, 'dpi' => 150 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
					'paging' => array( 'total' => 3 ),
				)
			),
		);
	}

	/**
	 * Product 257: an all-over tee of two parts on 2 × 3 inch files, and the
	 * same with a white pillow's empty colour under 84.
	 *
	 * @return array<string, mixed>
	 */
	private function flat_answers(): array {
		$answers = array();
		$rows    = array(
			'product'   => array(
				'data' => array(
					'name'            => 'All-over tee',
					'techniques'      => array(
						array(
							'key'        => 'cut-sew',
							'is_default' => true,
						),
					),
					'product_options' => array(
						array(
							'name'   => 'stitch_color',
							'values' => array(
								'white' => 'White',
								'black' => 'Black',
							),
						),
					),
				),
			),
			'variants'  => array(
				'data'   => array( self::flat_variant( 501, 'S' ), self::flat_variant( 502, 'M' ) ),
				'paging' => array( 'total' => 2 ),
			),
			'stock'     => array(
				'data'   => array( self::stock( 501, 'cut-sew' ), self::stock( 502, 'cut-sew' ) ),
				'paging' => array( 'total' => 2 ),
			),
			'styles'    => array(
				'data'   => array(
					array(
						'placement'         => 'default',
						'display_name'      => 'Front print',
						'technique'         => 'cut-sew',
						'print_area_width'  => 2,
						'print_area_height' => 3,
						'print_area_type'   => 'simple',
						'dpi'               => 100,
						'mockup_styles'     => array(
							array( 'id' => 900, 'category_name' => 'Halloween', 'view_name' => 'Front' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'id' => 15710, 'category_name' => 'Men\'s', 'view_name' => 'Front' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'id' => 15711, 'category_name' => 'Men\'s', 'view_name' => 'Back' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						),
					),
					array(
						'placement'         => 'back',
						'display_name'      => 'Back print',
						'technique'         => 'cut-sew',
						'print_area_width'  => 2,
						'print_area_height' => 3,
						'print_area_type'   => 'simple',
						'dpi'               => 100,
						'mockup_styles'     => array(
							array( 'id' => 15710, 'category_name' => 'Men\'s', 'view_name' => 'Front' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
							array( 'id' => 15711, 'category_name' => 'Men\'s', 'view_name' => 'Back' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						),
					),
				),
				'paging' => array( 'total' => 2 ),
			),
			'templates' => array(
				'data'   => array(
					array( 'placement' => 'default', 'role' => 'primary', 'catalog_variant_ids' => array( 501, 502 ), 'image_url' => 'https://cdn.example/photo.png', 'template_width' => 3000, 'template_height' => 3000, 'print_area_width' => 3000, 'print_area_height' => 3000, 'print_area_left' => 0, 'print_area_top' => 0 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'placement' => 'default', 'role' => 'template', 'catalog_variant_ids' => array( 501, 502, 503 ), 'image_url' => 'https://cdn.example/front.png', 'template_width' => 3000, 'template_height' => 3000, 'print_area_width' => 2000, 'print_area_height' => 3000, 'print_area_left' => 600, 'print_area_top' => 0 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'placement' => 'back', 'role' => 'template', 'catalog_variant_ids' => array( 501, 502, 503 ), 'image_url' => 'https://cdn.example/back-part.png', 'template_width' => 3000, 'template_height' => 3000, 'print_area_width' => 2000, 'print_area_height' => 3000, 'print_area_left' => 600, 'print_area_top' => 0 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
				'paging' => array( 'total' => 3 ),
			),
		);

		foreach ( array( 257, 84 ) as $id ) {
			$path                                  = self::API . '/v2/catalog-products/' . $id;
			$answers[ $path . '?' . self::REGION ] = self::answer( $rows['product'] );
			$answers[ $path . '/catalog-variants?' . self::PAGE ]                      = self::answer( $rows['variants'] );
			$answers[ $path . '/availability?' . self::REGION . '&' . self::PAGE ]     = self::answer( $rows['stock'] );
			$answers[ $path . '/mockup-styles?' . self::REGION . '&' . self::PAGE ]    = self::answer( $rows['styles'] );
			$answers[ $path . '/mockup-templates?' . self::REGION . '&' . self::PAGE ] = self::answer( $rows['templates'] );
		}

		$answers['https://cdn.example/front.png']        = self::file( AlertImagesTest::png( 40, 40 ) );
		$answers['https://cdn.example/back-part.png']    = self::file( AlertImagesTest::png( 40, 40 ) );
		$answers['https://cdn.example/style-front.png']  = self::file( AlertImagesTest::png( 300, 300 ) );
		$answers['https://cdn.example/back.png']         = self::file( AlertImagesTest::png( 320, 320 ) );
		$answers[ self::API . '/v2/mockup-tasks' ]       = self::answer( array( 'data' => array( array( 'id' => 77, 'status' => 'pending' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$answers[ self::API . '/v2/mockup-tasks?id=77' ] = self::answer(
			array(
				'data' => array(
					array(
						'id'                      => 77,
						'status'                  => 'completed',
						'failure_reasons'         => array(),
						'catalog_variant_mockups' => array(
							array(
								'catalog_variant_id' => 501,
								'mockups'            => array(
									array( 'placement' => 'default', 'style_id' => 15710, 'mockup_url' => 'https://cdn.example/style-front.png' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
									array( 'placement' => 'default', 'style_id' => 15711, 'mockup_url' => 'https://cdn.example/back.png' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
								),
							),
						),
					),
				),
			)
		);

		return $answers;
	}

	/**
	 * A JPEG.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	private static function jpeg( int $width, int $height ): string {
		$image = imagecreatetruecolor( max( 1, $width ), max( 1, $height ) );

		self::assertNotFalse( $image );
		ob_start();
		imagejpeg( $image );

		return (string) ob_get_clean();
	}

	/**
	 * A JSON answer.
	 *
	 * @param array<string, mixed>  $body    Body.
	 * @param int                   $status  Status.
	 * @param array<string, string> $headers Headers.
	 * @return array<string, mixed>
	 */
	private static function answer( array $body, int $status = 200, array $headers = array() ): array {
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
