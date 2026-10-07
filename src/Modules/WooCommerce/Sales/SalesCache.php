<?php
/**
 * Object cache of the sales statistics.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The cache of ADR-040 pkt. 10: WordPress' object cache (`wp_cache_*`),
 * group `merchboard_sales`, entries for 15 minutes, a generation per creator.
 * Never transients, never the database: without a persistent object cache it
 * only lives for the request. The cache is never the source of truth; a lost
 * entry or generation only costs a recomputation from the ledger.
 *
 * Generation: the key `generation:<creator id>` holds the creator's current
 * generation. Without one it is INITIAL_GENERATION (`1`). Invalidation does
 * not increment (the generic object cache API has no guaranteed atomic
 * increment, and a lost increment could reuse a generation that already has
 * entries); it sets a new random 64-bit token, so two invalidations, also
 * concurrent ones, never produce a generation that was used before. The
 * generation itself never expires (it may be evicted: the creator then falls
 * back to `1`, and entries stored under `1` within the last TTL can be served
 * until they expire).
 *
 * Report keys: `report:<creator id>:<generation>:<md5 of the format version,
 * from, to, interval, time zone and week convention>`, at most about 80
 * characters, with only integers, hex digits and fixed text, so no input can
 * build a key of another creator. Currency is not part of the key: one entry
 * holds every currency of the request.
 */
final class SalesCache implements SalesCacheInvalidator {

	/**
	 * Object cache group.
	 */
	public const GROUP = 'merchboard_sales';

	/**
	 * Lifetime of a report in seconds (15 minutes).
	 */
	public const TTL = 900;

	/**
	 * Generation of a creator without a stored one.
	 */
	public const INITIAL_GENERATION = '1';

	/**
	 * Version of the cached report format; part of every report key.
	 */
	private const FORMAT = 'v1';

	/**
	 * Week convention of SalesPeriods (ISO weeks, Monday first).
	 */
	private const WEEK = 'iso';

	/**
	 * The creator's current generation.
	 *
	 * @param int $creator_id Creator id.
	 */
	public function generation( int $creator_id ): string {
		$generation = wp_cache_get( self::generation_key( $creator_id ), self::GROUP );

		return is_string( $generation ) && 1 === preg_match( '/^[0-9a-f]{16}$/', $generation ) ? $generation : self::INITIAL_GENERATION;
	}

	/**
	 * Gives every creator a new generation, once per creator.
	 *
	 * @phpstan-param list<int> $creator_ids
	 *
	 * @param int[] $creator_ids Creator ids.
	 */
	public function invalidate( array $creator_ids ): void {
		foreach ( array_unique( $creator_ids ) as $creator_id ) {
			if ( $creator_id > 0 ) {
				wp_cache_set( self::generation_key( $creator_id ), bin2hex( random_bytes( 8 ) ), self::GROUP, 0 );
			}
		}
	}

	/**
	 * The key of a report.
	 *
	 * @param int        $creator_id Creator id.
	 * @param string     $generation The creator's generation (generation()).
	 * @param SalesQuery $query      Validated period.
	 */
	public function report_key( int $creator_id, string $generation, SalesQuery $query ): string {
		$period = implode( '|', array( self::FORMAT, $query->from, $query->to, $query->interval->value, $query->timezone->getName(), self::WEEK ) );

		return 'report:' . $creator_id . ':' . $generation . ':' . md5( $period );
	}

	/**
	 * A cached report of the query, or null on a miss (also for an entry that
	 * does not match the query).
	 *
	 * @param string     $key   Report key.
	 * @param SalesQuery $query Validated period.
	 * @return array<string, mixed>|null
	 */
	public function report( string $key, SalesQuery $query ): ?array {
		$report = wp_cache_get( $key, self::GROUP );

		if (
			! is_array( $report )
			|| ( $report['from'] ?? null ) !== $query->from
			|| ( $report['to'] ?? null ) !== $query->to
			|| ( $report['interval'] ?? null ) !== $query->interval->value
			|| ( $report['timezone'] ?? null ) !== $query->timezone->getName()
			|| ! is_array( $report['currencies'] ?? null )
		) {
			return null;
		}

		return $report;
	}

	/**
	 * Stores a report for TTL seconds.
	 *
	 * @param string               $key    Report key.
	 * @param array<string, mixed> $report Report.
	 */
	public function store( string $key, array $report ): void {
		wp_cache_set( $key, $report, self::GROUP, self::TTL );
	}

	/**
	 * Key of a creator's generation.
	 *
	 * @param int $creator_id Creator id.
	 */
	private static function generation_key( int $creator_id ): string {
		return 'generation:' . $creator_id;
	}
}
