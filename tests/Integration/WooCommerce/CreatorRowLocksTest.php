<?php
/**
 * The order lock accessors and R1's creator row locks (E3 T1), on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use Closure;
use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Payouts\CreatorRowLocks;
use MerchBoard\Modules\WooCommerce\Sales\SalesConnectionChangedException;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use wpdb;

/**
 * SalesRepository::lock_connection() and assert_order_lock() work only for
 * the order whose lock the instance holds, on the connection that took it;
 * CreatorRowLocks::acquire_for_order() requires that lock and locks the
 * creators' rows FOR UPDATE once each, in ascending id order, inside the
 * caller's transaction (a second connection waits for them); a creator that
 * does not exist is an error (I4). Synthetic data.
 */
final class CreatorRowLocksTest extends IntegrationTestCase {

	private const ORDER = 7;

	private ?wpdb $second = null;

	/**
	 * The statements seen by the `query` filter while recording.
	 *
	 * @var list<string>
	 */
	private array $statements = array();

	private ?Closure $filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
	}

	protected function tearDown(): void {
		if ( null !== $this->filter ) {
			remove_filter( 'query', $this->filter );
		}

		$this->wpdb->query( 'ROLLBACK' );

		if ( null !== $this->second ) {
			$this->second->query( 'ROLLBACK' );
			$this->second->close();
		}

		parent::tearDown();
	}

	public function test_the_accessors_require_the_orders_lock(): void {
		$sales = $this->sales();

		foreach ( array( 'lock_connection', 'assert_order_lock' ) as $method ) {
			try {
				$sales->$method( self::ORDER );
				self::fail( 'Expected DatabaseException.' );
			} catch ( DatabaseException $e ) {
				self::assertSame( 'The sales ledger lock of the order is not held.', $e->getMessage() );
			}
		}

		$sales->with_order_lock(
			self::ORDER,
			function () use ( $sales ): void {
				self::assertSame( (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' ), $sales->lock_connection( self::ORDER ) );
				$sales->assert_order_lock( self::ORDER );

				$this->expectException( DatabaseException::class );
				$sales->lock_connection( self::ORDER + 1 );
			}
		);
	}

	public function test_assert_order_lock_fails_once_the_lock_is_lost(): void {
		$sales = $this->sales();

		$sales->with_order_lock(
			self::ORDER,
			function () use ( $sales ): void {
				$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $sales->lock_name( self::ORDER ) ) );

				$this->expectException( SalesConnectionChangedException::class );
				$sales->assert_order_lock( self::ORDER );
			}
		);
	}

	public function test_the_creator_rows_are_locked_once_each_in_ascending_order(): void {
		$creators = new CreatorRepository( $this->wpdb );
		$low      = $creators->create( 'Low', CreatorStatus::Active )->id;
		$high     = $creators->create( 'High', CreatorStatus::Active )->id;
		$sales    = $this->sales();
		$second   = $this->second();

		$sales->with_order_lock(
			self::ORDER,
			function () use ( $sales, $low, $high, $second ): void {
				$this->transaction()->run(
					function () use ( $sales, $low, $high, $second ): void {
						$this->record_queries();
						self::assertSame( array( $low, $high ), ( new CreatorRowLocks( $this->wpdb ) )->acquire_for_order( $sales, self::ORDER, array( $high, $low, $high ) ) );

						$locks = array_values( array_filter( $this->statements, static fn( string $sql ): bool => str_ends_with( $sql, 'FOR UPDATE' ) ) );
						self::assertCount( 2, $locks );
						self::assertStringContainsString( 'id = ' . $low . ' ', $locks[0] );
						self::assertStringContainsString( 'id = ' . $high . ' ', $locks[1] );

						foreach ( array( $low, $high ) as $id ) {
							self::assertNull( $second->get_var( $this->lock_sql( $second, $id ) ), 'The second connection waits.' );
							self::assertSame( 1205, DatabaseErrno::last( $second ) );
						}
					}
				);
			}
		);

		self::assertSame( (string) $low, $second->get_var( $this->lock_sql( $second, $low ) ), 'Released at COMMIT.' );
	}

	public function test_without_the_orders_lock_nothing_is_locked(): void {
		$this->record_queries();

		try {
			( new CreatorRowLocks( $this->wpdb ) )->acquire_for_order( $this->sales(), self::ORDER, array( 1 ) );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'The sales ledger lock of the order is not held.', $e->getMessage() );
		}

		self::assertSame( array(), $this->statements );
	}

	public function test_a_creator_that_does_not_exist_is_an_error(): void {
		$sales = $this->sales();

		$sales->with_order_lock(
			self::ORDER,
			function () use ( $sales ): void {
				try {
					$this->transaction()->run(
						fn (): array => ( new CreatorRowLocks( $this->wpdb ) )->acquire_for_order( $sales, self::ORDER, array( 999999 ) )
					);
					self::fail( 'Expected DatabaseException.' );
				} catch ( DatabaseException $e ) {
					self::assertSame( 'The creator of the order does not exist.', $e->getMessage() );
				}
			}
		);
	}

	/**
	 * A sales repository with its own transaction helper.
	 */
	private function sales(): SalesRepository {
		return new SalesRepository( $this->wpdb, $this->transaction() );
	}

	/**
	 * The project's transaction helper.
	 */
	private function transaction(): Transaction {
		return new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
	}

	/**
	 * Locks a creator's row FOR UPDATE.
	 *
	 * @param wpdb $connection Connection that prepares the statement.
	 * @param int  $creator    Creator.
	 */
	private function lock_sql( wpdb $connection, int $creator ): string {
		return (string) $connection->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $this->table( Tables::CREATORS ), $creator );
	}

	/**
	 * Records every statement from now on.
	 */
	private function record_queries(): void {
		$this->filter = function ( string $sql ): string {
			$this->statements[] = $sql;

			return $sql;
		};
		add_filter( 'query', $this->filter );
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
}
