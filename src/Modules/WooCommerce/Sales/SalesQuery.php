<?php
/**
 * A validated sales statistics period.
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
 * The period of one sales request (ADR-040 pkt. 6-7): inclusive local dates,
 * their half-open UTC interval and the periods of the interval. Pure: the
 * caller passes the site time zone and the current time.
 */
final readonly class SalesQuery {

	/**
	 * Days before `to` of the default `from` (30 days including `to`).
	 */
	public const DEFAULT_DAYS = 29;

	/**
	 * Longest input interval in years.
	 */
	public const MAX_YEARS = 5;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<SalesPeriod> $periods
	 *
	 * @param string            $from     First date, `Y-m-d` (inclusive).
	 * @param string            $to       Last date, `Y-m-d` (inclusive).
	 * @param SalesInterval     $interval Period length.
	 * @param DateTimeZone      $timezone Site time zone.
	 * @param DateTimeImmutable $start    UTC start (inclusive).
	 * @param DateTimeImmutable $end      UTC end (exclusive).
	 * @param SalesPeriod[]     $periods  Periods of [start, end).
	 */
	private function __construct(
		public string $from,
		public string $to,
		public SalesInterval $interval,
		public DateTimeZone $timezone,
		public DateTimeImmutable $start,
		public DateTimeImmutable $end,
		public array $periods
	) {
	}

	/**
	 * The period of the given dates, with the defaults of ADR-040: `to` is
	 * today and `from` is 29 days before `to`.
	 *
	 * @param string|null       $from     First date `Y-m-d`, or null for the default.
	 * @param string|null       $to       Last date `Y-m-d`, or null for today.
	 * @param SalesInterval     $interval Period length.
	 * @param DateTimeZone      $timezone Site time zone.
	 * @param DateTimeImmutable $now      Current time.
	 * @throws InvalidArgumentException When a date is invalid, `from` is after `to`, the dates span more than MAX_YEARS years or there are more than SalesPeriods::MAX_PERIODS periods.
	 */
	public static function resolve( ?string $from, ?string $to, SalesInterval $interval, DateTimeZone $timezone, DateTimeImmutable $now ): self {
		$to   ??= $now->setTimezone( $timezone )->format( 'Y-m-d' );
		$from ??= self::days_before( $to, $timezone );

		[ $start, $end ] = SalesPeriods::from_dates( $from, $to, $timezone );

		$limit = new DateTimeImmutable( $from . ' +' . self::MAX_YEARS . ' years', $timezone );

		if ( $to >= $limit->format( 'Y-m-d' ) ) {
			throw new InvalidArgumentException( 'The period is longer than the limit.' );
		}

		return new self( $from, $to, $interval, $timezone, $start, $end, SalesPeriods::between( $start, $end, $interval, $timezone ) );
	}

	/**
	 * The date DEFAULT_DAYS days before a date.
	 *
	 * @param string       $date     Date `Y-m-d`.
	 * @param DateTimeZone $timezone Time zone.
	 * @throws InvalidArgumentException When the date is not valid.
	 */
	private static function days_before( string $date, DateTimeZone $timezone ): string {
		[ $start ] = SalesPeriods::from_dates( $date, $date, $timezone );

		return $start->setTimezone( $timezone )->modify( '-' . self::DEFAULT_DAYS . ' days' )->format( 'Y-m-d' );
	}
}
