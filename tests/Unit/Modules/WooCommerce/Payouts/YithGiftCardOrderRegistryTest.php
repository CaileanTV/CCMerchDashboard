<?php
/**
 * Tests for the optional order lookup of the YITH adapter (G1, B4/B5).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardAvailability;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardOrderRegistry;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviderException;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\YithGiftCardProvider;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Pricing\PricingUnitRecord;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * With an active YITH stand-in (tests/Fixtures/yith-gift-cards.php):
 * YWGC_META_GIFT_CARD_ORDERS decides only the order lookup, never the
 * adapter's availability, so requests, registration and the creator's view
 * (23.7) are unaffected when it is missing (owner decision B5, 2026-10-16).
 */
#[CoversClass( YithGiftCardProvider::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class YithGiftCardOrderRegistryTest extends TestCase {

	private RecordingWpdb $wpdb;

	private YithGiftCardProvider $yith;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/yith-gift-cards.php';
		Functions\when( 'post_type_exists' )->alias( static fn( string $type ): bool => 'gift_card' === $type );
		Functions\when( 'is_serialized' )->alias(
			static fn( mixed $data ): bool => is_string( $data ) && 1 === preg_match( '/^(a|i|s|b|O|d|N):/', $data )
		);

		$this->wpdb           = new RecordingWpdb();
		$this->wpdb->posts    = 'wp_posts';
		$this->wpdb->postmeta = 'wp_postmeta';
		$this->yith           = new YithGiftCardProvider( $this->wpdb, self::store(), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) ) );
	}

	public function test_the_adapter_offers_the_optional_lookup(): void {
		self::assertInstanceOf( GiftCardOrderRegistry::class, $this->yith );
	}

	public function test_without_the_constant_the_adapter_stays_available_and_only_the_lookup_is_not(): void {
		$availability = $this->yith->availability();
		self::assertTrue( $availability->available, '23.7: requests, registration and the creator view still work.' );
		self::assertSame( '4.38.0', $availability->version );

		$registry = $this->yith->order_registry_availability();
		self::assertFalse( $registry->available );
		self::assertSame( GiftCardAvailability::SELF_TEST_FAILED, $registry->code );
		self::assertSame( '4.38.0', $registry->version );

		$this->not_looked_up();
	}

	public function test_a_constant_with_another_value_disables_only_the_lookup(): void {
		define( 'YWGC_META_GIFT_CARD_ORDERS', '_ywgc_other_orders' );

		self::assertTrue( $this->yith->availability()->available );
		self::assertSame( GiftCardAvailability::SELF_TEST_FAILED, $this->yith->order_registry_availability()->code );
		$this->not_looked_up();
	}

	public function test_with_the_expected_constant_both_are_available_and_the_lookup_reads_the_plugins_list(): void {
		define( 'YWGC_META_GIFT_CARD_ORDERS', '_ywgc_orders' );
		$this->wpdb->results = array(
			array(
				'ID'         => '501',
				'meta_value' => serialize( array( 9103, '9101' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As the plugin stores it.
			),
			array(
				'ID'         => '502',
				'meta_value' => null, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- No order recorded.
			),
		);

		self::assertTrue( $this->yith->availability()->available );
		self::assertTrue( $this->yith->order_registry_availability()->available );
		self::assertSame(
			array(
				'501' => array( 9101, 9103 ),
				'502' => array(),
			),
			$this->yith->orders_paid_with( array( '501', '502', 'not-an-id' ) )
		);

		$lookup = end( $this->wpdb->prepared );
		self::assertIsArray( $lookup );
		self::assertStringContainsString( 'LEFT JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s', $lookup['query'] );
		self::assertSame( array( 'wp_posts', 'wp_postmeta', '_ywgc_orders', 'gift_card', 501, 502 ), $lookup['args'], 'Only valid card ids, by id.' );
	}

	public function test_an_unavailable_adapter_makes_the_lookup_unavailable_with_its_own_code(): void {
		define( 'YWGC_META_GIFT_CARD_ORDERS', '_ywgc_orders' );
		Functions\when( 'post_type_exists' )->justReturn( false );

		self::assertSame( GiftCardAvailability::SELF_TEST_FAILED, $this->yith->availability()->code );
		self::assertEquals( $this->yith->availability(), $this->yith->order_registry_availability() );
		$this->not_looked_up();
	}

	/**
	 * Requires orders_paid_with() to refuse as unavailable without reading.
	 */
	private function not_looked_up(): void {
		$prepared = count( $this->wpdb->prepared );

		try {
			$this->yith->orders_paid_with( array( '501' ) );
			self::fail( 'Expected the lookup to be unavailable.' );
		} catch ( GiftCardProviderException $e ) {
			self::assertSame( GiftCardProviderException::UNAVAILABLE, $e->reason() );
		}

		self::assertSame( array(), array_filter( array_slice( $this->wpdb->prepared, $prepared ), static fn( array $query ): bool => str_contains( $query['query'], 'm.meta_key' ) ), 'No order list is read.' );
	}

	/**
	 * A store with 2 decimals in DKK.
	 */
	private static function store(): PricingProductStore {
		return new class() implements PricingProductStore {

			public function find( int $item_id ): ?PricingUnitRecord {
				return null;
			}

			public function variation_ids( int $product_id ): array {
				return array();
			}

			public function currency(): string {
				return 'DKK';
			}

			public function decimals(): int {
				return 2;
			}
		};
	}
}
