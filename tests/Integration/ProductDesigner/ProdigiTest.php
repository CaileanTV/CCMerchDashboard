<?php
/**
 * Prodigi as catalogue and mockups, with a fake HTTP whose answers follow
 * Prodigi's OpenAPI file: nothing here has been run against Prodigi (ADR-084).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerDrafts;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerShop;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerSubmissions;
use MerchBoard\Modules\ProductDesigner\Designs\DesignValidator;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\CreatorUploads;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Providers\CatalogImport;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulMockups;
use MerchBoard\Modules\ProductDesigner\Providers\ProdigiAccount;
use MerchBoard\Modules\ProductDesigner\Providers\ProdigiApi;
use MerchBoard\Modules\ProductDesigner\Providers\ProdigiCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\ProdigiException;
use MerchBoard\Modules\ProductDesigner\Providers\ProdigiMockups;
use MerchBoard\Modules\ProductDesigner\Providers\ProviderTaskRepository;
use MerchBoard\Modules\ProductDesigner\Rest\MeDesignerController;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingDesignerWriter;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_Error;
use WP_REST_Request;

/**
 * The key is encrypted and nothing is called while Prodigi is off; every
 * product is offered, flat, wherever it is made; the mockups replace the
 * browser's, and what was saved at Prodigi is deleted, also after a failure
 * and after a preview of a draft (ADR-102).
 * Prodigi is never asked to produce anything.
 */
#[CoversClass( ProdigiAccount::class )]
#[CoversClass( ProdigiApi::class )]
#[CoversClass( ProdigiCatalog::class )]
#[CoversClass( ProdigiMockups::class )]
final class ProdigiTest extends IntegrationTestCase {

	private const API = 'https://api.prodigi.com/v5';

	private const KEY = 'sandbox_abcdefghijklmnop1234';

	/**
	 * Calls made: [method, url, args].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $calls = array();

	/**
	 * Answers by method and URL (a callable for changing answers).
	 *
	 * @var array<string, mixed>
	 */
	private array $answers = array();

	private ProdigiAccount $account;

	private ProdigiApi $api;

	private DesignerFiles $files;

	private BaseProductRepository $bases;

	private DesignerProductRepository $products;

	private FakeJobQueue $queue;

	/**
	 * The creator's user of the last design.
	 *
	 * @var int
	 */
	private int $creator_user = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		DesignerFiles::remove_all();
		wp_set_current_user( 1 );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => true, Settings::PRODIGI_ENABLED => true ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->account = new ProdigiAccount( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );

		$this->account->set( self::KEY );

		$this->api      = new ProdigiApi(
			$this->account,
			function ( string $method, string $url, array $args ): array|WP_Error {
				$this->calls[] = array( $method, $url, $args );
				$answer        = $this->answers[ $method . ' ' . $url ] ?? null;
				$answer        = is_callable( $answer ) ? $answer() : $answer;

				return is_array( $answer ) ? $answer : new WP_Error( 'http_request_failed', 'No route.' );
			}
		);
		$this->files    = new DesignerFiles( static fn( string $from, string $to ): bool => rename( $from, $to ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test files.
		$this->bases    = new BaseProductRepository( $this->wpdb );
		$this->products = new DesignerProductRepository( $this->wpdb );
		$this->queue    = new FakeJobQueue();
		$this->answers  = $this->prodigi_answers();
	}

	protected function tearDown(): void {
		delete_option( ProdigiAccount::KEY_OPTION );
		delete_transient( ProdigiCatalog::CATEGORIES );
		delete_transient( ProdigiCatalog::PRODUCTS . md5( '' ) );
		delete_transient( ProdigiCatalog::PRODUCTS . md5( 'wall-art' ) );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => false, Settings::PRODIGI_ENABLED => false ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		DesignerFiles::remove_all();
		parent::tearDown();
	}

	public function test_the_key_is_encrypted_never_audited_and_must_be_a_v5_key(): void {
		foreach ( array( 'test_abcdefghijklmnop1234', 'live_short', 'with spaces in it but long enough' ) as $invalid ) {
			try {
				$this->account->set( $invalid );
				self::fail( 'Invalid key.' );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::PRODIGI_KEY, $e->reason() );
			}
		}

		self::assertSame( self::KEY, $this->account->key() );
		self::assertStringNotContainsString( self::KEY, (string) get_option( ProdigiAccount::KEY_OPTION ) );

		// The dashboard shows a live key without its mode (2026-11-08).
		self::assertTrue( $this->account->set( ' 74e60b80-0000-4000-8000-000000000000 ' ) );
		self::assertSame( 'live_74e60b80-0000-4000-8000-000000000000', $this->account->key() );
		self::assertTrue( $this->account->set( self::KEY ) );
		self::assertStringNotContainsString( self::KEY, (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ) );
		self::assertFalse( $this->account->set( '' ), 'An empty field keeps the key.' );

		$this->account->clear();
		self::assertNull( $this->account->key() );
		self::assertFalse( $this->api->available() );
	}

	public function test_nothing_is_called_while_prodigi_is_off(): void {
		Settings::save( array_merge( Settings::all(), array( Settings::PRODIGI_ENABLED => false ) ) );

		try {
			$this->api->search( 'tee' );
			self::fail( 'Prodigi is off.' );
		} catch ( ProdigiException $e ) {
			self::assertSame( ProdigiException::NOT_CONFIGURED, $e->reason() );
		}

		self::assertSame( array(), $this->calls );
	}

	public function test_calls_carry_the_key_and_a_429_waits(): void {
		$this->answers[ 'GET ' . self::API . '/catalogue/products/prod-tee' ] = self::answer( array(), 429, array( 'retry-after' => '30' ) );

		try {
			$this->api->product( 'prod-tee' );
			self::fail( 'A 429 stands.' );
		} catch ( ProdigiException $e ) {
			self::assertSame( ProdigiException::RATE_LIMITED, $e->reason() );
			self::assertSame( 30, $e->retry_after );
		}

		self::assertSame( self::KEY, $this->calls[0][2]['headers']['X-API-Key'] ?? null );
		self::assertSame( 0, $this->calls[0][2]['redirection'] ?? null );
	}

	public function test_every_product_is_listed_and_offered_flat_wherever_it_is_made(): void {
		$catalog = new ProdigiCatalog( $this->api );

		delete_transient( 'merchboard_prodigi_search_' . md5( 'tee|' ) );
		self::assertSame( array( 'prod-tee', 'prod-poster' ), array_column( $catalog->search( 'tee' ), 'id' ), 'The UK poster is listed too.' );
		self::assertCount( 1, $this->calls, 'One call: no product is looked up for a region.' );
		self::assertSame( array( 'prod-tee', 'prod-poster' ), array_column( $catalog->search( 'tee' ), 'id' ) );
		self::assertCount( 1, $this->calls, 'A search is cached.' );

		$choice = $catalog->choice( 'prod-tee' );

		self::assertTrue( $choice['flat'] );
		self::assertSame( array( 'Black', 'White' ), array_column( $choice['colors'], 'name' ) );
		self::assertSame( array( 'S', 'M', 'XL' ), $choice['sizes'], 'The UK SKU is offered too.' );
		self::assertSame( 'TEE-S', $choice['variants']['White']['S'] ?? null );
		self::assertSame( array( 'front', 51, 76, 100 ), array( $choice['sides'][0]['placement'], $choice['sides'][0]['width_mm'], $choice['sides'][0]['height_mm'], $choice['sides'][0]['dpi'] ) );
	}

	public function test_a_product_without_print_areas_is_refused(): void {
		$this->answers[ 'GET ' . self::API . '/catalogue/products/prod-poster/variants?limit=100&offset=0' ] = self::answer( array( 'data' => array( array( 'id' => 'POSTER-A4', 'manufacturingRegions' => array( 'GB' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		try {
			( new ProdigiCatalog( $this->api ) )->choice( 'prod-poster' );
			self::fail( 'No print area.' );
		} catch ( ProdigiException $e ) {
			self::assertSame( ProdigiException::INVALID, $e->reason() );
		}
	}

	public function test_a_base_product_is_a_white_sheet_in_the_shape_of_the_print_area(): void {
		$id   = $this->import();
		$view = $this->bases->find( $id )->views[0] ?? null;

		self::assertSame( CatalogImport::READY, $this->bases->import( $id )['state'] ?? null, (string) wp_json_encode( $this->bases->import( $id ) ) );
		self::assertNotNull( $view );
		self::assertTrue( $view->flat );
		self::assertSame( array( 'background' ), array_keys( $view->layers ) );
		self::assertSame( array( 1000, 1490 ), array( $view->width, $view->height ) );
		self::assertSame( array( 'x' => 0, 'y' => 0, 'width' => 1000, 'height' => 1490 ), $view->area ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array(), array_values( array_filter( $this->calls, static fn( array $call ): bool => 'GET' !== $call[0] ) ), 'An import only reads.' );
	}

	public function test_the_mockups_replace_the_browser_s_and_nothing_stays_at_prodigi(): void {
		$version = $this->submit();
		$mockups = $this->mockups();

		$mockups->submitted( $version );
		$this->drain( $mockups );

		self::assertSame( PrintfulMockups::READY, $this->products->version( $version )['mockup_state'] ?? null );

		$files = SubmissionFiles::decode( (string) ( $this->products->version( $version )['files'] ?? '' ) );

		self::assertSame( 300, $files['mockups']['front']['white']['width'] ?? 0 );

		$body = json_decode( (string) ( $this->call( 'POST', self::API . '/products' )[2]['body'] ?? '' ), true );

		self::assertSame( 'prod-tee', $body['catalogueProductSlug'] ?? null );
		self::assertSame( array( 'catalogueVariantId' => 'TEE-S', 'attributes' => array( 'color' => 'White' ) ), $body['variants'][0] ?? null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$ingest = json_decode( (string) ( $this->call( 'POST', self::API . '/assets:ingest' )[2]['body'] ?? '' ), true );

		self::assertStringContainsString( '/designer-print/' . $version . '/front', urldecode( (string) ( $ingest['url'] ?? '' ) ) );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/products/saved1' ) );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/assets/asset1' ) );
		$this->assert_never_ordered();
	}

	public function test_each_colour_design_has_a_saved_product_with_its_own_artwork(): void {
		$version = $this->submit( true );
		$mockups = $this->mockups();

		$mockups->submitted( $version );
		$this->drain( $mockups );

		self::assertSame( PrintfulMockups::READY, $this->products->version( $version )['mockup_state'] ?? null );

		$bodies = array_map( static fn( array $call ): array => (array) json_decode( (string) ( $call[2]['body'] ?? '' ), true ), array_values( array_filter( $this->calls, static fn( array $call ): bool => 'POST' === $call[0] && self::API . '/products' === $call[1] ) ) );
		$ingest = array_map( static fn( array $call ): string => urldecode( (string) ( json_decode( (string) ( $call[2]['body'] ?? '' ), true )['url'] ?? '' ) ), array_values( array_filter( $this->calls, static fn( array $call ): bool => 'POST' === $call[0] && self::API . '/assets:ingest' === $call[1] ) ) );

		self::assertSame( array( array( 'Black' ), array( 'White' ) ), array_map( static fn( array $body ): array => array_map( static fn( array $variant ): string => (string) ( $variant['attributes']['color'] ?? '' ), (array) ( $body['variants'] ?? array() ) ), $bodies ), 'Black on the shared design, white on its own.' );
		self::assertCount( 2, $ingest );
		self::assertStringContainsString( '/designer-print/' . $version . '/front&', $ingest[0] );
		self::assertStringContainsString( '/designer-print/' . $version . '/front@d1', $ingest[1] );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/products/saved1' ) );
		$this->assert_never_ordered();
	}

	public function test_what_was_saved_is_deleted_after_a_failure_and_the_browser_s_images_stay(): void {
		$version = $this->submit();
		$mockups = $this->mockups();

		$this->answers[ 'GET ' . self::API . '/assets/asset1/mockups/mock1' ] = self::answer( array( 'data' => array( 'status' => 'failed' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$mockups->submitted( $version );
		$this->drain( $mockups );

		self::assertSame( PrintfulMockups::FAILED, $this->products->version( $version )['mockup_state'] ?? null );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/products/saved1' ) );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/assets/asset1' ) );

		$files = SubmissionFiles::decode( (string) ( $this->products->version( $version )['files'] ?? '' ) );

		self::assertSame( 1000, $files['mockups']['front']['white']['width'] ?? 0 );
	}

	public function test_the_whole_catalogue_is_an_overview_by_words_and_category(): void {
		$get = 'GET ' . self::API;

		$this->answers[ $get . '/catalogue/products?limit=100&offset=0' ]                   = self::answer(
			array(
				'data' => array(
					array( 'id' => 'prod-tee', 'name' => 'Classic tee', 'category' => 'apparel/tees' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 'prod-poster', 'name' => 'Art poster', 'category' => 'wall-art/posters' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 'prod-gaming-mats', 'name' => 'Gaming mats', 'category' => '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
			)
		);
		$this->answers[ $get . '/catalogue/products?category=wall-art&limit=100&offset=0' ] = self::answer( array( 'data' => array( array( 'id' => 'prod-poster', 'name' => 'Art poster', 'category' => 'wall-art/posters' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->answers[ $get . '/catalogue/categories?limit=100&offset=0' ]                 = self::answer(
			array(
				'data' => array(
					array( 'id' => 'wall-art', 'name' => 'Wall art' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 'wall-art/posters', 'name' => 'Posters', 'parentId' => 'wall-art' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 'apparel/tees', 'name' => 'T-shirts', 'parentId' => 'apparel' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
			)
		);

		$catalog = new ProdigiCatalog( $this->api );
		$all     = $catalog->browse( '' );

		self::assertSame( array( 'prod-poster', 'prod-tee', 'prod-gaming-mats' ), array_column( $all['products'], 'id' ), 'Every product, by name, also one without a category.' );
		self::assertSame( array( 3, 1, 1 ), array( $all['total'], $all['page'], $all['pages'] ) );
		self::assertSame( array( 'prod-gaming-mats' ), array_column( $catalog->browse( 'GAMING mat' )['products'], 'id' ), 'All the words, in any case.' );
		self::assertSame( array( 'prod-poster' ), array_column( $catalog->browse( '', 'wall-art' )['products'], 'id' ), 'Prodigi\'s own filter with its subcategories.' );

		$calls = count( $this->calls );

		$catalog->browse( 'tee' );
		self::assertCount( $calls, $this->calls, 'The list is cached.' );
		self::assertSame(
			array(
				'apparel/tees'     => 'T-shirts',
				'wall-art'         => 'Wall art',
				'wall-art/posters' => 'Wall art › Posters',
			),
			$catalog->category_labels()
		);
		$this->assert_never_ordered();
	}

	public function test_a_draft_is_previewed_at_prodigi_and_nothing_stays(): void {
		$version = $this->submit( false, false );
		$mockups = $this->mockups();

		$base = $this->bases->find( (int) ( $this->products->version( $version )['base_id'] ?? 0 ) );

		self::assertNotNull( $base );
		self::assertTrue( $mockups->can_preview( $base ) );

		$mockups->start_preview( $version );

		$ingest = json_decode( (string) ( $this->call( 'POST', self::API . '/assets:ingest' )[2]['body'] ?? '' ), true );

		self::assertStringContainsString( '/designer-print/' . $version . '/front', urldecode( (string) ( $ingest['url'] ?? '' ) ), 'The draft\'s print file, signed.' );
		self::assertContains( ProdigiMockups::PREVIEW_HOOK, $this->queue->pending_hooks(), 'An abandoned preview is cleaned later.' );
		self::assertSame( 'pending', $mockups->preview( $version )['status'] );

		$body = json_decode( (string) ( $this->call( 'POST', self::API . '/products' )[2]['body'] ?? '' ), true );

		self::assertSame( array( array( 'catalogueVariantId' => 'TEE-S', 'attributes' => array( 'color' => 'White' ) ) ), $body['variants'] ?? null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertNotNull( $this->call( 'POST', self::API . '/assets/asset1/mockups:fromProduct' ) );

		$calls = count( $this->calls );

		self::assertSame( 'pending', $mockups->preview( $version )['status'] );
		self::assertCount( $calls, $this->calls, 'Prodigi is asked at most every few seconds.' );

		$this->rewind( $version );
		$done = $mockups->preview( $version );

		self::assertSame( 'done', $done['status'] );
		self::assertSame( array( array( 'front', 'white' ) ), array_map( static fn( array $image ): array => array( $image['side'], $image['color'] ), $done['images'] ) );
		self::assertStringStartsWith( 'data:image/png;base64,', $done['images'][0]['url'] );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/products/saved1' ) );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/assets/asset1' ) );
		$again = $mockups->preview( $version );

		self::assertSame( 'done', $again['status'], 'A lost answer can be asked for again.' );
		self::assertSame( $done['images'][0]['url'] ?? null, $again['images'][0]['url'] ?? null );
		self::assertSame( DesignerProductRepository::DRAFT, $this->products->version( $version )['status'] ?? null, 'The draft stays a draft.' );
		$this->assert_never_ordered();
	}

	public function test_a_failed_preview_deletes_what_was_saved(): void {
		$version = $this->submit( false, false );
		$mockups = $this->mockups();

		$this->answers[ 'GET ' . self::API . '/assets/asset1/mockups/mock1' ] = self::answer( array( 'data' => array( 'status' => 'failed' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$mockups->start_preview( $version );
		$mockups->preview( $version );
		$this->rewind( $version );

		try {
			$mockups->preview( $version );
			self::fail( 'The mockup failed.' );
		} catch ( ProdigiException $e ) {
			self::assertSame( ProdigiException::FAILED, $e->reason() );
		}

		self::assertNotNull( $this->call( 'DELETE', self::API . '/products/saved1' ) );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/assets/asset1' ) );
		self::assertFalse( get_transient( ProdigiMockups::PREVIEW . $version ) );
	}

	public function test_a_preview_starts_once_a_minute_and_an_abandoned_one_is_cleaned_by_its_job(): void {
		$version = $this->submit( false, false );
		$mockups = $this->mockups();

		$mockups->start_preview( $version );

		try {
			$mockups->start_preview( $version );
			self::fail( 'Too soon.' );
		} catch ( ProdigiException $e ) {
			self::assertSame( ProdigiException::RATE_LIMITED, $e->reason() );
		}

		$mockups->preview( $version );

		$state = get_transient( ProdigiMockups::PREVIEW . $version );

		self::assertIsArray( $state );
		self::assertSame( 'saved1', $state['product'] ?? null );

		$mockups->clean_preview( $version, (int) $state['started'] - 1 );
		self::assertNull( $this->call( 'DELETE', self::API . '/products/saved1' ), 'A newer preview is left alone.' );

		$mockups->clean_preview( $version, $state['started'] );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/products/saved1' ) );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/assets/asset1' ) );
		self::assertFalse( get_transient( ProdigiMockups::PREVIEW . $version ) );
	}

	public function test_a_429_in_the_middle_of_a_preview_leaves_nothing_behind(): void {
		$version = $this->submit( false, false );
		$mockups = $this->mockups();
		$asked   = 0;

		$this->answers[ 'POST ' . self::API . '/assets/asset1/mockups:fromProduct' ] = static function () use ( &$asked ): array {
			return 1 === ++$asked ? self::answer( array(), 429, array( 'retry-after' => '1' ) ) : self::answer( array( 'data' => array( 'id' => 'mock1', 'status' => 'pending' ) ), 202 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		};

		$mockups->start_preview( $version );
		self::assertSame( 'pending', $mockups->preview( $version )['status'] );
		self::assertSame( 'saved1', get_transient( ProdigiMockups::PREVIEW . $version )['product'] ?? null, 'The saved product is remembered after the 429.' );

		$this->rewind( $version );
		self::assertSame( 'pending', $mockups->preview( $version )['status'] );
		$this->rewind( $version );
		self::assertSame( 'done', $mockups->preview( $version )['status'] );

		$count = static fn( array $calls, string $method, string $url ): int => count( array_filter( $calls, static fn( array $call ): bool => $method === $call[0] && $url === $call[1] ) );

		self::assertSame( 2, $count( $this->calls, 'POST', self::API . '/products' ), 'Started over once.' );
		self::assertSame( 2, $count( $this->calls, 'DELETE', self::API . '/products/saved1' ), 'Both saved products are deleted.' );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/assets/asset1' ) );
	}

	public function test_a_refused_delete_is_tried_again_by_the_job(): void {
		$version = $this->submit( false, false );
		$mockups = $this->mockups();
		$deletes = 0;

		$this->answers[ 'DELETE ' . self::API . '/products/saved1' ] = static function () use ( &$deletes ): array {
			return 1 === ++$deletes ? self::answer( array( 'message' => 'Busy' ), 500 ) : self::answer( array(), 204 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		};

		$mockups->start_preview( $version );
		$mockups->preview( $version );
		$this->rewind( $version );
		self::assertSame( 'done', $mockups->preview( $version )['status'] );
		self::assertNotNull( $this->call( 'DELETE', self::API . '/assets/asset1' ), 'One refused delete does not keep the others.' );
		self::assertSame( array( array( 'products' => array( 'saved1' ) ) ), get_transient( ProdigiMockups::PREVIEW_LEFT . $version ) );
		self::assertContains( array( $version, 0, 1 ), array_column( $this->queue->pending, 'args' ) );

		$mockups->clean_preview( $version, 0, 1 );

		self::assertSame( 2, $deletes );
		self::assertFalse( get_transient( ProdigiMockups::PREVIEW_LEFT . $version ) );
		delete_transient( ProdigiMockups::PREVIEW . $version );
	}

	public function test_the_creator_previews_a_prodigi_draft_through_the_api(): void {
		$version    = $this->submit( false, false );
		$product    = (int) ( $this->products->version( $version )['design_product_id'] ?? 0 );
		$assets     = new DesignerAssetRepository( $this->wpdb );
		$drafts     = new DesignerDrafts( $this->products, $this->bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, $this->files );
		$guard      = new DesignerProductGuard( $this->wpdb, $this->service( ProtectedProductMeta::class ), new RecordingLogger() );
		$controller = new MeDesignerController(
			$this->service( CreatorContext::class ),
			$this->bases,
			$this->products,
			$drafts,
			new DesignerSubmissions( $this->products, $this->bases, $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2 ),
			$assets,
			$this->files,
			new CreatorUploads( $assets, $this->files ),
			new AlertFonts( $this->service( AuditTrail::class ), $this->service( Transaction::class ) ),
			$this->service( Logger::class ),
			new DesignerShop( $this->products, $drafts, $guard, new RecordingDesignerWriter( $guard, new ProductPosts( $this->wpdb ) ), $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->files, $this->bases ),
			null,
			$this->mockups()
		);
		$register   = static function () use ( $controller ): void {
			$controller->register_routes();
		};

		add_action( 'rest_api_init', $register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server for the test.
		rest_get_server();
		wp_set_current_user( $this->creator_user );

		try {
			$route = '/merchboard/v1/me/designer/products/' . $product . '/preview';
			$bases = rest_do_request( new WP_REST_Request( 'GET', '/merchboard/v1/me/designer' ) )->get_data()['bases'] ?? array();

			self::assertSame( array( true ), array_column( $bases, 'preview' ), 'A Prodigi base product has the finished product.' );
			self::assertSame( 200, rest_do_request( new WP_REST_Request( 'POST', $route ) )->get_status() );

			$again = rest_do_request( new WP_REST_Request( 'POST', $route ) );

			self::assertSame( array( 400, 'preview_busy' ), array( $again->get_status(), $again->get_data()['data']['reason'] ?? null ) );
			self::assertSame( 'pending', rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data()['status'] ?? null );

			$this->rewind( $version );
			$done = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data();

			self::assertSame( 'done', $done['status'] ?? null );
			self::assertCount( 1, $done['images'] ?? array() );
			self::assertStringNotContainsString( 'prodigi', strtolower( (string) wp_json_encode( $done ) ), 'The provider is never named to the creator.' );
		} finally {
			remove_action( 'rest_api_init', $register );
			$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server after the test.
		}
	}

	/**
	 * Neither orders nor quotes nor sales channels.
	 */
	private function assert_never_ordered(): void {
		foreach ( $this->calls as $call ) {
			self::assertDoesNotMatchRegularExpression( '#/v5/(orders|quotes|sales-channels|billing|merchant)#', $call[1] );
		}
	}

	/**
	 * The base product service.
	 */
	private function base_service(): BaseProductService {
		return new BaseProductService( $this->bases, new DesignerAssetRepository( $this->wpdb ), $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );
	}

	/**
	 * Imports the tee in white (and black), sizes S and M.
	 *
	 * @param bool $black Also in black.
	 */
	private function import( bool $black = false ): int {
		$catalog = new CatalogImport( $this->wpdb, $this->bases, $this->base_service(), $this->files, $this->queue, $this->service( Logger::class ), 2, 'https://shop.example/assets/', null, null, new ProdigiCatalog( $this->api ) );
		$id      = $catalog->create(
			'prodigi',
			'prod-tee',
			array(
				'colors'           => $black ? array( 'Black', 'White' ) : array( 'White' ),
				'sizes'            => array( 'S', 'M' ),
				'sides'            => array( 'front' ),
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
	 * A submitted design on the tee, or its draft with its files.
	 *
	 * @param bool $black_own Black is chosen too, and white gets a design of its own (ADR-096).
	 * @param bool $send      Sent for approval; false keeps the draft (the preview, ADR-102).
	 * @return int The version.
	 */
	private function submit( bool $black_own = false, bool $send = true ): int {
		$base = $this->import( $black_own );

		$this->base_service()->set_active( $base, true );

		$user    = $this->create_user( 'prodigi-creator' );
		$creator = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $user );

		$this->creator_user = $user;
		$drafts             = new DesignerDrafts( $this->products, $this->bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, $this->files );
		$submissions        = new DesignerSubmissions( $this->products, $this->bases, $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2 );
		$product            = $drafts->create( $creator, $base );

		$drafts->save(
			$creator,
			$product,
			array(
				'title'        => 'Tee',
				'description'  => '',
				'colors'       => $black_own ? array( 'black', 'white' ) : array( 'white' ),
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
				) + ( $black_own ? array(
					'designs' => array(
						array(
							'id'     => 'd1',
							'colors' => array( 'white' ),
							'sides'  => array( 'front' => array( array( 'type' => 'rect', 'id' => 'r2', 'x' => 10, 'y' => 10, 'width' => 20, 'height' => 20, 'angle' => 0, 'scaleX' => 1, 'scaleY' => 1, 'opacity' => 1, 'fill' => '#0000ff', 'strokeWidth' => 0, 'radius' => 0 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						),
					),
				) : array() ),
			),
			new DesignValidator( static fn(): bool => false, array() )
		);

		$view = $this->bases->find( $base )->views[0] ?? null;

		self::assertNotNull( $view );

		[ $width, $height ] = $view->print_pixels();

		$submissions->attach( $creator, $product, 'print:front', AlertImagesTest::upload( AlertImagesTest::png( $width, $height ), 'print.png' ) );
		$submissions->attach( $creator, $product, 'mockup:front:white', AlertImagesTest::upload( AlertImagesTest::png( $view->width, $view->height ), 'mockup.png' ) );

		if ( $black_own ) {
			$submissions->attach( $creator, $product, 'print:front:d1', AlertImagesTest::upload( AlertImagesTest::png( $width, $height ), 'print.png' ) );
			$submissions->attach( $creator, $product, 'mockup:front:black', AlertImagesTest::upload( AlertImagesTest::png( $view->width, $view->height ), 'mockup.png' ) );
		}
		if ( ! $send ) {
			return (int) ( $this->products->draft( $product )['id'] ?? 0 );
		}

		$submissions->submit( $creator, $product );

		return (int) ( $this->products->pending( $product )['id'] ?? 0 );
	}

	/**
	 * Lets a preview look at Prodigi again at once.
	 *
	 * @param int $version Draft version.
	 */
	private function rewind( int $version ): void {
		$state = get_transient( ProdigiMockups::PREVIEW . $version );

		self::assertIsArray( $state );
		$state['checked'] = 0;
		set_transient( ProdigiMockups::PREVIEW . $version, $state, HOUR_IN_SECONDS );
	}

	/**
	 * The mockup service.
	 */
	private function mockups(): ProdigiMockups {
		return new ProdigiMockups( $this->wpdb, $this->products, $this->bases, $this->api, new ProviderTaskRepository( $this->wpdb ), $this->files, $this->queue, $this->service( Logger::class ) );
	}

	/**
	 * Runs the queued jobs.
	 *
	 * @param ProdigiMockups $mockups The service.
	 */
	private function drain( ProdigiMockups $mockups ): void {
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
	 * Prodigi's answers for a tee (one size made only in the UK) and a poster made in the UK.
	 *
	 * @return array<string, mixed>
	 */
	private function prodigi_answers(): array {
		$get   = 'GET ' . self::API;
		$post  = 'POST ' . self::API;
		$areas = array(
			array(
				'name'           => 'front',
				'required'       => true,
				'widthMm'        => 51,
				'heightMm'       => 76,
				'recommendedDpi' => 100,
			),
		);

		return array(
			$get . '/catalogue/search?q=tee&limit=100'   => self::answer(
				array(
					'data' => array(
						array( 'id' => 'prod-tee', 'name' => 'Classic tee', 'category' => 'apparel/tees' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'id' => 'prod-poster', 'name' => 'Poster', 'category' => 'wall-art' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				)
			),
			$get . '/catalogue/products/prod-tee'        => self::answer(
				array(
					'data' => array(
						'id'                   => 'prod-tee',
						'name'                 => 'Classic tee',
						'description'          => 'A tee.',
						'manufacturingRegions' => array( 'GB', 'EU' ),
					),
				)
			),
			$get . '/catalogue/products/prod-poster'     => self::answer(
				array(
					'data' => array(
						'id'                   => 'prod-poster',
						'name'                 => 'Poster',
						'manufacturingRegions' => array( 'GB' ),
					),
				)
			),
			$get . '/catalogue/products/prod-tee/variants?limit=100&offset=0' => self::answer(
				array(
					'data' => array(
						array(
							'id'                   => 'TEE-S',
							'attributes'           => array( 'size' => 'S' ),
							'attributeOptions'     => array( 'color' => array( 'Black', 'White' ) ),
							'printAreas'           => $areas,
							'manufacturingRegions' => array( 'EU' ),
						),
						array(
							'id'                   => 'TEE-M',
							'attributes'           => array( 'size' => 'M' ),
							'attributeOptions'     => array( 'color' => array( 'Black', 'White' ) ),
							'printAreas'           => $areas,
							'manufacturingRegions' => array( 'EU' ),
						),
						array(
							'id'                   => 'TEE-XL',
							'attributes'           => array( 'size' => 'XL' ),
							'attributeOptions'     => array( 'color' => array( 'Black', 'White' ) ),
							'printAreas'           => $areas,
							'manufacturingRegions' => array( 'GB' ),
						),
					),
				)
			),
			$post . '/assets:ingest'                     => self::answer( array( 'data' => array( 'id' => 'asset1', 'status' => 'pending' ) ), 201 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$get . '/assets/asset1'                      => self::answer( array( 'data' => array( 'id' => 'asset1', 'status' => 'ready' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$post . '/products'                          => self::answer( array( 'data' => array( 'id' => 'saved1' ) ), 201 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$get . '/products/saved1/variants?limit=100&offset=0' => self::answer(
				array(
					'data' => array(
						array(
							'id'                 => 'v1',
							'catalogueVariantId' => 'TEE-S',
							'attributes'         => array( 'color' => 'White' ),
						),
					),
				)
			),
			$post . '/products/saved1/assets'            => self::answer( array( 'data' => array( 'bindingId' => 'b1' ) ), 201 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$post . '/assets/asset1/mockups:fromProduct' => self::answer( array( 'data' => array( 'id' => 'mock1', 'status' => 'pending' ) ), 202 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$get . '/assets/asset1/mockups/mock1'        => self::answer(
				array(
					'data' => array(
						'status' => 'completed',
						'result' => array( 'url' => 'https://cdn.example/mock.png' ),
					),
				)
			),
			'GET https://cdn.example/mock.png'           => array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => AlertImagesTest::png( 300, 300 ),
			),
			'DELETE ' . self::API . '/products/saved1'   => self::answer( array(), 204 ),
			'DELETE ' . self::API . '/assets/asset1'     => self::answer( array(), 204 ),
		);
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
}
