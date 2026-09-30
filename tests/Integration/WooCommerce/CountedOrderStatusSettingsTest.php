<?php
/**
 * Integration tests for the counted order statuses setting (ADR-038, S1-S14).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatusSettings;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * The setting on a real database and option: audit and option are written
 * atomically, and a changed list applies to the next synchronization of an
 * order only (no backfill, no recalculation, history unchanged).
 */
final class CountedOrderStatusSettingsTest extends IntegrationTestCase {

	/**
	 * WooCommerce' registered statuses (the suite has no WooCommerce).
	 */
	private const REGISTERED = array(
		'wc-pending'           => 'Pending payment',
		'wc-processing'        => 'Processing',
		'wc-on-hold'           => 'On hold',
		'wc-completed'         => 'Completed',
		'wc-cancelled'         => 'Cancelled',
		'wc-refunded'          => 'Refunded',
		'wc-failed'            => 'Failed',
		'wc-awaiting_shipment' => 'Awaiting shipment',
	);

	private CountedOrderStatusSettings $settings;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private int $clock = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( Settings::OPTION );

		$transaction    = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$this->settings = new CountedOrderStatusSettings(
			$this->service( AuditTrail::class ),
			$transaction,
			new CountedOrderStatuses(),
			static fn(): array => self::REGISTERED
		);
		$this->reader   = new FakeOrderReader();
		$this->sync     = new SalesSynchronizer(
			new SalesRepository( $this->wpdb, $transaction ),
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-30 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
		);

		self::assertTrue( current_user_can( Capabilities::MANAGE_SETTINGS ), 'The administrator manages settings.' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_update_option_' . Settings::OPTION );
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_a_change_writes_the_option_and_one_audit_record(): void {
		self::assertTrue( $this->settings->save( array( 'wc-on-hold', 'completed', 'processing' ) ) );

		wp_cache_delete( 'alloptions', 'options' );
		self::assertSame( array( 'completed', 'on-hold', 'processing' ), Settings::counted_order_statuses() );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertCount( 1, $audit );
		self::assertSame( 'settings_updated', $audit[0]['action'] );
		self::assertSame(
			array(
				'setting'        => 'counted_order_statuses',
				'previous_value' => array( 'processing', 'completed' ),
				'value'          => array( 'completed', 'on-hold', 'processing' ),
			),
			json_decode( (string) $audit[0]['context'], true )
		);

		self::assertFalse( $this->settings->save( array( 'processing', 'on-hold', 'completed' ) ), 'Same statuses: no change.' );
		self::assertCount( 1, $this->rows( Tables::AUDIT_LOG ), 'No audit record for an unchanged list.' );
	}

	public function test_a_failed_option_write_rolls_back_the_audit_record(): void {
		add_filter( 'pre_update_option_' . Settings::OPTION, static fn( mixed $value, mixed $old ): mixed => $old, 10, 2 );

		try {
			$this->settings->save( array( 'completed' ) );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not save settings.', $e->getMessage() );
		}

		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ), 'The audit record was rolled back.' );
		self::assertFalse( get_option( Settings::OPTION ) );
		self::assertSame( array( 'processing', 'completed' ), Settings::counted_order_statuses() );
	}

	public function test_a_new_counted_status_opens_a_cycle_on_the_next_synchronization(): void {
		$this->order( 7, 'on-hold' );
		$this->sync->sync( 7 );
		self::assertSame( 0, $this->count_rows( Tables::SALES ), 'on-hold does not count by default.' );

		$this->settings->save( array( 'completed', 'on-hold', 'processing' ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES ), 'Saving the setting writes no ledger rows.' );

		$this->sync->sync( 7 );

		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( '1:open' ), $this->cycles() );
	}

	public function test_a_removed_status_reverses_and_closes_on_the_next_synchronization(): void {
		$this->order( 7, 'completed' );
		$this->sync->sync( 7 );
		self::assertSame( array( '1:open' ), $this->cycles() );

		$this->settings->save( array( 'processing' ) );
		self::assertSame( array( 's:11:1' ), $this->keys(), 'History is unchanged until the order is synchronized.' );

		$this->sync->sync( 7 );

		self::assertSame( array( 's:11:1', 'v:11:1' ), $this->keys() );
		self::assertSame( array( '1:open', '1:close' ), $this->cycles() );
		self::assertSame( '100.0000', $this->rows( Tables::SALES )[0]['net_amount'], 'The original sale row is never changed.' );
	}

	public function test_no_order_is_recalculated_until_it_is_synchronized(): void {
		$this->order( 7, 'completed' );
		$this->order( 8, 'completed' );
		$this->sync->sync( 7 );
		$this->sync->sync( 8 );
		$before = $this->rows( Tables::SALES );

		$this->settings->save( array( 'on-hold' ) );

		self::assertSame( $before, $this->rows( Tables::SALES ), 'No mass recalculation or backfill.' );
		self::assertSame( array( '1:open', '1:open' ), $this->cycles() );

		$this->sync->sync( 7 );

		self::assertSame( array( 's:11:1', 's:12:1', 'v:11:1' ), $this->keys(), 'Only the synchronized order changed.' );
		self::assertSame( array( '1:open', '1:open', '1:close' ), $this->cycles() );
	}

	public function test_an_underscore_status_is_stored_unchanged_and_counts_on_the_next_synchronization(): void {
		$this->order( 7, 'awaiting_shipment' );
		$this->sync->sync( 7 );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );

		self::assertTrue( $this->settings->save( array( 'wc-awaiting_shipment', 'completed' ) ) );

		wp_cache_delete( 'alloptions', 'options' );
		self::assertSame( array( 'awaiting_shipment', 'completed' ), Settings::counted_order_statuses() );
		self::assertStringContainsString( '"value":["awaiting_shipment","completed"]', (string) $this->rows( Tables::AUDIT_LOG )[0]['context'] );

		$this->sync->sync( 7 );

		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( '1:open' ), $this->cycles() );
	}

	public function test_the_slug_limit_fits_the_legacy_post_status_column_with_the_prefix(): void {
		$column = $this->wpdb->get_row( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $this->wpdb->posts, 'post_status' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check.

		self::assertIsArray( $column );
		self::assertSame( 'varchar(20)', strtolower( (string) $column['Type'] ) );
		self::assertSame( 20, strlen( 'wc-' . str_repeat( 'a', Settings::STATUS_SLUG_MAX_LENGTH ) ) );
	}

	public function test_denied_values_written_directly_never_count(): void {
		update_option( Settings::OPTION, array( Settings::COUNTED_ORDER_STATUSES => array( 'pending', 'refunded' ) ) );
		$this->order( 7, 'pending' );
		$this->order( 8, 'completed' );

		$this->sync->sync( 7 );
		$this->sync->sync( 8 );

		self::assertSame( array( 's:12:1' ), $this->keys(), 'Only order 8 (default applies) is recorded.' );
		self::assertSame( '8', (string) $this->rows( Tables::SALES )[0]['order_id'] );
	}

	/**
	 * Sets an order with one line of 100 (item id = order id + 4, so order 7
	 * has item 11; item ids are unique across orders, like WooCommerce').
	 *
	 * @param int    $id     Order id.
	 * @param string $status Status.
	 */
	private function order( int $id, string $status ): void {
		$this->reader->orders[ $id ] = array(
			'status'   => $status,
			'currency' => 'DKK',
			'lines'    => array( new OrderLineSnapshot( $id + 4, 20, 0, 'Line', 1, SalesAmount::parse( '100' ), 3 ) ),
			'refunds'  => array(),
		);
	}

	/**
	 * Event keys in insertion order.
	 *
	 * @return list<string|null>
	 */
	private function keys(): array {
		return array_column( $this->rows( Tables::SALES ), 'event_key' );
	}

	/**
	 * Cycle events as "cycle:event" in insertion order.
	 *
	 * @return list<string>
	 */
	private function cycles(): array {
		return array_map( static fn( array $row ): string => $row['cycle'] . ':' . $row['event'], $this->rows( Tables::SALES_CYCLES ) );
	}
}
