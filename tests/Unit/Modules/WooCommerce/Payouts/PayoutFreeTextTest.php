<?php
/**
 * Tests for payment details in the free text of payout decisions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDestination;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutFreeText;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutMethod;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( PayoutFreeText::class )]
final class PayoutFreeTextTest extends TestCase {

	/**
	 * Ordinary references and reasons, checked against a bank payout with
	 * account 0012345678 (registration 1234).
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function allowed(): array {
		return array(
			'empty'                      => array( '' ),
			'bank transaction id'        => array( 'Overført 12.10.2026, transaktions-ID 88213377' ),
			'PayPal transaction id'      => array( 'PayPal 8MC585209K746392H' ),
			'date and amount'            => array( 'Betalt 2026-10-12, 1.234,56 kr.' ),
			'order and payout numbers'   => array( 'Ordre 4711 og payout 15, bilag AFR-000001' ),
			'registration number alone'  => array( 'Reg.nr. 1234 er lukket' ),
			'part of the account number' => array( 'Kontoen slutter på 5678' ),
			'other long number'          => array( 'Sag 99887766554433' ),
			'IBAN-like, wrong check sum' => array( 'Reference DK51 0040 0440 1162 43' ),
			'letters and digits'         => array( 'REF AB12CD34EF56' ),
			'reason with at sign alone'  => array( 'Afvist @ admin, se mail' ),
			'danish reason'              => array( 'Kontonummeret passer ikke til navnet. Opret anmodningen igen med de rigtige oplysninger.' ),
			'date and transaction id'    => array( 'Overført 12.10.2026 88213377' ),
			'date, amount and order'     => array( 'Betalt 12.10.2026 - 1.234 kr. - bilag 4711' ),
			'reference with hyphens'     => array( 'Ref 2026-10-12-88213377' ),
			'order numbers in a list'    => array( 'Ordrer 4711, 4712 og 4713 er refunderet' ),
			'phone number'               => array( 'Tlf. 22 33 44 55' ),
			'letter-digit groups'        => array( 'Sag AB12 CD34 EF56 GH78' ),
			'IBAN-like, text after'      => array( 'DK51 0040 0440 1162 43 er forkert' ),
			'check sum, unknown country' => array( 'Ref XQ4600400440116243' ),
			'check sum, wrong length'    => array( 'Ref DK17 0040 0440 1162 4300' ),
			'other digits glued before'  => array( '4412345678' ),
			'case number ending in it'   => array( 'Sag 9912345678' ),
			'one digit glued before'     => array( '112345678' ),
			'zeros and other digits'     => array( 'Bankreference 0099912345678' ),
			'other registration glued'   => array( '56780012345678' ),
			'phone number ending in it'  => array( 'Tlf. +45 4412 3456 78' ),
			'amount ending in it'        => array( 'Beløb 4.412.345.678 kr.' ),
			'date and reference'         => array( 'Dato 2026-10-12, ref. 7712345678' ),
		);
	}

	/**
	 * Payment details that must be refused.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refused(): array {
		return array(
			'account number'                     => array( '0012345678' ),
			'account without leading zeros'      => array( 'Overført til 12345678' ),
			'account with spaces'                => array( 'Konto 0012 3456 78' ),
			'account with hyphens'               => array( 'Konto 12-345-678' ),
			'account with dots'                  => array( 'Konto 12.345.678' ),
			'registration and account, hyphen'   => array( '1234-0012345678' ),
			'registration and account, space'    => array( 'Reg 1234 0012345678 betalt' ),
			'registration and account, joined'   => array( '12340012345678' ),
			'e-mail address'                     => array( 'Send til jane.doe@example.com' ),
			'e-mail in upper case'               => array( 'JANE@EXAMPLE.DK' ),
			'IBAN in groups'                     => array( 'Overført til DK50 0040 0440 1162 43' ),
			'IBAN as one token'                  => array( 'IBAN DK5000400440116243' ),
			'IBAN lower case'                    => array( 'gb82west12345698765432' ),
			'IBAN of another country in groups'  => array( 'GB82 WEST 1234 5698 7654 32 er forkert' ),
			'IBAN in groups, a word after'       => array( 'BE68 5390 0754 7034 er lukket' ),
			'IBAN in groups, words after'        => array( 'AT61 1904 3002 3457 3201 blev afvist' ),
			'IBAN in groups, a number after'     => array( 'AT61 1904 3002 3457 3201 2026' ),
			'IBAN in groups, a sentence after'   => array( 'Overført DK50 0040 0440 1162 43. Tak' ),
			'IBAN after another reference'       => array( 'Ref AB12 DK50 0040 0440 1162 43' ),
			'IBAN with hyphens'                  => array( 'DK50-0040-0440-1162-43' ),
			'IBAN with dots'                     => array( 'DK50.0040.0440.1162.43' ),
			'IBAN with double spaces'            => array( 'DK50  0040  0440  1162  43' ),
			'shortest IBAN, Norway'              => array( 'NO93 8601 1117 947 er lukket' ),
			'long IBAN, Malta'                   => array( 'MT84 MALT 0110 0001 2345 MTLC AST0 01S' ),
			'date before the account'            => array( 'Overført 12.10.2026 0012345678' ),
			'long number before the account'     => array( 'ID 998877665544 12345678' ),
			'date after the account'             => array( '0012345678 12.10.2026' ),
			'account with double spaces'         => array( 'Konto 1234  5678' ),
			'account with spaced hyphens'        => array( 'Konto 12 - 345 - 678' ),
			'leading zeros split off'            => array( 'Konto 00 12345678' ),
			'own registration, joined, no zeros' => array( '123412345678' ),
			'own registration, space, no zeros'  => array( 'Reg 1234 12345678' ),
			'many leading zeros'                 => array( '000012345678' ),
			'other registration, separated'      => array( '5678-0012345678' ),
		);
	}

	#[DataProvider( 'allowed' )]
	public function test_ordinary_text_passes( string $text ): void {
		self::assertFalse( PayoutFreeText::contains_payment_details( $text, $this->bank() ) );
	}

	#[DataProvider( 'refused' )]
	public function test_payment_details_are_found( string $text ): void {
		self::assertTrue( PayoutFreeText::contains_payment_details( $text, $this->bank() ) );
	}

	public function test_the_account_is_only_checked_with_the_payouts_own_details(): void {
		self::assertFalse( PayoutFreeText::contains_payment_details( 'Overført til 12345678', null ), 'Without readable details only e-mail and IBAN are found.' );
		self::assertTrue( PayoutFreeText::contains_payment_details( 'jane@example.com', null ) );
		self::assertTrue( PayoutFreeText::contains_payment_details( 'DK50 0040 0440 1162 43', null ) );
		self::assertTrue( PayoutFreeText::contains_payment_details( 'BE68 5390 0754 7034 er lukket', null ) );
	}

	public function test_short_account_numbers_are_not_checked(): void {
		$short = PayoutDestination::bank( '1234', '0000012345' );

		self::assertSame( PayoutMethod::Bank, $short->method );
		self::assertFalse( PayoutFreeText::contains_payment_details( 'Ordre 12345', $short ), 'Five significant digits cannot be told apart from an order number.' );
		self::assertTrue( PayoutFreeText::contains_payment_details( '123456', PayoutDestination::bank( '1234', '123456' ) ), 'Six digits are checked.' );
	}

	public function test_only_the_payouts_own_registration_number_may_precede_the_account(): void {
		// Registration 0040 starts with zeros: it is not mistaken for leading zeros of the account.
		$bank = PayoutDestination::bank( '0040', '0440116243' );

		self::assertTrue( PayoutFreeText::contains_payment_details( '00400440116243', $bank ) );
		self::assertTrue( PayoutFreeText::contains_payment_details( 'Reg 0040, konto 0040 0440116243', $bank ) );
		self::assertTrue( PayoutFreeText::contains_payment_details( '440116243', $bank ) );
		self::assertFalse( PayoutFreeText::contains_payment_details( '400440116243', $bank ), 'Digits that are not the registration number.' );
		self::assertFalse( PayoutFreeText::contains_payment_details( 'Ordre 7123456', PayoutDestination::bank( '1234', '123456' ) ), 'A number ending in a short account.' );
		self::assertTrue( PayoutFreeText::contains_payment_details( 'Reg 1234 123456', PayoutDestination::bank( '1234', '123456' ) ) );
	}

	public function test_a_paypal_payout_is_checked_for_any_e_mail_address(): void {
		Functions\when( 'is_email' )->returnArg();
		$paypal = PayoutDestination::paypal( 'jane@example.com' );

		self::assertTrue( PayoutFreeText::contains_payment_details( 'Sendt til JANE@example.com', $paypal ) );
		self::assertFalse( PayoutFreeText::contains_payment_details( 'PayPal 8MC585209K746392H', $paypal ) );
	}

	/**
	 * A bank payout's details.
	 */
	private function bank(): PayoutDestination {
		return PayoutDestination::bank( '1234', '0012345678' );
	}
}
