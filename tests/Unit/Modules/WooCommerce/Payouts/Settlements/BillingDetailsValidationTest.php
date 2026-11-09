<?php
/**
 * Tests for the creator's own master data form (ADR-104).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts\Settlements;

use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\BillingDetailsInvalid;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\BillingDetailsReview;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetails;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( BillingDetailsReview::class )]
#[CoversClass( BillingDetailsInvalid::class )]
#[CoversClass( CreatorBillingDetails::class )]
final class BillingDetailsValidationTest extends TestCase {

	private const BUSINESS = array(
		'type'       => 'business',
		'legal_name' => ' Nova ApS ',
		'address_1'  => 'Nørregade 2',
		'address_2'  => '',
		'postcode'   => '8000',
		'city'       => 'Aarhus C',
		'country'    => 'Danmark',
		'cvr'        => '1234 5678',
	);

	public function test_a_business_needs_its_cvr_number(): void {
		$details = BillingDetailsReview::validate( self::BUSINESS );

		self::assertSame( array( 'Nova ApS', '12345678', CreatorBillingDetails::BUSINESS ), array( $details->legal_name, $details->cvr, $details->type ) );
		self::assertSame( array( 'cvr' => 'required' ), $this->errors( array( 'cvr' => '' ) + self::BUSINESS ) );
		self::assertSame( array( 'cvr' => 'invalid_cvr' ), $this->errors( array( 'cvr' => '1234' ) + self::BUSINESS ) );
	}

	public function test_a_private_person_keeps_no_cvr_number(): void {
		$details = BillingDetailsReview::validate( array( 'type' => 'private', 'cvr' => 'not even digits' ) + self::BUSINESS ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( array( '', CreatorBillingDetails::PRIVATE ), array( $details->cvr, $details->type ) );
		self::assertArrayNotHasKey( 'cpr', $details->values(), 'The CPR number is never master data.' );
	}

	public function test_required_fields_and_the_type_are_checked_together(): void {
		self::assertSame(
			array(
				'city'       => 'invalid',
				'legal_name' => 'required',
				'postcode'   => 'too_long',
				'type'       => 'required',
			),
			$this->errors(
				array(
					'type'       => 'company',
					'legal_name' => '   ',
					'postcode'   => str_repeat( '1', 21 ),
					'city'       => "Aarhus\nC",
				) + self::BUSINESS
			)
		);
		self::assertSame( array( 'country' => 'invalid' ), $this->errors( array( 'country' => array( 'DK' ) ) + self::BUSINESS ) );
	}

	public function test_admin_input_keeps_the_type_and_refuses_unknown_ones(): void {
		self::assertSame( CreatorBillingDetails::PRIVATE, CreatorBillingDetails::from_input( array( 'type' => 'private' ) )->type );
		self::assertSame( '', CreatorBillingDetails::from_input( array() )->type, 'Not chosen (before phase 42).' );
		self::assertSame( CreatorBillingDetails::BUSINESS, CreatorBillingDetails::from_stored( array( 'type' => 'business' ) )->type );
		self::assertSame( '', CreatorBillingDetails::from_stored( array( 'type' => 'other' ) )->type );

		try {
			CreatorBillingDetails::from_input( array( 'type' => 'other' ) );
			self::fail( 'Expected PayoutException.' );
		} catch ( PayoutException $e ) {
			self::assertSame( PayoutException::INVALID_BILLING_DETAILS, $e->reason() );
		}
	}

	/**
	 * The field errors of a submission.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return array<string, string>
	 */
	private function errors( array $values ): array {
		try {
			BillingDetailsReview::validate( $values );
		} catch ( BillingDetailsInvalid $e ) {
			$errors = $e->fields();
			ksort( $errors );

			return $errors;
		}

		return array();
	}
}
