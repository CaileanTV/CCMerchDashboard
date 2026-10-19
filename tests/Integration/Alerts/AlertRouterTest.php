<?php
/**
 * Integration tests for the routing of purchase alerts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertHooks;
use MerchBoard\Modules\Alerts\AlertRouter;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\PaidOrder;
use MerchBoard\Modules\Alerts\PaidOrderLine;
use MerchBoard\Modules\Alerts\PaidOrderReader;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * ADR-065 pkt. 2 and 6: a paid order gives one alert per product line and
 * creator the product is attributed to, only for active creators, never
 * twice, and nothing while alerts are off or MerchAlert is active.
 */
#[CoversClass( AlertRouter::class )]
#[CoversClass( AlertEventRepository::class )]
#[CoversClass( AlertsAvailability::class )]
#[CoversClass( AlertHooks::class )]
final class AlertRouterTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private CreatorRepository $creators;

	private RecordingLogger $logger;

	private bool $merchalert = false;

	/**
	 * Category terms created by the test, deleted in tearDown().
	 *
	 * @var list<int>
	 */
	private array $terms = array();

	/**
	 * Orders the fake reader returns, by id.
	 *
	 * @var array<int, PaidOrder>
	 */
	private array $orders = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		register_taxonomy( 'product_cat', 'product' );

		$this->posts    = new ProductPosts( $this->wpdb );
		$this->creators = new CreatorRepository( $this->wpdb );
		$this->logger   = new RecordingLogger();
		$this->enable( true );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();

		foreach ( $this->terms as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}

		unregister_taxonomy( 'product_cat' );
		delete_option( Settings::OPTION );

		parent::tearDown();
	}

	public function test_alerts_are_off_by_default_and_record_nothing(): void {
		$this->enable( false );
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$product = $this->posts->add( 'Hoodie', 'publish', array( (string) $creator->id ) );
		$this->order( 101, array( $this->line( 1001, $product ) ) );

		self::assertFalse( Settings::alerts_enabled(), 'Off by default (ADR-065 pkt. 6).' );
		self::assertSame( 0, $this->router()->order_paid( 101 ) );
		self::assertSame( 0, $this->stored_alerts() );
	}

	public function test_nothing_is_recorded_while_merchalert_is_active(): void {
		$creator          = $this->creators->create( 'Nova', CreatorStatus::Active );
		$product          = $this->posts->add( 'Hoodie', 'publish', array( (string) $creator->id ) );
		$this->merchalert = true;
		$this->order( 101, array( $this->line( 1001, $product ) ) );

		self::assertSame( 0, $this->router()->order_paid( 101 ) );
		self::assertSame( 0, $this->stored_alerts() );
	}

	public function test_each_line_goes_to_the_creator_the_product_is_attributed_to(): void {
		$nova     = $this->creators->create( 'Nova', CreatorStatus::Active );
		$orion    = $this->creators->create( 'Orion', CreatorStatus::Active );
		$hoodie   = $this->posts->add( 'Hoodie', 'publish', array( (string) $nova->id ) );
		$mug      = $this->posts->add( 'Mug', 'publish', array( (string) $orion->id ) );
		$sticker  = $this->posts->add( 'Sticker', 'publish', array() );
		$shirts   = wp_insert_term( 'Alert test shirts', 'product_cat' );
		$clothing = wp_insert_term( 'Alert test clothing', 'product_cat' );
		self::assertIsArray( $shirts );
		self::assertIsArray( $clothing );
		$this->terms[] = (int) $shirts['term_id'];
		$this->terms[] = (int) $clothing['term_id'];
		wp_set_object_terms( $hoodie, array( (int) $shirts['term_id'], (int) $clothing['term_id'] ), 'product_cat' );

		$this->order(
			101,
			array(
				new PaidOrderLine( 1001, $hoodie, 0, 'Hoodie', '', 2, 55 ),
				new PaidOrderLine( 1002, $mug, 0, 'Mug', '', 1, 0 ),
				new PaidOrderLine( 1003, $sticker, 0, 'Sticker', '', 1, 0 ),
			),
			'anna_streams'
		);

		self::assertSame( 2, $this->router()->order_paid( 101 ), 'The unlinked sticker gets no alert.' );

		$events = $this->repository()->for_order( 101 );
		self::assertCount( 2, $events );
		self::assertSame( array( $nova->id, $orion->id ), array_map( static fn( AlertEvent $e ): int => $e->creator_id, $events ) );

		$hoodie_alert = $events[0];
		self::assertSame( '#101', $hoodie_alert->order_number );
		self::assertSame( 1001, $hoodie_alert->order_item_id );
		self::assertSame( $hoodie, $hoodie_alert->product_id );
		self::assertSame( 'Hoodie', $hoodie_alert->product_name );
		self::assertSame( 2, $hoodie_alert->quantity );
		self::assertSame( 55, $hoodie_alert->image_id );
		self::assertEqualsCanonicalizing( array( (int) $shirts['term_id'], (int) $clothing['term_id'] ), $hoodie_alert->category_ids );
		self::assertSame( 'anna_streams', $hoodie_alert->customer_username );
		self::assertSame( '2026-10-18 12:00:00', $hoodie_alert->created_at->format( 'Y-m-d H:i:s' ) );
		self::assertSame( array(), $events[1]->category_ids );
	}

	public function test_inactive_creators_get_no_alerts(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Inactive );
		$product = $this->posts->add( 'Hoodie', 'publish', array( (string) $creator->id ) );
		$this->order( 101, array( $this->line( 1001, $product ) ) );

		self::assertSame( 0, $this->router()->order_paid( 101 ) );
		self::assertSame( 0, $this->stored_alerts() );
	}

	public function test_a_variation_line_uses_its_parent_product_attribution(): void {
		$creator   = $this->creators->create( 'Nova', CreatorStatus::Active );
		$product   = $this->posts->add( 'Hoodie', 'publish', array( (string) $creator->id ) );
		$variation = $this->posts->add( 'Hoodie - L', 'publish', array(), 'product_variation', $product );
		$this->order( 101, array( new PaidOrderLine( 1001, $product, $variation, 'Hoodie - L', 'Size: L', 1, 0 ) ) );

		self::assertSame( 1, $this->router()->order_paid( 101 ) );

		$event = $this->repository()->for_order( 101 )[0];
		self::assertSame( $variation, $event->variation_id );
		self::assertSame( 'Size: L', $event->variation );
	}

	public function test_processing_then_completed_keeps_one_alert_per_line(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$product = $this->posts->add( 'Hoodie', 'publish', array( (string) $creator->id ) );
		$this->order( 101, array( $this->line( 1001, $product ) ) );
		$router = $this->router();

		self::assertSame( 1, $router->order_paid( 101 ) );
		self::assertSame( 0, $router->order_paid( 101 ), 'The second status change records nothing new.' );
		self::assertSame( 1, $this->stored_alerts() );
	}

	public function test_unknown_orders_and_ids_record_nothing(): void {
		self::assertSame( 0, $this->router()->order_paid( 999 ) );
		self::assertSame( 0, $this->router()->order_paid( 0 ) );
		self::assertSame( array(), $this->logger->entries );
	}

	public function test_a_database_failure_is_logged_without_personal_data_and_never_thrown(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$product = $this->posts->add( 'Hoodie', 'publish', array( (string) $creator->id ) );
		$this->order( 101, array( $this->line( 1001, $product ) ), 'anna_streams' );
		$drop = $this->wpdb->prepare( 'DROP TABLE %i', Tables::name( $this->wpdb, Tables::ALERT_EVENTS ) );
		self::assertIsString( $drop );
		$this->wpdb->query( $drop );
		$this->wpdb->suppress_errors( true );

		self::assertSame( 0, $this->router()->order_paid( 101 ) );
		self::assertCount( 1, $this->logger->entries );
		self::assertSame( 'error', $this->logger->entries[0]['level'] );
		self::assertSame( array( 101 ), array( $this->logger->entries[0]['context']['order_id'] ) );
		self::assertStringNotContainsString( 'anna_streams', (string) wp_json_encode( $this->logger->entries ) );
	}

	public function test_long_texts_are_cut_to_the_columns(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$product = $this->posts->add( 'Hoodie', 'publish', array( (string) $creator->id ) );
		$long    = str_repeat( 'æ', 300 );
		$this->order( 101, array( new PaidOrderLine( 1001, $product, 0, $long, $long, 1, 0 ) ), $long );

		self::assertSame( 1, $this->router()->order_paid( 101 ) );

		$event = $this->repository()->for_order( 101 )[0];
		self::assertSame( 255, mb_strlen( $event->product_name ) );
		self::assertSame( 255, mb_strlen( $event->variation ) );
		self::assertSame( 191, mb_strlen( $event->customer_username ) );
	}

	public function test_both_paid_statuses_are_hooked_and_record_one_alert(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$product = $this->posts->add( 'Hoodie', 'publish', array( (string) $creator->id ) );
		$this->order( 101, array( $this->line( 1001, $product ) ) );
		$hooks = new AlertHooks( $this->router() );
		$hooks->register();

		try {
			self::assertSame( 10, has_action( 'woocommerce_order_status_processing', array( $hooks, 'order_paid' ) ) );
			self::assertSame( 10, has_action( 'woocommerce_order_status_completed', array( $hooks, 'order_paid' ) ) );

			do_action( 'woocommerce_order_status_processing', 101 );
			do_action( 'woocommerce_order_status_completed', '101' );
			$hooks->order_paid( null );

			self::assertSame( 1, $this->stored_alerts(), 'Both paid statuses reach the router; one alert per line.' );
		} finally {
			remove_action( 'woocommerce_order_status_processing', array( $hooks, 'order_paid' ) );
			remove_action( 'woocommerce_order_status_completed', array( $hooks, 'order_paid' ) );
		}
	}

	/**
	 * Switches alerts on or off directly in the option.
	 *
	 * @param bool $enabled Whether alerts are on.
	 */
	private function enable( bool $enabled ): void {
		$settings                             = Settings::all();
		$settings[ Settings::ALERTS_ENABLED ] = $enabled;
		Settings::save( $settings );
	}

	/**
	 * Registers an order for the fake reader.
	 *
	 * @phpstan-param list<PaidOrderLine> $lines
	 *
	 * @param int             $order_id   Order id.
	 * @param PaidOrderLine[] $lines      Lines.
	 * @param string          $username   Username.
	 */
	private function order( int $order_id, array $lines, string $username = '' ): void {
		$this->orders[ $order_id ] = new PaidOrder( $order_id, '#' . $order_id, $username, $lines );
	}

	/**
	 * A simple product line.
	 *
	 * @param int $item_id    Order item id.
	 * @param int $product_id Product id.
	 */
	private function line( int $item_id, int $product_id ): PaidOrderLine {
		return new PaidOrderLine( $item_id, $product_id, 0, 'Product', '', 1, 0 );
	}

	/**
	 * The router with a fake order reader, a fixed clock and the MerchAlert flag.
	 */
	private function router(): AlertRouter {
		$orders = &$this->orders;
		$reader = new class( $orders ) implements PaidOrderReader {

			/**
			 * Constructor.
			 *
			 * @param array<int, PaidOrder> $orders Orders by id.
			 */
			public function __construct( private array &$orders ) {
			}

			public function read( int $order_id ): ?PaidOrder {
				return $this->orders[ $order_id ] ?? null;
			}
		};

		$products = new PostMetaProductStore();

		return new AlertRouter(
			$reader,
			new ProductCreatorLink( $products, $this->creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) ),
			$products,
			$this->creators,
			$this->repository(),
			new AlertsAvailability( fn(): bool => $this->merchalert ),
			$this->logger,
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-18 12:00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * The repository.
	 */
	private function repository(): AlertEventRepository {
		return new AlertEventRepository( $this->wpdb );
	}

	/**
	 * Number of stored alerts.
	 */
	private function stored_alerts(): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::name( $this->wpdb, Tables::ALERT_EVENTS ) ) );
	}
}
