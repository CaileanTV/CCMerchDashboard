<?php
/**
 * /me/collections, the "Collections" tab and its forms.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Rest;

use Closure;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\NamedLock;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CollectionRequestActions;
use MerchBoard\Modules\WooCommerce\Admin\CollectionRequestsPage;
use MerchBoard\Modules\WooCommerce\Collections\CollectionFulfilment;
use MerchBoard\Modules\WooCommerce\Collections\CollectionGiftWarnings;
use MerchBoard\Modules\WooCommerce\Collections\CollectionOverview;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestRepository;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequests;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestStatus;
use MerchBoard\Modules\WooCommerce\Collections\CollectionWatcher;
use MerchBoard\Modules\WooCommerce\Collections\CollectionWriter;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Rest\MeCollectionsController;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ADR-120 pkt. 1 and 2: the creator only ever sees and asks for their own,
 * and the tab's forms take a capability and a nonce per request.
 */
#[CoversClass( MeCollectionsController::class )]
#[CoversClass( CollectionOverview::class )]
#[CoversClass( CollectionRequestsPage::class )]
#[CoversClass( CollectionRequestActions::class )]
#[CoversClass( CollectionGiftWarnings::class )]
final class MeCollectionsEndpointTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private CollectionRequestRepository $repository;

	private CollectionRequestsPage $page;

	private CollectionRequestActions $actions;

	private int $user;

	private Creator $creator;

	private int $red;

	private int $blue;

	private int $foreign;

	private bool $active = true;

	private ?Closure $register = null;

	/**
	 * The notifications sent: type and creator.
	 *
	 * @var list<string>
	 */
	private array $notified = array();

	private ?Closure $listen = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->active   = true;
		$this->notified = array();
		$this->posts    = new ProductPosts( $this->wpdb );
		$this->posts->remove_all();

		$service       = $this->service( CreatorService::class );
		$this->user    = $this->create_user( 'nova' );
		$this->creator = $service->create( 'Nova', CreatorStatus::Active, $this->user );
		$other         = $service->create( 'Sol', CreatorStatus::Active, $this->create_user( 'sol' ) );
		$this->red     = $this->posts->add( 'Tee Red', 'publish', array( (string) $this->creator->id ) );
		$this->blue    = $this->posts->add( 'Tee Blue', 'publish', array( (string) $this->creator->id ) );
		$this->foreign = $this->posts->add( 'Their Tee', 'publish', array( (string) $other->id ) );

		$collections      = new DesignCollections( fn(): bool => $this->active );
		$lock             = new NamedLock( $this->wpdb );
		$creators         = $this->service( CreatorRepository::class );
		$products         = new CreatorProducts( $this->wpdb );
		$logger           = $this->service( Logger::class );
		$this->repository = new CollectionRequestRepository( $this->wpdb );
		$requests         = new CollectionRequests(
			$this->repository,
			$collections,
			$products,
			$this->service( Transaction::class ),
			$this->service( AuditTrail::class ),
			$lock,
			static function ( int $product ): ?int {
				$link = get_post_meta( $product, ProductCreatorLink::META_KEY, true );

				return is_numeric( $link ) ? (int) $link : null;
			},
			static fn( int $id ): ?Creator => $creators->find( $id )
		);
		$create           = function ( array $draft ): int {
			$id = $this->posts->add( $draft['name'], 'draft', array() );
			update_post_meta( $id, DesignCollections::MEMBERS_KEY, $draft['members'] );

			return $id;
		};
		$this->page       = new CollectionRequestsPage( $this->repository, $collections, $products, $creators, new CollectionGiftWarnings( $this->wpdb, $collections ), $logger );
		$this->actions    = new CollectionRequestActions(
			$requests,
			new CollectionFulfilment( $requests, $this->repository, new CollectionWriter( $collections, $lock, $create, static fn(): bool => true ) ),
			new CollectionWatcher( $this->repository, $requests, $logger ),
			$logger
		);

		$controller     = new MeCollectionsController( $this->service( CreatorContext::class ), $requests, new CollectionOverview( $collections, $requests, $this->repository, $products ), $logger );
		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();

		$this->listen = function ( string $type, mixed $creator_id ): void {
			$this->notified[] = $type . ':' . ( is_numeric( $creator_id ) ? (string) $creator_id : '' );
		};
		add_action( 'merchboard_notify', $this->listen, 1, 2 );

		wp_set_current_user( $this->user );
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		if ( null !== $this->listen ) {
			remove_action( 'merchboard_notify', $this->listen, 1 );
		}

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_the_creator_sees_only_their_own(): void {
		$theirs = $this->collection( array( $this->foreign ) );

		$data = (array) $this->get()->get_data();

		self::assertTrue( $data['available'] );
		self::assertSame( array( $this->blue, $this->red ), array_column( $data['products'], 'id' ) );
		self::assertSame( array( 'id', 'name' ), array_keys( $data['products'][0] ) );
		self::assertSame( array(), $data['collections'], 'Not collection ' . $theirs . '.' );
		self::assertSame( array(), $data['requests'] );

		$mine = $this->collection( array( $this->red ) );
		$data = (array) $this->get()->get_data();

		self::assertSame( array( $this->blue ), array_column( $data['products'], 'id' ), 'A collected one cannot be chosen.' );
		self::assertSame(
			array(
				array(
					'id'          => $mine,
					'name'        => 'Collection',
					'published'   => true,
					'designs'     => 1,
					'product_ids' => array( $this->red ),
				),
			),
			$data['collections']
		);
	}

	public function test_a_request_is_sent_and_the_administrator_is_told(): void {
		$response = $this->post(
			array(
				'name'        => 'Nova Tee',
				'product_ids' => array( $this->red, $this->blue ),
			)
		);

		self::assertSame( 201, $response->get_status() );

		$data = (array) $response->get_data();

		self::assertSame( array(), $data['products'], 'Both wait in the request.' );
		self::assertSame( array( 'id', 'kind', 'name', 'status', 'note', 'designs', 'requested_at' ), array_keys( $data['requests'][0] ), 'Never the token.' );
		self::assertSame( 'pending', $data['requests'][0]['status'] );
		self::assertSame( array( 'admin_collection_requested:' . $this->creator->id ), $this->notified );
	}

	public function test_another_creators_products_and_collections_are_refused(): void {
		$theirs = $this->collection( array( $this->foreign ) );

		$product = $this->post(
			array(
				'name'        => 'Mixed',
				'product_ids' => array( $this->red, $this->foreign ),
			)
		);
		$add     = $this->post(
			array(
				'collection_id' => $theirs,
				'product_ids'   => array( $this->red ),
			)
		);

		self::assertSame( 400, $product->get_status() );
		self::assertSame( 'product_not_usable', ( (array) $product->get_data() )['data']['reason'] ?? null );
		self::assertSame( 400, $add->get_status() );
		self::assertSame( 'collection_unknown', ( (array) $add->get_data() )['data']['reason'] ?? null );
		self::assertSame( 0, $this->count_rows( Tables::COLLECTION_REQUESTS ) );
	}

	public function test_without_the_plugin_nothing_can_be_requested(): void {
		$this->active = false;

		self::assertFalse( ( (array) $this->get()->get_data() )['available'] );

		$response = $this->post(
			array(
				'name'        => 'Nova Tee',
				'product_ids' => array( $this->red, $this->blue ),
			)
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 0, $this->count_rows( Tables::COLLECTION_REQUESTS ) );
	}

	public function test_only_a_creator_gets_in(): void {
		wp_set_current_user( 0 );
		self::assertSame( 401, $this->get()->get_status() );

		wp_set_current_user( $this->create_user( 'customer' ) );
		self::assertSame( 403, $this->get()->get_status() );
	}

	public function test_the_tab_shows_the_next_step_and_its_forms_do_it(): void {
		$this->post(
			array(
				'name'        => '<b>Nova</b> Tee',
				'product_ids' => array( $this->red, $this->blue ),
			)
		);
		$id = $this->repository->open()[0]->id;

		wp_set_current_user( 1 );
		$html = $this->html();

		self::assertStringContainsString( 'New collection: Nova Tee', $html );
		self::assertStringContainsString( 'value="' . CollectionRequestActions::APPROVE . '"', $html );
		self::assertSame( 1, $this->page->approval_tab()->waiting() );

		$location = $this->submit( CollectionRequestActions::APPROVE, $id );

		self::assertStringContainsString( CollectionRequestsPage::RESULT_ARG . '=approved', $location );
		self::assertSame( CollectionRequestStatus::Approved, $this->repository->find( $id )?->status );
		self::assertStringContainsString( 'Check the collection and publish it', $this->html() );

		$location = $this->submit( CollectionRequestActions::DECLINE, $id, 'Not the same kind of product.' );

		self::assertStringContainsString( CollectionRequestsPage::RESULT_ARG . '=declined', $location );
		self::assertContains( 'collection_declined:' . $this->creator->id, $this->notified );
		self::assertStringContainsString( 'Declined: Not the same kind of product.', $this->html() );
	}

	public function test_an_addition_to_a_published_collection_offers_the_second_click(): void {
		$mine = $this->collection( array( $this->red ) );
		$this->post(
			array(
				'collection_id' => $mine,
				'product_ids'   => array( $this->blue ),
			)
		);
		$id = $this->repository->open()[0]->id;

		wp_set_current_user( 1 );

		self::assertStringContainsString( CollectionRequestsPage::RESULT_ARG . '=approved_waiting', $this->submit( CollectionRequestActions::APPROVE, $id ) );
		self::assertStringContainsString( 'value="' . CollectionRequestActions::ADD_NOW . '"', $this->html() );
	}

	public function test_open_gifts_of_a_collection_itself_are_shown(): void {
		$collection = $this->collection( array( $this->red ) );

		foreach ( array( $collection, $this->red ) as $order => $product ) {
			$this->wpdb->insert(
				$this->table( Tables::GIFTS ),
				array(
					'creator_id'      => $this->creator->id,
					'order_id'        => 900 + $order,
					'product_id'      => $product,
					'unit_price'      => '100.0000',
					'shipping_credit' => '50.0000',
					'currency'        => 'DKK',
					'status'          => Gift::WON,
					'created_at'      => '2026-11-22 10:00:00',
					'updated_at'      => '2026-11-22 10:00:00',
				)
			);
		}

		$affected = ( new CollectionGiftWarnings( $this->wpdb, new DesignCollections() ) )->affected();

		self::assertSame( array( $collection ), array_column( $affected, 'product_id' ), 'A design is given as before.' );

		wp_set_current_user( 1 );
		self::assertStringContainsString( 'Gift #' . $affected[0]['id'], $this->html() );
	}

	/**
	 * Sends a form of the tab.
	 *
	 * @param string $action Action.
	 * @param int    $id     Request.
	 * @param string $note   Note.
	 */
	private function submit( string $action, int $id, string $note = '' ): string {
		return $this->actions->process(
			array(
				'action'     => $action,
				'request_id' => (string) $id,
				'_wpnonce'   => wp_create_nonce( CollectionRequestActions::nonce_action( $action, $id ) ),
				'note'       => $note,
			)
		);
	}

	/**
	 * The tab's HTML.
	 */
	private function html(): string {
		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	/**
	 * `GET /me/collections`.
	 */
	private function get(): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/merchboard/v1/me/collections' ) );
	}

	/**
	 * `POST /me/collections`.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private function post( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/merchboard/v1/me/collections' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * A published collection as the plugin stores it.
	 *
	 * @param int[] $designs Products.
	 */
	private function collection( array $designs ): int {
		$id = $this->posts->add( 'Collection', 'publish', array() );

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

		foreach ( $designs as $design ) {
			update_post_meta( $design, DesignCollections::GROUP_KEY, $id );
		}

		return $id;
	}
}
