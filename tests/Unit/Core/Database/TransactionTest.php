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

	public function test_a_failed_rollback_after_the_work_is_recorded_and_the_original_error_kept(): void {
		$wpdb                            = new RecordingWpdb();
		$wpdb->query_results['ROLLBACK'] = false;
		$transaction                     = self::transaction( $wpdb );
		$error                           = new RuntimeException( 'boom' );
		$caught                          = null;

		try {
			$transaction->run(
				static function () use ( $error ): void {
					throw $error;
				}
			);
		} catch ( RuntimeException $e ) {
			$caught = $e;
		}

		self::assertSame( $error, $caught, 'The original error, no new exception.' );
		self::assertTrue( $transaction->rollback_failed() );
		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $wpdb->raw_queries, 'Same statements as before.' );
	}

	public function test_a_failed_rollback_after_a_failed_commit_is_recorded(): void {
		$wpdb                            = new RecordingWpdb();
		$wpdb->query_results['COMMIT']   = false;
		$wpdb->query_results['ROLLBACK'] = false;
		$transaction                     = self::transaction( $wpdb );

		try {
			$transaction->run( static fn(): int => 1 );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not commit the database transaction.', $e->getMessage() );
		}

		self::assertTrue( $transaction->rollback_failed() );
	}

	public function test_rollback_status_is_reset_by_the_next_run_and_untouched_by_a_rejected_nested_one(): void {
		$wpdb                            = new RecordingWpdb();
		$wpdb->query_results['ROLLBACK'] = false;
		$transaction                     = self::transaction( $wpdb );

		self::assertFalse( $transaction->rollback_failed(), 'Nothing rolled back yet.' );

		try {
			$transaction->run(
				static function (): void {
					throw new RuntimeException( 'boom' );
				}
			);
		} catch ( RuntimeException ) {
			self::assertTrue( $transaction->rollback_failed() );
		}

		self::assertSame( 1, $transaction->run( static fn(): int => 1 ) );
		self::assertFalse( $transaction->rollback_failed(), 'Reset at the start of the next run().' );

		unset( $wpdb->query_results['ROLLBACK'] );
		$status = null;

		try {
			$transaction->run(
				static function () use ( $transaction, &$status ): void {
					try {
						$transaction->run( static fn(): int => 1 );
					} catch ( DatabaseException ) {
						$status = $transaction->rollback_failed();
					}

					throw new RuntimeException( 'outer' );
				}
			);
		} catch ( RuntimeException ) {
			self::assertFalse( $transaction->rollback_failed(), 'The outer ROLLBACK succeeded.' );
		}

		self::assertFalse( $status, 'The rejected nested call did not change the status.' );
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
			array(
				'name'         => 'wp_merchboard_order_gift_card_assessments',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_order_gift_card_payouts',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_order_gift_card_attempts',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_g1_state',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_operations',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_recalculations',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_recalculation_items',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_adjustments',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_adjustment_rows',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_payout_recalculation_exports',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_creator_vat_history',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_creator_codes',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_order_exclusions',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_events',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_overlays',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_chatbot',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_chat_messages',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_chat_log',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_news',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_news_reads',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_api_keys',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_webhooks',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_alert_webhook_deliveries',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_category_proposals',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_notifications',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_designer_bases',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_designer_products',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_designer_versions',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_designer_assets',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_designer_provider_tasks',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_designer_campaigns',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
			array(
				'name'         => 'wp_merchboard_product_removal_requests',
				'engine'       => 'InnoDB',
				'transactions' => 'YES',
			),
		);

		return new Transaction( $wpdb, new TransactionSupport( $wpdb ) );
	}
}
