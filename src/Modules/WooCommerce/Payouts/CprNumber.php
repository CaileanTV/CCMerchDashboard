<?php
/**
 * A CPR number given with a payout request.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

defined( 'ABSPATH' ) || exit;

/**
 * The CPR number of a creator paid as a private person (ADR-104): ten
 * digits (dashes and spaces are removed) whose first six are a valid date
 * (DDMMYY). Modulus 11 is not checked, as not every number has it. Exists in
 * plain text only while a request is validated and encrypted, or shown to an
 * admin with `merchboard_view_payout_details`; never stored unencrypted,
 * logged, audited, e-mailed or put on a settlement document.
 */
final readonly class CprNumber {

	/**
	 * Constructor; use from_input().
	 *
	 * @param string $value Ten digits.
	 */
	private function __construct( public string $value ) {
	}

	/**
	 * A CPR number from the creator's input.
	 *
	 * @param mixed $input Input.
	 * @throws PayoutException `invalid_cpr` when it is missing or not a valid number.
	 */
	public static function from_input( mixed $input ): self {
		$value = is_string( $input ) ? preg_replace( '/[\s\p{Pd}]+/u', '', $input ) ?? '' : '';

		if ( 1 !== preg_match( '/^(\d{2})(\d{2})(\d{2})\d{4}$/', $value, $parts ) ) {
			throw new PayoutException( PayoutException::INVALID_CPR );
		}

		// The century is not known from the six digits: a date in either one (29 February).
		$day   = (int) $parts[1];
		$month = (int) $parts[2];

		if ( ! checkdate( $month, $day, 2000 + (int) $parts[3] ) && ! checkdate( $month, $day, 1900 + (int) $parts[3] ) ) {
			throw new PayoutException( PayoutException::INVALID_CPR );
		}

		return new self( $value );
	}

	/**
	 * The number as usually written: DDMMYY-XXXX.
	 */
	public function formatted(): string {
		return substr( $this->value, 0, 6 ) . '-' . substr( $this->value, 6 );
	}
}
