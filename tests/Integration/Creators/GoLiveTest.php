<?php
/**
 * Integration tests of creators going live (ADR-091, ADR-093).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Creators;

use Closure;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Admin\GoLivePage;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\Agreement\AgreementRepository;
use MerchBoard\Modules\Creators\Signup\SignupRepository;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\GoLive\GoLiveException;
use MerchBoard\Modules\Creators\GoLive\GoLiveRepository;
use MerchBoard\Modules\Creators\GoLive\GoLiveRequests;
use MerchBoard\Modules\Creators\GoLive\GoLiveShop;
use MerchBoard\Modules\Creators\Rest\MeGoLiveController;
use MerchBoard\Modules\Creators\Signup\SignupSettings;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\GoLive\WooCommerceGoLiveShop;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorPricingSettings;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatHistoryRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A creator who signed up asks once the agreement is accepted; approving
 * makes them live, creates their category under the parent and sets the
 * markup; declining needs a note. A category that cannot be made is tried
 * again from the tab.
 */
#[CoversClass( GoLiveRequests::class )]
#[CoversClass( GoLiveRepository::class )]
#[CoversClass( MeGoLiveController::class )]
#[CoversClass( WooCommerceGoLiveShop::class )]
final class GoLiveTest extends IntegrationTestCase {

	private int $user = 0;

	private int $parent = 0;

	/**
	 * Provisions the fake shop was asked for.
	 *
	 * @var list<array{0: int, 1: string, 2: int, 3: string}>
	 */
	private array $provisioned = array();

	private bool $shop_fails = false;

	private ?Closure $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		wp_set_current_user( 1 );
		register_taxonomy( 'product_cat', 'product' );

		$parent       = wp_insert_term( 'MB-GOLIVE Creators', 'product_cat' );
		$this->parent = is_array( $parent ) ? (int) $parent['term_id'] : 0;
		$this->user   = $this->create_user( 'golive' );
		$this->service( Agreement::class )->publish( '<p>Agreement</p>', false );
		SignupSettings::save( true, $this->parent );

		$controller     = new MeGoLiveController( $this->service( CreatorContext::class ), $this->requests(), $this->service( Logger::class ) );
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

		foreach ( (array) get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
			wp_delete_term( (int) $term_id, 'product_cat' );
		}

		delete_option( SignupSettings::OPTION );
		delete_option( Agreement::DRAFT_OPTION );
		parent::tearDown();
	}

	public function test_the_creator_asks_once_the_agreement_is_accepted(): void {
		$creator = $this->signed_up();
		wp_set_current_user( $this->user );

		$refused = $this->call( 'POST' );
		self::assertSame( array( 400, 'agreement_required' ), array( $refused->get_status(), $refused->get_data()['data']['reason'] ?? null ) );

		$this->service( Agreement::class )->accept( $creator, $this->user, 1, true );
		$asked = $this->call( 'POST' );
		self::assertSame( 200, $asked->get_status() );
		self::assertSame( array( false, true, 'pending' ), array( $asked->get_data()['live'], $asked->get_data()['agreement'], $asked->get_data()['request']['status'] ?? null ) );
		self::assertSame( 'request_pending', $this->call( 'POST' )->get_data()['data']['reason'] ?? null, 'One request at a time.' );
		self::assertContains( 'admin_go_live_requested', array_column( $this->rows( Tables::NOTIFICATIONS ), 'type' ) );
	}

	public function test_approving_makes_the_creator_live_with_category_and_markup(): void {
		$creator = $this->asked();
		$id      = $this->pending_id( $creator->id );
		$went    = did_action( GoLiveRequests::WENT_LIVE );

		$this->refused( GoLiveException::MARKUP, fn() => $this->requests( true )->approve( $id, 'Nova', '120' ) );
		$this->refused( GoLiveException::NAME, fn() => $this->requests( true )->approve( $id, ' ', '20' ) );
		self::assertFalse( ( new CreatorRepository( $this->wpdb ) )->find( $creator->id )?->is_live(), 'Refused before anything changed.' );

		self::assertTrue( $this->requests( true )->approve( $id, 'Nova Shop', '20' ) );

		self::assertTrue( ( new CreatorRepository( $this->wpdb ) )->find( $creator->id )?->is_live() );
		$mapping = ( new CreatorCategoryRepository( $this->wpdb ) )->for_creator( $creator->id );
		self::assertCount( 1, $mapping );
		$term = get_term( $mapping[0]->term_id, 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $term );
		self::assertSame( array( 'Nova Shop', $this->parent ), array( $term->name, $term->parent ), 'Created under the parent category (ADR-093).' );
		self::assertSame( 2000, ( new CreatorMarkupRepository( $this->wpdb ) )->find( $creator->id )?->bps );
		self::assertSame( $went + 1, did_action( GoLiveRequests::WENT_LIVE ), 'The designer releases the waiting designs.' );
		self::assertContains( 'account_live_approved', array_column( $this->rows( Tables::NOTIFICATIONS ), 'type' ) );
		$this->refused( GoLiveException::NOT_PENDING, fn() => $this->requests( true )->approve( $id, 'Nova Shop', '20' ) );
	}

	public function test_a_category_that_cannot_be_made_is_tried_again(): void {
		$creator          = $this->asked();
		$this->shop_fails = true;

		self::assertFalse( $this->requests()->approve( $this->pending_id( $creator->id ), 'Nova', '10' ), 'Live, but without the category.' );
		self::assertTrue( ( new CreatorRepository( $this->wpdb ) )->find( $creator->id )?->is_live() );

		$this->shop_fails = false;
		self::assertTrue( $this->requests()->retry_category( $creator->id, 'Nova', '10' ) );
		self::assertSame( array( $creator->id, 'Nova', $this->parent, '10' ), $this->provisioned[ count( $this->provisioned ) - 1 ] );
	}

	public function test_declining_needs_a_note_and_the_creator_can_ask_again(): void {
		$creator = $this->asked();
		$id      = $this->pending_id( $creator->id );

		$this->refused( GoLiveException::NOTE, fn() => $this->requests()->decline( $id, ' ' ) );
		$this->requests()->decline( $id, 'Add one more design first.' );

		wp_set_current_user( $this->user );
		$shown = $this->call( 'GET' )->get_data();
		self::assertSame( array( 'declined', 'Add one more design first.' ), array( $shown['request']['status'] ?? null, $shown['request']['note'] ?? null ) );
		self::assertSame( 200, $this->call( 'POST' )->get_status(), 'Asking again.' );
		self::assertFalse( ( new CreatorRepository( $this->wpdb ) )->find( $creator->id )?->is_live() );
	}

	public function test_approving_needs_the_parent_category_and_the_capability(): void {
		$creator = $this->asked();
		$id      = $this->pending_id( $creator->id );

		SignupSettings::save( true, 0 );
		$this->refused( GoLiveException::PARENT_MISSING, fn() => $this->requests()->approve( $id, 'Nova', '10' ) );

		SignupSettings::save( true, $this->parent );
		wp_set_current_user( $this->user );
		$this->refused( GoLiveException::NOT_AUTHORIZED, fn() => $this->requests()->approve( $id, 'Nova', '10' ) );
	}

	public function test_the_go_live_tab_shows_the_request_and_decides_it(): void {
		$creator = $this->asked();
		$page    = new GoLivePage( $this->requests(), new GoLiveRepository( $this->wpdb ), new CreatorRepository( $this->wpdb ), new SignupRepository( $this->wpdb ), new AgreementRepository( $this->wpdb ), $this->fake_shop(), $this->service( Logger::class ) );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Nova', $html );
		self::assertStringContainsString( 'Accepted the agreement, version 1', $html );
		self::assertSame( 1, $page->approval_tab()->waiting(), 'The red count.' );

		$input = array(
			'decision' => 'decline',
			'id'       => (string) $this->pending_id( $creator->id ),
		);
		self::assertSame( 'note_required', $page->handle( $input ) );
		self::assertSame( 'declined', $page->handle( $input + array( 'note' => 'Not yet.' ) ) );
	}

	/**
	 * The service with the fake shop, or the WooCommerce shop.
	 *
	 * @param bool $woocommerce Whether to use the real WooCommerce shop.
	 */
	private function requests( bool $woocommerce = false ): GoLiveRequests {
		$shop = $woocommerce ? $this->woocommerce_shop() : $this->fake_shop();

		return new GoLiveRequests( new GoLiveRepository( $this->wpdb ), new CreatorRepository( $this->wpdb ), $this->service( Agreement::class ), static fn(): GoLiveShop => $shop, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
	}

	/**
	 * The WooCommerce shop with the real category and pricing services.
	 */
	private function woocommerce_shop(): GoLiveShop {
		$creators = new CreatorRepository( $this->wpdb );
		$audit    = $this->service( AuditTrail::class );
		$tx       = $this->service( Transaction::class );
		$links    = new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $tx, $this->service( ProtectedProductMeta::class ) );
		$pricing  = new CreatorPricingSettings( $creators, new CreatorMarkupRepository( $this->wpdb ), new ProductMarkupRepository( $this->wpdb ), $links, new PostMetaPricingProductStore(), $audit, $tx, new CreatorVatRepository( $this->wpdb ), new CreatorVatHistoryRepository( $this->wpdb ) );

		return new WooCommerceGoLiveShop( new CreatorCategoryService( new CreatorCategoryRepository( $this->wpdb ), $creators, $audit, $tx ), new CreatorCategoryRepository( $this->wpdb ), $pricing );
	}

	/**
	 * A shop that records what it was asked and can fail.
	 */
	private function fake_shop(): GoLiveShop {
		$test = $this;

		return new class( $test ) implements GoLiveShop {

			/**
			 * Constructor.
			 *
			 * @param GoLiveTest $test The test.
			 */
			public function __construct( private GoLiveTest $test ) {
			}

			public function available(): bool {
				return true;
			}

			public function check_markup( string $percent ): void {
			}

			public function has_category( int $creator_id ): bool {
				return false;
			}

			public function provision( int $creator_id, string $name, int $parent_id, string $percent ): void {
				$this->test->provisioned( $creator_id, $name, $parent_id, $percent );
			}
		};
	}

	/**
	 * Records a provision of the fake shop.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $name       Name.
	 * @param int    $parent_id  Parent.
	 * @param string $percent    Markup.
	 * @throws GoLiveException When the shop is set to fail.
	 */
	public function provisioned( int $creator_id, string $name, int $parent_id, string $percent ): void {
		if ( $this->shop_fails ) {
			throw new GoLiveException( GoLiveException::CATEGORY_FAILED );
		}

		$this->provisioned[] = array( $creator_id, $name, $parent_id, $percent );
	}

	/**
	 * A creator who signed up, linked to the test user.
	 */
	private function signed_up(): Creator {
		$id = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->user )->id;
		$this->wpdb->update(
			$this->wpdb->prefix . Tables::CREATORS,
			array(
				'awaiting_live'      => 1,
				'agreement_required' => 1,
			),
			array( 'id' => $id )
		);
		$creator = ( new CreatorRepository( $this->wpdb ) )->find( $id );
		self::assertNotNull( $creator );

		return $creator;
	}

	/**
	 * A creator who signed up, accepted the agreement and asked to go live.
	 */
	private function asked(): Creator {
		$creator = $this->signed_up();
		$this->service( Agreement::class )->accept( $creator, $this->user, 1, true );
		$this->requests()->request( $creator, $this->user );

		return $creator;
	}

	/**
	 * The pending request of a creator.
	 *
	 * @param int $creator_id Creator.
	 */
	private function pending_id( int $creator_id ): int {
		$pending = ( new GoLiveRepository( $this->wpdb ) )->pending_for( $creator_id );
		self::assertNotNull( $pending );

		return $pending['id'];
	}

	/**
	 * A request to `/me/go-live`.
	 *
	 * @param string $method Method.
	 */
	private function call( string $method ): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, '/merchboard/v1' . MeGoLiveController::ROUTE ) );
	}

	/**
	 * Expects a refusal with a reason.
	 *
	 * @param string   $reason    Reason.
	 * @param callable $operation Operation.
	 */
	private function refused( string $reason, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Expected a refusal: ' . $reason );
		} catch ( GoLiveException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
