<?php
/**
 * Tests for SettlementCorrection.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts\Settlements;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CommissionVat;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementCorrection;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use UnexpectedValueException;

/**
 * ADR-062 K10 and K15: the amounts of a correction document. Synthetic
 * amounts only; a passing test is no validation by an auditor, the Danish
 * Tax Agency or a lawyer.
 */
#[CoversClass( SettlementCorrection::class )]
final class SettlementCorrectionTest extends TestCase {

	public function test_corrected_amounts_split_the_commission_with_vat_at_the_rate(): void {
		self::assertSame(
			array(
				'commission' => SalesAmount::parse( '1250' ),
				'excl'       => SalesAmount::parse( '800' ),
				'vat'        => SalesAmount::parse( '200' ),
				'incl'       => SalesAmount::parse( '1000' ),
				'untaxed'    => SalesAmount::parse( '250' ),
			),
			SettlementCorrection::corrected( 2500, SalesAmount::parse( '1000' ), SalesAmount::parse( '250' ), 2 )
		);
		self::assertSame( SalesAmount::parse( '0.02' ), SettlementCorrection::corrected( 2500, SalesAmount::parse( '0.10' ), 0, 2 )['vat'], '0.10 × 20 % = 0.02, rounded once.' );
		self::assertSame( SalesAmount::parse( '-2' ), SettlementCorrection::corrected( 2500, SalesAmount::parse( '-10' ), 0, 2 )['vat'], 'A negative commission has negative VAT.' );
	}

	public function test_an_amount_is_a_plain_decimal_with_at_most_the_store_precision(): void {
		self::assertSame( SalesAmount::parse( '1250.5' ), SettlementCorrection::amount( ' 1250,50 ', 2 ) );
		self::assertSame( SalesAmount::parse( '-3' ), SettlementCorrection::amount( '-3', 0 ) );
		self::assertSame( SalesAmount::parse( '0.125' ), SettlementCorrection::amount( '0.125', 3 ) );

		foreach ( array( array( '', 2 ), array( '1.5.0', 2 ), array( '1,234.50', 2 ), array( '1e3', 2 ), array( '+1', 2 ), array( '10 kr', 2 ), array( '1.005', 2 ), array( '1.5', 0 ), array( '0.00001', 4 ), array( '1234567890123', 2 ) ) as list( $input, $decimals ) ) {
			try {
				SettlementCorrection::amount( $input, $decimals );
				self::fail( 'Expected a refusal for ' . $input );
			} catch ( PayoutException $e ) {
				self::assertSame( PayoutException::INVALID_CORRECTION_AMOUNT, $e->reason(), $input );
			}
		}
	}

	public function test_the_amounts_in_force_are_the_latest_with_amounts_in_the_chain(): void {
		$original = self::original( 3, true );
		$rows     = array( self::row( '20', '1' ), self::row( '20', '1' ), self::row( '20', '0' ) );
		$in_force = SettlementCorrection::in_force( array( $original ), $rows );

		self::assertSame( array( 2500, 600000, 320000, 80000, 400000, 200000 ), array_values( $in_force ), 'The stored split of the original.' );

		$legacy = self::original( 2, false );
		self::assertSame( $in_force, SettlementCorrection::in_force( array( $legacy ), $rows ), 'Before template version 3: the split of the stored rows.' );

		$first  = self::correction( $original, 1, $in_force, SettlementCorrection::corrected( 2500, SalesAmount::parse( '30' ), SalesAmount::parse( '10' ), 2 ) );
		$old    = new SettlementDocument( 9, 'AFR-000009', SettlementDocument::TYPE_CORRECTION, 3, 41, $first->id, 'DKK', null, $original->period_to, 0, 0, 0, 0, 0, true, 'v', 4, array( 'correction_reason' => 'Old.' ), 1, $original->issued_at );
		$latest = SettlementCorrection::in_force( array( $original, $first, $old ), $rows );

		self::assertSame( array( 2500, 400000, 240000, 60000, 300000, 100000 ), array_values( $latest ), 'The first correction\'s amounts; the old correction has none.' );
		self::assertNull( SettlementCorrection::of_document( $old ) );
		self::assertNull( SettlementCorrection::of_document( $original ) );
	}

	public function test_a_stored_correction_must_add_up(): void {
		$original = self::original( 3, true );
		$before   = SettlementCorrection::in_force( array( $original ), array() );
		$after    = SettlementCorrection::corrected( 2500, SalesAmount::parse( '30' ), 0, 2 );
		$valid    = self::correction( $original, 1, $before, $after );
		$stored   = SettlementCorrection::of_document( $valid );

		self::assertNotNull( $stored );
		self::assertSame( array( 'AFR-000007', 'AFR-000007', 1, 2500, 2 ), array( $stored['original'], $stored['corrects'], $stored['sequence'], $stored['rate_bps'], $stored['decimals'] ) );
		self::assertSame( SalesAmount::parse( '-30' ), $stored['difference']['commission'] );

		$snapshot = $valid->snapshot['correction'];
		$cases    = array(
			'no correction'     => null,
			'other version'     => array( 'version' => 2 ) + $snapshot,
			'other number'      => array( 'sequence' => 2 ) + $snapshot,
			'other difference'  => array( 'difference' => array( 'commission' => '-29.0000' ) + $snapshot['difference'] ) + $snapshot, // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'other vat'         => array( 'after' => array( 'excl' => '25.0000', 'vat' => '5.0000' ) + $snapshot['after'] ) + $snapshot, // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'not adding up'     => array( 'before' => array( 'commission' => '61.0000' ) + $snapshot['before'] ) + $snapshot, // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'invalid amount'    => array( 'payout' => '25,00' ) + $snapshot,
			'invalid rate'      => array( 'rate_bps' => 0 ) + $snapshot,
			'invalid decimals'  => array( 'decimals' => 5 ) + $snapshot,
			'missing reference' => array( 'original' => '' ) + $snapshot,
		);

		foreach ( $cases as $case => $correction ) {
			$document = new SettlementDocument( $valid->id, $valid->number, $valid->type, 3, 41, $valid->corrects_document_id, 'DKK', null, $valid->period_to, 0, 0, 0, 0, 0, true, 'v', 5, array( 'correction' => $correction ), 1, $valid->issued_at );

			try {
				SettlementCorrection::of_document( $document );
				self::fail( 'Expected a refusal: ' . $case );
			} catch ( UnexpectedValueException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_the_vat_categories_come_from_the_rows_status_at_the_sale(): void {
		$cases = array(
			'only with VAT'    => array( array( self::row( '20', '1' ), self::row( '-20', '1' ) ), true, false ),
			'only without VAT' => array( array( self::row( '20', '0' ) ), false, true ),
			'mixed'            => array( array( self::row( '20', '1' ), self::row( '20', '0' ) ), true, true ),
			'no rows'          => array( array(), false, false ),
		);

		foreach ( $cases as $case => list( $rows, $vat, $untaxed ) ) {
			$categories = SettlementCorrection::categories( $rows );
			self::assertSame(
				array(
					'vat'     => $vat,
					'untaxed' => $untaxed,
				),
				$categories,
				$case
			);
		}

		$only_vat = SettlementCorrection::categories( array( self::row( '20', '1' ) ) );
		self::assertTrue( SettlementCorrection::within( $only_vat, SettlementCorrection::corrected( 2500, 100, 0, 2 ) ) );
		self::assertTrue( SettlementCorrection::within( $only_vat, SettlementCorrection::corrected( 2500, 0, 0, 2 ) ), 'Zero is in every category.' );
		self::assertFalse( SettlementCorrection::within( $only_vat, SettlementCorrection::corrected( 2500, 100, 100, 2 ) ), 'No commission without VAT.' );

		$none = SettlementCorrection::categories( array( self::row( '20', '0' ) ) );
		self::assertFalse( SettlementCorrection::within( $none, SettlementCorrection::corrected( 2500, 100, 0, 2 ) ), 'No VAT on sales while not registered.' );
		self::assertTrue( SettlementCorrection::within( $none, SettlementCorrection::corrected( 2500, 0, -100, 2 ) ) );

		$this->expectException( UnexpectedValueException::class );
		SettlementCorrection::categories( array( array( 'vat_registered' => 'yes' ) ) );
	}

	public function test_only_the_master_data_of_the_parties_counts_as_a_change_of_the_parties(): void {
		$issued = array(
			'creator' => array(
				'id'             => 3,
				'name'           => 'Søren',
				'vat_registered' => false,
				'legal_name'     => 'Søren Ærø ApS',
				'address'        => array( 'Nørregade 2' ),
				'cvr'            => '87654321',
			),
			'company' => array(
				'name'    => 'CCMerch ApS',
				'cvr'     => '12345678',
				'address' => array( 'Strandvejen 1' ),
			),
		);
		$now    = $issued;

		$now['creator']['name']           = 'Søren Renamed';
		$now['creator']['vat_registered'] = true;
		self::assertTrue( SettlementCorrection::same_parties( $issued, $now ), 'The display name and the current VAT status are no change of the parties.' );

		foreach ( array( array( 'creator', 'legal_name', 'Other ApS' ), array( 'creator', 'address', array( 'Vestergade 1' ) ), array( 'creator', 'cvr', '11112222' ), array( 'company', 'name', 'Other' ), array( 'company', 'address', array() ), array( 'company', 'cvr', '' ) ) as list( $party, $key, $value ) ) {
			$changed                   = $issued;
			$changed[ $party ][ $key ] = $value;
			self::assertFalse( SettlementCorrection::same_parties( $issued, $changed ), $party . ' ' . $key );
		}

		$legacy = array(
			'creator' => array( 'name' => 'Søren' ),
			'company' => array(
				'name'    => 'CCMerch ApS',
				'address' => array(),
			),
		);
		$empty  = array(
			'creator' => array(
				'name'       => 'Søren',
				'legal_name' => '',
				'address'    => array(),
				'cvr'        => '',
			),
			'company' => array(
				'name'    => 'CCMerch ApS',
				'cvr'     => '',
				'address' => array(),
			),
		);
		self::assertTrue( SettlementCorrection::same_parties( $legacy, $empty ), 'Missing keys of a document before template version 4 are not registered.' );
	}

	public function test_the_store_precision_comes_from_the_rows(): void {
		self::assertSame( 3, SettlementCorrection::decimals( array( self::row( '1', '1', '2' ), self::row( '1', '0', '3' ) ) ) );
		self::assertSame( 2, SettlementCorrection::decimals( array() ), 'Without rows or WooCommerce: 2.' );
		self::assertTrue( SettlementCorrection::unchanged( SettlementCorrection::corrected( 2500, 100, 0, 2 ), SettlementCorrection::corrected( 2500, 100, 0, 2 ) ) );
		self::assertFalse( SettlementCorrection::unchanged( SettlementCorrection::corrected( 2500, 100, 0, 2 ), SettlementCorrection::corrected( 2500, 0, 100, 2 ) ) );
	}

	/**
	 * An original settlement document: commission 60.00 (40.00 with VAT, 20.00 without), paid out 25.00.
	 *
	 * @param int  $template Template version.
	 * @param bool $split    Whether the split is stored.
	 */
	private static function original( int $template, bool $split ): SettlementDocument {
		$time     = new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$snapshot = $split ? array( CommissionVat::SNAPSHOT_KEY => CommissionVat::snapshot( CommissionVat::split( array( self::split_row( '40', true ), self::split_row( '20', false ) ) ) ) ) : array();

		return new SettlementDocument( 7, 'AFR-000007', SettlementDocument::TYPE_SETTLEMENT, 3, 41, null, 'DKK', null, $time, SalesAmount::parse( '60' ), SalesAmount::parse( '60' ), 0, SalesAmount::parse( '25' ), SalesAmount::parse( '35' ), true, 'v', $template, $snapshot, 1, $time );
	}

	/**
	 * A correction document of template version 5.
	 *
	 * @param SettlementDocument                                                                  $corrected Document it corrects.
	 * @param int                                                                                 $sequence  Number in the chain.
	 * @param array{rate_bps: int, commission: int, excl: int, vat: int, incl: int, untaxed: int} $before    Amounts in force.
	 * @param array{commission: int, excl: int, vat: int, incl: int, untaxed: int}                $after     Corrected amounts.
	 */
	private static function correction( SettlementDocument $corrected, int $sequence, array $before, array $after ): SettlementDocument {
		$snapshot = array( 'correction' => SettlementCorrection::snapshot( 'AFR-000007', $corrected->number, $sequence, 2, $before, $after, SalesAmount::parse( '25' ) ) );

		return new SettlementDocument( 8, 'AFR-000007-K' . $sequence, SettlementDocument::TYPE_CORRECTION, 3, 41, $corrected->id, 'DKK', null, $corrected->period_to, 0, 0, 0, 0, 0, true, 'v', 5, $snapshot, 1, $corrected->issued_at );
	}

	/**
	 * A row for CommissionVat::split().
	 *
	 * @param string $payout Commission.
	 * @param bool   $vat    VAT status at the sale.
	 * @return array{payout_units: int, vat_registered: bool, price_decimals: int}
	 */
	private static function split_row( string $payout, bool $vat ): array {
		return array(
			'payout_units'   => SalesAmount::parse( $payout ),
			'vat_registered' => $vat,
			'price_decimals' => 2,
		);
	}

	/**
	 * A settled row as stored.
	 *
	 * @param string $payout   Commission.
	 * @param string $vat      VAT status at the sale ('1' or '0').
	 * @param string $decimals Store precision.
	 * @return array<string, string>
	 */
	private static function row( string $payout, string $vat, string $decimals = '2' ): array {
		return array(
			'payout_amount'  => SalesAmount::format( SalesAmount::parse( $payout ) ),
			'vat_registered' => $vat,
			'price_decimals' => $decimals,
		);
	}
}
