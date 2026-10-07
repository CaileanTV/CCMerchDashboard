<?php
/**
 * Periods of sales statistics in the site's time zone.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The calendar side of ADR-040 (pkt. 6-7), outside the SQL layer: local dates
 * become half-open UTC intervals, computed with DateTimeImmutable in the
 * site's time zone (so days around daylight saving time are 23 or 25 hours)
 * and never with `CONVERT_TZ()` in SQL. Pure: no WordPress, no database.
 */
final class SalesPeriods {

	/**
	 * Most periods in one series.
	 */
	public const MAX_PERIODS = 366;

	/**
	 * UTC format of the ledger's `occurred_at`.
	 */
	public const SQL_FORMAT = 'Y-m-d H:i:s';

	/**
	 * The half-open UTC interval of an inclusive local date range:
	 * `[from 00:00, to + 1 day 00:00)` in the time zone.
	 *
	 * @param string       $from     First date, `Y-m-d` (inclusive).
	 * @param string       $to       Last date, `Y-m-d` (inclusive).
	 * @param DateTimeZone $timezone Site time zone.
	 * @return array{DateTimeImmutable, DateTimeImmutable} UTC start (inclusive) and end (exclusive).
	 * @throws InvalidArgumentException When a date is not a valid `Y-m-d` date or `to` is before `from`.
	 */
	public static function from_dates( string $from, string $to, DateTimeZone $timezone ): array {
		$first = self::local_date( $from, $timezone );
		$last  = self::local_date( $to, $timezone );

		if ( $last < $first ) {
			throw new InvalidArgumentException( 'The period ends before it starts.' );
		}

		$utc = new DateTimeZone( 'UTC' );

		return array( $first->setTimezone( $utc ), $last->modify( '+1 day' )->setTime( 0, 0 )->setTimezone( $utc ) );
	}

	/**
	 * The periods of `[from, to)`, split at local day, ISO week (Monday) or
	 * calendar month boundaries. The first and last period are not extended to
	 * whole weeks or months.
	 *
	 * @phpstan-return list<SalesPeriod>
	 *
	 * @param DateTimeImmutable $from     Start (inclusive).
	 * @param DateTimeImmutable $to       End (exclusive).
	 * @param SalesInterval     $interval Period length.
	 * @param DateTimeZone      $timezone Site time zone.
	 * @return SalesPeriod[]
	 * @throws InvalidArgumentException When there would be more than MAX_PERIODS periods.
	 */
	public static function between( DateTimeImmutable $from, DateTimeImmutable $to, SalesInterval $interval, DateTimeZone $timezone ): array {
		$utc     = new DateTimeZone( 'UTC' );
		$start   = $from->setTimezone( $timezone );
		$end     = $to->setTimezone( $timezone );
		$periods = array();

		while ( $start < $end ) {
			if ( count( $periods ) === self::MAX_PERIODS ) {
				throw new InvalidArgumentException( 'Too many periods.' );
			}

			$next      = self::next_boundary( $start, $interval );
			$next      = $next < $end ? $next : $end;
			$periods[] = new SalesPeriod(
				count( $periods ),
				$start->setTimezone( $utc ),
				$next->setTimezone( $utc ),
				$start->format( 'Y-m-d' ),
				$next->modify( '-1 second' )->format( 'Y-m-d' )
			);
			$start     = $next;
		}

		return $periods;
	}

	/**
	 * The next local day, Monday or first of the month at 00:00 after $start.
	 *
	 * @param DateTimeImmutable $start    Local start.
	 * @param SalesInterval     $interval Period length.
	 */
	private static function next_boundary( DateTimeImmutable $start, SalesInterval $interval ): DateTimeImmutable {
		$midnight = $start->setTime( 0, 0 );

		return match ( $interval ) {
			SalesInterval::Day   => $midnight->modify( '+1 day' )->setTime( 0, 0 ),
			SalesInterval::Week  => $midnight->modify( '+' . ( 8 - (int) $midnight->format( 'N' ) ) . ' days' )->setTime( 0, 0 ),
			SalesInterval::Month => $midnight->modify( 'first day of next month' )->setTime( 0, 0 ),
		};
	}

	/**
	 * Midnight of a strict `Y-m-d` date in the time zone.
	 *
	 * @param string       $date     Date.
	 * @param DateTimeZone $timezone Time zone.
	 * @throws InvalidArgumentException When the date is not valid.
	 */
	private static function local_date( string $date, DateTimeZone $timezone ): DateTimeImmutable {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );

		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			throw new InvalidArgumentException( 'Not a valid date.' );
		}

		return $parsed;
	}
}
