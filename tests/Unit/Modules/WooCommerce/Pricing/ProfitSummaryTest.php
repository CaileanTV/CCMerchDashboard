<?php
/**
 * Tests for the profit of a group of ledger rows (ADR-050).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitSummary;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ProfitSummary::class )]
final class ProfitSummaryTest extends TestCase {

	public function test_only_a_complete_group_has_an_amount(): void {
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'complete', 'amount' => '20.0000' ), ( new ProfitSummary( 'DKK', 200000, 2, 0 ) )->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'incomplete', 'amount' => null ), ( new ProfitSummary( 'DKK', 200000, 2, 1 ) )->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'unknown', 'amount' => null ), ( new ProfitSummary( 'DKK', 0, 0, 3 ) )->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_a_complete_zero_and_negative_profit_are_real_amounts(): void {
		self::assertSame( '0.0000', ( new ProfitSummary( 'DKK', 0, 2, 0 ) )->to_array()['amount'], 'Sold and fully refunded.' );
		self::assertSame( '-16.0000', ( new ProfitSummary( 'DKK', -160000, 1, 0 ) )->to_array()['amount'], 'A refund in the period.' );
	}

	public function test_groups_add_up_and_one_unknown_row_makes_the_sum_incomplete(): void {
		$sum = ( new ProfitSummary( 'DKK', 200000, 1, 0 ) )->plus( new ProfitSummary( 'DKK', -50000, 1, 0 ) );
		self::assertSame( 150000, $sum->amount_units() );

		$with_unknown = $sum->plus( new ProfitSummary( 'DKK', 0, 0, 1 ) );
		self::assertSame( ProfitSummary::INCOMPLETE, $with_unknown->status() );
		self::assertNull( $with_unknown->amount_units() );
		self::assertSame( array( 2, 1 ), array( $with_unknown->known_rows, $with_unknown->unknown_rows ) );
	}

	public function test_currencies_are_never_added(): void {
		$this->expectException( InvalidArgumentException::class );

		( new ProfitSummary( 'DKK', 1, 1, 0 ) )->plus( new ProfitSummary( 'EUR', 1, 1, 0 ) );
	}

	public function test_invalid_groups_are_rejected(): void {
		foreach ( array( array( 'DKK', 0, 0, 0 ), array( 'DKK', 5, 0, 1 ), array( 'DKK', 0, -1, 2 ), array( 'dkk', 0, 1, 0 ) ) as [ $currency, $units, $known, $unknown ] ) {
			try {
				new ProfitSummary( $currency, $units, $known, $unknown );
				self::fail( 'Expected an exception.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_the_schema_matches_the_representation(): void {
		$schema = ProfitSummary::schema();

		self::assertSame( array( 'currency', 'status', 'amount' ), array_keys( $schema['properties'] ) );
		self::assertSame( array( 'complete', 'incomplete', 'unknown' ), $schema['properties']['status']['enum'] );
	}
}
