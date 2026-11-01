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
			( new PrintifyCatalog( $this->api ) )->search( 'hoodie' );
			self::fail( 'Off.' );
		} catch ( PrintifyException $e ) {
			self::assertSame( PrintifyException::NOT_CONFIGURED, $e->reason() );
		}

		self::assertSame( array(), $this->calls );
	}

	public function test_calls_only_read_and_carry_the_token_and_a_429_waits(): void {
		$catalog = new PrintifyCatalog( $this->api );

		$catalog->search( 'hoodie' );
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

	public function test_the_whole_catalog_is_searched_and_producers_anywhere_are_offered_the_eu_first(): void {
		$catalog = new PrintifyCatalog( $this->api );

		self::assertSame( array( 77 ), array_column( $catalog->search( 'gildan HOODED' ), 'id' ) );
		self::assertSame( array(), $catalog->search( '  ' ) );

		$producers = $catalog->producers( 77 );

		self::assertSame( array( 'Textildruck Europa', 'Monster Digital' ), array_column( $producers, 'title' ) );
		self::assertSame( array( 'DE', 'US' ), array_column( $producers, 'country' ) );

		// The second search reads no list again: it is 5.5 MB.
		$calls = count( $this->calls );

		$catalog->search( 'tee' );
		$catalog->producers( 77 );

		self::assertSame( $calls + 1, count( $this->calls ), 'Only the producers of the product are asked again.' );
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
	 * Imports the hoodie with its front and back, running the job at once.
	 *
	 * @return int The base product.
	 */
	private function import(): int {
		$catalog = new CatalogImport( $this->wpdb, $this->bases, $this->base_service(), $this->files, $this->queue, $this->service( Logger::class ), 2, 'https://shop.example/assets/', null, null, null, new PrintifyCatalog( $this->api ) );
		$id      = $catalog->create(
			PrintifyCatalog::SOURCE,
			'77-26',
			array(
				'colors'     => array( 'Ash', 'Black' ),
				'sizes'      => array( 'S', 'M', 'L' ),
				'sides'      => array( 'front', 'back' ),
				'base_price' => '200',
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
			self::API . '/catalog/blueprints.json'      => self::answer(
				array(
					array(
						'id'          => 5,
						'title'       => 'Unisex Cotton Crew Tee',
						'brand'       => 'Next Level',
						'description' => '<p>Long.</p>',
					),
					array(
						'id'    => 77,
						'title' => 'Unisex Heavy Blend™ Hooded Sweatshirt',
						'brand' => 'Gildan',
					),
				)
			),
			self::API . '/catalog/blueprints/77.json'   => self::answer(
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
			self::API . '/catalog/print_providers.json' => self::answer(
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
