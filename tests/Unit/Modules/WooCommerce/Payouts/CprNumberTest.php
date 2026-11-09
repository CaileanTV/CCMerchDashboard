<?php
/**
 * Tests for the CPR number given with a payout (ADR-104).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Payouts\CprNumber;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDestination;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutFreeText;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutMethod;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( CprNumber::class )]
#[CoversClass( PayoutDestination::class )]
#[CoversClass( PayoutFreeText::class )]
final class CprNumberTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( static fn( string $email ): string|false => false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? false : $email );
	}

	public function test_a_cpr_number_is_ten_digits_with_a_date(): void {
		self::assertSame( '0101901234', CprNumber::from_input( '010190-1234' )->value );
		self::assertSame( '0101901234', CprNumber::from_input( ' 010190 1234 ' )->value );
		self::assertSame( '0101901234', CprNumber::from_input( '010190–1234' )->value, 'A typographic dash too.' );
		self::assertSame( '290200-1234', CprNumber::from_input( '2902001234' )->formatted(), '29 February 2000.' );
	}

	/**
	 * Invalid input.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid(): array {
		return array(
			'empty'       => array( '' ),
			'missing'     => array( null ),
			'nine digits' => array( '010190123' ),
			'letters'     => array( '010190-12a4' ),
			'no date'     => array( '3213901234' ),
			'31 April'    => array( '3104901234' ),
			'not string'  => array( 101901234 ),
		);
	}

	/**
	 * Invalid numbers are refused with `invalid_cpr`.
	 *
	 * @param mixed $input Input.
	 */
	#[DataProvider( 'invalid' )]
	public function test_invalid_numbers_are_refused( mixed $input ): void {
		try {
			CprNumber::from_input( $input );
			self::fail( 'Expected PayoutException.' );
		} catch ( PayoutException $e ) {
			self::assertSame( PayoutException::INVALID_CPR, $e->reason() );
		}
	}

	public function test_the_cpr_number_goes_with_the_encrypted_details_and_never_in_the_hint(): void {
		$cpr  = CprNumber::from_input( '010190-1234' );
		$bank = PayoutDestination::bank( '1234', '0012345678' )->with_cpr( $cpr );

		self::assertSame( '•••• 5678', $bank->hint() );

		// The plain text that PayoutCipher encrypts (encryption itself: integration tests).
		$read = PayoutDestination::from_json( $bank->to_json() );
		self::assertSame( array( PayoutMethod::Bank, '0012345678', '0101901234' ), array( $read->method, $read->account, $read->cpr ) );

		$gift = PayoutDestination::from_json( PayoutDestination::gift_card( $cpr )->to_json() );
		self::assertSame( array( PayoutMethod::GiftCard, '', '0101901234' ), array( $gift->method, $gift->hint(), $gift->cpr ) );
		self::assertSame( '', PayoutDestination::bank( '1234', '0012345678' )->cpr, 'Without a CPR number nothing is added.' );
		self::assertStringNotContainsString( 'cpr', PayoutDestination::bank( '1234', '0012345678' )->to_json() );
	}

	public function test_a_gift_card_without_a_cpr_number_or_a_broken_one_is_unreadable(): void {
		foreach ( array( '{"method":"gift_card","registration":"","account":""}', '{"method":"bank","registration":"1234","account":"5678","cpr":"x"}' ) as $json ) {
			try {
				PayoutDestination::from_json( $json );
				self::fail( 'Expected PayoutException.' );
			} catch ( PayoutException $e ) {
				self::assertSame( PayoutException::DETAILS_UNREADABLE, $e->reason() );
			}
		}
	}

	public function test_the_cpr_number_is_refused_in_a_kept_text(): void {
		$details = PayoutDestination::bank( '1234', '0012345678' )->with_cpr( CprNumber::from_input( '0101901234' ) );

		self::assertTrue( PayoutFreeText::contains_payment_details( 'CPR 010190-1234', $details ) );
		self::assertTrue( PayoutFreeText::contains_payment_details( 'cpr 010190 1234', $details ) );
		self::assertFalse( PayoutFreeText::contains_payment_details( 'Bank transaktions-ID 88213377', $details ) );
	}
}
