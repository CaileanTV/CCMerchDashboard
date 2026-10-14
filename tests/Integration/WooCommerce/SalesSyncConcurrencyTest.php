<?php
/**
 * E3 tests 7 and 8 for the synchronization's R0 (T5a) against a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use wpdb;

/**
 * Concurrency is made deterministic: synchronization A (the test connection)
 * is paused by a `query` filter at an exact statement boundary while its
 * transaction is open (checked in information_schema.INNODB_TRX); during the
 * pause a second, real connection with a 1-second InnoDB lock wait runs one
 * statement or a whole synchronization B. A lock conflict shows as 1205, a
 * deadlock as 1213; the outcome is read as an error number or result, not as
 * a duration.
 *
 * Test 7: under SERIALIZABLE, READ UNCOMMITTED (and the references READ
 * COMMITTED and REPEATABLE READ) R0's shared lock on the version row holds;
 * only SERIALIZABLE turns state()'s plain reads into locking reads. The
 * synchronization leaves the session's level as it was.
 *
 * Test 8: two synchronizations of the same order are serialized by the order
 * lock (N1); two of different new orders never wait for each other at any of
 * three pause points (after R0, after state(), before COMMIT), so neither
 * 1205 nor 1213 can occur between them there. Synthetic data only.
 */
final class SalesSyncConcurrencyTest extends IntegrationTestCase {

	private const ORDER = 7;

	private const OTHER = 8;

	private FakeOrderReader $reader;

	private RecordingFailureMarker $marker;

	private RecordingLogger $logger;

	private SalesSynchronizer $sync;

	private int $clock = 0;

	private ?wpdb $second = null;

	/**
	 * Filters added by the test, removed in tearDown().
	 *
	 * @var list<Closure>
	 */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->reader = new FakeOrderReader();
		$this->marker = new RecordingFailureMarker();
		$this->logger = new RecordingLogger();
		$this->sync   = $this->synchronizer( $this->wpdb, $this->marker, $this->logger, 5 );
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( 'query', $filter );
		}

		if ( null !== $this->second ) {
			$this->second->close();
			$this->second = null;
		}

		parent::tearDown();
	}

	/**
	 * Session isolation levels and whether state() takes row locks under them.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function levels(): array {
		return array(
			'SERIALIZABLE'     => array( 'SERIALIZABLE', true ),
			'READ UNCOMMITTED' => array( 'READ UNCOMMITTED', false ),
			'READ COMMITTED'   => array( 'READ COMMITTED', false ),
			'REPEATABLE READ'  => array( 'REPEATABLE READ', false ),
		);
	}

	#[DataProvider( 'levels' )]
	public function test_7_r0_holds_under_every_session_isolation_level( string $level, bool $state_locks_rows ): void {
		$lines = array( self::line( 11, 2, '100' ) );
		$this->order( self::ORDER, 'completed', $lines );
		$this->sync->sync( self::ORDER );
		$row = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM %i WHERE event_key = %s', $this->table( Tables::SALES ), 's:11:1' ) );
		self::assertGreaterThan( 0, $row );

		$second  = $this->second();
		$own     = $this->connection_id();
		$cycles  = '`' . $this->table( Tables::SALES_CYCLES ) . '`';
		$version = $this->prepared( $second, 'UPDATE %i SET option_value = option_value WHERE option_name = %s', $second->options, Migrator::VERSION_OPTION );
		$ledger  = $this->prepared( $second, 'UPDATE %i SET item_name = item_name WHERE id = %d', $this->table( Tables::SALES ), $row );
		$seen    = null;

		// At A's first INSERT (R0 and state() done), the second connection
		// tries the version row and the order's existing ledger row.
		$this->pause_at(
			static fn( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO ' . $cycles ),
			function () use ( $second, $own, $version, $ledger, &$seen ): void {
				$seen = array(
					'open'    => $this->open_transactions( $own ),
					'version' => array( $second->query( $version ), DatabaseErrno::last( $second ) ),
					'ledger'  => array( $second->query( $ledger ), DatabaseErrno::last( $second ) ),
				);
			}
		);

		$previous = $this->session_level();
		self::assertNotFalse( $this->wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . self::level_sql( $level ) ) );

		try {
			self::assertSame( str_replace( ' ', '-', $level ), $this->session_level() );
			$this->order( self::ORDER, 'cancelled', $lines );
			$this->sync->sync( self::ORDER );
			$after = $this->session_level();
		} finally {
			$this->wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . self::level_sql( str_replace( '-', ' ', $previous ) ) );
		}

		self::assertIsArray( $seen, 'A was paused at its first INSERT.' );
		self::assertSame( 1, $seen['open'], 'A\'s transaction was open during the pause.' );
		self::assertSame( array( false, 1205 ), $seen['version'], 'R0\'s shared lock on the version row holds under ' . $level . '.' );
		self::assertSame( $state_locks_rows ? array( false, 1205 ) : array( 0, 0 ), $seen['ledger'], 'state() locks the order\'s rows only under SERIALIZABLE.' );
		self::assertSame( str_replace( ' ', '-', $level ), $after, 'The synchronization left the session level as it was.' );
		self::assertSame( array( 's:11:1', 'v:11:1' ), $this->keys() );
		self::assertSame( array( '1:open', '1:close' ), $this->cycles() );
		self::assertSame( array( 'clear:7', 'clear:7' ), $this->marker->calls );
		self::assertSame( array(), $this->logger->at( 'error' ) );
	}

	public function test_8_two_synchronizations_of_the_same_order_are_serialized_by_the_order_lock(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$own    = $this->connection_id();
		$marker = new RecordingFailureMarker();
		$logger = new RecordingLogger();
		$other  = $this->synchronizer( $this->second(), $marker, $logger, 0 );
		$sales  = '`' . $this->table( Tables::SALES ) . '` WHERE order_id = ' . self::ORDER;
		$open   = null;

		// A holds the order lock and R0; B synchronizes the same order.
		$this->pause_at(
			static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, $sales ),
			function () use ( $other, $own, &$open ): void {
				$open = $this->open_transactions( $own );
				$other->sync( self::ORDER );
			}
		);

		$this->sync->sync( self::ORDER );

		self::assertSame( 1, $open, 'B ran while A\'s transaction was open.' );
		self::assertSame( array( 'record:7:lock_timeout' ), $marker->calls, 'B could not take the order lock (N1).' );
		self::assertSame( array( 'lock_timeout' ), self::codes( $logger ) );
		self::assertSame( array( 's:11:1' ), $this->keys(), 'Only A wrote.' );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
		self::assertSame( array(), $this->logger->at( 'error' ) );

		// B again, now alone: it converges and writes nothing new.
		$other->sync( self::ORDER );

		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( '1:open' ), $this->cycles() );
		self::assertSame( array( 'record:7:lock_timeout', 'clear:7' ), $marker->calls );
		self::assertSame( array( 'lock_timeout' ), self::codes( $logger ), 'No db_error (1205 or 1213) anywhere.' );
	}

	/**
	 * Where A pauses, by the statement that is about to run.
	 *
	 * @return array<string, array{string}>
	 */
	public static function pause_points(): array {
		return array(
			'after R0'          => array( 'state' ),
			'after state()'     => array( 'insert' ),
			'after the inserts' => array( 'commit' ),
		);
	}

	#[DataProvider( 'pause_points' )]
	public function test_8_two_synchronizations_of_different_new_orders_do_not_wait_for_each_other( string $point ): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$this->order( self::OTHER, 'completed', array( self::line( 21, 1, '40' ) ) );
		$own    = $this->connection_id();
		$marker = new RecordingFailureMarker();
		$logger = new RecordingLogger();
		$other  = $this->synchronizer( $this->second(), $marker, $logger, 5 );
		$open   = null;
		$keys   = null;

		$this->pause_at(
			$this->statement( $point ),
			function () use ( $other, $own, &$open, &$keys ): void {
				$open = $this->open_transactions( $own );
				$other->sync( self::OTHER );
				$keys = $this->second()->get_col( $this->prepared( $this->second(), 'SELECT event_key FROM %i ORDER BY id', $this->table( Tables::SALES ) ) );
			}
		);

		$this->sync->sync( self::ORDER );

		self::assertSame( 1, $open, 'B ran while A\'s transaction was open.' );
		self::assertSame( array( 'clear:8' ), $marker->calls, 'B committed without waiting for A (no 1205, no 1213).' );
		self::assertSame( array(), $logger->at( 'error' ) );
		self::assertSame( array( 's:21:1' ), $keys, 'B\'s row was committed while A was paused; A\'s was not yet.' );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
		self::assertSame( array(), $this->logger->at( 'error' ) );
		// Both committed; the row order follows the auto-increment ids, which
		// depend on the pause point (A inserts before or after B), so sort.
		$committed = $this->keys();
		sort( $committed );
		self::assertSame( array( 's:11:1', 's:21:1' ), $committed );
		self::assertSame( array( '1:open', '1:open' ), $this->cycles() );
	}

	/**
	 * Matches the statement of A at a pause point.
	 *
	 * @param string $point `state`, `insert` or `commit`.
	 * @return Closure(string): bool
	 */
	private function statement( string $point ): Closure {
		$sales  = '`' . $this->table( Tables::SALES ) . '` WHERE order_id = ' . self::ORDER;
		$cycles = '`' . $this->table( Tables::SALES_CYCLES ) . '`';

		return match ( $point ) {
			'state'  => static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, $sales ),
			'insert' => static fn( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO ' . $cycles ),
			default  => static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(' ),
		};
	}

	/**
	 * Runs $pause once, right before the first statement of the test
	 * connection's synchronization that $matches; statements run during the
	 * pause (on any connection) are not matched.
	 *
	 * @param Closure $matches Closure(string): bool.
	 * @param Closure $pause   Work during the pause.
	 */
	private function pause_at( Closure $matches, Closure $pause ): void {
		$done   = false;
		$filter = static function ( string $sql ) use ( $matches, $pause, &$done ): string {
			// Marked before the pause, so its own statements never match.
			if ( ! $done && $matches( $sql ) ) {
				$done = true;
				$pause();
			}

			return $sql;
		};
		add_filter( 'query', $filter );
		$this->filters[] = $filter;
	}

	/**
	 * Open InnoDB transactions of a connection, read on the second connection.
	 *
	 * @param int $connection_id Connection id.
	 */
	private function open_transactions( int $connection_id ): int {
		$second = $this->second();
		$count  = $second->get_var( $this->prepared( $second, 'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = %d', $connection_id ) );
		self::assertSame( '', $second->last_error );

		return (int) $count;
	}

	/**
	 * The test connection's id.
	 */
	private function connection_id(): int {
		return (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
	}

	/**
	 * The session isolation level: `tx_isolation` on MariaDB 10.11,
	 * `transaction_isolation` on MySQL 8.0.
	 */
	private function session_level(): string {
		$level = $this->is_mariadb()
			? $this->wpdb->get_var( 'SELECT @@SESSION.tx_isolation' )
			: $this->wpdb->get_var( 'SELECT @@SESSION.transaction_isolation' );

		self::assertSame( '', $this->wpdb->last_error );

		return (string) $level;
	}

	/**
	 * A level for SET TRANSACTION ISOLATION LEVEL, from a fixed list.
	 *
	 * @param string $level Level with spaces.
	 * @return literal-string
	 */
	private static function level_sql( string $level ): string {
		return match ( $level ) {
			'SERIALIZABLE'     => 'SERIALIZABLE',
			'READ UNCOMMITTED' => 'READ UNCOMMITTED',
			'READ COMMITTED'   => 'READ COMMITTED',
			default            => 'REPEATABLE READ',
		};
	}

	/**
	 * Prepares a statement on a connection.
	 *
	 * @param wpdb   $connection Connection.
	 * @param string $query      Query.
	 * @param mixed  ...$args    Arguments.
	 * @phpstan-param literal-string $query
	 */
	private function prepared( wpdb $connection, string $query, mixed ...$args ): string {
		$sql = $connection->prepare( $query, ...$args );
		self::assertIsString( $sql );

		return $sql;
	}

	/**
	 * A second, real connection with a 1-second InnoDB lock wait.
	 */
	private function second(): wpdb {
		if ( null === $this->second ) {
			$this->second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$this->second->set_prefix( $this->wpdb->prefix );
			$this->second->suppress_errors( true );
			$this->second->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		}

		return $this->second;
	}

	/**
	 * A synchronizer on a connection with a stepping clock.
	 *
	 * @param wpdb                   $connection   Connection.
	 * @param RecordingFailureMarker $marker       Marker.
	 * @param RecordingLogger        $logger       Logger.
	 * @param int                    $lock_timeout Order lock timeout in seconds.
	 */
	private function synchronizer( wpdb $connection, RecordingFailureMarker $marker, RecordingLogger $logger, int $lock_timeout ): SalesSynchronizer {
		return new SalesSynchronizer(
			new SalesRepository( $connection, new Transaction( $connection, new TransactionSupport( $connection ) ), $lock_timeout ),
			new SalesPlanner(),
			$this->reader,
			$marker,
			$logger,
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-29 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * The codes of the logged synchronization failures.
	 *
	 * @param RecordingLogger $logger Logger.
	 * @return list<mixed>
	 */
	private static function codes( RecordingLogger $logger ): array {
		return array_map( static fn( array $entry ): mixed => $entry['context']['code'] ?? null, $logger->at( 'error' ) );
	}

	/**
	 * Sets an order in the test reader.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 *
	 * @param int                 $id     Order id.
	 * @param string              $status Status.
	 * @param OrderLineSnapshot[] $lines  Lines.
	 */
	private function order( int $id, string $status, array $lines ): void {
		$this->reader->orders[ $id ] = array(
			'status'   => $status,
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => array(),
		);
	}

	/**
	 * A line of product 20 by creator 3.
	 *
	 * @param int    $item     Item id.
	 * @param int    $quantity Quantity.
	 * @param string $net      Net amount.
	 */
	private static function line( int $item, int $quantity, string $net ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, 20, 0, 'Line ' . $item, $quantity, SalesAmount::parse( $net ), 3 );
	}

	/**
	 * Event keys in insertion order.
	 *
	 * @return list<string|null>
	 */
	private function keys(): array {
		return array_column( $this->rows( Tables::SALES ), 'event_key' );
	}

	/**
	 * Cycle events as "cycle:event" in insertion order.
	 *
	 * @return list<string>
	 */
	private function cycles(): array {
		return array_map( static fn( array $row ): string => $row['cycle'] . ':' . $row['event'], $this->rows( Tables::SALES_CYCLES ) );
	}
}
