<?php
/**
 * Base products and cliparts on a real database and file system (step 30.6).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerAssetDownload;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
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
