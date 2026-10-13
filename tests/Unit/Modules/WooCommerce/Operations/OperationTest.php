<?php
/**
 * Tests for the operation value and the E3/F-5 classification.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Operations;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Operations\Operation;
use MerchBoard\Modules\WooCommerce\Operations\OperationFrame;
use MerchBoard\Modules\WooCommerce\Operations\OperationResult;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( Operation::class )]
#[CoversClass( OperationFrame::class )]
final class OperationTest extends TestCase {

	private const ID   = '0f8fad5b-d9cb-469f-a165-70867728950e';
	private const HASH = 'a3f5c0d2e1b4968700112233445566778899aabbccddeeff0011223344556677';

	public function test_a_valid_operation(): void {
		$operation = new Operation( self::ID, 'g1_decision', 42, self::HASH );

		self::assertSame( array( self::ID, 'g1_decision', 42, self::HASH ), array( $operation->id, $operation->kind, $operation->order_id, $operation->payload_hash ) );
	}

	public function test_the_kinds_are_f5s_without_the_activation(): void {
		self::assertSame( array( 'g1_finalize', 'g1_decision', 'g1_batch', 'mark_24_5', 'unmark_24_5', 'approve_24_11', 'revoke_24_11' ), Operation::KINDS );
	}

	/**
	 * Invalid values.
	 *
	 * @return array<string, array{string, string, int, string}>
	 */
	public static function invalid(): array {
		return array(
			'uppercase id'   => array( strtoupper( self::ID ), 'g1_decision', 42, self::HASH ),
			'not a uuid'     => array( 'not-a-uuid', 'g1_decision', 42, self::HASH ),
			'unknown kind'   => array( self::ID, 'g1_activate', 42, self::HASH ),
			'order 0'        => array( self::ID, 'g1_decision', 0, self::HASH ),
			'short hash'     => array( self::ID, 'g1_decision', 42, substr( self::HASH, 1 ) ),
			'uppercase hash' => array( self::ID, 'g1_decision', 42, strtoupper( self::HASH ) ),
			'non-hex hash'   => array( self::ID, 'g1_decision', 42, str_repeat( 'g', 64 ) ),
			'long hash'      => array( self::ID, 'g1_decision', 42, self::HASH . '0' ),
		);
	}

	#[DataProvider( 'invalid' )]
	public function test_invalid_values_are_refused( string $id, string $kind, int $order_id, string $hash ): void {
		$this->expectException( InvalidArgumentException::class );

		new Operation( $id, $kind, $order_id, $hash );
	}

	/**
	 * Failures and their classification (E3.6, F-5, D-1 to D-5).
	 *
	 * @return array<string, array{bool, bool, bool, bool, bool, int|null|false, string|null, string}>
	 */
	public static function failures(): array {
		$unknown   = OperationResult::OUTCOME_UNKNOWN;
		$reconcile = OperationFrame::RECONCILE;

		return array(
			// reconciling, entered, finished, rollback failed, changed, errno, statement => result.
			'failed rollback'                 => array( false, true, false, true, false, 1644, 'mutation', $unknown ),
			'failed rollback beats busy'      => array( false, true, false, true, false, 1205, 'mutation', $unknown ),
			'changed connection (D-5)'        => array( false, true, false, false, true, false, null, $unknown ),
			'failed commit'                   => array( false, true, true, false, false, false, null, $unknown ),
			'lock wait timeout (D-1)'         => array( false, true, false, false, false, 1205, 'creators', OperationResult::BUSY ),
			'deadlock (D-1)'                  => array( false, true, false, false, false, 1213, 'mutation', OperationResult::BUSY ),
			'unreadable error number (D-3)'   => array( false, true, false, false, false, null, 'mutation', $reconcile ),
			'duplicate registry id'           => array( false, true, false, false, false, 1062, 'registry_insert', $reconcile ),
			'duplicate state row'             => array( false, true, false, false, false, 1062, 'mutation', OperationResult::ERROR ),
			'other statement failure'         => array( false, true, false, false, false, 1146, 'audit', OperationResult::ERROR ),
			'failure before start'            => array( false, false, false, false, false, false, null, OperationResult::ERROR ),
			'stale rollback flag before work' => array( false, false, false, true, false, false, null, OperationResult::ERROR ),
			'reconciliation lock conflict'    => array( true, true, false, false, false, 1205, 'registry_read', OperationResult::BUSY ),
			'reconciliation read failure'     => array( true, true, false, false, false, 1146, 'registry_read', $unknown ),
			'reconciliation unreadable'       => array( true, true, false, false, false, null, 'registry_read', $unknown ),
			'reconciliation before start'     => array( true, false, false, false, false, false, null, $unknown ),
		);
	}

	#[DataProvider( 'failures' )]
	public function test_failures_are_classified( bool $reconciling, bool $entered, bool $finished, bool $rollback_failed, bool $changed, int|null|false $errno, ?string $statement, string $expected ): void {
		self::assertSame( $expected, OperationFrame::classify( $reconciling, $entered, $finished, $rollback_failed, $changed, $errno, $statement ) );
	}
}
