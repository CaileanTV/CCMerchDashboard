<?php
/**
 * Tests for the transaction helper.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass( Transaction::class )]
final class TransactionTest extends TestCase {

	public function test_commits_and_returns_the_result(): void {
		$wpdb = new RecordingWpdb();

		self::assertSame( 'done', ( self::transaction( $wpdb ) )->run( static fn(): string => 'done' ) );
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $wpdb->raw_queries );
	}

	public function test_rolls_back_and_rethrows_the_original_exception(): void {
		$wpdb  = new RecordingWpdb();
		$error = new RuntimeException( 'boom' );

		$this->expectExceptionObject( $error );

		try {
			( self::transaction( $wpdb ) )->run(
				static function () use ( $error ): void {
					throw $error;
				}
			);
		} finally {
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $wpdb->raw_queries );
		}
	}

	public function test_fails_loudly_when_the_transaction_cannot_start(): void {
		$wpdb                                     = new RecordingWpdb();
		$wpdb->query_results['START TRANSACTION'] = false;
		$ran                                      = false;

		try {
			( self::transaction( $wpdb ) )->run(
				static function () use ( &$ran ): void {
					$ran = true;
				}
			);
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertFalse( $ran, 'Work must not run outside a transaction.' );
		}
	}

	public function test_failed_commit_rolls_back_and_throws(): void {
		$wpdb                          = new RecordingWpdb();
		$wpdb->query_results['COMMIT'] = false;

		$this->expectException( DatabaseException::class );

		try {
			( self::transaction( $wpdb ) )->run( static fn(): int => 1 );
		} finally {
			self::assertSame( array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), $wpdb->raw_queries );
		}
	}

	public function test_nested_transactions_are_rejected(): void {
		$wpdb        = new RecordingWpdb();
		$transaction = self::transaction( $wpdb );

		try {
			$transaction->run( static fn() => $transaction->run( static fn(): int => 1 ) );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Nested transactions are not supported.', $e->getMessage() );
		}

		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $wpdb->raw_queries );
		self::assertSame( 2, $transaction->run( static fn(): int => 2 ), 'Usable again after a rollback.' );
	}

	public function test_refuses_to_start_when_a_table_cannot_roll_back(): void {
		$wpdb = new RecordingWpdb();
		$ran  = false;

		$transaction      = self::transaction( $wpdb );
		$wpdb->results[1] = array(
			'name'         => 'wp_merchboard_audit_log',
			'engine'       => 'MyISAM',
			'transactions' => 'NO',
		);

		try {
			$transaction->run(
				static function () use ( &$ran ): void {
					$ran = true;
				}
			);
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Database tables do not support transactions; write refused.', $e->getMessage() );
			self::assertSame( array( 'tables' => 'wp_merchboard_audit_log (MyISAM)' ), $e->context() );
		}

		self::assertFalse( $ran, 'Nothing may be written without rollback protection.' );
		self::assertSame( array(), $wpdb->raw_queries, 'No transaction is started.' );
	}

	/**
	 * Transaction whose tables report InnoDB.
	 *
	 * @param RecordingWpdb $wpdb Database double.
	 */
	private static function transaction( RecordingWpdb $wpdb ): Transaction {
		$wpdb->results = array(
			array(
				'name'         => 'wp_merchboard_creators',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_audit_log',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_creatormeta',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_creator_categories',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_sales',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_sales_cycles',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_pricing_overrides',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_item_pricing',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_sales_line_pricing',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_sales_tax',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payouts',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_secrets',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_settlement_documents',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_settlement_document_rows',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_settlement_counters',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
		);

		return new Transaction( $wpdb, new TransactionSupport( $wpdb ) );
	}
}
