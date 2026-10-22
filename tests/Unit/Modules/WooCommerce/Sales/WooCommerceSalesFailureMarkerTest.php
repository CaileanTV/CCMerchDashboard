<?php
/**
 * Tests for the failure marker as WooCommerce order meta.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Modules\WooCommerce\Sales\SalesFailureCode;
use MerchBoard\Modules\WooCommerce\Sales\WooCommerceSalesFailureMarker;
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

#[CoversClass( WooCommerceSalesFailureMarker::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceSalesFailureMarkerTest extends TestCase {

	private const KEY = WooCommerceSalesFailureMarker::META_KEY;

	private WooCommerceSalesFailureMarker $marker;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-order.php';
		WcOrderStorage::reset();
		WcOrderStorage::add_order( 7, 'completed' );
		WcOrderStorage::add_refund( 100, 7 );
		$this->marker = new WooCommerceSalesFailureMarker();
	}

	public function test_first_failure_sets_the_versioned_marker_and_suppresses_the_hpos_order_save(): void {
		$this->marker->record( 7, SalesFailureCode::LockTimeout, self::at( '2026-09-29 10:00:00' ) );

		self::assertSame(
			array(
				'v'        => 1,
				'first_at' => '2026-09-29T10:00:00Z',
				'last_at'  => '2026-09-29T10:00:00Z',
				'code'     => 'lock_timeout',
				'count'    => 1,
			),
			$this->stored()
		);
		self::assertSame( array( true ), WcOrderStorage::$saves, 'Meta saved once, with the HPOS save filter active.' );
		self::assertFalse( has_filter( WooCommerceSalesFailureMarker::SAVE_FILTER, '__return_false' ), 'Filter removed again.' );
	}

	public function test_later_failures_keep_first_at_and_count_up(): void {
		$this->marker->record( 7, SalesFailureCode::LockTimeout, self::at( '2026-09-29 10:00:00' ) );
		$this->marker->record( 7, SalesFailureCode::NegativeBalance, new DateTimeImmutable( '2026-09-29 14:30:00', new DateTimeZone( 'Europe/Copenhagen' ) ) );

		self::assertSame(
			array(
				'v'        => 1,
				'first_at' => '2026-09-29T10:00:00Z',
				'last_at'  => '2026-09-29T12:30:00Z',
				'code'     => 'negative_balance',
				'count'    => 2,
			),
			$this->stored()
		);
	}

	public function test_an_unreadable_marker_starts_over(): void {
		$first_at = 'x';

		foreach ( array( 'garbage', array( 'v' => 2 ), compact( 'first_at' ) + array( 'v' => 1, 'count' => 0 ) ) as $stored ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			WcOrderStorage::$meta[7] = array( self::KEY => $stored );

			$this->marker->record( 7, SalesFailureCode::DbError, self::at( '2026-09-29 11:00:00' ) );

			self::assertSame( array( '2026-09-29T11:00:00Z', 1 ), array( $this->stored()['first_at'] ?? null, $this->stored()['count'] ?? null ) );
		}
	}

	public function test_clear_removes_the_marker_and_does_nothing_without_one(): void {
		WcOrderStorage::$meta[7] = array( '_other' => 'kept' );
		$this->marker->clear( 7 );
		self::assertSame( array(), WcOrderStorage::$saves, 'Nothing to remove: no save.' );

		$this->marker->record( 7, SalesFailureCode::Unexpected, self::at( '2026-09-29 10:00:00' ) );
		$this->marker->clear( 7 );

		self::assertSame( array( '_other' => 'kept' ), WcOrderStorage::$meta[7] );
		self::assertSame( array( true, true ), WcOrderStorage::$saves );
	}

	public function test_the_save_filter_is_removed_even_when_saving_fails(): void {
		WcOrderStorage::$fail_save = true;
		$failed                    = false;

		try {
			$this->marker->record( 7, SalesFailureCode::DbError, self::at( '2026-09-29 10:00:00' ) );
		} catch ( RuntimeException ) {
			$failed = true;
		}

		self::assertTrue( $failed );
		self::assertFalse( has_filter( WooCommerceSalesFailureMarker::SAVE_FILTER, '__return_false' ) );
	}

	public function test_refunds_and_missing_orders_get_no_marker(): void {
		$this->marker->record( 100, SalesFailureCode::DbError, self::at( '2026-09-29 10:00:00' ) );
		$this->marker->record( 404, SalesFailureCode::DbError, self::at( '2026-09-29 10:00:00' ) );
		$this->marker->clear( 404 );

		self::assertSame( array(), WcOrderStorage::$saves );
		self::assertSame( array(), WcOrderStorage::$meta );
	}

	public function test_uninstall_removes_the_same_key(): void {
		self::assertSame( self::KEY, Uninstaller::ORDER_META_KEYS[0] );
	}

	/**
	 * The stored marker of order 7.
	 *
	 * @return array<mixed>
	 */
	private function stored(): array {
		$meta = WcOrderStorage::$meta[7][ self::KEY ] ?? null;
		self::assertIsArray( $meta );

		return $meta;
	}

	/**
	 * UTC time.
	 *
	 * @param string $time Y-m-d H:i:s.
	 */
	private static function at( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}
}
