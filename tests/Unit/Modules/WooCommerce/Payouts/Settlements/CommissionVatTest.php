<?php
/**
 * Tests for CommissionVat.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts\Settlements;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CommissionVat;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use UnexpectedValueException;

/**
 * ADR-062 K05: the commission includes VAT where VAT is calculated; none for
 * sales while the creator was not VAT registered. Synthetic amounts only; a
 * passing test is no validation by an auditor or the Danish Tax Agency.
 */
#[CoversClass( CommissionVat::class )]
final class CommissionVatTest extends TestCase {

	public function test_1000_incl_vat_at_25_percent_is_800_excl_vat_and_200_vat(): void {
		self::assertSame(
			array(
				'rate_bps' => 2500,
				'incl'     => '1000.0000',
				'vat'      => '200.0000',
				'excl'     => '800.0000',
				'untaxed'  => '0.0000',
			),
			self::formatted( CommissionVat::split( array( self::row( '1000', true ) ) ) )
		);
		self::assertSame( 2500, CommissionVat::RATE_BPS );
	}

	public function test_no_vat_is_calculated_for_sales_while_the_creator_was_not_vat_registered(): void {
		self::assertSame(
			array(
				'rate_bps' => 2500,
				'incl'     => '0.0000',
				'vat'      => '0.0000',
				'excl'     => '0.0000',
				'untaxed'  => '1000.0000',
			),
			self::formatted( CommissionVat::split( array( self::row( '1000', false ) ) ) )
		);
	}

	public function test_each_row_follows_the_vat_status_at_its_sale(): void {
		$split = self::formatted( CommissionVat::split( array( self::row( '600', true ), self::row( '400', false ) ) ) );

		self::assertSame( array( '600.0000', '120.0000', '480.0000', '400.0000' ), array( $split['incl'], $split['vat'], $split['excl'], $split['untaxed'] ) );
	}

	public function test_the_vat_is_rounded_half_away_from_zero_once_per_document(): void {
		self::assertSame( '0.0100', self::formatted( CommissionVat::split( array( self::row( '0.03', true ) ) ) )['vat'], '0.006 → 0.01.' );
		self::assertSame( '0.0200', self::formatted( CommissionVat::split( array( self::row( '0.03', true ) ) ) )['excl'], 'excl = incl − VAT exactly.' );
		self::assertSame( '0.0000', self::formatted( CommissionVat::split( array( self::row( '0.02', true ) ) ) )['vat'], '0.004 → 0.00.' );
		self::assertSame( '0.0100', self::formatted( CommissionVat::split( array( self::row( '0.025', true ) ) ) )['vat'], '0.005 → 0.01 (half away from zero).' );
		self::assertSame( '0.0200', self::formatted( CommissionVat::split( array( self::row( '0.03', true ), self::row( '0.03', true ), self::row( '0.03', true ) ) ) )['vat'], 'Of the total 0.09 (0.018 → 0.02), not 3 × 0.01.' );
		self::assertSame( '-0.0100', self::formatted( CommissionVat::split( array( self::row( '-0.03', true ) ) ) )['vat'], 'A refund mirrors a sale.' );
		self::assertSame( '2.0000', self::formatted( CommissionVat::split( array( self::row( '11', true, 0 ) ) ) )['vat'], 'Store precision 0: 2.2 → 2.' );
		self::assertSame( '0.0000', self::formatted( CommissionVat::split( array( self::row( '100', true ), self::row( '-100', true ) ) ) )['vat'], 'A full refund leaves no VAT.' );
		self::assertSame( '0.0000', self::formatted( CommissionVat::split( array() ) )['vat'] );
	}

	public function test_the_rate_and_the_precision_must_be_valid(): void {
		$refused = 0;

		foreach ( array( 0, 10001 ) as $rate ) {
			try {
				CommissionVat::split( array( self::row( '10', true ) ), $rate );
				self::fail( 'Expected a refused rate: ' . $rate );
			} catch ( InvalidArgumentException ) {
				++$refused;
			}
		}

		self::assertSame( 2, $refused );
		$this->expectException( InvalidArgumentException::class );
		CommissionVat::split( array( self::row( '10', false, 5 ) ) );
	}

	public function test_stored_rows_give_the_same_split_and_invalid_rows_are_refused(): void {
		$stored = array(
			array(
				'vat_registered' => '1',
				'price_decimals' => '2',
				'payout_amount'  => '600.0000',
			),
			array(
				'vat_registered' => '0',
				'price_decimals' => '2',
				'payout_amount'  => '400.0000',
			),
		);

		self::assertSame( CommissionVat::split( array( self::row( '600', true ), self::row( '400', false ) ) ), CommissionVat::of_stored_rows( $stored, 2500 ) );

		$refused = 0;

		foreach ( array( array( 'vat_registered' => null ), array( 'vat_registered' => 'yes' ), array( 'price_decimals' => '9' ), array( 'payout_amount' => 600 ) ) as $broken ) {
			try {
				CommissionVat::of_stored_rows( array( array_merge( $stored[0], $broken ) ), 2500 );
				self::fail( 'Expected a refused row.' );
			} catch ( UnexpectedValueException ) {
				++$refused;
			}
		}

		self::assertSame( 4, $refused );
	}

	public function test_a_template_3_document_carries_its_split_and_earlier_ones_none(): void {
		$snapshot = CommissionVat::snapshot( CommissionVat::split( array( self::row( '600', true ), self::row( '400', false ) ) ) );

		self::assertSame(
			array(
				'version'  => 1,
				'rate_bps' => 2500,
				'incl'     => '600.0000',
				'vat'      => '120.0000',
				'excl'     => '480.0000',
				'untaxed'  => '400.0000',
			),
			$snapshot
		);
		self::assertSame( '120.0000', SalesAmount::format( CommissionVat::of_document( self::document( 3, '1000', $snapshot ) )['vat_units'] ?? 0 ) );
		self::assertSame( CommissionVat::of_document( self::document( 3, '1000', $snapshot ) ), CommissionVat::shown( self::document( 3, '1000', $snapshot ) ) );
		self::assertNull( CommissionVat::of_document( self::document( 2, '1000', $snapshot ) ), 'Version 2 never has VAT on the commission.' );
		self::assertNull( CommissionVat::of_document( self::document( 3, '1000', $snapshot, SettlementDocument::TYPE_CORRECTION ) ) );
	}

	public function test_nothing_is_shown_without_vat_on_the_commission(): void {
		$none = CommissionVat::snapshot( CommissionVat::split( array( self::row( '1000', false ) ) ) );

		self::assertNotNull( CommissionVat::of_document( self::document( 3, '1000', $none ) ) );
		self::assertNull( CommissionVat::shown( self::document( 3, '1000', $none ) ), 'K12: nothing that could be read as VAT on the commission.' );
	}

	public function test_a_missing_or_inconsistent_split_is_refused(): void {
		$valid = CommissionVat::snapshot( CommissionVat::split( array( self::row( '1000', true ) ) ) );
		$cases = array(
			'missing'          => null,
			'version'          => array( 'version' => 2 ) + $valid,
			'rate'             => array( 'rate_bps' => 0 ) + $valid,
			'rate type'        => array( 'rate_bps' => '2500' ) + $valid,
			'excl + vat'       => array( 'vat' => '199.0000' ) + $valid,
			'not a commission' => array( 'untaxed' => '1.0000' ) + $valid,
			'amount type'      => array( 'incl' => 1000 ) + $valid,
		);

		foreach ( $cases as $case => $stored ) {
			try {
				CommissionVat::of_document( self::document( 3, '1000', $stored ) );
				self::fail( 'Expected a refused split: ' . $case );
			} catch ( UnexpectedValueException ) {
				self::assertNull( CommissionVat::shown( self::document( 3, '1000', $stored ) ), 'Never shown: ' . $case );
			}
		}
	}

	public function test_the_rate_is_written_as_a_percentage(): void {
		self::assertSame( array( '25', '24,5', '25,05', '0,05', '100' ), array( CommissionVat::percent( 2500, ',' ), CommissionVat::percent( 2450, ',' ), CommissionVat::percent( 2505, ',' ), CommissionVat::percent( 5, ',' ), CommissionVat::percent( 10000, ',' ) ) );
	}

	/**
	 * A settled row.
	 *
	 * @param string $payout   Commission (payout) incl. VAT where VAT is calculated.
	 * @param bool   $vat      VAT status at the sale.
	 * @param int    $decimals Store precision.
	 * @return array{payout_units: int, vat_registered: bool, price_decimals: int}
	 */
	private static function row( string $payout, bool $vat, int $decimals = 2 ): array {
		return array(
			'payout_units'   => SalesAmount::parse( $payout ),
			'vat_registered' => $vat,
			'price_decimals' => $decimals,
		);
	}

	/**
	 * A split with decimal strings.
	 *
	 * @param array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int} $split Split.
	 * @return array{rate_bps: int, incl: string, vat: string, excl: string, untaxed: string}
	 */
	private static function formatted( array $split ): array {
		return array(
			'rate_bps' => $split['rate_bps'],
			'incl'     => SalesAmount::format( $split['incl_units'] ),
			'vat'      => SalesAmount::format( $split['vat_units'] ),
			'excl'     => SalesAmount::format( $split['excl_units'] ),
			'untaxed'  => SalesAmount::format( $split['untaxed_units'] ),
		);
	}

	/**
	 * A document with a commission and a stored split.
	 *
	 * @param int    $template   Template version.
	 * @param string $commission Commission.
	 * @param mixed  $stored     The stored split (null = none).
	 * @param string $type       Document type.
	 */
	private static function document( int $template, string $commission, mixed $stored, string $type = SettlementDocument::TYPE_SETTLEMENT ): SettlementDocument {
		$time = new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );

		return new SettlementDocument( 1, 'AFR-000001', $type, 3, 41, null, 'DKK', null, $time, 0, SalesAmount::parse( $commission ), 0, 0, SalesAmount::parse( $commission ), true, 'adr063-adr054-v1', $template, null === $stored ? array() : array( CommissionVat::SNAPSHOT_KEY => $stored ), 1, $time );
	}
}
