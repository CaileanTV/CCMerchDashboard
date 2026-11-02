<?php
/**
 * Printify as a catalog, with a fake HTTP whose answers are cut from Printify's
 * real ones of 2026-11-01 (ADR-087).
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
use MerchBoard\Modules\ProductDesigner\Admin\PrintifyThumbnails;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Providers\CatalogImport;
use MerchBoard\Modules\ProductDesigner\Providers\PrintifyAccount;
use MerchBoard\Modules\ProductDesigner\Providers\PrintifyApi;
use MerchBoard\Modules\ProductDesigner\Providers\PrintifyCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\PrintifyException;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_Error;

/**
 * The token is encrypted and nothing is called while Printify is off; only
 * reading calls are made; the whole catalog is offered, the EU's producers
 * first; a side holds the smallest print size, on a white sheet of the size of
 * the company's photos, and is not a flat part. Photos then fit it.
 */
#[CoversClass( PrintifyAccount::class )]
#[CoversClass( PrintifyApi::class )]
#[CoversClass( PrintifyCatalog::class )]
#[CoversClass( PrintifyThumbnails::class )]
final class PrintifyTest extends IntegrationTestCase {

	private const API = 'https://api.printify.com/v1';

	private const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.abc-def_123';

	/**
	 * Calls made: [method, url, args].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $calls = array();

	/**
	 * Answers by URL.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $answers = array();

	private PrintifyAccount $account;

	private PrintifyApi $api;

	private DesignerFiles $files;

	private BaseProductRepository $bases;

	private FakeJobQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		DesignerFiles::remove_all();
		wp_set_current_user( 1 );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => true, Settings::PRINTIFY_ENABLED => true ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		delete_transient( PrintifyCatalog::BLUEPRINTS );
		delete_transient( PrintifyCatalog::PRODUCERS );
		delete_transient( PrintifyCatalog::PRODUCER_PRODUCTS . '26' );
		delete_transient( PrintifyCatalog::PRODUCER_PRODUCTS . '29' );

		$this->account = new PrintifyAccount( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );

		$this->account->set( self::TOKEN );

		$this->api     = new PrintifyApi(
			$this->account,
			function ( string $method, string $url, array $args ): array|WP_Error {
				$this->calls[] = array( $method, $url, $args );

				return $this->answers[ $url ] ?? new WP_Error( 'http_request_failed', 'No route.' );
			}
		);
		$this->files   = new DesignerFiles( static fn( string $from, string $to ): bool => rename( $from, $to ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test files.
		$this->bases   = new BaseProductRepository( $this->wpdb );
		$this->queue   = new FakeJobQueue();
		$this->answers = self::printify_answers();
	}

	protected function tearDown(): void {
		delete_option( PrintifyAccount::TOKEN_OPTION );
		delete_transient( PrintifyCatalog::BLUEPRINTS );
		delete_transient( PrintifyCatalog::PRODUCERS );
		delete_transient( PrintifyCatalog::PRODUCER_PRODUCTS . '26' );
		delete_transient( PrintifyCatalog::PRODUCER_PRODUCTS . '29' );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => false, Settings::PRINTIFY_ENABLED => false ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		DesignerFiles::remove_all();
		parent::tearDown();
	}

	public function test_the_token_is_encrypted_never_audited_and_must_look_like_one(): void {
		foreach ( array( 'short', 'with spaces in it but long enough' ) as $invalid ) {
			try {
				$this->account->set( $invalid );
				self::fail( 'Invalid token.' );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::PRINTIFY_KEY, $e->reason() );
			}
		}

		self::assertSame( self::TOKEN, $this->account->token() );
		self::assertStringNotContainsString( self::TOKEN, (string) get_option( PrintifyAccount::TOKEN_OPTION ) );
		self::assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );
		self::assertFalse( $this->account->set( '' ), 'An empty field keeps the token.' );

		$this->account->clear();

		self::assertFalse( $this->account->configured() );
	}

	public function test_nothing_is_called_while_printify_is_off(): void {
		Settings::save( array_merge( Settings::all(), array( Settings::PRINTIFY_ENABLED => false ) ) );

		try {
			( new PrintifyCatalog( $this->api ) )->browse( 'hoodie' );
			self::fail( 'Off.' );
		} catch ( PrintifyException $e ) {
			self::assertSame( PrintifyException::NOT_CONFIGURED, $e->reason() );
		}

		self::assertSame( array(), $this->calls );
	}

	public function test_calls_only_read_and_carry_the_token_and_a_429_waits(): void {
		$catalog = new PrintifyCatalog( $this->api );

		$catalog->browse( 'hoodie', '', true );
		$catalog->producers( 77 );
		$catalog->choice( '77-26' );

		self::assertSame( array( 'GET' ), array_values( array_unique( array_column( $this->calls, 0 ) ) ) );
		self::assertSame( 'Bearer ' . self::TOKEN, $this->calls[0][2]['headers']['Authorization'] );
		self::assertSame( 'MerchDashboard', $this->calls[0][2]['user-agent'], 'Printify requires a user agent.' );

		foreach ( $this->calls as $call ) {
			self::assertStringStartsWith( self::API . '/catalog/', $call[1] );
		}

		$this->answers[ self::API . '/catalog/blueprints/77.json' ] = self::answer( array( 'message' => 'Slow down' ), 429, array( 'retry-after' => '7' ) );

		try {
			$catalog->choice( '77-26' );
			self::fail( 'Rate limited.' );
		} catch ( PrintifyException $e ) {
			self::assertSame( array( PrintifyException::RATE_LIMITED, 7 ), array( $e->reason(), $e->retry_after ) );
			self::assertStringNotContainsString( self::TOKEN, $e->getMessage() . $e->detail . $e->call );
		}
	}

	public function test_the_whole_catalog_is_browsed_by_words_group_eu_and_producer(): void {
		$catalog = new PrintifyCatalog( $this->api );
		$ids     = static fn( array $found ): array => array_column( $found['products'], 'id' );

		self::assertSame( array( 5, 77, 443 ), $ids( $catalog->browse( '' ) ), 'Everything shows at once.' );
		self::assertSame( array( 77 ), $ids( $catalog->browse( 'gildan HOODED' ) ) );
		self::assertSame( array( 77 ), $ids( $catalog->browse( '', 'hoodies' ) ) );
		self::assertSame( array( 5 ), $ids( $catalog->browse( '', 'tshirts' ) ) );
		self::assertSame( array( 'tshirts', 'hoodies', 'wall', 'other' ), array_map( array( PrintifyCatalog::class, 'group_of' ), array( 'Unisex Cotton Crew Tee', 'Unisex Heavy Blend™ Hooded Sweatshirt', 'Posters (EU)', 'Golf Balls' ) ) );
		self::assertSame( array( 77 ), $ids( $catalog->browse( '', '', true ) ), 'Only what the producer in Germany makes.' );
		self::assertSame( array( 5, 77 ), $ids( $catalog->browse( '', '', false, 1, 29 ) ), 'Only what the producer in the US makes.' );
		self::assertSame( array( 'Textildruck Europa' ), array_column( $catalog->all_producers( true ), 'title' ) );
		self::assertSame( array( 'Textildruck Europa', 'Monster Digital' ), array_column( $catalog->all_producers(), 'title' ) );

		$all = $catalog->browse( '' );

		self::assertSame( array( array( 'Monster Digital' ), array( 'Textildruck Europa', 'Monster Digital' ), array() ), array_map( static fn( array $product ): array => array_column( $product['producers'], 'title' ), $all['products'] ), 'Each card names its producers, the EU first.' );
		self::assertTrue( $all['complete'] );

		$page = $catalog->browse( '', '', false, 9 );

		self::assertSame( array( 3, 1, 1 ), array( $page['total'], $page['page'], $page['pages'] ), 'A page past the end shows the last one.' );

		$producers = $catalog->producers( 77 );

		self::assertSame( array( 'Textildruck Europa', 'Monster Digital' ), array_column( $producers, 'title' ) );
		self::assertSame( array( 'DE', 'US' ), array_column( $producers, 'country' ) );

		self::assertSame( array( 'Textildruck Europa' ), array_column( $catalog->producers( 77, true ), 'title' ) );

		// Nothing is read again: the list is 5.5 MB, and the producers' products are kept too.
		$calls = count( $this->calls );

		$catalog->browse( 'tee', '', true );
		$catalog->browse( '', '', false, 1, 29 );
		$catalog->producers( 77 );

		self::assertSame( $calls + 1, count( $this->calls ), 'Only the producers of the product are asked again.' );
	}

	public function test_producers_a_page_has_no_time_for_are_read_in_the_background(): void {
		$api     = $this->api;
		$catalog = new PrintifyCatalog( $api, $this->files, $this->queue, 0.0 );
		$first   = $catalog->browse( '' );

		self::assertFalse( $first['complete'] );
		self::assertSame( array( array(), array(), array() ), array_column( $first['products'], 'producers' ), 'The page does not wait.' );
		self::assertSame( array( PrintifyCatalog::HOOK ), $this->queue->pending_hooks() );

		$catalog->browse( '' );

		self::assertCount( 1, $this->queue->pending_hooks(), 'Queued once.' );

		$this->queue->drain(
			static function ( string $hook ) use ( $api ): void {
				unset( $hook );
				( new PrintifyCatalog( $api, null, null, 60.0 ) )->read_producers();
			}
		);

		$after = $catalog->browse( '' );

		self::assertTrue( $after['complete'] );
		self::assertSame( array( 'Textildruck Europa (DE)', 'Monster Digital (US)' ), array_map( static fn( array $producer ): string => $producer['title'] . ' (' . $producer['country'] . ')', $after['products'][1]['producers'] ) );
	}

	public function test_a_small_picture_is_made_once_without_the_token_and_only_for_the_administrator(): void {
		$catalog    = new PrintifyCatalog( $this->api, $this->files );
		$thumbnails = new PrintifyThumbnails( $catalog );
		$input      = array(
			'product'  => '77',
			'_wpnonce' => wp_create_nonce( PrintifyThumbnails::ACTION ),
		);
		$response   = $thumbnails->response( $input );
		$size       = getimagesizefromstring( $response['body'] );

		self::assertSame( array( 200, 'image/jpeg' ), array( $response['status'], $response['headers']['Content-Type'] ) );
		self::assertSame( array( PrintifyCatalog::THUMB, PrintifyCatalog::THUMB ), is_array( $size ) ? array( $size[0], $size[1] ) : array() );

		$picture = array_values( array_filter( $this->calls, static fn( array $call ): bool => str_starts_with( $call[1], 'https://images.printify.com/' ) ) );

		self::assertCount( 1, $picture );
		self::assertArrayNotHasKey( 'headers', $picture[0][2], 'The picture host never gets the token.' );

		$thumbnails->response( $input );

		self::assertCount( 1, array_filter( $this->calls, static fn( array $call ): bool => str_starts_with( $call[1], 'https://images.printify.com/' ) ), 'It is kept.' );
		self::assertSame( 403, $thumbnails->response( array_merge( $input, array( '_wpnonce' => 'wrong' ) ) )['status'] );
		self::assertSame( 404, $thumbnails->response( array_merge( $input, array( 'product' => '443' ) ) )['status'], 'A product without a picture.' );
		self::assertStringContainsString( PrintifyException::NOT_FOUND, $thumbnails->response( array_merge( $input, array( 'product' => '443' ) ) )['body'], 'The administrator sees why.' );

		// A list kept by the version before pictures is read again.
		delete_transient( PrintifyCatalog::BLUEPRINTS );
		set_transient(
			PrintifyCatalog::BLUEPRINTS,
			array(
				array(
					'id'    => 77,
					'title' => 'Old',
					'brand' => 'Gildan',
				),
			),
			HOUR_IN_SECONDS
		);
		$this->files->delete( md5( 'printify-thumb|' . PrintifyCatalog::THUMB . '|https://images.printify.com/66c32f6ebb0d2981ff0aa7f3' ) . '.jpg' );

		self::assertSame( 200, $thumbnails->response( $input )['status'] );

		wp_set_current_user( 0 );

		self::assertSame( 403, $thumbnails->response( $input )['status'] );
	}

	public function test_a_side_holds_the_smallest_print_size_and_every_size_is_kept(): void {
		$choice = ( new PrintifyCatalog( $this->api ) )->choice( '77-26' );

		self::assertSame( 'Unisex Heavy Blend™ Hooded Sweatshirt', $choice['name'] );
		self::assertSame( array( 'Ash', 'Black' ), array_column( $choice['colors'], 'name' ) );
		self::assertSame( array( '#ffffff' ), array_values( array_unique( array_column( $choice['colors'], 'hex' ) ) ) );
		self::assertSame( array( 'S', 'M', 'L' ), $choice['sizes'] );
		self::assertSame( array( 'front', 'back', 'neck' ), array_column( $choice['sides'], 'placement' ) );

		$front = $choice['sides'][0];

		// 3531 × 2908 px at 300 dpi.
		self::assertSame( array( 299, 246, 300, 'dtg' ), array( $front['width_mm'], $front['height_mm'], $front['dpi'], $front['technique'] ) );
		self::assertSame( array( 1000, 1000 ), array( $front['template_width'], $front['template_height'] ) );
		self::assertSame( '32870', $choice['variants']['Ash']['S'] );
		self::assertTrue( $choice['printed'] );
		self::assertArrayNotHasKey( 'groups', $choice, 'One base product covers every size.' );
	}

	public function test_sizes_of_another_shape_get_their_own_sides_and_inches_become_centimetres(): void {
		$poster = static fn( int $id, string $size, int $width, int $height ): array => array(
			'id'           => $id,
			'options'      => array( 'size' => $size ),
			'placeholders' => array(
				array(
					'position'          => 'front',
					'decoration_method' => 'digital-printing',
					'width'             => $width,
					'height'            => $height,
				),
			),
		);

		$this->answers[ self::API . '/catalog/blueprints/443.json' ]                             = self::answer( array( 'title' => 'Posters (in)' ) );
		$this->answers[ self::API . '/catalog/blueprints/443/print_providers/26/variants.json' ] = self::answer(
			array(
				'variants' => array(
					$poster( 1, '11.7" x 16.5"', 3510, 4950 ),
					$poster( 2, '16.5" x 23.4"', 4950, 7020 ),
					$poster( 3, '16.5" x 11.7"', 4950, 3510 ),
				),
			)
		);

		$catalog = new PrintifyCatalog( $this->api );
		$choice  = $catalog->choice( '443-26' );

		self::assertSame( array( array( '11.7" x 16.5"', '16.5" x 23.4"' ), array( '16.5" x 11.7"' ) ), array_column( $choice['sides'], 'sizes' ), 'Upright and lying are two sides.' );
		self::assertSame( array( 297, 419 ), array( $choice['sides'][0]['width_mm'], $choice['sides'][0]['height_mm'] ), 'The smallest of a shape fits all its sizes.' );
		self::assertArrayNotHasKey( 'sizes', ( new PrintifyCatalog( $this->api ) )->choice( '77-26' )['sides'][0], 'A garment\'s sizes share one shape.' );

		$id   = $this->import( '443-26', array( '30 × 42 cm', '42 × 30 cm' ), array( 'front' ), '', array( 'Standard' ) );
		$base = $this->bases->find( $id );

		self::assertNotNull( $base );
		self::assertSame( 'Posters (cm)', $base->name );
		self::assertSame( array( '30 × 42 cm', '42 × 30 cm' ), $base->sizes );
		self::assertSame( array( array( '30 × 42 cm' ), array( '42 × 30 cm' ) ), array_map( static fn( $view ): array => $view->sizes, $base->views ) );
		self::assertSame( '30 × 42 cm', array_key_first( $this->bases->provider( $id )['variants']['standard'] ?? array() ) );

		$inches = $this->bases->find( $this->import( '443-26', array( '11.7" x 16.5"' ), array( 'front' ), 'in', array( 'Standard' ) ) );

		self::assertSame( array( '11.7" x 16.5"' ), $inches->sizes ?? null, 'Inches when they are asked for.' );
	}

	public function test_a_product_without_colours_uses_its_other_option_and_a_large_print_stays_within_the_limit(): void {
		$url = self::API . '/catalog/blueprints/443/print_providers/26/variants.json';

		$this->answers[ self::API . '/catalog/blueprints/443.json' ] = self::answer( array( 'title' => 'Posters (EU)' ) );
		$this->answers[ $url ]                                       = self::answer(
			array(
				'variants' => array(
					array(
						'id'           => 62338,
						'options'      => array(
							'size'  => '27.5" x 39.4"',
							'paper' => 'Glossy',
						),
						'placeholders' => array(
							array(
								'position'          => 'front',
								'decoration_method' => 'digital-printing',
								'width'             => 7020,
								'height'            => 9930,
							),
						),
					),
				),
			)
		);

		$choice = ( new PrintifyCatalog( $this->api ) )->choice( '443-26' );

		self::assertSame( array( 'Glossy' ), array_column( $choice['colors'], 'name' ) );
		self::assertLessThanOrEqual( DesignerFiles::MAX_PIXELS, $choice['sides'][0]['height'] );
	}

	public function test_a_bad_reference_or_a_product_without_print_areas_is_refused(): void {
		$catalog = new PrintifyCatalog( $this->api );

		$this->answers[ self::API . '/catalog/blueprints/77/print_providers/30/variants.json' ] = self::answer( array( 'variants' => array() ) );

		foreach ( array( '77', '77-26/../orders', '77-30' ) as $ref ) {
			try {
				$catalog->choice( $ref );
				self::fail( 'Refused: ' . $ref );
			} catch ( PrintifyException $e ) {
				self::assertSame( PrintifyException::INVALID, $e->reason() );
			}
		}
	}

	public function test_an_import_gives_sheets_that_take_the_company_s_photos_and_is_not_flat(): void {
		$id   = $this->import();
		$base = $this->bases->find( $id );

		self::assertNotNull( $base );
		self::assertSame( array( 'printify', 'draft' ), array( $base->source, $base->status ) );
		self::assertSame( array( 'Ash', 'Black' ), array_column( $base->colors, 'name' ) );
		self::assertSame( array( 'S', 'M', 'L' ), $base->sizes );
		self::assertSame( array( 'Front', 'Back' ), array_map( static fn( $view ): string => $view->name, $base->views ) );

		$view = $base->views[0];

		self::assertSame( array( 1000, 1000, false, 299, 246 ), array( $view->width, $view->height, $view->flat, $view->width_mm, $view->height_mm ) );
		self::assertSame( array( 100 ), array( $view->area['x'] ), 'The area fills the sheet\'s width until it is drawn.' );
		self::assertFalse( $this->bases->provider( $id )['flat'] ?? true, 'A design prints with transparency.' );

		$this->base_service()->add_color_photos( $id, 'front', array( 'Black' => AlertImagesTest::upload( AlertImagesTest::png( 1000, 1000 ), 'Black.png' ) ) );

		self::assertSame( array( 'black' ), array_keys( $this->bases->find( $id )?->views[0]->color_backgrounds() ?? array() ) );
	}

	/**
	 * The base product service as the import uses it.
	 */
	private function base_service(): BaseProductService {
		return new BaseProductService( $this->bases, new DesignerAssetRepository( $this->wpdb ), $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );
	}

	/**
	 * Imports a product, by default the hoodie with its front and back, running the job at once.
	 *
	 * @param string        $ref    Product and producer.
	 * @param array<string> $sizes  Sizes.
	 * @param array<string> $sides  Placements.
	 * @param string        $units  'in' for inches.
	 * @param array<string> $colors Colours.
	 * @return int The base product.
	 */
	private function import( string $ref = '77-26', array $sizes = array( 'S', 'M', 'L' ), array $sides = array( 'front', 'back' ), string $units = '', array $colors = array( 'Ash', 'Black' ) ): int {
		$catalog = new CatalogImport( $this->wpdb, $this->bases, $this->base_service(), $this->files, $this->queue, $this->service( Logger::class ), 2, 'https://shop.example/assets/', null, null, null, new PrintifyCatalog( $this->api ) );
		$id      = $catalog->create(
			PrintifyCatalog::SOURCE,
			$ref,
			array(
				'colors'     => $colors,
				'sizes'      => $sizes,
				'sides'      => $sides,
				'base_price' => '200',
				'units'      => $units,
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
	 * Printify's answers, cut from the real ones of 2026-11-01.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function printify_answers(): array {
		$place   = static fn( string $position, string $method, int $width, int $height ): array => array(
			'position'          => $position,
			'decoration_method' => $method,
			'width'             => $width,
			'height'            => $height,
		);
		$variant = static fn( int $id, string $color, string $size, int $width, int $height, int $back_width, int $back_height ): array => array(
			'id'                 => $id,
			'title'              => $color . ' / ' . $size,
			'options'            => array(
				'color' => $color,
				'size'  => $size,
			),
			'placeholders'       => array( $place( 'front', 'dtg', $width, $height ), $place( 'back', 'dtg', $back_width, $back_height ), $place( 'neck', 'dtf', 750, 750 ) ),
			'decoration_methods' => array( 'dtg' ),
		);

		return array(
			self::API . '/catalog/blueprints.json'         => self::answer(
				array(
					array(
						'id'          => 5,
						'title'       => 'Unisex Cotton Crew Tee',
						'brand'       => 'Next Level',
						'description' => '<p>Long.</p>',
						'images'      => array( 'https://images.printify.com/tee' ),
					),
					array(
						'id'     => 77,
						'title'  => 'Unisex Heavy Blend™ Hooded Sweatshirt',
						'brand'  => 'Gildan',
						'images' => array( 'https://images.printify.com/66c32f6ebb0d2981ff0aa7f3', 'https://images.printify.com/second' ),
					),
					array(
						'id'     => 443,
						'title'  => 'Posters (EU)',
						'brand'  => 'Generic brand',
						'images' => array( 'https://elsewhere.example/poster.png' ),
					),
				)
			),
			self::API . '/catalog/blueprints/77.json'      => self::answer(
				array(
					'id'          => 77,
					'title'       => 'Unisex Heavy Blend™ Hooded Sweatshirt',
					'description' => '<p>A soft hoodie.</p>',
					'brand'       => 'Gildan',
					'images'      => array( 'https://images.printify.com/1.jpg' ),
				)
			),
			self::API . '/catalog/blueprints/77/print_providers.json' => self::answer(
				array(
					array(
						'id'    => 29,
						'title' => 'Monster Digital',
					),
					array(
						'id'    => 26,
						'title' => 'Textildruck Europa',
					),
				)
			),
			self::API . '/catalog/print_providers.json'    => self::answer(
				array(
					array(
						'id'       => 26,
						'title'    => 'Textildruck Europa',
						'location' => array(
							'city'    => 'Halle (Saale)',
							'country' => 'DE',
						),
					),
					array(
						'id'       => 29,
						'title'    => 'Monster Digital',
						'location' => array(
							'city'    => 'Las Vegas',
							'country' => 'US',
						),
					),
				)
			),
			self::API . '/catalog/print_providers/26.json' => self::answer(
				array(
					'id'         => 26,
					'blueprints' => array( array( 'id' => 77 ) ),
				)
			),
			self::API . '/catalog/print_providers/29.json' => self::answer(
				array(
					'id'         => 29,
					'blueprints' => array( array( 'id' => 5 ), array( 'id' => 77 ) ),
				)
			),
			'https://images.printify.com/66c32f6ebb0d2981ff0aa7f3' => array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => AlertImagesTest::png( 600, 300 ),
			),
			self::API . '/catalog/blueprints/77/print_providers/26/variants.json' => self::answer(
				array(
					'id'       => 26,
					'variants' => array(
						$variant( 32870, 'Ash', 'S', 3531, 2908, 3319, 3761 ),
						$variant( 32871, 'Ash', 'M', 3921, 3239, 3909, 4431 ),
						$variant( 32872, 'Ash', 'L', 4016, 3307, 4500, 5100 ),
						$variant( 32880, 'Black', 'S', 3531, 2908, 3319, 3761 ),
					),
				)
			),
		);
	}

	/**
	 * A WordPress response.
	 *
	 * @param array<mixed>          $body    JSON body.
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
}
