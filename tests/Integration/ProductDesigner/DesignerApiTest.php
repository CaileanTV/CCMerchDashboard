<?php
/**
 * The creator's designer API on a real database (step 30.8).
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
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\DashboardSections;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\DesignerDashboardSection;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerDrafts;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Files\ClipartLibrary;
use MerchBoard\Modules\ProductDesigner\Files\CreatorUploads;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Rest\MeDesignerController;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/me/designer` through the REST server: only while the designer is on,
 * only the current creator's products and files, and only a design that
 * passes the whitelist (approved fonts, own uploads and cliparts, the base
 * product's sides) is stored. The price follows the base price and profit.
 */
final class DesignerApiTest extends IntegrationTestCase {

	/**
	 * The REST routes' registration.
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	private int $base;

	private int $clipart;

	private int $user;

	private int $other;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		DesignerFiles::remove_all();
		wp_set_current_user( 1 );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => true ) ) );
		update_option(
			AlertFonts::APPROVED_OPTION,
			array(
				array(
					'family'   => 'Roboto',
					'category' => 'sans-serif',
					'weights'  => array( 400, 700 ),
				),
			)
		);

		$files    = new DesignerFiles( static fn( string $from, string $to ): bool => rename( $from, $to ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test upload move.
		$assets   = new DesignerAssetRepository( $this->wpdb );
		$bases    = new BaseProductRepository( $this->wpdb );
		$service  = new BaseProductService( $bases, $assets, $files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );
		$products = new DesignerProductRepository( $this->wpdb );

		$this->base = $service->save(
			null,
			array(
				'name'        => 'Organic tee',
				'description' => '',
				'base_price'  => '149,95',
				'category_id' => '0',
				'colors'      => "Black #111111\nSand #d8c8a8",
				'sizes'       => 'S, M, L',
			),
			array(
				array(
					'key'       => '',
					'name'      => 'Front',
					'x'         => '100',
					'y'         => '100',
					'width'     => '200',
					'height'    => '200',
					'width_mm'  => '300',
					'height_mm' => '400',
					'dpi'       => '300',
					'remove'    => false,
					'clear'     => array(),
					'uploads'   => array( 'color' => AlertImagesTest::upload( AlertImagesTest::png( 400, 400 ), 'color.png' ) ),
				),
			)
		);
		$service->set_active( $this->base, true );
		$service->save( null, array( 'name' => 'Draft mug', 'description' => '', 'base_price' => '99', 'category_id' => '0', 'colors' => '', 'sizes' => '' ), array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->clipart = ( new ClipartLibrary( $assets, $files, $this->service( AuditTrail::class ), $this->service( Transaction::class ) ) )->add( 'Star', AlertImagesTest::upload( AlertImagesTest::png( 32, 32 ), 'star.png' ) );

		$this->user  = $this->create_user( 'designer-a' );
		$this->other = $this->create_user( 'designer-b' );
		$this->service( CreatorService::class )->create( 'Designer A', CreatorStatus::Active, $this->user );
		$this->service( CreatorService::class )->create( 'Designer B', CreatorStatus::Active, $this->other );

		$controller     = new MeDesignerController(
			$this->service( CreatorContext::class ),
			$bases,
			$products,
			new DesignerDrafts( $products, $bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2 ),
			$assets,
			$files,
			new CreatorUploads( $assets, $files ),
			new AlertFonts( $this->service( AuditTrail::class ), $this->service( Transaction::class ) ),
			$this->service( Logger::class )
		);
		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		DesignerFiles::remove_all();
		parent::tearDown();
	}

	public function test_the_designer_exists_only_while_it_is_switched_on(): void {
		wp_set_current_user( $this->user );
		$sections = ( new DesignerDashboardSection() )->add_section( array( array( 'id' => 'products' ), array( 'id' => 'orders' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertIsArray( $sections );
		self::assertSame( array( 'products', 'designer', 'orders' ), array_column( $sections, 'id' ) );
		self::assertSame( 200, $this->call( 'GET', '/merchboard/v1/me/designer' )->get_status() );

		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => false ) ) );
		self::assertSame( array( 'products', 'orders' ), array_column( (array) DesignerDashboardSection::add_section( array( array( 'id' => 'products' ), array( 'id' => 'orders' ) ) ), 'id' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$off = $this->call( 'GET', '/merchboard/v1/me/designer' );
		self::assertSame( array( 404, 'merchboard_designer_disabled' ), array( $off->get_status(), $off->get_data()['code'] ?? null ) );
		self::assertNotEmpty( DashboardSections::FILTER );
	}

	public function test_a_creator_designs_on_an_active_base_product_with_own_uploads_cliparts_and_approved_fonts(): void {
		wp_set_current_user( $this->user );
		$overview = $this->data( $this->call( 'GET', '/merchboard/v1/me/designer' ) );

		self::assertSame( array( 'Organic tee' ), array_column( $overview['bases'], 'name' ), 'Only active base products.' );
		self::assertSame( array( 'front' ), array_column( $overview['bases'][0]['views'], 'key' ) );
		self::assertSame( array( 3543, 4724 ), array( $overview['bases'][0]['views'][0]['print_width'], $overview['bases'][0]['views'][0]['print_height'] ) );
		self::assertSame( array( 'Star' ), array_column( $overview['cliparts'], 'name' ) );
		self::assertSame( array( 'Roboto' ), array_column( $overview['fonts'], 'family' ) );

		$upload = $this->upload( AlertImagesTest::png( 50, 40 ) );
		self::assertSame( 201, $upload->get_status() );
		$own = (int) $upload->get_data()['id'];

		$created = $this->call( 'POST', '/merchboard/v1/me/designer/products', array( 'base_id' => $this->base ) );
		self::assertSame( 201, $created->get_status() );
		$product = (int) $created->get_data()['id'];
		self::assertSame( array( 'Organic tee', array( 'black' ), array( 'S', 'M', 'L' ), '149.9500' ), array( $created->get_data()['draft']['title'], $created->get_data()['draft']['colors'], $created->get_data()['draft']['sizes'], $created->get_data()['draft']['price'] ) );

		$saved = $this->call( 'PUT', '/merchboard/v1/me/designer/products/' . $product, $this->draft( array( self::text(), self::image( $own ), self::image( $this->clipart ), self::rect() ) ) );
		self::assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$draft = $saved->get_data()['draft'];
		self::assertSame( array( 'Stream tee', array( 'black', 'sand' ), array( 'M', 'L' ), 'percent', '20.0000', '179.9400' ), array( $draft['title'], $draft['colors'], $draft['sizes'], $draft['profit_kind'], $draft['profit_value'], $draft['price'] ) );
		self::assertSame( array( 'text', 'image', 'image', 'rect' ), array_column( $draft['design']['sides']['front'], 'type' ) );
		self::assertArrayNotHasKey( 'evil', $draft['design']['sides']['front'][0], 'Unknown keys are dropped.' );
		self::assertSame( '#ff0000', $draft['design']['sides']['front'][0]['fill'] );

		self::assertSame( 200, $this->call( 'GET', '/merchboard/v1/me/designer/assets/' . $own )->get_status() );
		self::assertSame( 'image/png', $this->call( 'GET', '/merchboard/v1/me/designer/assets/' . $this->clipart )->get_headers()['Content-Type'] );

		$listed = $this->data( $this->call( 'GET', '/merchboard/v1/me/designer' ) );
		self::assertSame( array( array( $product, 'Stream tee', 'draft' ) ), array_map( static fn( array $row ): array => array( $row['id'], $row['title'], $row['status'] ), $listed['products'] ) );
	}

	public function test_only_a_valid_design_with_the_creator_s_own_files_is_stored(): void {
		wp_set_current_user( $this->other );
		$foreign = (int) $this->upload( AlertImagesTest::png( 10, 10 ) )->get_data()['id'];
		wp_set_current_user( $this->user );
		$product = (int) $this->call( 'POST', '/merchboard/v1/me/designer/products', array( 'base_id' => $this->base ) )->get_data()['id'];
		$layer   = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM %i WHERE kind = %s', $this->table( Tables::DESIGNER_ASSETS ), 'layer' ) );

		$refusals = array(
			'design_invalid' => array(
				$this->draft( array( array_merge( self::text(), array( 'font' => 'Comic Sans MS' ) ) ) ),
				$this->draft( array( self::image( $foreign ) ) ),
				$this->draft( array( self::image( $layer ) ) ),
				$this->draft( array_fill( 0, 101, self::rect() ) ),
				array_merge( $this->draft( array() ), array( 'design' => array( 'version' => 1, 'sides' => array( 'back' => array() ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				$this->draft(
					array(
					array_merge(
						self::rect(),
						array(
						'fill'   => 'red',
						'stroke' => null,
						)
					),
					)
				),
				$this->draft( array( array_merge( self::text(), array( 'size' => 99999 ) ) ) ),
			),
			'design_title'   => array( array_merge( $this->draft( array() ), array( 'title' => ' ' ) ) ),
			'design_profit'  => array( array_merge( $this->draft( array() ), array( 'profit_value' => '-1' ) ), array_merge( $this->draft( array() ), array( 'profit_kind' => 'share' ) ) ),
			'design_options' => array( array_merge( $this->draft( array() ), array( 'colors' => array( 'green' ) ) ) ),
		);

		foreach ( $refusals as $reason => $bodies ) {
			foreach ( $bodies as $body ) {
				$response = $this->call( 'PUT', '/merchboard/v1/me/designer/products/' . $product, $body );
				self::assertSame( array( 400, $reason ), array( $response->get_status(), $response->get_data()['data']['reason'] ?? null ) );
			}
		}

		self::assertSame( array(), $this->call( 'GET', '/merchboard/v1/me/designer/products/' . $product )->get_data()['draft']['design']['sides'], 'Nothing refused was stored.' );
		self::assertSame( 404, $this->call( 'GET', '/merchboard/v1/me/designer/assets/' . $foreign )->get_status(), 'Another creator\'s upload.' );
		self::assertSame( 200, $this->call( 'GET', '/merchboard/v1/me/designer/assets/' . $layer )->get_status(), 'Template layers are visible.' );
	}

	public function test_another_creator_never_sees_or_changes_a_product_and_a_draft_can_be_deleted(): void {
		wp_set_current_user( $this->user );
		$product = (int) $this->call( 'POST', '/merchboard/v1/me/designer/products', array( 'base_id' => $this->base ) )->get_data()['id'];
		$own     = (int) $this->upload( AlertImagesTest::png( 10, 10 ) )->get_data()['id'];

		wp_set_current_user( $this->other );
		self::assertSame( 404, $this->call( 'GET', '/merchboard/v1/me/designer/products/' . $product )->get_status() );
		self::assertSame( 404, $this->call( 'PUT', '/merchboard/v1/me/designer/products/' . $product, $this->draft( array() ) )->get_status() );
		self::assertSame( 404, $this->call( 'DELETE', '/merchboard/v1/me/designer/products/' . $product )->get_status() );
		self::assertSame( 404, $this->call( 'DELETE', '/merchboard/v1/me/designer/uploads/' . $own )->get_status() );
		self::assertSame( 404, $this->call( 'POST', '/merchboard/v1/me/designer/products', array( 'base_id' => 99999 ) )->get_status() );

		wp_set_current_user( $this->user );
		self::assertSame( array(), $this->data( $this->call( 'DELETE', '/merchboard/v1/me/designer/uploads/' . $own ) )['uploads'] );
		self::assertSame( array(), $this->data( $this->call( 'DELETE', '/merchboard/v1/me/designer/products/' . $product ) )['products'] );
		self::assertSame( 0, $this->count_rows( Tables::DESIGNER_PRODUCTS ) );
		self::assertSame( 0, $this->count_rows( Tables::DESIGNER_VERSIONS ) );

		$audit = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT action FROM %i WHERE action LIKE %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), 'designer_product_%' ) );
		self::assertSame( array( 'designer_product_created', 'designer_product_deleted' ), $audit );

		wp_set_current_user( $this->create_user( 'not-a-creator' ) );
		self::assertSame( 403, $this->call( 'GET', '/merchboard/v1/me/designer' )->get_status() );
	}

	/**
	 * A draft body.
	 *
	 * @param list<array<string, mixed>> $objects Objects on the front.
	 * @return array<string, mixed>
	 */
	private function draft( array $objects ): array {
		return array(
			'title'        => 'Stream tee',
			'description'  => 'Soft.',
			'colors'       => array( 'sand', 'black', 'green' ),
			'sizes'        => array( 'L', 'M' ),
			'profit_kind'  => 'percent',
			'profit_value' => '20',
			'design'       => array(
				'version' => 1,
				'sides'   => array() === $objects ? array() : array( 'front' => $objects ),
			),
		);
	}

	/**
	 * The common fields of an object.
	 *
	 * @param string $type Type.
	 * @return array<string, mixed>
	 */
	private static function base_object( string $type ): array {
		return array(
			'type'    => $type,
			'id'      => 'o-' . $type,
			'x'       => 1000,
			'y'       => 800.5,
			'width'   => 400,
			'height'  => 200,
			'angle'   => 0,
			'scaleX'  => 1,
			'scaleY'  => 1,
			'opacity' => 1,
		);
	}

	/**
	 * A text object.
	 *
	 * @return array<string, mixed>
	 */
	private static function text(): array {
		return array_merge(
			self::base_object( 'text' ),
			array(
				'text'        => 'Hello',
				'font'        => 'Roboto',
				'weight'      => 700,
				'size'        => 120,
				'fill'        => '#FF0000',
				'lineHeight'  => 1.2,
				'charSpacing' => 0,
				'strokeWidth' => 0,
				'evil'        => '<script>',
			)
		);
	}

	/**
	 * An image object.
	 *
	 * @param int $asset Asset.
	 * @return array<string, mixed>
	 */
	private static function image( int $asset ): array {
		return array_merge( self::base_object( 'image' ), array( 'asset' => $asset ) );
	}

	/**
	 * A rectangle.
	 *
	 * @return array<string, mixed>
	 */
	private static function rect(): array {
		return array_merge(
			self::base_object( 'rect' ),
			array(
				'fill'        => '#00ff00',
				'strokeWidth' => 0,
				'radius'      => 8,
			)
		);
	}

	/**
	 * Uploads an image as the current user.
	 *
	 * @param string $png Bytes.
	 */
	private function upload( string $png ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/merchboard/v1/me/designer/uploads' );
		$request->set_file_params( array( 'file' => AlertImagesTest::upload( $png, 'logo.png' ) ) );

		return rest_do_request( $request );
	}

	/**
	 * Dispatches a request with a JSON body.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $body   JSON body.
	 */
	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * The data of a 200 answer.
	 *
	 * @param WP_REST_Response $response Answer.
	 * @return array<mixed>
	 */
	private function data( WP_REST_Response $response ): array {
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );

		return $data;
	}
}
