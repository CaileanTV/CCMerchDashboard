<?php
/**
 * Requests to remove a legacy product (ADR-083).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\ProductRemovalActions;
use MerchBoard\Modules\WooCommerce\Admin\ProductRemovalsPage;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalException;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalRepository;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovals;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalsCleanup;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalStatus;
use MerchBoard\Modules\WooCommerce\Rest\MeProductsController;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeProductDetails;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A creator asks, an administrator decides by hand, and the product is never
 * touched by any of it. WooCommerce is not loaded: products are posts.
 */
#[CoversClass( ProductRemovals::class )]
#[CoversClass( ProductRemovalActions::class )]
final class ProductRemovalsTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private ProductRemovalRepository $requests;

	private ProductRemovals $removals;

	private FakeProductDetails $details;

	private int $user;

	private Creator $creator;

	private Creator $other;

	private int $product;

	/**
	 * Products that count as designer products here.
	 *
	 * @var list<int>
	 */
	private array $designer = array();

	private ?\Closure $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->posts    = new ProductPosts( $this->wpdb );
		$this->requests = new ProductRemovalRepository( $this->wpdb );
		$this->details  = new FakeProductDetails();
		$this->posts->remove_all();

		$creators      = $this->service( CreatorService::class );
		$this->user    = $this->create_user( 'nova' );
		$other         = $this->create_user( 'sol' );
		$repository    = $this->service( CreatorRepository::class );
		$this->creator = $creators->create( 'Nova', CreatorStatus::Active, $this->user );
		$this->other   = $creators->create( 'Sol', CreatorStatus::Active, $other );
		$this->product = $this->posts->add( 'Logo Hoodie', 'publish', array( (string) $this->creator->id ) );
		$this->creator = $repository->find( $this->creator->id ) ?? self::fail( 'No creator.' );

		$this->removals = new ProductRemovals(
			$this->requests,
			new CreatorProducts( $this->wpdb ),
			$this->service( Transaction::class ),
			$this->service( AuditTrail::class ),
			fn( int $product ): bool => in_array( $product, $this->designer, true )
		);

		$controller     = new MeProductsController(
			$this->service( CreatorContext::class ),
			new CreatorProducts( $this->wpdb ),
			$this->details,
			$this->service( Logger::class ),
			new LedgerCreatorProfits( $this->wpdb ),
			$this->requests,
			$this->removals
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

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_a_request_waits_for_an_administrator_and_never_touches_the_product(): void {
		$before = $this->product_state();
		$sent   = did_action( 'merchboard_notify' );

		wp_set_current_user( $this->user );
		$request = $this->removals->request( $this->creator, $this->product );

		self::assertSame( ProductRemovalStatus::Pending, $request->status );
		self::assertSame( 'Logo Hoodie', $request->product_name );
		self::assertSame( $this->user, $request->requested_by );
		self::assertSame( $sent + 1, did_action( 'merchboard_notify' ), 'The administrator is told.' );
		self::assertSame( array( $request->id ), array_map( static fn( $row ): int => $row->id, $this->requests->pending() ) );
		self::assertSame( $before, $this->product_state() );

		wp_set_current_user( 1 );
		$done = $this->removals->complete( $request->id );

		self::assertSame( ProductRemovalStatus::Completed, $done->status );
		self::assertSame( 1, $done->handled_by );
		self::assertSame( array(), $this->requests->pending() );
		self::assertSame( $sent + 2, did_action( 'merchboard_notify' ), 'The creator is told.' );
		self::assertSame( $before, $this->product_state(), 'Closing a request removes nothing.' );
		self::assertSame( array( 'removal_id' => $request->id, 'product_id' => $this->product ), $this->audit( AuditAction::ProductRemovalRequested )[0] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertCount( 1, $this->audit( AuditAction::ProductRemovalCompleted ) );
	}

	public function test_a_declined_request_carries_the_note_and_can_be_made_again(): void {
		$before = $this->product_state();

		wp_set_current_user( $this->user );
		$first = $this->removals->request( $this->creator, $this->product );

		wp_set_current_user( 1 );
		$this->expect_refusal( ProductRemovalException::NOTE_REQUIRED, fn() => $this->removals->decline( $first->id, '   ' ) );

		$declined = $this->removals->decline( $first->id, 'It is part of a <b>campaign</b>.' );

		self::assertSame( ProductRemovalStatus::Declined, $declined->status );
		self::assertSame( 'It is part of a campaign.', $declined->note );
		self::assertSame( 'It is part of a campaign.', $this->audit( AuditAction::ProductRemovalDeclined )[0]['decision_note'] ?? null );
		$this->expect_refusal( ProductRemovalException::NOT_PENDING, fn() => $this->removals->complete( $first->id ) );

		wp_set_current_user( $this->user );
		self::assertNotSame( $first->id, $this->removals->request( $this->creator, $this->product )->id );
		self::assertSame( $before, $this->product_state() );
	}

	public function test_only_one_request_waits_per_product(): void {
		wp_set_current_user( $this->user );
		$sent  = did_action( 'merchboard_notify' );
		$first = $this->removals->request( $this->creator, $this->product );

		self::assertSame( $first->id, $this->removals->request( $this->creator, $this->product )->id );
		self::assertSame( 1, $this->count_rows( Tables::PRODUCT_REMOVAL_REQUESTS ) );
		self::assertSame( $sent + 1, did_action( 'merchboard_notify' ) );
	}

	public function test_another_creators_and_a_designer_product_are_refused(): void {
		wp_set_current_user( $this->user );
		$theirs = $this->posts->add( 'Sol tee', 'publish', array( (string) $this->other->id ) );
		$draft  = $this->posts->add( 'Draft tee', 'draft', array( (string) $this->creator->id ) );
		$design = $this->posts->add( 'Designer tee', 'publish', array( (string) $this->creator->id ) );

		$this->designer = array( $design );

		$this->expect_refusal( ProductRemovalException::NOT_FOUND, fn() => $this->removals->request( $this->creator, $theirs ) );
		$this->expect_refusal( ProductRemovalException::NOT_FOUND, fn() => $this->removals->request( $this->creator, $draft ) );
		$this->expect_refusal( ProductRemovalException::NOT_FOUND, fn() => $this->removals->request( $this->creator, 999999 ) );
		$this->expect_refusal( ProductRemovalException::DESIGNER_PRODUCT, fn() => $this->removals->request( $this->creator, $design ) );
		self::assertSame( 0, $this->count_rows( Tables::PRODUCT_REMOVAL_REQUESTS ) );
	}

	public function test_only_an_administrator_decides(): void {
		wp_set_current_user( $this->user );
		$request = $this->removals->request( $this->creator, $this->product );

		$this->expect_refusal( ProductRemovalException::NOT_AUTHORIZED, fn() => $this->removals->complete( $request->id ) );
		$this->expect_refusal( ProductRemovalException::NOT_AUTHORIZED, fn() => $this->removals->decline( $request->id, 'No.' ) );
		$this->expect_refusal( ProductRemovalException::NOT_AUTHORIZED, fn() => $this->removals->decline( $request->id, '' ) );
		self::assertCount( 1, $this->requests->pending() );
	}

	public function test_the_rest_route_answers_the_creator(): void {
		wp_set_current_user( $this->user );

		$first = $this->post( $this->product );
		self::assertSame( 200, $first->get_status() );
		self::assertSame( array( 'removal_pending' => true ), $first->get_data() );
		self::assertSame( 200, $this->post( $this->product )->get_status() );
		self::assertSame( 1, $this->count_rows( Tables::PRODUCT_REMOVAL_REQUESTS ) );

		$list = rest_do_request( new WP_REST_Request( 'GET', '/merchboard/v1/me/products' ) )->get_data();
		self::assertTrue( $list[0]['removal_pending'] ?? null );
		self::assertFalse( $list[0]['designer'] ?? null );

		$theirs = $this->posts->add( 'Sol tee', 'publish', array( (string) $this->other->id ) );
		self::assertSame( 404, $this->post( $theirs )->get_status() );

		$this->designer = array( $this->product );
		$design         = $this->post( $this->product );
		self::assertSame( 400, $design->get_status() );
		self::assertSame( 'designer_product', $design->get_data()['data']['reason'] ?? null );

		wp_set_current_user( $this->create_user( 'fan' ) );
		self::assertSame( 403, $this->post( $this->product )->get_status() );

		wp_set_current_user( 0 );
		self::assertSame( 401, $this->post( $this->product )->get_status() );
	}

	public function test_the_list_names_what_a_designer_row_holds(): void {
		$this->wpdb->insert( $this->table( Tables::DESIGNER_PRODUCTS ), $this->designer_row() );
		wp_set_current_user( $this->user );

		$list = rest_do_request( new WP_REST_Request( 'GET', '/merchboard/v1/me/products' ) )->get_data();

		self::assertTrue( $list[0]['designer'] ?? null );
		self::assertFalse( $list[0]['removal_pending'] ?? null );
	}

	public function test_the_page_lists_the_request_and_the_actions_decide_it(): void {
		wp_set_current_user( $this->user );
		$request = $this->removals->request( $this->creator, $this->product );

		wp_set_current_user( 1 );
		$page    = new ProductRemovalsPage( $this->requests, $this->service( CreatorRepository::class ), $this->service( Logger::class ) );
		$actions = new ProductRemovalActions( $this->removals, $this->service( Logger::class ) );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Logo Hoodie', $html );
		self::assertStringContainsString( 'Nova', $html );
		self::assertStringContainsString( 'post.php?post=' . $this->product, $html );

		$url = $actions->process(
			array(
				'action'     => ProductRemovalActions::COMPLETE,
				'request_id' => (string) $request->id,
				'_wpnonce'   => wp_create_nonce( ProductRemovalActions::nonce_action( ProductRemovalActions::COMPLETE, $request->id ) ),
			)
		);

		self::assertStringContainsString( 'completed_in_shop', $url, 'The administrator sees that the product still exists.' );
		self::assertSame( 'publish', get_post_status( $this->product ) );
		self::assertNotNull( ProductRemovalsPage::result_message( 'completed_in_shop' ) );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No requests are waiting.', $html );
	}

	public function test_deleting_the_creator_deletes_their_requests_and_not_the_product(): void {
		wp_set_current_user( $this->user );
		$this->removals->request( $this->creator, $this->product );

		$cleanup = new ProductRemovalsCleanup( $this->requests );

		self::assertSame( array( 'rows' => 1 ), $cleanup->remove( $this->creator ) );
		self::assertSame( 0, $this->count_rows( Tables::PRODUCT_REMOVAL_REQUESTS ) );
		self::assertSame( 'publish', get_post_status( $this->product ) );
	}

	/**
	 * A designer row for the product.
	 *
	 * @return array<string, mixed>
	 */
	private function designer_row(): array {
		$columns = $this->wpdb->get_results( 'SHOW COLUMNS FROM ' . $this->table( Tables::DESIGNER_PRODUCTS ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test table.
		$row     = array();

		foreach ( (array) $columns as $column ) {
			if ( 'NO' === $column['Null'] && null === $column['Default'] && 'auto_increment' !== $column['Extra'] ) {
				$row[ $column['Field'] ] = str_contains( (string) $column['Type'], 'int' ) ? 1 : ( 'datetime' === $column['Type'] ? '2026-10-31 12:00:00' : 'x' );
			}
		}

		$row['wc_product_id'] = $this->product;

		return $row;
	}

	/**
	 * Everything stored about the product.
	 *
	 * @return array<int, mixed>
	 */
	private function product_state(): array {
		clean_post_cache( $this->product );

		return array(
			$this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $this->wpdb->posts, $this->product ), ARRAY_A ),
			$this->wpdb->get_results( $this->wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE post_id = %d ORDER BY meta_id', $this->wpdb->postmeta, $this->product ), ARRAY_A ),
		);
	}

	/**
	 * Dispatches the request as the current user.
	 *
	 * @param int $product Product.
	 */
	private function post( int $product ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'POST', '/merchboard/v1/me/products/' . $product . '/removal-request' ) );
	}

	/**
	 * The audited contexts of an action.
	 *
	 * @param AuditAction $action Action.
	 * @return list<array<mixed>>
	 */
	private function audit( AuditAction $action ): array {
		$contexts = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT context FROM %i WHERE action = %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), $action->value ) );

		return array_map( static fn( ?string $json ): array => (array) json_decode( (string) $json, true ), $contexts );
	}

	/**
	 * Asserts a refusal with a reason.
	 *
	 * @param string   $reason Expected reason.
	 * @param callable $call   The call.
	 */
	private function expect_refusal( string $reason, callable $call ): void {
		try {
			$call();
			self::fail( 'Not refused: ' . $reason );
		} catch ( ProductRemovalException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
