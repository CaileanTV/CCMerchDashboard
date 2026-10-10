<?php
/**
 * Creator pricing storage on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkup;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricing;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SnapshotStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Synthetic ids only; every table is dropped before each test.
 */
final class CreatorPricingRepositoriesTest extends IntegrationTestCase {

	private int $creator_a;

	private int $creator_b;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators        = new CreatorRepository( $this->wpdb );
		$this->creator_a = $creators->create( 'Pris A', CreatorStatus::Active )->id;
		$this->creator_b = $creators->create( 'Pris B', CreatorStatus::Active )->id;
	}

	public function test_markup_is_0_by_default_and_round_trips_exactly(): void {
		$markups = new CreatorMarkupRepository( $this->wpdb );

		self::assertSame( 0, $markups->find( $this->creator_a )?->bps );

		$markups->save( $this->creator_a, MarkupBps::from_percent( '17.5' ) );
		$markups->save( $this->creator_b, new MarkupBps( 10000 ) );

		self::assertSame( 1750, $markups->find( $this->creator_a )?->bps );
		self::assertSame( 10000, $markups->find( $this->creator_b )?->bps );

		// Saving the same value again (0 affected rows) is fine.
		$markups->save( $this->creator_a, new MarkupBps( 1750 ) );
		self::assertSame( 1750, $markups->find( $this->creator_a )?->bps );
	}

	public function test_markup_of_a_missing_creator(): void {
		$markups = new CreatorMarkupRepository( $this->wpdb );

		self::assertNull( $markups->find( 999999 ) );

		$this->expectException( DatabaseException::class );
		$markups->save( 999999, new MarkupBps( 100 ) );
	}

	public function test_out_of_range_markup_stored_outside_the_domain_is_rejected_on_read(): void {
		$this->execute( 'UPDATE %i SET default_markup_bps = 10001 WHERE id = %d', $this->table( Tables::CREATORS ), $this->creator_a );

		$this->expectException( DatabaseException::class );
		( new CreatorMarkupRepository( $this->wpdb ) )->find( $this->creator_a );
	}

	public function test_product_markup_crud_one_per_product(): void {
		$products = new ProductMarkupRepository( $this->wpdb );

		self::assertNull( $products->find( 100 ) );

		$created = $products->save( new ProductMarkup( 100, $this->creator_a, new MarkupBps( 2500 ) ) );
		self::assertSame( 2500, $created->markup->bps );
		self::assertNotNull( $created->created_at );

		$replaced = $products->save( new ProductMarkup( 100, $this->creator_a, new MarkupBps( 1250 ) ) );
		self::assertSame( 1250, $replaced->markup->bps );
		self::assertEquals( $created->created_at, $replaced->created_at, 'Creation time kept.' );
		self::assertSame( 1, $this->count_rows( Tables::PRICING_OVERRIDES ), 'Never two shares for one product.' );

		$products->save( new ProductMarkup( 101, $this->creator_a, new MarkupBps( 0 ) ) );
		$products->save( new ProductMarkup( 102, $this->creator_b, new MarkupBps( 10000 ) ) );
		self::assertSame( array( 100, 101 ), array_map( static fn ( ProductMarkup $m ): int => $m->product_id, $products->for_creator( $this->creator_a ) ) );
		self::assertSame( '10000', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT markup_bps FROM %i WHERE product_id = 102', $this->table( Tables::PRICING_OVERRIDES ) ) ) );

		self::assertTrue( $products->delete( 100 ) );
		self::assertFalse( $products->delete( 100 ) );
		self::assertNull( $products->find( 100 ) );
	}

	public function test_out_of_range_product_markup_is_rejected_on_read(): void {
		( new ProductMarkupRepository( $this->wpdb ) )->save( new ProductMarkup( 100, $this->creator_a, new MarkupBps( 100 ) ) );
		$this->execute( 'UPDATE %i SET markup_bps = 10001 WHERE product_id = %d', $this->table( Tables::PRICING_OVERRIDES ), 100 );

		$this->expectException( DatabaseException::class );
		( new ProductMarkupRepository( $this->wpdb ) )->find( 100 );
	}

	public function test_the_product_markup_table_has_no_amount_and_item_pricing_is_gone(): void {
		$columns = $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::PRICING_OVERRIDES ) ) );

		self::assertSame( array( 'product_id', 'creator_id', 'markup_bps', 'created_at', 'updated_at' ), $columns );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->prefix . Tables::ITEM_PRICING ) ), 'Dropped by schema version 8.' );
		self::assertNotContains( Tables::ITEM_PRICING, Tables::all() );
		self::assertSame( array( Tables::ITEM_PRICING ), Tables::retired() );
	}

	public function test_snapshot_is_stored_and_read_back(): void {
		$snapshots = new SalesLinePricingRepository( $this->wpdb );
		$simple    = self::snapshot( 9001, $this->creator_a );
		$variation = new SalesLinePricing( 9002, 9000, $this->creator_a, 300, 302, SnapshotStatus::Share, 'DKK', self::time( '2026-10-09 10:00:00' ), 1000, MarkupSource::Product, 0, true );

		self::assertTrue( $snapshots->insert( $simple ) );
		self::assertTrue( $snapshots->insert( $variation ) );

		self::assertEquals( $simple, $snapshots->find( 9001 ) );
		self::assertEquals( $variation, $snapshots->find( 9002 ) );
		self::assertNull( $snapshots->find( 9003 ) );

		$row = $this->raw( 9001 );
		self::assertSame( array( 'share', '1667', 'creator', '2', '0' ), array( $row['status'], $row['share_bps'], $row['share_source'], $row['price_decimals'], $row['vat_registered'] ) );
		self::assertNull( $row['rule'], 'The withdrawn columns stay empty.' );
		self::assertNull( $row['base_unit'] );
		self::assertNull( $row['price_unit'] );
	}

	public function test_a_snapshot_of_the_withdrawn_model_reads_as_unknown_share(): void {
		$this->wpdb->insert(
			$this->table( Tables::SALES_LINE_PRICING ),
			array(
				'order_item_id'  => 9005,
				'order_id'       => 9000,
				'creator_id'     => $this->creator_a,
				'product_id'     => 200,
				'variation_id'   => 0,
				'status'         => 'priced',
				'currency'       => 'DKK',
				'rule'           => 'default',
				'markup_bps'     => 1900,
				'base_unit'      => '99.0000',
				'profit_unit'    => '18.8100',
				'price_unit'     => '117.8100',
				'captured_at'    => '2026-10-08 10:00:00',
				'vat_registered' => 0,
			)
		);

		$snapshot = ( new SalesLinePricingRepository( $this->wpdb ) )->find( 9005 );

		self::assertSame( SnapshotStatus::Priced, $snapshot?->status );
		self::assertFalse( $snapshot->has_share(), 'The old markup on a base price is never read as a share of the sale price.' );
		self::assertSame( '117.8100', $this->raw( 9005 )['price_unit'], 'The old values stay as they were.' );
	}

	public function test_snapshot_is_append_only_and_a_second_insert_keeps_the_first(): void {
		$snapshots = new SalesLinePricingRepository( $this->wpdb );
		$first     = self::snapshot( 9001, $this->creator_a );

		self::assertTrue( $snapshots->insert( $first ) );
		self::assertFalse( $snapshots->insert( new SalesLinePricing( 9001, 9000, $this->creator_b, 200, 0, SnapshotStatus::Share, 'DKK', self::time( '2026-10-10 10:00:00' ), 5000, MarkupSource::Creator, 2, true ) ) );

		self::assertEquals( $first, $snapshots->find( 9001 ) );
		self::assertSame( 1, $this->count_rows( Tables::SALES_LINE_PRICING ) );

		$public = array_map( static fn ( ReflectionMethod $m ): string => $m->getName(), ( new ReflectionClass( SalesLinePricingRepository::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) );
		sort( $public );
		self::assertSame( array( '__construct', 'find', 'insert' ), $public, 'No update or delete API.' );
	}

	public function test_snapshot_survives_a_deleted_product_a_deactivated_creator_and_changed_rules(): void {
		$product = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Synthetic pricing tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$snapshots = new SalesLinePricingRepository( $this->wpdb );
		$snapshot  = new SalesLinePricing( 9001, 9000, $this->creator_a, $product, 0, SnapshotStatus::Share, 'DKK', self::time( '2026-10-09 10:00:00' ), 2000, MarkupSource::Creator, 2, false );
		$snapshots->insert( $snapshot );
		$before = $this->raw( 9001 );

		// Everything the snapshot came from changes or disappears.
		( new CreatorMarkupRepository( $this->wpdb ) )->save( $this->creator_a, new MarkupBps( 5000 ) );
		( new ProductMarkupRepository( $this->wpdb ) )->save( new ProductMarkup( $product, $this->creator_a, new MarkupBps( 9000 ) ) );
		( new CreatorVatRepository( $this->wpdb ) )->save( $this->creator_a, true );
		( new CreatorRepository( $this->wpdb ) )->set_status( $this->creator_a, CreatorStatus::Inactive );
		wp_delete_post( $product, true );

		self::assertNull( get_post( $product ) );
		self::assertSame( $before, $this->raw( 9001 ), 'Row unchanged.' );
		self::assertEquals( $snapshot, $snapshots->find( 9001 ) );
	}

	/**
	 * The stored snapshot row.
	 *
	 * @param int $order_item_id Order item.
	 * @return array<string, mixed>
	 */
	private function raw( int $order_item_id ): array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE order_item_id = %d', $this->table( Tables::SALES_LINE_PRICING ), $order_item_id ), ARRAY_A );
		self::assertIsArray( $row );

		return $row;
	}

	/**
	 * A DKK share snapshot of 16.67 % (creator markup, not VAT registered).
	 *
	 * @param int $order_item_id Order item.
	 * @param int $creator_id    Creator.
	 */
	private static function snapshot( int $order_item_id, int $creator_id ): SalesLinePricing {
		return new SalesLinePricing( $order_item_id, 9000, $creator_id, 200, 0, SnapshotStatus::Share, 'DKK', self::time( '2026-10-09 10:00:00' ), 1667, MarkupSource::Creator, 2, false );
	}

	/**
	 * A UTC time.
	 *
	 * @param string $time MySQL datetime.
	 */
	private static function time( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}
}
