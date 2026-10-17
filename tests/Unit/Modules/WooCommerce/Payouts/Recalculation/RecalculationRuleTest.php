<?php
/**
 * Tests for the recalculation of a settled row without the 20 % deduction (ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts\Recalculation;

use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\RecalculationRule;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RecalculationRule::class )]
final class RecalculationRuleTest extends TestCase {

	public function test_only_the_deduction_is_removed(): void {
		// 100.00 incl. VAT at 20 %: profit 20.00, settled at 80 % = 16.00.
		self::assertSame(
			array(
				'original'     => 160000,
				'recalculated' => 200000,
				'difference'   => 40000,
			),
			RecalculationRule::row( self::row() )
		);
	}

	public function test_rounding_follows_the_stored_precision(): void {
		// 99.99 × 16.67 % = 16.67; × 80 % = 13.336 → 13.34; difference 3.33.
		self::assertSame(
			array(
				'original'     => 133400,
				'recalculated' => 166700,
				'difference'   => 33300,
			),
			RecalculationRule::row(
				self::row(
					array(
						'net_amount'    => '79.9920',
						'tax_amount'    => '19.9980',
						'share_bps'     => '1667',
						'profit_amount' => '16.6700',
						'payout_amount' => '13.3400',
					)
				)
			)
		);
	}

	public function test_a_refund_gives_a_negative_difference(): void {
		self::assertSame(
			array(
				'original'     => -160000,
				'recalculated' => -200000,
				'difference'   => -40000,
			),
			RecalculationRule::row(
				self::row(
					array(
						'net_amount'    => '-80.0000',
						'tax_amount'    => '-20.0000',
						'profit_amount' => '-20.0000',
						'payout_amount' => '-16.0000',
					)
				)
			)
		);
	}

	public function test_a_row_that_does_not_reproduce_needs_a_manual_assessment(): void {
		self::assertNull( RecalculationRule::row( self::row( array( 'payout_amount' => '20.0000' ) ) ), 'Already without the deduction: not a row of ADR-049.' );
		self::assertNull( RecalculationRule::row( self::row( array( 'profit_amount' => '19.9900' ) ) ), 'A profit that does not follow from the stored inputs.' );
		self::assertNull( RecalculationRule::row( self::row( array( 'share_bps' => null ) ) ), 'Missing input: never guessed.' );
		self::assertNull( RecalculationRule::row( self::row( array( 'tax_amount' => 'abc' ) ) ) );
		self::assertNull( RecalculationRule::row( self::row( array( 'price_decimals' => '9' ) ) ), 'Precision out of range.' );
	}

	/**
	 * A settled row of 100.00 incl. VAT at 20 %, settled under ADR-049.
	 *
	 * @param array<string, mixed> $override Values to change.
	 * @return array<string, mixed>
	 */
	private static function row( array $override = array() ): array {
		return array_merge(
			array(
				'net_amount'     => '80.0000',
				'tax_amount'     => '20.0000',
				'share_bps'      => '2000',
				'price_decimals' => '2',
				'profit_amount'  => '20.0000',
				'payout_amount'  => '16.0000',
			),
			$override
		);
	}
}
