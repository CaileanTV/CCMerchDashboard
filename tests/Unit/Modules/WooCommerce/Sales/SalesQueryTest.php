<?php
/**
 * Tests for the period of a sales request.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( SalesQuery::class )]
final class SalesQueryTest extends TestCase {

	private DateTimeZone $copenhagen;

	protected function setUp(): void {
		parent::setUp();
		$this->copenhagen = new DateTimeZone( 'Europe/Copenhagen' );
	}

	public function test_defaults_are_the_last_30_local_days_by_day(): void {
		$query = SalesQuery::resolve( null, null, SalesInterval::Day, $this->copenhagen, self::utc( '2026-09-30 10:00:00' ) );

		self::assertSame( array( '2026-09-01', '2026-09-30' ), array( $query->from, $query->to ) );
		self::assertSame( SalesInterval::Day, $query->interval );
		self::assertSame( 'Europe/Copenhagen', $query->timezone->getName() );
		self::assertSame( '2026-08-31 22:00:00', $query->start->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '2026-09-30 22:00:00', $query->end->format( 'Y-m-d H:i:s' ) );
		self::assertCount( 30, $query->periods );
	}

	public function test_today_is_the_date_in_the_time_zone(): void {
		$late = SalesQuery::resolve( null, null, SalesInterval::Day, $this->copenhagen, self::utc( '2026-09-30 22:30:00' ) );
		$utc  = SalesQuery::resolve( null, null, SalesInterval::Day, new DateTimeZone( 'UTC' ), self::utc( '2026-09-30 22:30:00' ) );

		self::assertSame( array( '2026-09-02', '2026-10-01' ), array( $late->from, $late->to ) );
		self::assertSame( array( '2026-09-01', '2026-09-30' ), array( $utc->from, $utc->to ) );
	}

	public function test_the_default_from_is_29_days_before_a_given_to(): void {
		$query = SalesQuery::resolve( null, '2026-03-31', SalesInterval::Week, $this->copenhagen, self::utc( '2026-09-30 10:00:00' ) );

		self::assertSame( array( '2026-03-02', '2026-03-31' ), array( $query->from, $query->to ) );
		self::assertSame( array( '2026-03-02', '2026-03-08' ), array( $query->periods[0]->first_day, $query->periods[0]->last_day ) );
	}

	public function test_five_years_is_the_limit(): void {
		$now = self::utc( '2026-09-30 10:00:00' );

		self::assertCount( 60, SalesQuery::resolve( '2020-01-01', '2024-12-31', SalesInterval::Month, $this->copenhagen, $now )->periods );
		self::assertCount( 61, SalesQuery::resolve( '2020-02-29', '2025-02-28', SalesInterval::Month, $this->copenhagen, $now )->periods, 'From a leap day; partial first and last month.' );

		$this->expectException( InvalidArgumentException::class );
		SalesQuery::resolve( '2020-01-01', '2025-01-01', SalesInterval::Month, $this->copenhagen, $now );
	}

	/**
	 * Invalid periods.
	 *
	 * @return array<string, array{?string, ?string, SalesInterval}>
	 */
	public static function invalid(): array {
		return array(
			'from after to'        => array( '2026-09-02', '2026-09-01', SalesInterval::Day ),
			'from after today'     => array( '2026-10-01', null, SalesInterval::Day ),
			'not a date'           => array( '2026-02-30', '2026-03-01', SalesInterval::Day ),
			'to not a date'        => array( null, 'today', SalesInterval::Day ),
			'more than 366 days'   => array( '2025-01-01', '2026-01-02', SalesInterval::Day ),
			'more than five years' => array( '2019-01-01', '2026-01-01', SalesInterval::Week ),
		);
	}

	/**
	 * Invalid periods throw.
	 *
	 * @param string|null   $from     From.
	 * @param string|null   $to       To.
	 * @param SalesInterval $interval Interval.
	 */
	#[DataProvider( 'invalid' )]
	public function test_invalid_periods_throw( ?string $from, ?string $to, SalesInterval $interval ): void {
		$this->expectException( InvalidArgumentException::class );
		SalesQuery::resolve( $from, $to, $interval, $this->copenhagen, self::utc( '2026-09-30 10:00:00' ) );
	}

	/**
	 * A UTC time.
	 *
	 * @param string $time Time.
	 */
	private static function utc( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}
}
