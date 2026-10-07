<?php
/**
 * Data set and measuring of the sales benchmark (phase 20, step 8), shared by
 * SalesBenchmarkTest and the query-strategy experiment so both measure the
 * same rows the same way.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Benchmark;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;

/**
 * Deterministic data set (see SalesBenchmarkTest) and timing with WARMUP
 * untimed and RUNS timed runs (median and nearest-rank p95). For
 * IntegrationTestCase subclasses.
 */
trait SalesBenchmarkFixture {

	/**
	 * Timed runs per scenario (after WARMUP untimed runs).
	 */
	protected const RUNS = 50;

	protected const WARMUP = 3;

	protected const FIRST_DAY = '2025-10-01';

	protected const DAYS = 365;

	protected const NOW = '2026-09-30 10:00:00';

	protected const MEDIUM_CREATORS = 40;

	protected int $seed = 20261007;

	protected int $order = 1000000;

	protected int $item = 5000000;

	protected int $key = 0;

	/**
	 * Rows waiting for the next multi-row INSERT.
	 *
	 * @var list<string>
	 */
	protected array $pending = array();

	/**
	 * Results by scenario.
	 *
	 * @var list<array<string, int|float|string>>
	 */
	protected array $results = array();

	/**
	 * Installs the plugin and inserts the data set: the large and the small
	 * creator (with users, so the REST route can be called as them) and
	 * MEDIUM_CREATORS medium creators (ids 100001 and up, ledger rows only).
	 * Prints the database, PHP, object cache and row counts.
	 *
	 * @return array{Creator, Creator} Large and small creator.
	 */
	protected function seed_dataset(): array {
		$this->install();
		$creators = $this->service( CreatorService::class );
		$large    = $creators->create( 'Benchmark Large', CreatorStatus::Active, $this->create_user( 'bench_large' ) );
		$small    = $creators->create( 'Benchmark Small', CreatorStatus::Active, $this->create_user( 'bench_small' ) );

		$this->seed_creator( $large->id, 60, 81, 3, array( 85, 95 ) );
		$this->seed_creator( $small->id, 0, 2, 2, array( 100, 100 ) );

		for ( $n = 1; $n <= self::MEDIUM_CREATORS; $n++ ) {
			$this->seed_creator( 100000 + $n, 2, 5, 2, array( 95, 100 ) );
		}

		$this->flush_rows();
		$sales = $this->table( Tables::SALES );
		$this->execute( 'ANALYZE TABLE %i', $sales );

		$version = (string) $this->wpdb->get_var( 'SELECT VERSION()' );
		$rows    = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $sales ) );
		$counts  = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT creator_id, COUNT(*) AS n, COUNT(DISTINCT currency) AS currencies, COUNT(DISTINCT product_id) AS products FROM %i WHERE creator_id IN (%d, %d, %d) GROUP BY creator_id', $sales, $large->id, 100001, $small->id ),
			ARRAY_A
		);
		$cache   = wp_using_ext_object_cache() ? 'persistent' : 'in-memory (non-persistent)';
		fwrite( STDERR, sprintf( "\nDATABASE %s | PHP %s | object cache: %s\n", $version, PHP_VERSION, $cache ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Benchmark output.
		fwrite( STDERR, 'ROWS total ' . $rows . ' | ' . (string) wp_json_encode( $counts ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Benchmark output.

		return array( $large, $small );
	}

	/**
	 * Times a callback RUNS times after WARMUP untimed runs and records the
	 * number of ledger queries of one run.
	 *
	 * @param string                   $scenario Scenario.
	 * @param string                   $method   What is timed.
	 * @param callable(): mixed        $run      Timed work.
	 * @param (callable(): mixed)|null $before Untimed preparation before every run.
	 */
	protected function measure( string $scenario, string $method, callable $run, ?callable $before = null ): void {
		$queries = $this->ledger_queries(
			static function () use ( $run, $before ): void {
				if ( null !== $before ) {
					$before();
				}

				$run();
			}
		);
		$times   = array();

		for ( $n = 0; $n < self::WARMUP + self::RUNS; $n++ ) {
			if ( null !== $before ) {
				$before();
			}

			$start = hrtime( true );
			$run();
			$elapsed = ( hrtime( true ) - $start ) / 1e6;

			if ( $n >= self::WARMUP ) {
				$times[] = $elapsed;
			}
		}

		sort( $times );
		$count = count( $times );

		$this->results[] = array(
			'scenario' => $scenario,
			'method'   => $method,
			'runs'     => $count,
			'queries'  => $queries,
			'min'      => round( $times[0], 2 ),
			'median'   => round( ( $times[ intdiv( $count - 1, 2 ) ] + $times[ intdiv( $count, 2 ) ] ) / 2, 2 ),
			'p95'      => round( $times[ (int) ceil( 0.95 * $count ) - 1 ], 2 ),
			'max'      => round( $times[ $count - 1 ], 2 ),
		);
	}

	/**
	 * Ledger queries while a callback runs.
	 *
	 * @param callable(): mixed $callback Callback.
	 */
	protected function ledger_queries( callable $callback ): int {
		$table  = $this->table( Tables::SALES );
		$count  = 0;
		$record = static function ( string $sql ) use ( $table, &$count ): string {
			if ( str_contains( $sql, $table ) ) {
				++$count;
			}

			return $sql;
		};
		add_filter( 'query', $record );

		try {
			$callback();
		} finally {
			remove_filter( 'query', $record );
		}

		return $count;
	}

	/**
	 * Prints the results (and writes them as JSON when asked to).
	 */
	protected function report(): void {
		fwrite( STDERR, sprintf( "\n%-28s | %-24s | %4s | %3s | %9s | %9s | %9s | %9s\n", 'scenario', 'method', 'runs', 'q', 'min ms', 'median ms', 'p95 ms', 'max ms' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Benchmark output.

		foreach ( $this->results as $r ) {
			fwrite( STDERR, sprintf( "%-28s | %-24s | %4d | %3d | %9.2f | %9.2f | %9.2f | %9.2f\n", $r['scenario'], $r['method'], $r['runs'], $r['queries'], $r['min'], $r['median'], $r['p95'], $r['max'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Benchmark output.
		}

		$out = getenv( 'MERCHBOARD_BENCHMARK_OUT' );

		if ( is_string( $out ) && '' !== $out ) {
			file_put_contents( $out, (string) wp_json_encode( $this->results, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Benchmark output file chosen by the caller.
		}
	}

	/**
	 * Inserts a year of ledger rows for one creator.
	 *
	 * @param int             $creator_id Creator.
	 * @param int             $base       Least orders per day.
	 * @param int             $spread     Orders per day are base + [0, spread).
	 * @param int             $max_lines  Lines per order are 1..max_lines.
	 * @param array{int, int} $currency Thresholds of 100: below the first DKK, below the second EUR, else SEK.
	 */
	protected function seed_creator( int $creator_id, int $base, int $spread, int $max_lines, array $currency ): void {
		$utc   = new DateTimeZone( 'UTC' );
		$first = ( new DateTimeImmutable( self::FIRST_DAY, $utc ) )->getTimestamp();

		for ( $day = 0; $day < self::DAYS; $day++ ) {
			$orders = $base + $this->random( $spread );

			for ( $o = 0; $o < $orders; $o++ ) {
				$order = ++$this->order;
				$pick  = $this->random( 100 );
				$code  = $pick < $currency[0] ? 'DKK' : ( $pick < $currency[1] ? 'EUR' : 'SEK' );
				$at    = $first + $day * 86400 + $this->random( 86400 );
				$lines = array();

				for ( $l = 1 + $this->random( $max_lines ); $l > 0; $l-- ) {
					$item      = ++$this->item;
					$product   = 10001 + $this->random( 250 );
					$variation = $product % 5 < 2 ? $product * 10 + $this->random( 4 ) : 0;
					$quantity  = 1 + $this->random( 3 );
					$cents     = $quantity * ( ( 100 + $this->random( 400 ) ) * 100 + $this->random( 100 ) );
					$lines[]   = array( $item, $product, $variation, $quantity, $cents );
					$this->row( $creator_id, $order, $item, 1, 0, $product, $variation, 'sale', null, $quantity, $cents, $code, $at );

					if ( $this->random( 100 ) < 6 ) {
						$this->row( $creator_id, $order, $item, 1, 9000000 + $item, $product, $variation, 'refund', null, -1, -intdiv( $cents, $quantity ), $code, $at + 86400 * ( 1 + $this->random( 20 ) ) );
					}

					if ( $this->random( 1000 ) < 5 ) {
						$this->row( $creator_id, $order, $item, 1, 0, $product, $variation, 'correction', 'line_changed', 0, -intdiv( $cents, 10 ), $code, $at + 3600 );
					}
				}

				if ( $this->random( 100 ) < 2 ) {
					$cancelled = $at + 86400 * ( 1 + $this->random( 5 ) );
					$reopen    = 0 === $this->random( 2 );

					foreach ( $lines as [ $item, $product, $variation, $quantity, $cents ] ) {
						$this->row( $creator_id, $order, $item, 1, 0, $product, $variation, 'reversal', null, -$quantity, -$cents, $code, $cancelled );

						if ( $reopen ) {
							$this->row( $creator_id, $order, $item, 2, 0, $product, $variation, 'sale', null, $quantity, $cents, $code, $cancelled + 86400 );
						}
					}
				}
			}
		}
	}

	/**
	 * Queues one ledger row.
	 *
	 * @param int         $creator_id Creator.
	 * @param int         $order      Order id.
	 * @param int         $item       Order item id.
	 * @param int         $cycle      Cycle.
	 * @param int         $refund     Refund id (0: none).
	 * @param int         $product    Parent product id.
	 * @param int         $variation  Variation id (0: none).
	 * @param string      $type       Type.
	 * @param string|null $reason     Correction reason.
	 * @param int         $quantity   Signed quantity.
	 * @param int         $cents      Signed amount in cents.
	 * @param string      $currency   Currency.
	 * @param int         $at         UTC timestamp.
	 */
	protected function row( int $creator_id, int $order, int $item, int $cycle, int $refund, int $product, int $variation, string $type, ?string $reason, int $quantity, int $cents, string $currency, int $at ): void {
		$amount          = ( $cents < 0 ? '-' : '' ) . intdiv( abs( $cents ), 100 ) . '.' . str_pad( (string) ( abs( $cents ) % 100 ), 2, '0', STR_PAD_LEFT ) . '00';
		$this->pending[] = (string) $this->wpdb->prepare(
			'(%s, %d, %d, %d, %d, %d, %d, %d, %s, %s, NULLIF(%s, \'\'), %d, %s, %s, %s, %s)',
			'b:' . ( ++$this->key ),
			$creator_id,
			$order,
			$item,
			$cycle,
			$refund,
			$product,
			$variation,
			'Product ' . $product,
			$type,
			$reason ?? '',
			$quantity,
			$amount,
			$currency,
			gmdate( 'Y-m-d H:i:s', $at ),
			'2026-10-01 00:00:00'
		);

		if ( count( $this->pending ) >= 1000 ) {
			$this->flush_rows();
		}
	}

	/**
	 * Inserts the queued rows in one statement.
	 */
	protected function flush_rows(): void {
		if ( array() === $this->pending ) {
			return;
		}

		$sql           = 'INSERT INTO ' . $this->table( Tables::SALES ) . ' (event_key, creator_id, order_id, order_item_id, cycle, refund_id, product_id, variation_id, item_name, type, correction_reason, quantity, net_amount, currency, occurred_at, created_at) VALUES ' . implode( ', ', $this->pending );
		$this->pending = array();

		self::assertNotFalse( $this->wpdb->query( $sql ), $this->wpdb->last_error ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every row tuple is the result of $wpdb->prepare(); the table name is the plugin's own.
	}

	/**
	 * A deterministic pseudo-random number in [0, max).
	 *
	 * @param int $max Upper bound (> 0).
	 */
	protected function random( int $max ): int {
		$this->seed = ( $this->seed * 1103515245 + 12345 ) & 0x7fffffff;

		return intdiv( $this->seed, 65536 ) % $max;
	}
}
