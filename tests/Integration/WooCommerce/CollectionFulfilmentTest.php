<?php
/**
 * Carrying collection requests out in the shop, against a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use Closure;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\NamedLock;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Collections\CollectionFulfilment;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestException;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestRepository;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequests;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestStatus;
use MerchBoard\Modules\WooCommerce\Collections\CollectionWatcher;
use MerchBoard\Modules\WooCommerce\Collections\CollectionWriter;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * ADR-120 pkt. 2 and 9. Our own minimal double of the collection plugin: a
 * draft is a post with its meta, and the sync marks the designs. Neither the
 * plugin nor WooCommerce is loaded.
 */
#[CoversClass( CollectionFulfilment::class )]
#[CoversClass( CollectionWriter::class )]
#[CoversClass( CollectionWatcher::class )]
final class CollectionFulfilmentTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private CollectionRequestRepository $repository;

	private CollectionRequests $requests;

	private CollectionFulfilment $fulfilment;

	private CollectionWatcher $watcher;

	private Creator $creator;

	private int $user;

	private int $red;

	private int $blue;

	private int $green;

	/**
	 * The drafts created.
	 *
	 * @var list<int>
	 */
	private array $drafts = array();

	/**
	 * Whether the plugin is active.
	 *
	 * @var bool
	 */
	private bool $active = true;

	/**
	 * Whether creating a draft fails right after it was saved, as in a crash.
	 *
	 * @var bool
	 */
	private bool $crash = false;

	/**
	 * Collections the plugin's sync ran for.
	 *
	 * @var list<int>
	 */
	private array $synced = array();

	/**
	 * Whether a draft was saved inside a transaction of ours.
	 *
	 * @var bool
	 */
	private bool $in_transaction = false;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->drafts         = array();
		$this->synced         = array();
		$this->active         = true;
		$this->crash          = false;
		$this->in_transaction = false;

		$this->posts = new ProductPosts( $this->wpdb );
		$this->posts->remove_all();

		$this->user    = $this->create_user( 'nova' );
		$this->creator = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->user );
		$link          = array( (string) $this->creator->id );
		$this->red     = $this->posts->add( 'Tee Red', 'publish', $link );
		$this->blue    = $this->posts->add( 'Tee Blue', 'publish', $link );
		$this->green   = $this->posts->add( 'Tee Green', 'publish', $link );

		$collections = new DesignCollections( fn(): bool => $this->active );
		$lock        = new NamedLock( $this->wpdb );
		$creators    = $this->service( CreatorRepository::class );

		$this->repository = new CollectionRequestRepository( $this->wpdb );
		$this->requests   = new CollectionRequests(
			$this->repository,
			$collections,
			new CreatorProducts( $this->wpdb ),
			$this->service( Transaction::class ),
			$this->service( AuditTrail::class ),
			$lock,
			static function ( int $product ): ?int {
				$link = get_post_meta( $product, ProductCreatorLink::META_KEY, true );

				return is_numeric( $link ) ? (int) $link : null;
			},
			static fn( int $id ): ?Creator => $creators->find( $id )
		);
		$this->fulfilment = new CollectionFulfilment(
			$this->requests,
			$this->repository,
			new CollectionWriter( $collections, $lock, $this->create(), $this->sync() )
		);
		$this->watcher    = new CollectionWatcher( $this->repository, $this->requests, $this->service( Logger::class ) );

		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		remove_action( 'shutdown', array( $this->watcher, 'check' ) );
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_approval_makes_one_draft_and_hides_nothing(): void {
		$id = $this->requests->request_new( $this->creator, 'Nova Tee', array( $this->red, $this->blue ) )->id;

		self::assertTrue( $this->fulfilment->approve( $id ) );
		self::assertTrue( $this->fulfilment->retry( $id ) );

		self::assertCount( 1, $this->drafts );
		self::assertSame( $this->drafts[0], $this->repository->find( $id )?->collection_id );
		self::assertSame( 'draft', get_post_status( $this->drafts[0] ) );
		self::assertSame( 'Nova Tee', get_post_field( 'post_title', $this->drafts[0] ) );
		self::assertSame( array( $this->red, $this->blue ), $this->members( $this->drafts[0] ) );
		self::assertSame( '', get_post_meta( $this->drafts[0], CollectionWriter::TOKEN_KEY, true ), 'A copy must not pass for it.' );
		self::assertSame( '', get_post_meta( $this->red, DesignCollections::GROUP_KEY, true ) );
		self::assertSame( array(), $this->synced );
		self::assertFalse( $this->in_transaction, 'WooCommerce would commit our transaction.' );
	}

	public function test_a_crash_after_the_save_never_makes_a_second_draft(): void {
		$id          = $this->requests->request_new( $this->creator, 'Nova Tee', array( $this->red, $this->blue ) )->id;
		$this->crash = true;

		try {
			$this->fulfilment->approve( $id );
			self::fail( 'No crash.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'Crash.', $e->getMessage() );
		}

		self::assertSame( CollectionRequestStatus::Approved, $this->repository->find( $id )?->status );
		self::assertNull( $this->repository->find( $id )?->collection_id );

		// The administrator may even have trashed it meanwhile.
		wp_update_post(
			array(
				'ID'          => $this->drafts[0],
				'post_status' => 'trash',
			)
		);
		$this->crash = false;

		self::assertTrue( $this->fulfilment->retry( $id ) );
		self::assertCount( 1, $this->drafts );
		self::assertSame( $this->drafts[0], $this->repository->find( $id )?->collection_id );
	}

	public function test_a_copied_draft_is_not_guessed_at(): void {
		$request = $this->requests->request_new( $this->creator, 'Nova Tee', array( $this->red, $this->blue ) );

		foreach ( array( 'One', 'Two' ) as $name ) {
			$this->posts->meta( $this->posts->add( $name, 'draft', array() ), CollectionWriter::TOKEN_KEY, $request->token );
		}

		$this->expect_refusal( CollectionRequestException::DRAFT_UNCLEAR, fn() => $this->fulfilment->approve( $request->id ) );
		self::assertSame( array(), $this->drafts );
	}

	public function test_without_the_plugin_nothing_is_written(): void {
		$id           = $this->requests->request_new( $this->creator, 'Nova Tee', array( $this->red, $this->blue ) )->id;
		$this->active = false;

		$this->expect_refusal( CollectionRequestException::PLUGIN_INACTIVE, fn() => $this->fulfilment->approve( $id ) );
		self::assertSame( array(), $this->drafts );

		$this->active = true;

		self::assertTrue( $this->fulfilment->retry( $id ), 'The approval stayed.' );
	}

	public function test_an_addition_to_a_draft_is_appended_at_approval(): void {
		$collection = $this->collection( 'draft' );
		$id         = $this->requests->request_add( $this->creator, $collection, array( $this->green ) )->id;

		self::assertTrue( $this->fulfilment->approve( $id ) );
		self::assertTrue( $this->fulfilment->retry( $id ) );

		self::assertSame( array( $this->red, $this->green ), $this->members( $collection ) );
		self::assertSame( 'Red', get_post_meta( $collection, DesignCollections::MEMBERS_KEY, true )[0]['label'], 'The rows there are unchanged.' );
		self::assertSame( array(), $this->synced );
	}

	public function test_an_addition_to_a_published_collection_takes_the_second_click(): void {
		$collection = $this->collection( 'publish' );
		$id         = $this->requests->request_add( $this->creator, $collection, array( $this->green ) )->id;

		self::assertFalse( $this->fulfilment->approve( $id ) );
		self::assertSame( array( $this->red ), $this->members( $collection ), 'Approval changes nothing in the shop.' );
		self::assertSame( array(), $this->synced );

		self::assertTrue( $this->fulfilment->add_now( $id ) );
		self::assertSame( array( $this->red, $this->green ), $this->members( $collection ) );
		self::assertSame( array( $collection ), $this->synced );

		$this->watcher->check();

		self::assertSame( CollectionRequestStatus::Ready, $this->repository->find( $id )?->status );
	}

	public function test_an_open_editor_is_not_written_under(): void {
		$collection = $this->collection( 'publish' );
		$id         = $this->requests->request_add( $this->creator, $collection, array( $this->green ) )->id;
		$this->fulfilment->approve( $id );
		update_post_meta( $collection, '_edit_lock', time() . ':1' );

		$this->expect_refusal( CollectionRequestException::EDITING, fn() => $this->fulfilment->add_now( $id ) );
		self::assertSame( array( $this->red ), $this->members( $collection ) );
	}

	public function test_only_an_administrator_carries_out_and_only_what_is_approved(): void {
		$id = $this->requests->request_new( $this->creator, 'Nova Tee', array( $this->red, $this->blue ) )->id;

		$this->expect_refusal( CollectionRequestException::NOT_APPROVED, fn() => $this->fulfilment->retry( $id ) );

		wp_set_current_user( $this->user );
		$this->expect_refusal( CollectionRequestException::NOT_AUTHORIZED, fn() => $this->fulfilment->retry( $id ) );
		self::assertSame( array(), $this->drafts );
	}

	public function test_it_is_ready_at_the_end_of_the_request_that_published_it(): void {
		$id = $this->requests->request_new( $this->creator, 'Nova Tee', array( $this->red, $this->blue ) )->id;
		$this->fulfilment->approve( $id );
		$draft = $this->drafts[0];

		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'publish',
			)
		);
		$this->watcher->status_changed( 'publish', 'draft', get_post( $draft ) );

		self::assertNotFalse( has_action( 'shutdown', array( $this->watcher, 'check' ) ) );
		self::assertSame( CollectionRequestStatus::Approved, $this->repository->find( $id )?->status, 'Not before the plugin has hidden the designs.' );

		( $this->sync() )( $draft );
		$this->watcher->check();

		self::assertSame( CollectionRequestStatus::Ready, $this->repository->find( $id )?->status );
	}

	/**
	 * The double of WooCommerce' save with the plugin's class.
	 *
	 * @return Closure(array{name: string, members: list<array{id: int, label: string}>, categories: list<int>, tags: list<int>, image: int, token: string}): int
	 */
	private function create(): Closure {
		return function ( array $draft ): int {
			$this->in_transaction = $this->transaction_open();

			$id = $this->posts->add( $draft['name'], 'draft', array() );
			update_post_meta( $id, DesignCollections::MEMBERS_KEY, $draft['members'] );
			update_post_meta( $id, CollectionWriter::TOKEN_KEY, $draft['token'] );
			$this->drafts[] = $id;

			if ( $this->crash ) {
				throw new RuntimeException( 'Crash.' );
			}

			return $id;
		};
	}

	/**
	 * Whether the connection has a transaction open: only then does a
	 * savepoint outlive its statement (MySQL has no @@in_transaction).
	 */
	private function transaction_open(): bool {
		$wpdb = $this->wpdb;
		$wpdb->query( 'SAVEPOINT merchboard_probe' );
		$suppressed = $wpdb->suppress_errors( true );
		$open       = false !== $wpdb->query( 'RELEASE SAVEPOINT merchboard_probe' );
		$wpdb->suppress_errors( $suppressed );

		return $open;
	}

	/**
	 * The double of the plugin's sync.
	 *
	 * @return Closure(int): bool
	 */
	private function sync(): Closure {
		return function ( int $collection ): bool {
			foreach ( $this->members( $collection ) as $design ) {
				update_post_meta( $design, DesignCollections::GROUP_KEY, $collection );
			}

			$this->synced[] = $collection;

			return true;
		};
	}

	/**
	 * A collection with the red design.
	 *
	 * @param string $status Post status.
	 */
	private function collection( string $status ): int {
		$id = $this->posts->add( 'Collection', $status, array() );
		update_post_meta(
			$id,
			DesignCollections::MEMBERS_KEY,
			array(
				array(
					'id'    => $this->red,
					'label' => 'Red',
				),
			)
		);

		if ( 'publish' === $status ) {
			update_post_meta( $this->red, DesignCollections::GROUP_KEY, $id );
		}

		return $id;
	}

	/**
	 * A collection's designs.
	 *
	 * @param int $collection Collection.
	 * @return list<int>
	 */
	private function members( int $collection ): array {
		return array_column( ( new DesignCollections() )->members( $collection ), 'id' );
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
