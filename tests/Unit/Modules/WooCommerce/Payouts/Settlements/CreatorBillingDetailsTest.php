<?php
/**
 * Tests for CreatorBillingDetails.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts\Settlements;

use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetails;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * ADR-062 K09: the creator's master data on settlement documents. Synthetic
 * data only; a passing test is no validation of the formal requirements.
 */
#[CoversClass( CreatorBillingDetails::class )]
final class CreatorBillingDetailsTest extends TestCase {

	public function test_complete_master_data_is_trimmed_and_the_cvr_number_has_its_spaces_removed(): void {
		$details = CreatorBillingDetails::from_input(
			array(
				'legal_name' => ' Søren Ærø ApS ',
				'address_1'  => 'Nørregade 2',
				'address_2'  => '1. th.',
				'postcode'   => '8000',
				'city'       => 'Aarhus C',
				'country'    => 'Danmark',
				'cvr'        => ' 8765 4321 ',
			)
		);

		self::assertSame( 'Søren Ærø ApS', $details->legal_name );
		self::assertSame( '87654321', $details->cvr );
		self::assertSame( array( 'Nørregade 2', '1. th.', '8000 Aarhus C', 'Danmark' ), $details->address_lines() );
		self::assertSame( $details->values(), $details->to_stored() );
	}

	public function test_missing_master_data_stays_empty_and_is_never_filled_in(): void {
		$details = CreatorBillingDetails::from_input( array( 'city' => 'Aarhus C' ) );

		self::assertSame( '', $details->legal_name );
		self::assertSame( '', $details->cvr );
		self::assertSame( array( 'Aarhus C' ), $details->address_lines(), 'Only the lines that are registered.' );
		self::assertSame( array( 'city' => 'Aarhus C' ), $details->to_stored() );

		$none = CreatorBillingDetails::from_input( array() );

		self::assertSame( array(), $none->address_lines() );
		self::assertSame( array(), $none->to_stored(), 'Nothing to store.' );
	}

	public function test_a_cvr_or_se_number_must_be_eight_digits(): void {
		foreach ( array( '1234567', '123456789', '1234567a', 'DK12345678', '12-345678' ) as $cvr ) {
			try {
				CreatorBillingDetails::from_input( array( 'cvr' => $cvr ) );
				self::fail( 'Expected a refusal for ' . $cvr );
			} catch ( PayoutException $e ) {
				self::assertSame( PayoutException::INVALID_CVR, $e->reason() );
			}
		}
	}

	public function test_texts_that_are_too_long_or_have_control_characters_are_refused(): void {
		$cases = array(
			'too long name'     => array( 'legal_name' => str_repeat( 'Æ', 192 ) ),
			'too long postcode' => array( 'postcode' => str_repeat( '1', 21 ) ),
			'line break'        => array( 'address_1' => "Nørregade 2\nX" ),
			'control character' => array( 'city' => "Aar\x00hus" ),
			'invalid UTF-8'     => array( 'country' => "Danmark\xC3" ),
		);

		foreach ( $cases as $case => $values ) {
			try {
				CreatorBillingDetails::from_input( $values );
				self::fail( 'Expected a refusal: ' . $case );
			} catch ( PayoutException $e ) {
				self::assertSame( PayoutException::INVALID_BILLING_DETAILS, $e->reason(), $case );
			}
		}

		self::assertSame( 191, mb_strlen( CreatorBillingDetails::from_input( array( 'legal_name' => str_repeat( 'Æ', 191 ) ) )->legal_name ), 'The longest name is allowed.' );
	}

	public function test_stored_master_data_ignores_anything_that_is_not_text(): void {
		$details = CreatorBillingDetails::from_stored(
			array(
				'legal_name' => 'Søren Ærø ApS',
				'cvr'        => 87654321,
				'city'       => array( 'Aarhus C' ),
				'other'      => 'x',
			)
		);

		self::assertSame( array( 'legal_name' => 'Søren Ærø ApS' ), $details->to_stored() );

		foreach ( array( null, '', 'x', 7 ) as $stored ) {
			self::assertSame( array(), CreatorBillingDetails::from_stored( $stored )->to_stored() );
		}
	}

	public function test_changed_fields_are_named_without_their_values(): void {
		$before = new CreatorBillingDetails( 'Søren Ærø', 'Nørregade 2', '', '8000', 'Aarhus C', '', '' );
		$after  = new CreatorBillingDetails( 'Søren Ærø ApS', 'Nørregade 2', '', '8000', 'Aarhus C', '', '87654321' );

		self::assertSame( array( 'legal_name', 'cvr' ), $after->changed_fields( $before ) );
		self::assertSame( array(), $after->changed_fields( $after ) );
	}
}
