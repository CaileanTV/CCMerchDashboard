<?php
/**
 * Tests for the object cache of the sales statistics.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SalesCache::class )]
final class SalesCacheTest extends TestCase {

	/**
	 * The stand-in object cache: group => key => [value, expire].
	 *
	 * @var array<string, array<string, array{mixed, int}>>
	 */
	private array $store = array();

	private SalesCache $cache;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_cache_get' )->alias(
			fn( string $key, string $group = '' ): mixed => $this->store[ $group ][ $key ][0] ?? false
		);
		Functions\when( 'wp_cache_set' )->alias(
			function ( string $key, mixed $value, string $group = '', int $expire = 0 ): bool {
				$this->store[ $group ][ $key ] = array( $value, $expire );

				return true;
			}
		);
		$this->cache = new SalesCache();
	}

	public function test_the_group_and_the_ttl_are_those_of_adr_040(): void {
		self::assertSame( 'merchboard_sales', SalesCache::GROUP );
		self::assertSame( 15 * 60, SalesCache::TTL );

		$this->cache->store( 'report:3:1:abc', array( 'from' => '2026-09-01' ) );

		self::assertSame( array( array( 'from' => '2026-09-01' ), 900 ), $this->store['merchboard_sales']['report:3:1:abc'] );
	}

	public function test_the_generation_starts_at_1_and_invalidation_sets_a_new_token_once_per_creator(): void {
		self::assertSame( '1', $this->cache->generation( 3 ) );

		$this->cache->invalidate( array( 3, 4, 3, 0, -1 ) );

		self::assertSame( array( 'generation:3', 'generation:4' ), array_keys( $this->store['merchboard_sales'] ) );
		self::assertSame( 0, $this->store['merchboard_sales']['generation:3'][1], 'A generation does not expire.' );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $this->cache->generation( 3 ) );
		self::assertNotSame( $this->cache->generation( 3 ), $this->cache->generation( 4 ) );
		self::assertSame( '1', $this->cache->generation( 5 ) );
	}

	public function test_every_invalidation_gives_a_generation_never_seen_before(): void {
		$seen = array( $this->cache->generation( 3 ) );

		for ( $i = 0; $i < 50; $i++ ) {
			$this->cache->invalidate( array( 3 ) );
			$seen[] = $this->cache->generation( 3 );
		}

		self::assertCount( 51, array_unique( $seen ) );
	}

	public function test_report_keys_depend_on_creator_generation_and_every_part_of_the_period(): void {
		$base = $this->query( '2026-09-01', '2026-09-30', SalesInterval::Day, 'Europe/Copenhagen' );
		$key  = $this->cache->report_key( 3, '1', $base );

		self::assertMatchesRegularExpression( '/^report:3:1:[0-9a-f]{32}$/', $key );
		self::assertSame( $key, $this->cache->report_key( 3, '1', $this->query( '2026-09-01', '2026-09-30', SalesInterval::Day, 'Europe/Copenhagen' ) ), 'Deterministic.' );

		$others = array(
			$this->cache->report_key( 4, '1', $base ),
			$this->cache->report_key( 3, 'aaaaaaaaaaaaaaaa', $base ),
			$this->cache->report_key( 3, '1', $this->query( '2026-09-02', '2026-09-30', SalesInterval::Day, 'Europe/Copenhagen' ) ),
			$this->cache->report_key( 3, '1', $this->query( '2026-09-01', '2026-09-29', SalesInterval::Day, 'Europe/Copenhagen' ) ),
			$this->cache->report_key( 3, '1', $this->query( '2026-09-01', '2026-09-30', SalesInterval::Week, 'Europe/Copenhagen' ) ),
			$this->cache->report_key( 3, '1', $this->query( '2026-09-01', '2026-09-30', SalesInterval::Day, 'UTC' ) ),
			$this->cache->report_key( 31, '1', $base ),
		);

		self::assertCount( 8, array_unique( array_merge( array( $key ), $others ) ) );

		foreach ( $others as $other ) {
			self::assertLessThanOrEqual( 100, strlen( $other ) );
		}
	}

	public function test_a_stored_report_is_returned_only_for_its_own_query(): void {
		$query  = $this->query( '2026-09-01', '2026-09-30', SalesInterval::Day, 'Europe/Copenhagen' );
		$report = array(
			'from'       => '2026-09-01',
			'to'         => '2026-09-30',
			'interval'   => 'day',
			'timezone'   => 'Europe/Copenhagen',
			'currencies' => array(),
		);

		self::assertNull( $this->cache->report( 'k', $query ), 'Nothing stored.' );

		$this->cache->store( 'k', $report );
		self::assertSame( $report, $this->cache->report( 'k', $query ) );
		self::assertNull( $this->cache->report( 'k', $this->query( '2026-09-01', '2026-09-30', SalesInterval::Week, 'Europe/Copenhagen' ) ) );
		self::assertNull( $this->cache->report( 'k', $this->query( '2026-09-01', '2026-09-30', SalesInterval::Day, 'UTC' ) ) );

		$this->cache->store( 'k', array( 'currencies' => 'x' ) + $report );
		self::assertNull( $this->cache->report( 'k', $query ) );
	}

	/**
	 * A resolved query.
	 *
	 * @param string        $from     From.
	 * @param string        $to       To.
	 * @param SalesInterval $interval Interval.
	 * @param string        $timezone Time zone.
	 */
	private function query( string $from, string $to, SalesInterval $interval, string $timezone ): SalesQuery {
		return SalesQuery::resolve( $from, $to, $interval, new DateTimeZone( $timezone ), new DateTimeImmutable( '2026-10-01 00:00:00', new DateTimeZone( 'UTC' ) ) );
	}
}
