<?php
/**
 * Approval, the apply pipeline, a new base price and a retired base product
 * on a real database (steps 30.10 and 30.11).
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
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerVersionFiles;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerApply;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerDrafts;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerRepricing;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerRetirement;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerReview;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerSubmissions;
use MerchBoard\Modules\ProductDesigner\Designs\DesignValidator;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\CreatorUploads;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Rest\MeDesignerController;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerMedia;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerOrderFiles;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingDesignerWriter;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_Error;
use WP_REST_Response;
use wpdb;

/**
 * A submitted design is approved with a frozen price and applied in steps
 * that can be repeated (an interrupted apply continues and imports no
 * mockup twice), or rejected with a note into a new draft. A live product
 * gets changes the same way; the change supersedes the live version. The
 * order line keeps the sold version, and only the administrator gets its
 * print files. A new base price reaches the shop (the creator's percent
 * stays) without making waiting designs stale, and a sale is settled at the
 * price the shop had; a retired base product takes its products out of the
 * shop and rejects its waiting designs.
 */
#[CoversClass( DesignerReview::class )]
#[CoversClass( DesignerApply::class )]
#[CoversClass( DesignerMedia::class )]
#[CoversClass( DesignerOrderFiles::class )]
#[CoversClass( DesignerVersionFiles::class )]
#[CoversClass( DesignerRepricing::class )]
#[CoversClass( DesignerRetirement::class )]
#[CoversClass( DesignerPrices::class )]
#[CoversClass( MeDesignerController::class )]
final class DesignerReviewTest extends IntegrationTestCase {

	private DesignerFiles $files;

	private DesignerProductRepository $products;

	private DesignerSubmissions $submissions;

	private DesignerDrafts $drafts;

	private DesignerReview $review;

	private DesignerApply $apply;

	private RecordingDesignerWriter $writer;

	private DesignerProductGuard $guard;

	private ProductPosts $posts;

	private FakeJobQueue $queue;

	private Creator $creator;

	private BaseProductService $bases;

	private DesignerRepricing $repricing;

	private DesignerRetirement $retirement;

	/**
	 * The base product hooks of this test.
	 *
	 * @var list<array{0: string, 1: callable}>
	 */
	private array $hooks = array();

	private int $base;

	/**
	 * Notifications fired.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $notified = array();

	/**
	 * Links made.
	 *
	 * @var list<array{0: int, 1: int}>
	 */
	private array $links = array();

	/**
	 * The notification listener.
	 *
	 * @var callable
	 */
	private $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		DesignerFiles::remove_all();
		wp_set_current_user( 1 );

		$this->files    = new DesignerFiles( static fn( string $from, string $to ): bool => rename( $from, $to ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test upload move.
		$assets         = new DesignerAssetRepository( $this->wpdb );
		$bases          = new BaseProductRepository( $this->wpdb );
		$this->products = new DesignerProductRepository( $this->wpdb );
		$this->posts    = new ProductPosts( $this->wpdb );
		$this->queue    = new FakeJobQueue();
		$this->guard    = new DesignerProductGuard( $this->wpdb, $this->service( ProtectedProductMeta::class ), new RecordingLogger() );
		$this->writer   = new RecordingDesignerWriter( $this->guard, $this->posts );
		$service        = new BaseProductService( $bases, $assets, $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, 'DKK' );
		$this->bases    = $service;

		$this->base = $service->save(
			null,
			array(
				'name'        => 'Organic tee',
				'description' => '',
				'base_price'  => '149,95',
				'category_id' => '0',
				'colors'      => "Black #111111\nSand #d8c8a8",
				'sizes'       => 'S, M',
			),
			array(
				array(
					'key'       => '',
					'name'      => 'Front',
					'x'         => '100',
					'y'         => '100',
					'width'     => '200',
					'height'    => '200',
					'width_mm'  => '30',
					'height_mm' => '40',
					'dpi'       => '100',
					'remove'    => false,
					'clear'     => array(),
					'uploads'   => array( 'color' => AlertImagesTest::upload( AlertImagesTest::png( 400, 400 ), 'color.png' ) ),
				),
			)
		);
		$service->set_active( $this->base, true );

		$user          = $this->create_user( 'review-creator' );
		$created       = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $user );
		$this->creator = $created;

		$this->drafts      = new DesignerDrafts( $this->products, $bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2, $this->files );
		$this->submissions = new DesignerSubmissions( $this->products, $bases, $this->files, $this->service( AuditTrail::class ), $this->service( Transaction::class ), 2 );
		$this->apply       = new DesignerApply(
			$this->wpdb,
			$this->products,
			$bases,
			$this->service( CreatorRepository::class ),
			new CreatorCategoryRepository( $this->wpdb ),
			$this->writer,
			new DesignerMedia( $this->files ),
			function ( int $product, int $creator ): array {
				$this->links[] = array( $product, $creator );

				return array();
			},
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			$this->service( Logger::class ),
			2,
			fn( int $product ) => $this->repricing->schedule( $product ),
			function ( int $version ): void {
				$this->queue->enqueue( DesignerApply::HOOK, array( $version ), 30 );
			}
		);
		$this->repricing   = new DesignerRepricing( $this->wpdb, $this->products, $bases, $this->guard, $this->writer, $this->queue, $this->service( AuditTrail::class ), $this->service( Logger::class ), 2 );
		$this->retirement  = new DesignerRetirement( $this->wpdb, $this->products, $this->guard, $this->writer, $this->queue, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( Logger::class ) );
		$this->hooks       = array(
			array( BaseProductService::PRICE_CHANGED, fn( mixed $base ) => $this->repricing->base_changed( $base ) ),
			array( BaseProductService::RETIRED, fn( mixed $base ) => $this->retirement->base_retired( $base ) ),
		);

		foreach ( $this->hooks as [ $hook, $callback ] ) {
			add_action( $hook, $callback );
		}

		$this->review = new DesignerReview( $this->products, $bases, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->queue, 2, fn( int $version ) => $this->apply->run( $version ) );

		$this->listener = function ( mixed $type, mixed $creator, mixed $data ): void {
			$this->notified[] = array( (string) $type, is_array( $data ) ? $data : array() );
		};
		add_action( 'merchboard_notify', $this->listener, 1, 3 );
	}

	protected function tearDown(): void {
		remove_action( 'merchboard_notify', $this->listener, 1 );

		foreach ( $this->hooks as [ $hook, $callback ] ) {
			remove_action( $hook, $callback );
		}

		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => DesignerMedia::META ) ) as $attachment ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test cleanup.
			wp_delete_attachment( (int) $attachment, true );
		}

		$this->posts->remove_all();
		DesignerFiles::remove_all();
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => false ) ) );
		parent::tearDown();
	}

	public function test_an_approved_design_is_put_in_the_shop_and_goes_live(): void {
		$product = $this->submitted();
		$version = $this->pending_id( $product );

		$this->review->approve( $version );

		self::assertSame( array( array( DesignerApply::HOOK, array( $version ) ) ), array_map( static fn( array $job ): array => array( $job['hook'], $job['args'] ), $this->queue->queued ) );
		self::assertSame( array( 'approved', 'queued', '179.9400' ), $this->state( $version ) );

		$this->drain();

		self::assertSame( array( 'approved', 'done', '179.9400' ), $this->state( $version ) );
		$row = $this->products->find( $this->creator->id, $product );
		self::assertNotNull( $row );
		self::assertSame( array( (string) $version, 'live', '179.9400', '149.9500' ), array( (string) $row['live_version_id'], $row['status'], $row['shop_price'], $row['shop_base_price'] ) );
		self::assertNotNull( $this->guard->guard( (int) $row['wc_product_id'] ), 'The shop product is a proven designer product.' );
		self::assertSame( array( array( (int) $row['wc_product_id'], $this->creator->id ) ), $this->links );

		[ $shop, $content ] = $this->writer->contents[0];
		self::assertSame( (int) $row['wc_product_id'], $shop );
		self::assertSame( array( 'Stream tee', false, 1799400, array( 'S', 'M' ), array( 'black', 'sand' ) ), array( $content->name, $content->publish, $content->price_units, $content->sizes, array_column( $content->colors, 'key' ) ) );
		self::assertSame( array( (int) $row['wc_product_id'] ), $this->writer->published, 'Published only after the settlement price is stored (review 30.15).' );
		self::assertCount( 2, $content->images, 'A product image per colour.' );
		self::assertSame( array( 'black', 'sand' ), array_keys( $content->color_images ) );
		self::assertSame( 'attachment', get_post_type( $content->images[0] ) );
		self::assertSame( 'Stream tee', get_post_meta( $content->images[0], '_wp_attachment_image_alt', true ) );

		self::assertSame( array( DesignerNotifications::APPROVED ), $this->types( 'designer_approved', 'designer_change_approved', 'admin_designer_apply_failed' ) );
		self::assertSame( array( 'designer_version_approved', 'designer_version_applied' ), $this->audit( 'designer_version_approved', 'designer_version_applied' ) );

		$this->drain_again( $version );
		self::assertCount( 1, $this->writer->contents, 'A done version is never applied again.' );
	}

	public function test_an_interrupted_apply_continues_where_it_stopped(): void {
		$product                    = $this->submitted();
		$version                    = $this->pending_id( $product );
		$this->writer->fail_content = 1;

		$this->review->approve( $version );
		$this->drain();

		self::assertSame( array( 'approved', 'failed', 'content', 'error' ), array_merge( array_slice( $this->state( $version ), 0, 2 ), $this->failure( $version ) ) );
		self::assertSame( array( DesignerNotifications::APPLY_FAILED ), $this->types( 'admin_designer_apply_failed' ) );
		self::assertNull( $this->products->find( $this->creator->id, $product )['live_version_id'] ?? null, 'Not live yet.' );
		$media = $this->media_count();

		try {
			$this->review->approve( $version );
			self::fail( 'An approved version is not pending.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::NOT_PENDING, $e->reason() );
		}

		$this->review->retry( $version );
		$this->drain();

		self::assertSame( 'done', $this->state( $version )[1] );
		self::assertCount( 1, $this->writer->created, 'The same product, never a second one.' );
		self::assertSame( $media, $this->media_count(), 'No mockup imported twice.' );
		self::assertCount( 1, $this->writer->contents );

		try {
			$this->review->retry( $version );
			self::fail( 'Only a failed version is tried again.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::NOT_PENDING, $e->reason() );
		}
	}

	public function test_a_rejected_design_gets_the_note_and_continues_as_a_draft(): void {
		$product = $this->submitted();
		$version = $this->pending_id( $product );

		foreach ( array( '', str_repeat( 'x', DesignerReview::MAX_NOTE + 1 ) ) as $note ) {
			try {
				$this->review->reject( $version, $note );
				self::fail( 'A note is required.' );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::REVIEW_NOTE, $e->reason() );
			}
		}

		$this->review->reject( $version, '  Please use a sharper <b>logo</b>.  ' );

		$rejection = $this->products->last_rejection( $product );
		self::assertSame( 'Please use a sharper logo.', $rejection['note'] ?? null );
		$draft = $this->products->draft( $product );
		self::assertNotNull( $draft );
		self::assertSame( 2, (int) $draft['number'] );
		self::assertNotNull( $draft['files'], 'The files move to the draft: it can be sent again as it is.' );
		self::assertSame( array(), $this->queue->queued );
		self::assertSame( array( array( DesignerNotifications::REJECTED, 'Please use a sharper logo.' ) ), array_map( static fn( array $n ): array => array( $n[0], $n[1]['note'] ?? '' ), array_values( array_filter( $this->notified, static fn( array $n ): bool => DesignerNotifications::REJECTED === $n[0] ) ) ) );

		$this->submissions->submit( $this->creator, $product );
		self::assertNotNull( $this->products->pending( $product ), 'Sent again unchanged.' );
	}

	public function test_a_live_product_changes_through_a_new_approved_version(): void {
		$product = $this->submitted();
		$first   = $this->pending_id( $product );
		$this->review->approve( $first );
		$this->drain();

		try {
			$this->submissions->change( $this->creator, $product + 1000 );
			self::fail( 'Unknown product.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::DESIGN_UNKNOWN, $e->reason() );
		}

		$this->submissions->change( $this->creator, $product );
		$draft = $this->products->draft( $product );
		self::assertNotNull( $draft );
		self::assertNull( $draft['files'], 'The live version keeps its files.' );

		try {
			$this->submissions->change( $this->creator, $product );
			self::fail( 'One change at a time.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::SUBMISSION_PENDING, $e->reason() );
		}

		$this->save( $product, '30' );
		$this->send_files( $product );
		$this->submissions->submit( $this->creator, $product );
		self::assertContains( DesignerNotifications::CHANGE_SUBMITTED, array_column( $this->notified, 0 ) );

		$second = $this->pending_id( $product );
		$this->review->approve( $second );
		$this->drain();

		self::assertSame( 'superseded', $this->products->version( $first )['status'] ?? null );
		self::assertSame( (string) $second, (string) ( $this->products->find( $this->creator->id, $product )['live_version_id'] ?? '' ) );
		self::assertFalse( $this->writer->contents[1][1]->publish, 'A change keeps the product\'s status.' );
		self::assertCount( 1, $this->writer->published, 'Only the first approval publishes.' );
		self::assertSame( 1949400, $this->writer->contents[1][1]->price_units );
		self::assertContains( DesignerNotifications::CHANGE_APPROVED, array_column( $this->notified, 0 ) );
		self::assertNotNull( $this->products->version( $first )['files'] ?? null, 'Orders of the first version keep its print files.' );
	}

	public function test_an_older_version_tried_again_never_replaces_a_newer_live_one(): void {
		$product = $this->submitted();
		$this->review->approve( $this->pending_id( $product ) );
		$this->drain();

		// Version 2 fails; the creator makes version 3, which goes live.
		$this->submissions->change( $this->creator, $product );
		$this->save( $product, '30' );
		$this->send_files( $product );
		$this->submissions->submit( $this->creator, $product );
		$second                     = $this->pending_id( $product );
		$this->writer->fail_content = 1;
		$this->review->approve( $second );
		$this->drain();
		self::assertSame( 'failed', $this->state( $second )[1] );

		$this->submissions->change( $this->creator, $product );
		$this->save( $product, '40' );
		$this->send_files( $product );
		$this->submissions->submit( $this->creator, $product );
		$third = $this->pending_id( $product );
		$this->review->approve( $third );
		$this->drain();
		self::assertSame( (string) $third, (string) ( $this->products->find( $this->creator->id, $product )['live_version_id'] ?? '' ) );
		self::assertSame( 'superseded', $this->state( $second )[0], 'The newer live version puts the older failed one aside.' );

		// Trying version 2 again is refused: version 3 stays live.
		try {
			$this->review->retry( $second );
			self::fail( 'An older version is never applied over a newer one.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::NOT_PENDING, $e->reason() );
		}

		// Even a job of version 2 that still runs later (queued before) leaves the shop alone.
		$contents = count( $this->writer->contents );
		$this->wpdb->update( $this->table( Tables::DESIGNER_VERSIONS ), array( 'status' => DesignerProductRepository::APPROVED ), array( 'id' => $second ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: the state of a job queued before version 3 went live.
		$this->apply->run( $second );

		self::assertSame( 'superseded', $this->state( $second )[0] );
		self::assertSame( (string) $third, (string) ( $this->products->find( $this->creator->id, $product )['live_version_id'] ?? '' ) );
		self::assertCount( $contents, $this->writer->contents, 'The shop is not written.' );
	}

	public function test_a_stuck_apply_can_be_tried_again_and_a_busy_product_is_queued_again(): void {
		$product = $this->submitted();
		$version = $this->pending_id( $product );
		$this->review->approve( $version );
		$this->queue->queued = array();

		// The job died while running (a fatal error): after ten minutes it may be tried again.
		$this->products->set_apply( $version, DesignerProductRepository::APPLY_RUNNING, 'media', null );
		self::assertFalse( DesignerReview::retryable( (array) $this->products->version( $version ) ) );
		$this->wpdb->update( $this->table( Tables::DESIGNER_VERSIONS ), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DesignerReview::STALE - 5 ) ), array( 'id' => $version ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: the job stopped long ago.
		self::assertTrue( DesignerReview::retryable( (array) $this->products->version( $version ) ) );
		$this->review->retry( $version );

		// Another connection holds the product (a repricing runs): the job is queued again, nothing is written.
		$lock  = 'merchboard_designer_product_' . $product;
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) );
		$queued              = $this->queue->queued;
		$this->queue->queued = array();

		foreach ( $queued as $job ) {
			$this->apply->run( $job['args'][0] ?? 0 );
		}

		self::assertSame( array(), $this->writer->contents );
		self::assertSame( array( array( DesignerApply::HOOK, array( $version ) ) ), array_map( static fn( array $job ): array => array( $job['hook'], $job['args'] ), $this->queue->queued ) );
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		$other->close();

		$this->drain();
		self::assertSame( 'done', $this->state( $version )[1] );
	}

	public function test_a_renamed_colour_or_a_removed_size_makes_waiting_designs_outdated(): void {
		$product = $this->submitted();
		$version = $this->pending_id( $product );
		$before  = $this->bases_version();
		$fields  = $this->base_fields( '149,95' );

		$fields['colors'] = "Black #111111\nSand #d8c8a8\nRed #ff0000";
		$this->bases->save( $this->base, $fields, $this->base_views() );
		self::assertSame( $before, $this->bases_version(), 'A new colour does not.' );

		$fields['colors'] = "Jet black #111111\nSand #d8c8a8";
		$this->bases->save( $this->base, $fields, $this->base_views() );
		self::assertSame( $before + 1, $this->bases_version(), 'A renamed colour does.' );

		try {
			$this->review->approve( $version );
			self::fail( 'Outdated.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::DESIGN_STALE, $e->reason() );
		}

		$fields['sizes'] = 'S';
		$this->bases->save( $this->base, $fields, $this->base_views() );
		self::assertSame( $before + 2, $this->bases_version(), 'A removed size does.' );
	}

	public function test_a_design_on_a_changed_base_product_cannot_be_approved(): void {
		$product = $this->submitted();
		$version = $this->pending_id( $product );
		$this->execute( 'UPDATE %i SET version = version + 1 WHERE id = %d', $this->table( Tables::DESIGNER_BASES ), $this->base );

		try {
			$this->review->approve( $version );
			self::fail( 'The base product changed.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::DESIGN_STALE, $e->reason() );
		}

		wp_set_current_user( $this->create_user( 'no-designer-rights' ) );

		try {
			$this->review->reject( $version, 'No.' );
			self::fail( 'Only an administrator decides.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::NOT_AUTHORIZED, $e->reason() );
		}

		self::assertSame( 'pending', $this->state( $version )[0] );
	}

	public function test_the_order_line_keeps_the_sold_version_and_only_the_administrator_gets_its_print_files(): void {
		$product = $this->submitted();
		$version = $this->pending_id( $product );
		$this->review->approve( $version );
		$this->drain();
		$shop  = (int) ( $this->products->find( $this->creator->id, $product )['wc_product_id'] ?? 0 );
		$files = new DesignerOrderFiles( $this->products, $this->guard, new RecordingLogger() );
		$line  = new class( $shop ) {
			/**
			 * Meta.
			 *
			 * @var array<string, mixed>
			 */
			public array $meta = array();

			/**
			 * Constructor.
			 *
			 * @param int $product Product.
			 */
			public function __construct( private int $product ) {
			}

			/**
			 * The product.
			 */
			public function get_product_id(): int {
				return $this->product;
			}

			/**
			 * Adds meta.
			 *
			 * @param string $key    Key.
			 * @param mixed  $value  Value.
			 * @param bool   $unique Unique.
			 */
			public function add_meta_data( string $key, mixed $value, bool $unique = false ): void {
				$this->meta[ $key ] = $value;
			}

			/**
			 * Reads meta.
			 *
			 * @param string $key    Key.
			 * @param bool   $single Single.
			 */
			public function get_meta( string $key, bool $single = true ): mixed {
				return $this->meta[ $key ] ?? '';
			}
		};

		$files->remember( $line );
		self::assertSame( (string) $version, $line->meta[ DesignerOrderFiles::ITEM_META ] ?? null );

		$legacy = new class( $this->posts->add( 'Legacy', 'publish', array() ) ) {
			/**
			 * Meta.
			 *
			 * @var array<string, mixed>
			 */
			public array $meta = array();

			/**
			 * Constructor.
			 *
			 * @param int $product Product.
			 */
			public function __construct( private int $product ) {
			}

			/**
			 * The product.
			 */
			public function get_product_id(): int {
				return $this->product;
			}

			/**
			 * Adds meta.
			 *
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 */
			public function add_meta_data( string $key, mixed $value ): void {
				$this->meta[ $key ] = $value;
			}
		};
		$files->remember( $legacy );
		self::assertSame( array(), $legacy->meta, 'A legacy product\'s order line gets nothing.' );

		ob_start();
		$files->render_item( 1, $line );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( DesignerVersionFiles::ACTION, $html );
		self::assertStringContainsString( '>front<', $html );

		$download = new DesignerVersionFiles( $this->products, $this->files );
		$slot     = 'print:front';
		$ok       = $download->response(
			array(
				'version'  => (string) $version,
				'slot'     => rawurlencode( $slot ),
				'_wpnonce' => wp_create_nonce( DesignerVersionFiles::nonce_action( $version, $slot ) ),
			)
		);
		self::assertSame( array( 200, 'image/png' ), array( $ok['status'], $ok['headers']['Content-Type'] ?? null ) );
		self::assertStringStartsWith( 'attachment; filename="stream-tee-v1-front.png"', $ok['headers']['Content-Disposition'] ?? '' );
		self::assertSame( 403, $download->response( array( 'version' => (string) $version, 'slot' => $slot, '_wpnonce' => 'x' ) )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		wp_set_current_user( $this->create_user( 'shop-visitor' ) );
		self::assertSame( 403, $download->response( array( 'version' => (string) $version, 'slot' => $slot, '_wpnonce' => wp_create_nonce( DesignerVersionFiles::nonce_action( $version, $slot ) ) ) )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		ob_start();
		$files->render_item( 1, $line );
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function test_a_new_base_price_reaches_the_shop_and_the_settlement_follows_the_shop(): void {
		$product = $this->submitted();
		$this->review->approve( $this->pending_id( $product ) );
		$this->drain();
		$waiting = $this->submitted();
		$shop    = (int) ( $this->products->product( $product )['wc_product_id'] ?? 0 );
		$prices  = new DesignerPrices( $this->wpdb, new PostMetaPricingProductStore() );

		$this->bases->save( $this->base, $this->base_fields( '159,95' ), $this->base_views() );

		self::assertSame( 1, $this->bases_version(), 'A new price does not make designs stale.' );
		self::assertSame( array( array( DesignerRepricing::HOOK, array( $product, 1 ) ) ), array_map( static fn( array $job ): array => array( $job['hook'], $job['args'] ), array_values( array_filter( $this->queue->pending, static fn( array $job ): bool => DesignerRepricing::HOOK === $job['hook'] ) ) ), 'Only the product in the shop.' );
		self::assertSame( 1499500, ( $prices->price( $shop ) ?? self::fail( 'No price.' ) )->base_units, 'Until the shop has the new price, a sale is settled at the old one.' );

		$this->queue->drain(
			function ( string $hook, array $args ): void {
				$this->repricing->run( ...$args );
			}
		);

		self::assertSame( array( array( $shop, 1919400 ) ), $this->writer->prices, '159.95 + 20 %.' );
		self::assertSame( array( '191.9400', '159.9500' ), array( $this->products->product( $product )['shop_price'] ?? null, $this->products->product( $product )['shop_base_price'] ?? null ) );
		self::assertSame( 1599500, ( $prices->price( $shop ) ?? self::fail( 'No price.' ) )->base_units );
		self::assertSame( array( array( '191.9400', '179.9400' ) ), array_map( static fn( array $n ): array => array( $n[1]['price'], $n[1]['previous_price'] ), array_values( array_filter( $this->notified, static fn( array $n ): bool => DesignerNotifications::PRICE_CHANGED === $n[0] ) ) ) );
		self::assertSame( array( 'designer_product_repriced' ), $this->audit( 'designer_product_repriced' ) );

		$this->repricing->run( $product );
		self::assertCount( 1, $this->writer->prices, 'Unchanged: nothing written.' );

		$this->review->approve( $this->pending_id( $waiting ) );
		$this->drain();
		self::assertSame( 1919400, $this->writer->contents[1][1]->price_units, 'A design approved before is put in the shop at the new base price.' );
	}

	public function test_a_retired_base_product_takes_its_products_out_of_the_shop(): void {
		$product = $this->submitted();
		$this->review->approve( $this->pending_id( $product ) );
		$this->drain();
		$waiting = $this->submitted();
		$version = $this->pending_id( $waiting );
		$shop    = (int) ( $this->products->product( $product )['wc_product_id'] ?? 0 );

		$this->bases->retire( $this->base );

		self::assertSame( 'rejected', $this->state( $version )[0], 'A waiting design is rejected.' );
		self::assertStringContainsString( 'no longer offer', (string) ( $this->products->version( $version )['note'] ?? '' ) );
		$this->drain_retirement();

		self::assertSame( array( $shop ), $this->writer->unpublished );
		self::assertSame( 'retired', $this->products->product( $product )['status'] ?? null );
		self::assertSame( array( DesignerNotifications::REJECTED, DesignerNotifications::UNPUBLISHED ), $this->types( DesignerNotifications::REJECTED, DesignerNotifications::UNPUBLISHED ) );
		self::assertSame( array( 'designer_base_retired', 'designer_product_unpublished' ), $this->audit( 'designer_base_retired', 'designer_product_unpublished' ) );

		$this->retirement->run( $product );
		self::assertCount( 1, $this->writer->unpublished, 'Once.' );

		foreach (
			array(
				fn() => $this->bases->save( $this->base, $this->base_fields( '99' ), $this->base_views() ),
				fn() => $this->bases->set_active( $this->base, true ),
				fn() => $this->submissions->change( $this->creator, $product ),
			) as $operation
		) {
			try {
				$operation();
				self::fail( 'A retired base product is final.' );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::BASE_RETIRED, $e->reason() );
			}
		}

		$this->repricing->run( $product );
		self::assertSame( array(), $this->writer->prices, 'A retired product is never repriced.' );
	}

	public function test_the_creator_sees_each_product_s_state_in_the_section_products(): void {
		$live = $this->submitted();
		$this->review->approve( $this->pending_id( $live ) );
		$this->drain();
		$this->submissions->change( $this->creator, $live );
		$this->save( $live, '30' );
		$this->send_files( $live );
		$this->submissions->submit( $this->creator, $live );
		$waiting  = $this->submitted();
		$rejected = $this->submitted();
		$this->review->reject( $this->pending_id( $rejected ), 'Please use a sharper logo.' );
		$draft = $this->drafts->create( $this->creator, $this->base );
		Settings::save( array_merge( Settings::all(), array( Settings::DESIGNER_ENABLED => true ) ) );
		wp_set_current_user( (int) $this->creator->user_id );

		$states = $this->states();
		self::assertSame(
			array(
				$draft    => array( 'draft', null, null, null, true ),
				$rejected => array( 'rejected', null, null, 'Please use a sharper logo.', true ),
				$waiting  => array( 'waiting', null, '179.9400', null, false ),
				$live     => array( 'in_shop', '179.9400', '194.9400', null, false ),
			),
			array_map( static fn( array $state ): array => array( $state['state'], $state['price'], $state['pending']['price'] ?? null, $state['rejection']['note'] ?? null, $state['draft'] ), array_column( $states, null, 'id' ) )
		);
		self::assertSame( array( 'DKK' ), array_values( array_unique( array_column( $states, 'currency' ) ) ) );
		self::assertNull( array_column( $states, 'url', 'id' )[ $live ], 'A product that is not published has no link.' );

		$shop = (int) ( $this->products->product( $live )['wc_product_id'] ?? 0 );
		$this->wpdb->update( $this->wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $shop ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: the shop publishes the product.
		clean_post_cache( $shop );
		$link = get_permalink( $shop );
		self::assertSame( is_string( $link ) ? $link : null, array_column( $this->states(), 'url', 'id' )[ $live ] );

		wp_set_current_user( 1 );
		$this->bases->retire( $this->base );
		$this->drain_retirement();
		wp_set_current_user( (int) $this->creator->user_id );
		$retired = array_column( $this->states(), null, 'id' );
		self::assertSame( array( 'retired', null, null ), array( $retired[ $live ]['state'], $retired[ $live ]['pending'], $retired[ $live ]['rejection'] ) );
		self::assertSame( 'rejected', $retired[ $waiting ]['state'], 'Retiring rejects what waits.' );

		wp_set_current_user( $this->create_user( 'other-creator' ) );
		$refused = $this->controller()->products();
		self::assertInstanceOf( WP_Error::class, $refused, 'Only a creator gets an answer.' );
		self::assertSame( 403, rest_convert_error_to_response( $refused )->get_status() );
	}

	/**
	 * The creator's states from `GET /me/designer/products`.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function states(): array {
		$response = $this->controller()->products();
		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );

		return array_values( $data );
	}

	/**
	 * The creator's designer API.
	 */
	private function controller(): MeDesignerController {
		$assets = new DesignerAssetRepository( $this->wpdb );
		$bases  = new BaseProductRepository( $this->wpdb );

		return new MeDesignerController(
			$this->service( CreatorContext::class ),
			$bases,
			$this->products,
			$this->drafts,
			$this->submissions,
			$assets,
			$this->files,
			new CreatorUploads( $assets, $this->files ),
			new AlertFonts( $this->service( AuditTrail::class ), $this->service( Transaction::class ) ),
			$this->service( Logger::class )
		);
	}

	/**
	 * Runs the queued retirement jobs.
	 */
	private function drain_retirement(): void {
		$this->queue->drain(
			function ( string $hook, array $args ): void {
				if ( DesignerRetirement::HOOK === $hook ) {
					$this->retirement->run( $args[0] ?? 0 );
				}
			}
		);
	}

	/**
	 * The base product's version.
	 */
	private function bases_version(): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT version FROM %i WHERE id = %d', $this->table( Tables::DESIGNER_BASES ), $this->base ) );
	}

	/**
	 * The base product's fields with a price.
	 *
	 * @param string $price Base price.
	 * @return array<string, string>
	 */
	private function base_fields( string $price ): array {
		return array(
			'name'        => 'Organic tee',
			'description' => '',
			'base_price'  => $price,
			'category_id' => '0',
			'colors'      => "Black #111111\nSand #d8c8a8",
			'sizes'       => 'S, M',
		);
	}

	/**
	 * The base product's side as stored (no new upload).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function base_views(): array {
		return array(
			array(
				'key'       => 'front',
				'name'      => 'Front',
				'x'         => '100',
				'y'         => '100',
				'width'     => '200',
				'height'    => '200',
				'width_mm'  => '30',
				'height_mm' => '40',
				'dpi'       => '100',
				'remove'    => false,
				'clear'     => array(),
				'uploads'   => array(),
			),
		);
	}

	/**
	 * A designer product with a submitted draft (a rectangle on the front,
	 * black and sand, 20 % profit).
	 */
	private function submitted(): int {
		$product = $this->drafts->create( $this->creator, $this->base );
		$this->save( $product, '20' );
		$this->send_files( $product );
		$this->submissions->submit( $this->creator, $product );

		return $product;
	}

	/**
	 * Saves the draft.
	 *
	 * @param int    $product Designer product.
	 * @param string $profit  Percent.
	 */
	private function save( int $product, string $profit ): void {
		$this->drafts->save(
			$this->creator,
			$product,
			array(
				'title'        => 'Stream tee',
				'description'  => 'Soft.',
				'colors'       => array( 'black', 'sand' ),
				'sizes'        => array( 'S', 'M' ),
				'profit_kind'  => 'percent',
				'profit_value' => $profit,
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
	}

	/**
	 * Sends the files of the draft.
	 *
	 * @param int $product Designer product.
	 */
	private function send_files( int $product ): void {
		$this->submissions->attach( $this->creator, $product, 'print:front', AlertImagesTest::upload( AlertImagesTest::png( 118, 157 ), 'print.png' ) );

		foreach ( array( 'black', 'sand' ) as $color ) {
			$this->submissions->attach( $this->creator, $product, 'mockup:front:' . $color, AlertImagesTest::upload( AlertImagesTest::png( 400, 400 ), 'mockup.png' ) );
		}
	}

	/**
	 * The pending version of a product.
	 *
	 * @param int $product Designer product.
	 */
	private function pending_id( int $product ): int {
		return (int) ( $this->products->pending( $product )['id'] ?? self::fail( 'Nothing pending.' ) );
	}

	/**
	 * Runs the queued jobs.
	 */
	private function drain(): void {
		$this->queue->drain(
			function ( string $hook, array $args ): void {
				$this->apply->run( $args[0] ?? 0 );
			}
		);
	}

	/**
	 * Runs a version's job once more.
	 *
	 * @param int $version Version.
	 */
	private function drain_again( int $version ): void {
		$this->apply->run( $version );
	}

	/**
	 * Status, apply state and price of a version.
	 *
	 * @param int $version Version.
	 * @return list<string>
	 */
	private function state( int $version ): array {
		$row = $this->products->version( $version ) ?? self::fail( 'Unknown version.' );

		return array( (string) $row['status'], (string) $row['apply_state'], (string) $row['quoted_price'] );
	}

	/**
	 * Step and reason of a failed apply.
	 *
	 * @param int $version Version.
	 * @return list<string>
	 */
	private function failure( int $version ): array {
		$row = $this->products->version( $version ) ?? self::fail( 'Unknown version.' );

		return array( (string) $row['apply_step'], (string) $row['apply_error'] );
	}

	/**
	 * The notification types fired among some.
	 *
	 * @param string ...$types Types.
	 * @return list<string>
	 */
	private function types( string ...$types ): array {
		return array_values( array_filter( array_column( $this->notified, 0 ), static fn( string $type ): bool => in_array( $type, $types, true ) ) );
	}

	/**
	 * The audit actions among some, in order.
	 *
	 * @param string ...$actions Actions.
	 * @return list<string>
	 */
	private function audit( string ...$actions ): array {
		return array_values( array_filter( array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT action FROM %i ORDER BY id', $this->table( Tables::AUDIT_LOG ) ) ) ), static fn( string $action ): bool => in_array( $action, $actions, true ) ) );
	}

	/**
	 * Imported mockups.
	 */
	private function media_count(): int {
		return count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => DesignerMedia::META ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test check.
	}
}
