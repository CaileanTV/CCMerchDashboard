<?php
/**
 * Base products and cliparts on a real database and file system (step 30.6).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerAssetDownload;
use MerchBoard\Modules\ProductDesigner\Bases\BasePriceSchedule;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Files\ClipartLibrary;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;

/**
 * The base product service with real PNG layers in the private folder: a
 * side's template is its layers' common size, a refused change keeps no
 * file, a replaced layer is deleted, and the version grows with the price or
 * template. Cliparts and the administrator's file endpoint as well.
 */
final class BaseProductsTest extends IntegrationTestCase {

	private BaseProductService $service;

	private BaseProductRepository $bases;

	private DesignerAssetRepository $assets;

	private DesignerFiles $files;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		DesignerFiles::remove_all();

		$this->bases  = new BaseProductRepository( $this->wpdb );
		$this->assets = new DesignerAssetRepository( $this->wpdb );
		// Uploads in tests are not HTTP uploads: is_uploaded_file() is false.
		$this->files   = new DesignerFiles( static fn( string $from, string $to ): bool => rename( $from, $to ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test upload move.
		$this->service = new BaseProductService( $this->bases, $this->assets, $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );
	}

	protected function tearDown(): void {
		DesignerFiles::remove_all();
		parent::tearDown();
	}

	public function test_a_base_product_is_created_with_its_template_and_changed_with_a_new_version(): void {
		$id   = $this->service->save(
			null,
			self::fields(),
			array(
				self::side(
					'Front',
					array(
						'background' => 1000,
						'color'      => 1000,
					)
				),
			)
		);
		$base = $this->bases->find( $id );

		self::assertNotNull( $base );
		self::assertSame( array( BaseProduct::DRAFT, 'own', 1499500, 'DKK', 1 ), array( $base->status, $base->source, $base->base_units, $base->currency, $base->version ) );
		self::assertSame(
			array(
				array(
					'key'  => 'black',
					'name' => 'Black',
					'hex'  => '#111111',
				),
				array(
					'key'  => 'sand',
					'name' => 'Sand',
					'hex'  => '#d8c8a8',
				),
			),
			$base->colors
		);
		self::assertSame( array( 'S', 'M', 'L' ), $base->sizes );
		self::assertSame( array( 'front', 1000, 1000, array( 'background', 'color' ), array( 3543, 4724 ) ), array( $base->views[0]->key, $base->views[0]->width, $base->views[0]->height, array_keys( $base->views[0]->layers ), $base->views[0]->print_pixels() ) );
		self::assertSame( 2, count( (array) glob( DesignerFiles::directory() . '/*.png' ) ) );

		// Only the description changes: the same version.
		$this->service->save( $id, array_merge( self::fields(), array( 'description' => 'Heavy cotton.' ) ), array( self::existing( 'front' ) ) );
		self::assertSame( 1, $this->bases->find( $id )?->version );

		// A new colour mask replaces the old one, whose file is deleted; a new template raises the version.
		$old  = $base->views[0]->layers['color'];
		$file = $this->assets->find( $old )['file'] ?? '';
		$this->service->save( $id, self::fields(), array( self::existing( 'front', array( 'color' => 1000 ) ) ) );
		$base = $this->bases->find( $id );

		self::assertNotNull( $base );
		self::assertSame( 2, $base->version );
		self::assertNotSame( $old, $base->views[0]->layers['color'] );
		self::assertNull( $this->assets->find( $old ) );
		self::assertNull( $this->files->path( $file ) );

		// A new base price keeps the version (step 30.11: designs stay valid; the shop is repriced).
		$this->service->save( $id, array_merge( self::fields(), array( 'base_price' => '159,95' ) ), array( self::existing( 'front' ) ) );
		self::assertSame( array( 2, 1599500 ), array( $this->bases->find( $id )?->version, $this->bases->find( $id )?->base_units ) );

		$audit = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT action FROM %i WHERE action LIKE %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), 'designer_base_%' ) );
		self::assertSame( array( 'designer_base_created', 'designer_base_changed', 'designer_base_changed', 'designer_base_changed' ), $audit );
	}

	public function test_a_colour_can_have_its_own_background_which_goes_with_the_colour(): void {
		$id   = $this->service->save( null, self::fields(), array( self::side( 'Front', array( 'background' => 1000 ) ) ) );
		$side = self::existing( 'front' );

		$side['uploads'][ BaseView::color_layer( 'black' ) ] = AlertImagesTest::upload( AlertImagesTest::png( 1000, 1000 ), 'black.png' );
		$side['uploads'][ BaseView::color_layer( 'pink' ) ]  = AlertImagesTest::upload( AlertImagesTest::png( 1000, 1000 ), 'pink.png' );
		$this->service->save( $id, self::fields(), array( $side ) );
		$view = $this->bases->find( $id )?->views[0];

		self::assertNotNull( $view );
		self::assertSame( array( 'black' ), array_keys( $view->color_backgrounds() ), 'Only a colour of the base product gets a background.' );
		self::assertContains( $view->color_backgrounds()['black'], $this->bases->find( $id )?->layer_assets() ?? array() );

		// A colour's background must have the template's size.
		$wrong = self::existing( 'front' );

		$wrong['uploads'][ BaseView::color_layer( 'sand' ) ] = AlertImagesTest::upload( AlertImagesTest::png( 500, 500 ), 'sand.png' );

		try {
			$this->service->save( $id, self::fields(), array( $wrong ) );
			self::fail( 'Another size.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::BASE_LAYER_SIZE, $e->reason() );
		}

		// Removing the colour removes its background and deletes its file.
		$asset  = $view->color_backgrounds()['black'];
		$fields = array_merge( self::fields(), array( 'colors' => 'Sand #d8c8a8' ) );
		$this->service->save( $id, $fields, array( self::existing( 'front' ) ) );

		self::assertSame( array(), $this->bases->find( $id )?->views[0]->color_backgrounds() );
		self::assertNull( $this->assets->find( $asset ) );
	}

	public function test_colour_photos_come_in_batches_and_a_refused_batch_keeps_nothing(): void {
		$id    = $this->service->save( null, array_merge( self::fields(), array( 'colors' => "Mørk Grøn #111111\nSand #d8c8a8\nBlack #000000" ) ), array( self::side( 'Forside', array( 'background' => 1000 ) ) ) );
		$photo = static fn( int $size = 1000 ): array => AlertImagesTest::upload( AlertImagesTest::png( $size, $size ), 'photo.png' );

		// By the name of the side, as a new side has no key in the browser, and whatever the case of the colour.
		$this->service->add_color_photos(
			$id,
			'Forside',
			array(
				'mørk grøn' => $photo(),
				'Sand'      => $photo(),
			)
		);
		$this->service->add_color_photos( $id, 'forside', array( 'Black' => $photo() ) );

		$base  = $this->bases->find( $id );
		$saved = $base?->views[0]->color_backgrounds() ?? array();

		self::assertNotNull( $base );
		self::assertSame( array( 'mork-gron', 'sand', 'black' ), array_keys( $saved ) );
		self::assertSame( 3, $base->version, 'Each batch changes the template.' );
		self::assertSame( array( 500, 600 ), array( $base->views[0]->area['width'], $base->views[0]->area['height'] ), 'The print area stays.' );

		// A photo replaces the colour's own background, whose file is deleted.
		$file = $this->assets->find( $saved['sand'] )['file'] ?? '';

		$this->service->add_color_photos( $id, 'forside', array( 'Sand' => $photo() ) );

		self::assertNull( $this->assets->find( $saved['sand'] ) );
		self::assertNull( $this->files->path( $file ) );

		$count = count( (array) glob( DesignerFiles::directory() . '/*.png' ) );
		$cases = array(
			DesignerException::BASE_LAYER_SIZE => array( 'forside', array( 'Sand' => $photo(), 'Black' => $photo( 500 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A short case.
			DesignerException::BASE_COLORS     => array( 'forside', array( 'Black' => $photo(), 'Mynxie' => $photo() ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A short case.
			DesignerException::BASE_VIEW       => array( 'bagside', array( 'Sand' => $photo() ) ),
			DesignerException::FILE_MISSING    => array( 'forside', array( 'Sand' => array() ) ),
		);

		foreach ( $cases as $reason => [ $side, $photos ] ) {
			try {
				$this->service->add_color_photos( $id, $side, $photos );
				self::fail( 'Refused: ' . $reason );
			} catch ( DesignerException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		self::assertSame( $count, count( (array) glob( DesignerFiles::directory() . '/*.png' ) ), 'A refused batch keeps no file.' );
		self::assertSame( 4, $this->bases->find( $id )?->version );
	}

	public function test_a_side_keeps_a_scene_of_its_own_size_until_it_is_removed(): void {
		$scene = (string) wp_json_encode(
			array(
				'width'  => 600,
				'height' => 400,
				'steps'  => array(
					array(
						'type'   => 'design',
						'end'    => 'start',
						'width'  => 10,
						'height' => 10,
						'quad'   => array( 0, 0, 10, 0, 10, 10, 0, 10 ),
						'mesh'   => array_fill( 0, 32, 0.5 ),
						'mask'   => null,
					),
				),
			)
		);
		$side  = self::side(
			'Mug',
			array(
				'background' => 1000,
				'scene'      => 300,
			)
		);
		$id    = $this->service->save( null, self::fields(), array( array_merge( $side, array( 'scene' => $scene ) ) ) );
		$view  = $this->bases->find( $id )?->views[0];

		self::assertNotNull( $view );
		self::assertSame( array( 1000, 1000 ), array( $view->width, $view->height ), 'The image of a scene has a size of its own.' );
		self::assertSame( array( 600, 400 ), $view->mockup_size() );
		self::assertArrayHasKey( 'scene', $view->layers );

		$this->service->save( $id, self::fields(), array( self::existing( 'mug' ) ) );
		self::assertSame( array( 600, 400 ), $this->bases->find( $id )?->views[0]->mockup_size(), 'Kept when nothing is sent.' );

		$this->service->save( $id, self::fields(), array( array_merge( self::existing( 'mug' ), array( 'scene_preview' => '1' ) ) ) );
		self::assertTrue( $this->bases->find( $id )?->views[0]->scene['preview'] ?? null, 'The preview is switched on.' );

		$version = $this->bases->find( $id )?->version;

		$this->service->save( $id, self::fields(), array( array_merge( self::existing( 'mug' ), array( 'scene_preview' => '0' ) ) ) );
		self::assertFalse( $this->bases->find( $id )?->views[0]->scene['preview'] ?? null, 'And off.' );
		self::assertSame( $version, $this->bases->find( $id )?->version, 'No design is outdated by the switch.' );

		$this->service->save( $id, self::fields(), array( array_merge( self::existing( 'mug' ), array( 'clear' => array( 'scene' ) ) ) ) );

		$view = $this->bases->find( $id )?->views[0];

		self::assertNotNull( $view );
		self::assertNull( $view->scene );
		self::assertArrayNotHasKey( 'scene', $view->layers );

		try {
			$this->service->save( $id, self::fields(), array( array_merge( self::existing( 'mug', array( 'scene' => 300 ) ), array( 'scene' => 'nope' ) ) ) );
			self::fail( 'An image needs its scene.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::BASE_VIEW, $e->reason() );
		}
	}

	public function test_a_note_to_creators_is_kept_until_it_is_changed(): void {
		$id = $this->service->save( null, array_merge( self::fields(), array( 'note' => "Saml produkter?\nKontakt os. <b>x</b>" ) ), array( self::side( 'Front', array( 'background' => 1000 ) ) ) );

		self::assertSame( "Saml produkter?\nKontakt os. x", $this->bases->find( $id )?->note );

		$this->service->save( $id, self::fields(), array( self::existing( 'front' ) ) );
		self::assertSame( "Saml produkter?\nKontakt os. x", $this->bases->find( $id )?->note, 'Left out, it is kept.' );
		self::assertSame( 1, $this->bases->find( $id )?->version, 'No design is outdated by it.' );

		$this->service->set_active( $id, true );
		self::assertSame( "Saml produkter?\nKontakt os. x", $this->bases->find( $id )?->note, 'A change of status keeps it.' );

		$this->service->save( $id, array_merge( self::fields(), array( 'note' => '' ) ), array( self::existing( 'front' ) ) );
		self::assertSame( '', $this->bases->find( $id )?->note );
	}

	public function test_refused_changes_keep_no_file_and_change_nothing(): void {
		$refusals = array(
			DesignerException::BASE_NAME       => array( array( 'name' => ' ' ), array() ),
			DesignerException::BASE_PRICE      => array( array( 'base_price' => '149.955' ), array() ),
			DesignerException::BASE_COLORS     => array( array( 'colors' => "Black #111111\nBlack #222222" ), array() ),
			DesignerException::BASE_SIZES      => array( array( 'sizes' => 'S, S' ), array() ),
			DesignerException::BASE_CATEGORY   => array( array( 'category_id' => '999999' ), array() ),
			DesignerException::BASE_LAYER_SIZE => array(
				array(),
				array(
					self::side(
						'Front',
						array(
							'background' => 1000,
							'color'      => 900,
						)
					),
				),
			),
			DesignerException::BASE_VIEW       => array( array(), array( self::side( 'Front', array( 'background' => 1000 ), array( 'x' => '900' ) ) ) ),
		);

		foreach ( $refusals as $reason => [ $fields, $views ] ) {
			try {
				$this->service->save( null, array_merge( self::fields(), $fields ), $views );
				self::fail( 'Expected a refusal: ' . $reason );
			} catch ( DesignerException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		$type = AlertImagesTest::upload( 'GIF89a', 'layer.png' );

		try {
			$this->service->save( null, self::fields(), array( array_merge( self::side( 'Front', array() ), array( 'uploads' => array( 'background' => $type ) ) ) ) );
			self::fail( 'A layer must be a PNG.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::FILE_TYPE, $e->reason() );
		}

		self::assertSame( 0, $this->count_rows( Tables::DESIGNER_BASES ) );
		self::assertSame( 0, $this->count_rows( Tables::DESIGNER_ASSETS ) );
		self::assertSame( array(), (array) glob( DesignerFiles::directory() . '/*.png' ), 'Refused uploads are deleted.' );
	}

	public function test_activation_needs_colours_sizes_and_sides_and_a_used_base_product_is_kept(): void {
		$empty = $this->service->save( null, array_merge( self::fields(), array( 'colors' => '' ) ), array() );

		try {
			$this->service->set_active( $empty, true );
			self::fail( 'No colours or sides.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::BASE_INCOMPLETE, $e->reason() );
		}

		$id = $this->service->save( null, self::fields(), array( self::side( 'Front', array( 'color' => 800 ) ) ) );
		$this->service->set_active( $id, true );
		self::assertSame( BaseProduct::ACTIVE, $this->bases->find( $id )?->status );

		try {
			$this->service->save( $id, array_merge( self::fields(), array( 'sizes' => '' ) ), array( self::existing( 'front' ) ) );
			self::fail( 'An active base product stays complete.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::BASE_INCOMPLETE, $e->reason() );
		}

		$this->wpdb->insert(
			$this->table( Tables::DESIGNER_PRODUCTS ),
			array(
				'creator_id'     => 1,
				'base_id'        => $id,
				'creation_token' => str_repeat( 'ab', 16 ),
				'created_at'     => '2026-10-29 12:00:00',
				'updated_at'     => '2026-10-29 12:00:00',
			)
		);

		try {
			$this->service->delete( $id );
			self::fail( 'A base product with designs is kept.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::BASE_IN_USE, $e->reason() );
		}

		$this->service->delete( $empty );
		self::assertNull( $this->bases->find( $empty ) );
		self::assertNotNull( $this->bases->find( $id ) );

		wp_set_current_user( 0 );

		try {
			$this->service->save( null, self::fields(), array() );
			self::fail( 'Only users with merchboard_manage_designer.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::NOT_AUTHORIZED, $e->reason() );
		}
	}

	public function test_the_price_of_extra_design_areas_is_kept_and_refused_strictly(): void {
		$id = $this->service->save( null, array_merge( self::fields(), array( 'extra_side_price' => '49,95' ) ), array() );

		self::assertSame( 499500, $this->bases->find( $id )->extra_side_units ?? null );

		$this->service->save( $id, self::fields(), array() );
		self::assertSame( 499500, $this->bases->find( $id )->extra_side_units ?? null, 'Left out: kept.' );

		$this->service->save( $id, array_merge( self::fields(), array( 'extra_side_price' => '' ) ), array() );
		self::assertSame( 0, $this->bases->find( $id )->extra_side_units ?? null, 'Empty is 0, which is allowed.' );

		$this->service->save( $id, array_merge( self::fields(), array( 'extra_side_price' => '25' ) ), array() );

		foreach ( array( '-1', '1.999', 'abc' ) as $invalid ) {
			try {
				$this->service->save( $id, array_merge( self::fields(), array( 'extra_side_price' => $invalid ) ), array() );
				self::fail( 'Refused: ' . $invalid );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::BASE_EXTRA, $e->reason() );
			}
		}

		self::assertSame( 250000, $this->bases->find( $id )->extra_side_units ?? null );

		$this->service->retire( $id );
		self::assertSame( 250000, $this->bases->find( $id )->extra_side_units ?? null, 'A change of status keeps it.' );
	}

	public function test_cliparts_and_the_administrator_s_file_endpoint(): void {
		$library = new ClipartLibrary( $this->assets, $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$id      = $library->add( 'Star', AlertImagesTest::upload( AlertImagesTest::png( 64, 64 ), 'star.png' ) );
		$asset   = $this->assets->find( $id );

		self::assertSame( array( 'clipart', 'Star', 64, 64, null ), array( $asset['kind'] ?? '', $asset['name'] ?? '', $asset['width'] ?? 0, $asset['height'] ?? 0, $asset['creator_id'] ?? null ) );
		self::assertCount( 1, $this->assets->shared( DesignerAssetRepository::CLIPART ) );

		$download = new DesignerAssetDownload( $this->assets, $this->files );
		$nonce    = wp_create_nonce( DesignerAssetDownload::nonce_action( $id ) );
		$response = $download->response(
			array(
				'asset'    => (string) $id,
				'_wpnonce' => $nonce,
			)
		);

		self::assertSame( 200, $response['status'] );
		self::assertSame( array( 'image/png', 'nosniff' ), array( $response['headers']['Content-Type'], $response['headers']['X-Content-Type-Options'] ) );
		self::assertStringStartsWith( "\x89PNG", $response['body'] );
		self::assertSame(
			403,
			$download->response(
				array(
					'asset'    => (string) $id,
					'_wpnonce' => wp_create_nonce( DesignerAssetDownload::nonce_action( $id + 1 ) ),
				)
			)['status'],
			'The nonce is bound to the asset.'
		);

		$library->delete( $id );
		self::assertNull( $this->assets->find( $id ) );
		self::assertSame( 404, $download->response( array( 'asset' => (string) $id, '_wpnonce' => $nonce ) )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		wp_set_current_user( 0 );
		self::assertSame( 403, $download->response( array( 'asset' => (string) $id, '_wpnonce' => $nonce ) )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_a_higher_price_of_a_base_product_creators_design_on_waits_14_days(): void {
		$id       = $this->service->save( null, self::fields(), array() );
		$now      = new DateTimeImmutable( '2026-11-06 12:00:00', new DateTimeZone( 'UTC' ) );
		$clock    = static function () use ( &$now ): DateTimeImmutable {
			return $now;
		};
		$schedule = new BasePriceSchedule( $this->bases, new DesignerProductRepository( $this->wpdb ), $this->service( AuditTrail::class ), $this->service( Logger::class ), $clock );
		$service  = new BaseProductService( $this->bases, $this->assets, $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK', $schedule );
		$changed  = did_action( BaseProductService::PRICE_CHANGED );
		$told     = array();
		$listen   = static function ( mixed $type, mixed $creator ) use ( &$told ): void {
			$told[] = array( $type, $creator );
		};
		add_action( 'merchboard_notify', $listen, 10, 2 );

		// Nobody designs on it yet: a higher price applies at once.
		$service->save(
			$id,
			array_merge(
				self::fields(),
				array(
					'base_price'       => '159',
					'extra_side_price' => '50',
				)
			),
			array()
		);
		self::assertSame( 1590000, $this->bases->find( $id )?->base_units );
		self::assertNull( BasePriceSchedule::scheduled( $id ) );

		$this->wpdb->insert(
			$this->wpdb->prefix . 'merchboard_designer_products',
			array(
				'creator_id'     => 7,
				'base_id'        => $id,
				'creation_token' => str_repeat( 'a', 32 ),
				'status'         => 'live',
				'created_at'     => '2026-11-01 10:00:00',
				'updated_at'     => '2026-11-01 10:00:00',
			)
		);

		// A lower price still applies at once.
		$service->save( $id, array_merge( self::fields(), array( 'base_price' => '149' ) ), array() );
		self::assertSame( 1490000, $this->bases->find( $id )?->base_units );

		// A higher one waits 14 days, and the creator is told.
		$service->save( $id, array_merge( self::fields(), array( 'base_price' => '169' ) ), array() );
		self::assertSame( 1490000, $this->bases->find( $id )?->base_units, 'The shop keeps the price until the date.' );
		$planned = BasePriceSchedule::scheduled( $id );
		self::assertSame( array( 1690000, $now->getTimestamp() + 14 * DAY_IN_SECONDS ), array( $planned['base'] ?? null, $planned['at'] ?? null ) );
		self::assertSame( array( array( 'designer_price_increase', 7 ) ), $told, 'The creator who designs on it is told.' );
		remove_action( 'merchboard_notify', $listen, 10 );
		self::assertStringContainsString( '169.00', BasePriceSchedule::notice( $this->bases->find( $id ) ?? self::fail() ) );

		$service->save(
			$id,
			array_merge(
				self::fields(),
				array(
					'base_price'       => '149',
					'extra_side_price' => '10',
				)
			),
			array()
		);
		self::assertSame( 100000, $this->bases->find( $id )?->extra_side_units, 'A lower price applies at once, also while an increase is planned.' );
		self::assertSame( 1690000, BasePriceSchedule::scheduled( $id )['base'] ?? null, 'The planned increase stays.' );

		$now = $now->modify( '+13 days' );
		self::assertSame( 0, $schedule->apply_due(), 'Not before the date.' );

		$now    = $now->modify( '+1 day' );
		$before = did_action( BaseProductService::PRICE_CHANGED );
		self::assertSame( 1, $schedule->apply_due() );
		self::assertSame( array( 1690000, 100000 ), array( $this->bases->find( $id )?->base_units, $this->bases->find( $id )?->extra_side_units ), 'Only the increase is set; the lower price saved meanwhile stays (review 32.7).' );
		self::assertNull( BasePriceSchedule::scheduled( $id ) );
		self::assertSame( $before + 1, did_action( BaseProductService::PRICE_CHANGED ), 'The shop is repriced on the date.' );
		self::assertGreaterThan( $changed, $before );

		// A planned increase can be cancelled.
		$service->save( $id, array_merge( self::fields(), array( 'base_price' => '179' ) ), array() );
		self::assertNotNull( BasePriceSchedule::scheduled( $id ) );
		$schedule->cancel( $id );
		self::assertNull( BasePriceSchedule::scheduled( $id ) );
		self::assertSame( 1690000, $this->bases->find( $id )?->base_units );

		// "Change the price now" sets a due increase the automatic check has not set yet, never one before its date.
		$service->save( $id, array_merge( self::fields(), array( 'base_price' => '179' ) ), array() );
		self::assertFalse( $schedule->apply_now( $id ), 'Not before the date: the 14 days hold.' );
		self::assertSame( 1690000, $this->bases->find( $id )?->base_units );
		$now = $now->modify( '+14 days' );
		self::assertTrue( $schedule->apply_now( $id ) );
		self::assertSame( 1790000, $this->bases->find( $id )?->base_units );
		self::assertNull( BasePriceSchedule::scheduled( $id ) );
		self::assertFalse( $schedule->apply_now( $id ), 'Nothing planned any more.' );
		delete_option( BasePriceSchedule::OPTION );
	}

	public function test_only_prices_that_go_up_are_increases(): void {
		$base  = new BaseProduct(
			1,
			BaseProduct::DRAFT,
			'own',
			'Cup',
			'',
			100000,
			'DKK',
			null,
			array(),
			array( 'XS', 'S', 'L' ),
			array(),
			1,
			20000,
			'',
			array(
				'XS' => 80000,
				'L'  => 120000,
			),
			10000
		);
		$sizes = array( 'XS', 'S', 'L' );
		$own   = array(
			'XS' => 80000,
			'L'  => 120000,
		);

		self::assertNull( BasePriceSchedule::increases( $base, 100000, $own, $sizes, 20000, 10000, array() ), 'Unchanged.' );
		self::assertNull( BasePriceSchedule::increases( $base, 90000, array(), $sizes, 20000, 10000, array() ), 'Lower prices only.' );
		self::assertNull( BasePriceSchedule::increases( $base, 100000, array( 'L' => 120000 ), array( 'S', 'L' ), 20000, 10000, array() ), 'A removed size with its own price is no increase (review 32.7).' );
		self::assertSame( array( 'L' => 130000 ), BasePriceSchedule::increases( $base, 100000, array_merge( $own, array( 'L' => 130000 ) ), $sizes, 20000, 10000, array() )['sizes'] ?? null );
		self::assertSame( array( 'S' => 110000 ), BasePriceSchedule::increases( $base, 100000, array_merge( $own, array( 'S' => 110000 ) ), $sizes, 20000, 10000, array() )['sizes'] ?? null, 'A size with its own price above the base price.' );
		self::assertSame( array( 25000, null ), array_values( array_intersect_key( BasePriceSchedule::increases( $base, 100000, $own, $sizes, 25000, 10000, array() ) ?? array(), array_flip( array( 'large', 'small' ) ) ) ) );
		self::assertSame( 15000, BasePriceSchedule::increases( $base, 100000, $own, $sizes, 20000, 15000, array() )['small'] ?? null );
	}

	public function test_text_fields_are_parsed_strictly(): void {
		self::assertSame( array( 'S', 'M', 'XL' ), BaseProductService::sizes( "S, M\nXL ," ) );
		self::assertSame( '#aabbcc', BaseProductService::colors( "Light blue, AABBCC\n" )[0]['hex'] );
		self::assertSame( 'light-blue', BaseProductService::colors( 'Light blue #aabbcc' )[0]['key'] );
		self::assertSame( 1499500, SalesAmount::parse( '149.95' ) );
	}

	/**
	 * General fields.
	 *
	 * @return array<string, string>
	 */
	private static function fields(): array {
		return array(
			'name'        => 'Organic tee',
			'description' => '',
			'base_price'  => '149,95',
			'category_id' => '0',
			'colors'      => "Black #111111\nSand #D8C8A8",
			'sizes'       => 'S, M, L',
		);
	}

	/**
	 * A new side with square PNG layers.
	 *
	 * @param string                $name   Name.
	 * @param array<string, int>    $layers Size in px by layer.
	 * @param array<string, string> $area Overrides of the print area.
	 * @return array<string, mixed>
	 */
	private static function side( string $name, array $layers, array $area = array() ): array {
		return array_merge(
			array(
				'key'       => '',
				'name'      => $name,
				'x'         => '250',
				'y'         => '200',
				'width'     => '500',
				'height'    => '600',
				'width_mm'  => '300',
				'height_mm' => '400',
				'dpi'       => '300',
				'remove'    => false,
				'clear'     => array(),
				'uploads'   => array_map( static fn( int $size ): array => AlertImagesTest::upload( AlertImagesTest::png( $size, $size ), 'layer.png' ), $layers ),
			),
			$area
		);
	}

	/**
	 * A stored side as the form sends it, with new layers.
	 *
	 * @param string             $key    Side key.
	 * @param array<string, int> $layers Size in px by new layer.
	 * @return array<string, mixed>
	 */
	private static function existing( string $key, array $layers = array() ): array {
		return array_merge( self::side( 'Front', $layers ), array( 'key' => $key ) );
	}
}
