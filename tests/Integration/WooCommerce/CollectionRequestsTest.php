<?php
/**
 * The requests to have designs collected, against a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\NamedLock;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequest;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestException;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestKind;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestRepository;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequests;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestsCleanup;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestStatus;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * ADR-120 pkt. 1 and 2. The collections are posts with the meta the
 * collection plugin writes; neither it nor WooCommerce is loaded.
 */
#[CoversClass( CollectionRequests::class )]
#[CoversClass( CollectionRequestRepository::class )]
#[CoversClass( CollectionRequestsCleanup::class )]
final class CollectionRequestsTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private CollectionRequestRepository $repository;

	private CollectionRequests $requests;

	private int $user;

	private Creator $creator;

	private Creator $other;

	private int $red;

	private int $blue;

	private int $green;

	private int $foreign;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->posts = new ProductPosts( $this->wpdb );
		$this->posts->remove_all();

		$creators      = $this->service( CreatorService::class );
		$this->user    = $this->create_user( 'nova' );
		$this->creator = $creators->create( 'Nova', CreatorStatus::Active, $this->user );
		$this->other   = $creators->create( 'Sol', CreatorStatus::Active, $this->create_user( 'sol' ) );

		$this->red     = $this->product( 'Tee Red', $this->creator->id );
		$this->blue    = $this->product( 'Tee Blue', $this->creator->id );
		$this->green   = $this->product( 'Tee Green', $this->creator->id );
		$this->foreign = $this->product( 'Their Tee', $this->other->id );

		$found            = $this->service( CreatorRepository::class );
		$this->repository = new CollectionRequestRepository( $this->wpdb );
		$this->requests   = new CollectionRequests(
			$this->repository,
			new DesignCollections(),
			new CreatorProducts( $this->wpdb ),
			$this->service( Transaction::class ),
			$this->service( AuditTrail::class ),
			new NamedLock( $this->wpdb ),
			static function ( int $product ): ?int {
				$link = get_post_meta( $product, ProductCreatorLink::META_KEY, true );

				return is_numeric( $link ) ? (int) $link : null;
			},
			static fn( int $id ): ?Creator => $found->find( $id )
		);

		wp_set_current_user( $this->user );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_a_request_is_only_a_case_until_it_is_approved(): void {
		$request = $this->requests->request_new( $this->creator, ' <b>Nova Tee</b> ', array( $this->blue, $this->red, $this->blue ) );

		self::assertSame( CollectionRequestKind::NewCollection, $request->kind );
		self::assertSame( CollectionRequestStatus::Pending, $request->status );
		self::assertSame( 'Nova Tee', $request->name );
		self::assertNull( $request->collection_id );
		self::assertSame( 32, strlen( $request->token ) );
		self::assertSame( array( $this->blue, $this->red ), $request->product_ids(), 'The order chosen, each once.' );
		self::assertSame( 'Tee Blue', $request->designs[0]['name'] );
		self::assertSame( 1, $this->repository->pending_count() );

		wp_set_current_user( 1 );
		$approved = $this->requests->approve( $request->id );

		self::assertSame( CollectionRequestStatus::Approved, $approved->status );
		self::assertNull( $approved->collection_id, 'No draft is made here.' );
		self::assertSame( 0, $this->repository->pending_count() );
		self::assertSame( '', get_post_meta( $this->red, DesignCollections::GROUP_KEY, true ), 'The shop is untouched.' );
		$this->expect_refusal( CollectionRequestException::NOT_PENDING, fn() => $this->requests->approve( $request->id ) );

		self::assertSame(
			array(
				'collection_request_id' => $request->id,
				'collection_id'         => null,
				'product_ids'           => array( $this->blue, $this->red ),
			),
			$this->audit( AuditAction::CollectionRequested )[0]
		);
		self::assertCount( 1, $this->audit( AuditAction::CollectionApproved ) );
	}

	public function test_a_new_collection_needs_a_name_and_two_own_published_products(): void {
		$draft = $this->product( 'Draft', $this->creator->id, 'draft' );

		$this->expect_refusal( CollectionRequestException::NAME_REQUIRED, fn() => $this->requests->request_new( $this->creator, ' ', array( $this->red, $this->blue ) ) );
		$this->expect_refusal( CollectionRequestException::TOO_FEW, fn() => $this->requests->request_new( $this->creator, 'Tee', array( $this->red, $this->red ) ) );
		$this->expect_refusal( CollectionRequestException::PRODUCT_NOT_USABLE, fn() => $this->requests->request_new( $this->creator, 'Tee', array( $this->red, $this->foreign ) ) );
		$this->expect_refusal( CollectionRequestException::PRODUCT_NOT_USABLE, fn() => $this->requests->request_new( $this->creator, 'Tee', array( $this->red, $draft ) ) );

		self::assertSame( 0, $this->count_rows( Tables::COLLECTION_REQUESTS ) );
		self::assertSame( 0, $this->count_rows( Tables::COLLECTION_REQUEST_ITEMS ) );
	}

	public function test_a_product_is_in_one_open_request_at_a_time(): void {
		$first = $this->requests->request_new( $this->creator, 'First', array( $this->red, $this->blue ) );

		$this->expect_refusal( CollectionRequestException::PRODUCT_REQUESTED, fn() => $this->requests->request_new( $this->creator, 'Second', array( $this->blue, $this->green ) ) );
		self::assertSame( 1, $this->count_rows( Tables::COLLECTION_REQUESTS ), 'Nothing is left of the refused one.' );

		wp_set_current_user( 1 );
		$this->requests->decline( $first->id, 'Not the same product.' );
		wp_set_current_user( $this->user );

		$second = $this->requests->request_new( $this->creator, 'Second', array( $this->blue, $this->green ) );

		self::assertSame( CollectionRequestStatus::Pending, $second->status );
	}

	public function test_a_collection_is_the_creators_when_every_design_in_it_is(): void {
		$own   = $this->collection( array( $this->red ) );
		$mixed = $this->collection( array( $this->red, $this->foreign ) );
		$empty = $this->collection( array() );

		self::assertTrue( $this->requests->is_creators( $this->creator->id, $own ) );
		self::assertFalse( $this->requests->is_creators( $this->other->id, $own ) );
		self::assertFalse( $this->requests->is_creators( $this->creator->id, $mixed ) );
		self::assertFalse( $this->requests->is_creators( $this->creator->id, $empty ) );

		$request = $this->requests->request_add( $this->creator, $own, array( $this->green ) );

		self::assertSame( CollectionRequestKind::Add, $request->kind );
		self::assertSame( $own, $request->collection_id );
		self::assertSame( 'Collection', $request->name );

		$this->expect_refusal( CollectionRequestException::COLLECTION_UNKNOWN, fn() => $this->requests->request_add( $this->creator, $mixed, array( $this->blue ) ) );
		$this->expect_refusal( CollectionRequestException::PRODUCT_NOT_USABLE, fn() => $this->requests->request_add( $this->creator, $own, array( $this->red ) ) );
	}

	public function test_a_collected_product_cannot_be_asked_for(): void {
		$this->collection( array( $this->red ) );

		$this->expect_refusal( CollectionRequestException::PRODUCT_NOT_USABLE, fn() => $this->requests->request_new( $this->creator, 'Tee', array( $this->red, $this->blue ) ) );
	}

	public function test_the_rules_are_checked_again_at_approval(): void {
		$request = $this->requests->request_new( $this->creator, 'Tee', array( $this->red, $this->blue ) );
		// As a row: the plugin's guard refuses any other write of the link.
		$this->execute( 'UPDATE %i SET meta_value = %s WHERE post_id = %d AND meta_key = %s', $this->wpdb->postmeta, (string) $this->other->id, $this->blue, ProductCreatorLink::META_KEY );
		wp_cache_delete( $this->blue, 'post_meta' );

		wp_set_current_user( 1 );
		$this->expect_refusal( CollectionRequestException::PRODUCT_NOT_USABLE, fn() => $this->requests->approve( $request->id ) );

		self::assertSame( CollectionRequestStatus::Pending, $this->read( $request )->status );
		self::assertSame( array(), $this->audit( AuditAction::CollectionApproved ) );
	}

	public function test_only_an_administrator_decides(): void {
		$request = $this->requests->request_new( $this->creator, 'Tee', array( $this->red, $this->blue ) );

		$this->expect_refusal( CollectionRequestException::NOT_AUTHORIZED, fn() => $this->requests->approve( $request->id ) );
		$this->expect_refusal( CollectionRequestException::NOT_AUTHORIZED, fn() => $this->requests->decline( $request->id, 'No.' ) );

		wp_set_current_user( 1 );
		$this->expect_refusal( CollectionRequestException::NOT_FOUND, fn() => $this->requests->approve( $request->id + 100 ) );
		$this->expect_refusal( CollectionRequestException::NOTE_REQUIRED, fn() => $this->requests->decline( $request->id, '  ' ) );

		$declined = $this->requests->decline( $request->id, 'They are <b>different</b> products.' );

		self::assertSame( CollectionRequestStatus::Declined, $declined->status );
		self::assertSame( 'They are different products.', $declined->note );
		self::assertSame( 'They are different products.', $this->audit( AuditAction::CollectionDeclined )[0]['decision_note'] ?? null );
		$this->expect_refusal( CollectionRequestException::NOT_OPEN, fn() => $this->requests->decline( $request->id, 'Again.' ) );
	}

	public function test_it_is_ready_once_the_published_collection_holds_the_designs(): void {
		$request = $this->requests->request_new( $this->creator, 'Tee', array( $this->red, $this->blue ) );

		wp_set_current_user( 1 );
		$this->requests->approve( $request->id );
		$draft = $this->collection( array( $this->red ), 'draft' );
		self::assertTrue( $this->repository->set_collection( $request->id, $draft ) );
		self::assertFalse( $this->repository->set_collection( $request->id, $draft + 1 ), 'Only the first draft is recorded.' );

		self::assertNull( $this->requests->mark_ready( $request->id ), 'A draft hides nothing.' );

		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'publish',
			)
		);
		self::assertNull( $this->requests->mark_ready( $request->id ), 'The plugin has not hidden the design yet.' );

		update_post_meta( $this->red, DesignCollections::GROUP_KEY, $draft );

		$this->expect_refusal( CollectionRequestException::ALREADY_DONE, fn() => $this->requests->decline( $request->id, 'Too late.' ) );

		$ready = $this->requests->mark_ready( $request->id );

		self::assertSame( CollectionRequestStatus::Ready, $ready?->status, 'A design the administrator took out does not block.' );
		self::assertNull( $this->requests->mark_ready( $request->id ), 'Only once.' );
		self::assertSame( array(), $this->repository->requested_among( array( $this->red, $this->blue ) ) );
		self::assertCount( 1, $this->audit( AuditAction::CollectionReady ) );
	}

	public function test_a_design_held_by_another_collection_does_not_make_it_ready(): void {
		$request = $this->requests->request_new( $this->creator, 'Tee', array( $this->red, $this->blue ) );

		wp_set_current_user( 1 );
		$this->requests->approve( $request->id );
		$mine = $this->collection( array( $this->red, $this->blue ) );
		$this->repository->set_collection( $request->id, $mine );
		update_post_meta( $this->blue, DesignCollections::GROUP_KEY, $this->collection( array( $this->blue ) ) );

		self::assertNull( $this->requests->mark_ready( $request->id ) );
	}

	public function test_deleting_the_creator_deletes_the_requests_and_no_collection(): void {
		$own = $this->collection( array( $this->red ) );
		$this->requests->request_add( $this->creator, $own, array( $this->green ) );
		$theirs  = $this->product( 'Their Cap', $this->other->id );
		$keeps   = $this->requests->request_new( $this->other, 'Sol Tee', array( $this->foreign, $theirs ) );
		$cleanup = new CollectionRequestsCleanup( $this->repository );

		self::assertSame( array( 'rows' => 1 ), $cleanup->remove( $this->creator ) );
		self::assertSame( array( 'rows' => 0 ), $cleanup->remove( $this->creator ), 'Idempotent.' );
		self::assertSame( 1, $this->count_rows( Tables::COLLECTION_REQUESTS ) );
		self::assertSame( 2, $this->count_rows( Tables::COLLECTION_REQUEST_ITEMS ) );
		self::assertSame( array( $this->foreign, $theirs ), $this->read( $keeps )->product_ids() );
		self::assertSame( 'publish', get_post_status( $own ) );
	}

	/**
	 * A creator's product.
	 *
	 * @param string $name       Name.
	 * @param int    $creator_id Creator.
	 * @param string $status     Post status.
	 */
	private function product( string $name, int $creator_id, string $status = 'publish' ): int {
		return $this->posts->add( $name, $status, array( (string) $creator_id ) );
	}

	/**
	 * A collection as the plugin stores it; a published one marks its designs.
	 *
	 * @param int[]  $designs Products.
	 * @param string $status  Post status.
	 */
	private function collection( array $designs, string $status = 'publish' ): int {
		$id = $this->posts->add( 'Collection', $status, array() );

		update_post_meta(
			$id,
			DesignCollections::MEMBERS_KEY,
			array_map(
				static fn( int $design ): array => array(
					'id'    => $design,
					'label' => '',
				),
				$designs
			)
		);

		foreach ( 'publish' === $status ? $designs : array() as $design ) {
			update_post_meta( $design, DesignCollections::GROUP_KEY, $id );
		}

		return $id;
	}

	/**
	 * A request as stored now.
	 *
	 * @param CollectionRequest $request The request.
	 */
	private function read( CollectionRequest $request ): CollectionRequest {
		return $this->repository->find( $request->id ) ?? self::fail( 'No request.' );
	}

	/**
	 * Audit contexts of an action.
	 *
	 * @param AuditAction $action Action.
	 * @return list<array<string, mixed>>
	 */
	private function audit( AuditAction $action ): array {
		$contexts = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT context FROM %i WHERE action = %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), $action->value ) );

		return array_map( static fn( ?string $json ): array => (array) json_decode( (string) $json, true ), $contexts );
	}

	/**
	 * Expects a refusal.
	 *
	 * @param string   $reason Reason code.
	 * @param callable $call   The call.
	 */
	private function expect_refusal( string $reason, callable $call ): void {
		try {
			$call();
			self::fail( 'Not refused: ' . $reason );
		} catch ( CollectionRequestException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
