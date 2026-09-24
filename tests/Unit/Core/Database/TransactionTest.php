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
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass( Transaction::class )]
final class TransactionTest extends TestCase {

	public function test_commits_and_returns_the_result(): void {
		$wpdb = new RecordingWpdb();

		self::assertSame( 'done', ( new Transaction( $wpdb ) )->run( static fn(): string => 'done' ) );
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $wpdb->raw_queries );
	}

	public function test_rolls_back_and_rethrows_the_original_exception(): void {
		$wpdb  = new RecordingWpdb();
		$error = new RuntimeException( 'boom' );

		$this->expectExceptionObject( $error );

		try {
			( new Transaction( $wpdb ) )->run(
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
			( new Transaction( $wpdb ) )->run(
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
			( new Transaction( $wpdb ) )->run( static fn(): int => 1 );
		} finally {
			self::assertSame( array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), $wpdb->raw_queries );
		}
	}

	public function test_nested_transactions_are_rejected(): void {
		$wpdb        = new RecordingWpdb();
		$transaction = new Transaction( $wpdb );

		try {
			$transaction->run( static fn() => $transaction->run( static fn(): int => 1 ) );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Nested transactions are not supported.', $e->getMessage() );
		}

		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $wpdb->raw_queries );
		self::assertSame( 2, $transaction->run( static fn(): int => 2 ), 'Usable again after a rollback.' );
	}
}
