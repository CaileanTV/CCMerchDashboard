<?php
/**
 * Tests for payout details, their masking and the balance (ADR-054, ADR-056).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalance;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDestination;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutMethod;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( PayoutDestination::class )]
#[CoversClass( PayoutBalance::class )]
#[CoversClass( PayoutCipher::class )]
final class PayoutDestinationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( static fn( string $email ): string|false => false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? false : $email );
	}

	public function test_bank_details_are_validated_and_masked_without_the_registration_number(): void {
		$bank = PayoutDestination::bank( ' 1234 ', '0012 345 678' );

		self::assertSame( array( PayoutMethod::Bank, '1234', '0012345678' ), array( $bank->method, $bank->registration, $bank->account ) );
		self::assertSame( '•••• 5678', $bank->hint() );
		self::assertSame( '••••', PayoutDestination::bank( '1234', '5678' )->hint(), 'A short account is never shown in full.' );
	}

	/**
	 * Invalid bank details.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function invalid_bank(): array {
		return array(
			'registration of 3 digits' => array( '123', '1' ),
			'registration of 5 digits' => array( '12345', '1' ),
			'letters'                  => array( '12a4', '1' ),
			'empty account'            => array( '1234', '' ),
			'account of 11 digits'     => array( '1234', '12345678901' ),
			'account with a dash'      => array( '1234', '123-456' ),
		);
	}

	#[DataProvider( 'invalid_bank' )]
	public function test_invalid_bank_details_are_refused( string $registration, string $account ): void {
		$this->expectExceptionObject( new PayoutException( PayoutException::INVALID_DETAILS ) );
		PayoutDestination::bank( $registration, $account );
	}

	public function test_paypal_is_an_email_address_and_is_masked(): void {
		self::assertSame( 'j•••@g•••.com', PayoutDestination::paypal( ' jane.doe@gmail.com ' )->hint() );
		self::assertSame( 'a•••@e•••.uk', PayoutDestination::paypal( 'a@example.co.uk' )->hint(), 'Only the top-level domain is shown.' );

		foreach ( array( '', 'not-an-email', str_repeat( 'a', 190 ) . '@x.dk' ) as $email ) {
			try {
				PayoutDestination::paypal( $email );
				self::fail( 'Expected a refusal for ' . $email );
			} catch ( PayoutException $e ) {
				self::assertSame( PayoutException::INVALID_DETAILS, $e->reason() );
			}
		}
	}

	public function test_details_survive_the_json_round_trip_and_damaged_text_is_unreadable(): void {
		$bank   = PayoutDestination::bank( '1234', '0012345678' );
		$paypal = PayoutDestination::paypal( 'jane.doe@gmail.com' );

		self::assertEquals( $bank, PayoutDestination::from_json( $bank->to_json() ) );
		self::assertEquals( $paypal, PayoutDestination::from_json( $paypal->to_json() ) );
		self::assertEquals( $bank, PayoutDestination::from_input( PayoutMethod::Bank, array( 'registration' => '1234', 'account' => '0012345678' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		foreach ( array( '', '{', '[]', '{"method":"gift_card"}', '{"method":"bank","registration":"12","account":"1"}', '{"method":"paypal","account":"x"}' ) as $json ) {
			try {
				PayoutDestination::from_json( $json );
				self::fail( 'Expected unreadable details for ' . $json );
			} catch ( PayoutException $e ) {
				self::assertSame( PayoutException::DETAILS_UNREADABLE, $e->reason() );
			}
		}
	}

	public function test_a_balance_is_unknown_never_0_when_the_earnings_are_unknown(): void {
		$known = new PayoutBalance( 'DKK', 600000, 0, 100000, 200000 );
		self::assertSame( array( 'complete', 300000, 300000, 0 ), array( $known->status(), $known->balance_units(), $known->available_units(), $known->outstanding_units() ) );

		$negative = new PayoutBalance( 'DKK', 100000, 0, 300000, 0 );
		self::assertSame( array( -200000, 0, 200000 ), array( $negative->balance_units(), $negative->available_units(), $negative->outstanding_units() ) );

		$unknown = new PayoutBalance( 'DKK', null, 2, 100000, 0 );
		self::assertSame( array( 'unknown', null, null, null ), array( $unknown->status(), $unknown->balance_units(), $unknown->available_units(), $unknown->outstanding_units() ) );
	}

	public function test_a_missing_or_malformed_key_makes_the_cipher_unavailable(): void {
		foreach ( array( null, '', 'not base64!', base64_encode( str_repeat( 'k', 31 ) ), base64_encode( str_repeat( 'k', 33 ) ) ) as $key ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Malformed test keys.
			$cipher = new PayoutCipher( $key );
			self::assertFalse( $cipher->available() );

			try {
				$cipher->encrypt( 'secret' );
				self::fail( 'Expected an unavailable cipher.' );
			} catch ( PayoutException $e ) {
				self::assertSame( PayoutException::METHOD_UNAVAILABLE, $e->reason() );
			}
		}
	}
}
