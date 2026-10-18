<?php
/**
 * Tests for the VAT deducted from the payout of sales while not VAT registered (ADR-064).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts\Settlements;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\VatDeduction;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use UnexpectedValueException;

#[CoversClass( VatDeduction::class )]
final class VatDeductionTest extends TestCase {

	public function test_only_rows_of_sales_while_not_vat_registered_count(): void {
		$deduction = VatDeduction::of_rows(
			array(
				self::row( '20.0000', '16.0000', false ),
				self::row( '20.0000', '20.0000', true ),
				self::row( '-5.0000', '-4.0000', false ),
			)
		);

		self::assertSame(
			array(
				'gross_units'     => SalesAmount::parse( '15' ),
				'deduction_units' => SalesAmount::parse( '3' ),
				'net_units'       => SalesAmount::parse( '12' ),
			),
			$deduction
		);
	}

	public function test_no_rows_while_not_vat_registered_give_no_deduction(): void {
		self::assertSame(
			array(
				'gross_units'     => 0,
				'deduction_units' => 0,
				'net_units'       => 0,
			),
			VatDeduction::of_rows( array( self::row( '20.0000', '20.0000', true ) ) )
		);
	}

	public function test_stored_rows_give_the_same_deduction(): void {
		$stored = array(
			array(
				'vat_registered' => '0',
				'profit_amount'  => '20.0000',
				'payout_amount'  => '16.0000',
			),
			array(
				'vat_registered' => '1',
				'profit_amount'  => '20.0000',
				'payout_amount'  => '20.0000',
			),
		);

		self::assertSame( VatDeduction::of_rows( array( self::row( '20.0000', '16.0000', false ), self::row( '20.0000', '20.0000', true ) ) ), VatDeduction::of_stored_rows( $stored ) );
	}

	public function test_an_invalid_stored_row_is_refused(): void {
		$this->expectException( UnexpectedValueException::class );

		VatDeduction::of_stored_rows(
			array(
				array(
					'vat_registered' => 'yes',
					'profit_amount'  => '20.0000',
					'payout_amount'  => '16.0000',
				),
			)
		);
	}

	public function test_the_snapshot_round_trips_from_template_version_6(): void {
		$deduction = VatDeduction::of_rows( array( self::row( '20.0000', '16.0000', false ) ) );
		$document  = self::document( 6, array( VatDeduction::SNAPSHOT_KEY => VatDeduction::snapshot( $deduction ) ) );

		self::assertSame( $deduction, VatDeduction::of_document( $document ) );
		self::assertSame( $deduction, VatDeduction::shown( $document ) );
	}

	public function test_earlier_versions_and_corrections_have_none(): void {
		foreach ( array( 1, 2, 3, 4 ) as $version ) {
			self::assertNull( VatDeduction::of_document( self::document( $version, array() ) ), 'Template version ' . $version );
		}

		self::assertNull( VatDeduction::of_document( self::document( 6, array(), SettlementDocument::TYPE_CORRECTION ) ) );
	}

	public function test_a_zero_deduction_is_not_shown(): void {
		$document = self::document( 6, array( VatDeduction::SNAPSHOT_KEY => VatDeduction::snapshot( VatDeduction::of_rows( array() ) ) ) );

		self::assertNotNull( VatDeduction::of_document( $document ) );
		self::assertNull( VatDeduction::shown( $document ) );
	}

	public function test_an_invalid_snapshot_is_refused_and_never_shown(): void {
		$cases = array(
			'missing'         => array(),
			'other version'   => array(
				VatDeduction::SNAPSHOT_KEY => array(
					'version'   => 2,
					'gross'     => '20.0000',
					'deduction' => '4.0000',
					'net'       => '16.0000',
				),
			),
			'does not add up' => array(
				VatDeduction::SNAPSHOT_KEY => array(
					'version'   => VatDeduction::VERSION,
					'gross'     => '20.0000',
					'deduction' => '4.0000',
					'net'       => '17.0000',
				),
			),
			'not an amount'   => array(
				VatDeduction::SNAPSHOT_KEY => array(
					'version'   => VatDeduction::VERSION,
					'gross'     => 20,
					'deduction' => '4.0000',
					'net'       => '16.0000',
				),
			),
		);

		$refused = array();

		foreach ( $cases as $case => $snapshot ) {
			$document = self::document( 6, $snapshot );
			self::assertNull( VatDeduction::shown( $document ), $case );

			try {
				VatDeduction::of_document( $document );
			} catch ( UnexpectedValueException ) {
				$refused[] = $case;
			}
		}

		self::assertSame( array_keys( $cases ), $refused, 'Every invalid snapshot is refused.' );
	}

	/**
	 * A row for of_rows().
	 *
	 * @param string $profit     Profit.
	 * @param string $payout     Payout.
	 * @param bool   $registered VAT registered at the sale.
	 * @return array{profit_units: int, payout_units: int, vat_registered: bool}
	 */
	private static function row( string $profit, string $payout, bool $registered ): array {
		return array(
			'profit_units'   => SalesAmount::parse( $profit ),
			'payout_units'   => SalesAmount::parse( $payout ),
			'vat_registered' => $registered,
		);
	}

	/**
	 * A document with a snapshot.
	 *
	 * @param int                  $version  Template version.
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param string               $type     Document type.
	 */
	private static function document( int $version, array $snapshot, string $type = SettlementDocument::TYPE_SETTLEMENT ): SettlementDocument {
		$utc = new DateTimeZone( 'UTC' );

		return new SettlementDocument(
			7,
			'AFR-000007',
			$type,
			3,
			41,
			null,
			'DKK',
			null,
			new DateTimeImmutable( '2026-11-30 12:00:00', $utc ),
			0,
			0,
			0,
			0,
			0,
			false,
			'adr064-adr054-v1',
			$version,
			$snapshot,
			1,
			new DateTimeImmutable( '2026-11-30 12:00:00', $utc )
		);
	}
}
