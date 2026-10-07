<?php
/**
 * Tests for the periods of sales statistics.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesPeriod;
use MerchBoard\Modules\WooCommerce\Sales\SalesPeriods;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( SalesPeriods::class )]
#[CoversClass( SalesPeriod::class )]
final class SalesPeriodsTest extends TestCase {

	private DateTimeZone $copenhagen;

	protected function setUp(): void {
		parent::setUp();
		$this->copenhagen = new DateTimeZone( 'Europe/Copenhagen' );
	}

	public function test_inclusive_local_dates_become_a_half_open_utc_interval(): void {
		[ $from, $to ] = SalesPeriods::from_dates( '2026-07-01', '2026-07-31', $this->copenhagen );

		self::assertSame( '2026-06-30 22:00:00', self::utc( $from ) );
		self::assertSame( '2026-07-31 22:00:00', self::utc( $to ), 'The last day is included: end is the next local midnight.' );
		self::assertSame( 'UTC', $from->getTimezone()->getName() );
		self::assertSame( 'UTC', $to->getTimezone()->getName() );
	}

	public function test_one_day_is_one_local_day(): void {
		[ $from, $to ] = SalesPeriods::from_dates( '2026-01-15', '2026-01-15', $this->copenhagen );

		self::assertSame( array( '2026-01-14 23:00:00', '2026-01-15 23:00:00' ), array( self::utc( $from ), self::utc( $to ) ) );
	}

	public function test_daylight_saving_days_are_23_and_25_hours(): void {
		[ $from, $to ] = SalesPeriods::from_dates( '2026-03-29', '2026-03-29', $this->copenhagen );
		self::assertSame( 23 * 3600, $to->getTimestamp() - $from->getTimestamp() );

		[ $from, $to ] = SalesPeriods::from_dates( '2026-10-25', '2026-10-25', $this->copenhagen );
		self::assertSame( 25 * 3600, $to->getTimestamp() - $from->getTimestamp() );

		$days = SalesPeriods::between( ...self::range( '2026-03-28', '2026-03-30', SalesInterval::Day, $this->copenhagen ) );
		self::assertSame(
			array(
				array( '2026-03-27 23:00:00', '2026-03-28 23:00:00' ),
				array( '2026-03-28 23:00:00', '2026-03-29 22:00:00' ),
				array( '2026-03-29 22:00:00', '2026-03-30 22:00:00' ),
			),
			self::bounds( $days )
		);
	}

	/**
	 * Invalid inputs.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function invalid_dates(): array {
		return array(
			'to before from'   => array( '2026-07-02', '2026-07-01' ),
			'not a date'       => array( 'yesterday', '2026-07-01' ),
			'no leading zero'  => array( '2026-7-01', '2026-07-01' ),
			'day out of range' => array( '2026-02-30', '2026-03-01' ),
			'time included'    => array( '2026-07-01 00:00:00', '2026-07-02' ),
			'empty'            => array( '2026-07-01', '' ),
		);
	}

	#[DataProvider( 'invalid_dates' )]
	public function test_invalid_dates_are_rejected( string $from, string $to ): void {
		$this->expectException( InvalidArgumentException::class );
		SalesPeriods::from_dates( $from, $to, $this->copenhagen );
	}

	public function test_days(): void {
		$periods = SalesPeriods::between( ...self::range( '2026-07-30', '2026-08-02', SalesInterval::Day, $this->copenhagen ) );

		self::assertSame(
			array(
				array( 0, '2026-07-30', '2026-07-30' ),
				array( 1, '2026-07-31', '2026-07-31' ),
				array( 2, '2026-08-01', '2026-08-01' ),
				array( 3, '2026-08-02', '2026-08-02' ),
			),
			self::days( $periods )
		);
		self::assertSame( '2026-07-29 22:00:00', self::utc( $periods[0]->start ) );
		self::assertSame( '2026-08-02 22:00:00', self::utc( $periods[3]->end ) );
	}

	public function test_iso_weeks_start_on_monday_and_partial_weeks_are_not_extended(): void {
		// 2026-07-01 is a Wednesday, 2026-07-16 a Thursday.
		$periods = SalesPeriods::between( ...self::range( '2026-07-01', '2026-07-16', SalesInterval::Week, $this->copenhagen ) );

		self::assertSame(
			array(
				array( 0, '2026-07-01', '2026-07-05' ),
				array( 1, '2026-07-06', '2026-07-12' ),
				array( 2, '2026-07-13', '2026-07-16' ),
			),
			self::days( $periods )
		);
	}

	public function test_a_week_starting_on_monday_and_one_across_the_year(): void {
		// 2026-12-28 is a Monday; ISO week 53 of 2026 runs to Sunday 2027-01-03.
		$periods = SalesPeriods::between( ...self::range( '2026-12-28', '2027-01-10', SalesInterval::Week, $this->copenhagen ) );

		self::assertSame(
			array(
				array( 0, '2026-12-28', '2027-01-03' ),
				array( 1, '2027-01-04', '2027-01-10' ),
			),
			self::days( $periods )
		);
	}

	public function test_months_and_partial_months(): void {
		$periods = SalesPeriods::between( ...self::range( '2026-01-15', '2026-04-10', SalesInterval::Month, $this->copenhagen ) );

		self::assertSame(
			array(
				array( 0, '2026-01-15', '2026-01-31' ),
				array( 1, '2026-02-01', '2026-02-28' ),
				array( 2, '2026-03-01', '2026-03-31' ),
				array( 3, '2026-04-01', '2026-04-10' ),
			),
			self::days( $periods )
		);
		self::assertSame( '2026-02-28 23:00:00', self::utc( $periods[2]->start ), 'March starts at local midnight (UTC+1).' );
		self::assertSame( '2026-03-31 22:00:00', self::utc( $periods[2]->end ), 'and ends at local midnight after DST (UTC+2).' );
	}

	public function test_periods_are_contiguous(): void {
		$periods = SalesPeriods::between( ...self::range( '2026-01-01', '2026-12-31', SalesInterval::Day, $this->copenhagen ) );

		self::assertCount( 365, $periods );

		foreach ( $periods as $i => $period ) {
			self::assertSame( $i, $period->index );
			self::assertLessThan( $period->end, $period->start );

			if ( $i > 0 ) {
				self::assertEquals( $periods[ $i - 1 ]->end, $period->start );
			}
		}
	}

	public function test_an_empty_range_has_no_periods(): void {
		$at = new DateTimeImmutable( '2026-07-01 00:00:00', new DateTimeZone( 'UTC' ) );

		self::assertSame( array(), SalesPeriods::between( $at, $at, SalesInterval::Day, $this->copenhagen ) );
		self::assertSame( array(), SalesPeriods::between( $at, $at->modify( '-1 day' ), SalesInterval::Day, $this->copenhagen ) );
	}

	public function test_at_most_366_periods(): void {
		self::assertCount( 366, SalesPeriods::between( ...self::range( '2028-01-01', '2028-12-31', SalesInterval::Day, $this->copenhagen ) ) );

		$this->expectException( InvalidArgumentException::class );
		SalesPeriods::between( ...self::range( '2026-01-01', '2027-01-02', SalesInterval::Day, $this->copenhagen ) );
	}

	public function test_utc_time_zone(): void {
		$periods = SalesPeriods::between( ...self::range( '2026-07-01', '2026-07-02', SalesInterval::Day, new DateTimeZone( 'UTC' ) ) );

		self::assertSame(
			array(
				array( '2026-07-01 00:00:00', '2026-07-02 00:00:00' ),
				array( '2026-07-02 00:00:00', '2026-07-03 00:00:00' ),
			),
			self::bounds( $periods )
		);
	}

	/**
	 * Arguments for between() from inclusive local dates.
	 *
	 * @param string        $from     First date.
	 * @param string        $to       Last date.
	 * @param SalesInterval $interval Interval.
	 * @param DateTimeZone  $timezone Time zone.
	 * @return array{DateTimeImmutable, DateTimeImmutable, SalesInterval, DateTimeZone}
	 */
	private static function range( string $from, string $to, SalesInterval $interval, DateTimeZone $timezone ): array {
		[ $start, $end ] = SalesPeriods::from_dates( $from, $to, $timezone );

		return array( $start, $end, $interval, $timezone );
	}

	/**
	 * `[index, first day, last day]` per period.
	 *
	 * @param SalesPeriod[] $periods Periods.
	 * @return list<array{int, string, string}>
	 */
	private static function days( array $periods ): array {
		return array_values( array_map( static fn( SalesPeriod $p ): array => array( $p->index, $p->first_day, $p->last_day ), $periods ) );
	}

	/**
	 * `[start, end]` in UTC per period.
	 *
	 * @param SalesPeriod[] $periods Periods.
	 * @return list<array{string, string}>
	 */
	private static function bounds( array $periods ): array {
		return array_values( array_map( static fn( SalesPeriod $p ): array => array( self::utc( $p->start ), self::utc( $p->end ) ), $periods ) );
	}

	/**
	 * A time in UTC.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
