<?php
/**
 * Bank or PayPal details of one payout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use JsonException;

defined( 'ABSPATH' ) || exit;

/**
 * The full details a payout is sent to (ADR-056). Exists in plain text only
 * while a request is validated, encrypted or shown to an admin with
 * `merchboard_view_payout_details`; everywhere else only hint() is used.
 * Never stored for a later request (planning decision 11) and never logged.
 */
final class PayoutDestination {

	/**
	 * Longest e-mail address accepted for PayPal.
	 */
	private const MAX_EMAIL_LENGTH = 191;

	/**
	 * Masking character.
	 */
	private const MASK = '•••';

	/**
	 * Constructor; use bank() or paypal().
	 *
	 * @param PayoutMethod $method       Method.
	 * @param string       $registration Danish registration number (bank only).
	 * @param string       $account      Account number (bank) or e-mail address (PayPal).
	 */
	private function __construct( public readonly PayoutMethod $method, public readonly string $registration, public readonly string $account ) {
	}

	/**
	 * Danish bank details: registration number of 4 digits and account
	 * number of 1–10 digits. Spaces are ignored.
	 *
	 * @param string $registration Registration number.
	 * @param string $account      Account number.
	 * @throws PayoutException When a number is not valid.
	 */
	public static function bank( string $registration, string $account ): self {
		$registration = str_replace( ' ', '', $registration );
		$account      = str_replace( ' ', '', $account );

		if ( 1 !== preg_match( '/^\d{4}$/', $registration ) || 1 !== preg_match( '/^\d{1,10}$/', $account ) ) {
			throw new PayoutException( PayoutException::INVALID_DETAILS );
		}

		return new self( PayoutMethod::Bank, $registration, $account );
	}

	/**
	 * A PayPal account by e-mail address.
	 *
	 * @param string $email E-mail address.
	 * @throws PayoutException When the address is not valid.
	 */
	public static function paypal( string $email ): self {
		$email = trim( $email );

		if ( '' === $email || strlen( $email ) > self::MAX_EMAIL_LENGTH || false === is_email( $email ) ) {
			throw new PayoutException( PayoutException::INVALID_DETAILS );
		}

		return new self( PayoutMethod::PayPal, '', $email );
	}

	/**
	 * Details from request input for a method.
	 *
	 * @param PayoutMethod $method Method.
	 * @param array<mixed> $input  `registration` and `account` (bank) or `email` (PayPal).
	 * @throws PayoutException When the details are missing or not valid.
	 */
	public static function from_input( PayoutMethod $method, array $input ): self {
		$value = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';

		return match ( $method ) {
			PayoutMethod::Bank     => self::bank( $value( 'registration' ), $value( 'account' ) ),
			PayoutMethod::PayPal   => self::paypal( $value( 'email' ) ),
			PayoutMethod::GiftCard => throw new PayoutException( PayoutException::INVALID_DETAILS ),
		};
	}

	/**
	 * The masked form shown in lists and history: "•••• 1234" for an account
	 * (never the registration number), "j•••@g•••.com" for PayPal.
	 */
	public function hint(): string {
		if ( PayoutMethod::Bank === $this->method ) {
			return strlen( $this->account ) > 4 ? '•••• ' . substr( $this->account, -4 ) : '••••';
		}

		$at     = (int) strrpos( $this->account, '@' );
		$domain = substr( $this->account, $at + 1 );
		$dot    = strrpos( $domain, '.' );
		$tld    = false === $dot ? '' : substr( $domain, $dot );

		return substr( $this->account, 0, 1 ) . self::MASK . '@' . substr( $domain, 0, 1 ) . self::MASK . $tld;
	}

	/**
	 * The plain text that is encrypted.
	 *
	 * @throws JsonException Never for valid details.
	 */
	public function to_json(): string {
		// json_encode() for JSON_THROW_ON_ERROR; wp_json_encode() returns false instead.
		return json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'method'       => $this->method->value,
				'registration' => $this->registration,
				'account'      => $this->account,
			),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Details from decrypted plain text, validated again.
	 *
	 * @param string $json Plain text from to_json().
	 * @throws PayoutException When it is not valid details.
	 */
	public static function from_json( string $json ): self {
		try {
			$data = json_decode( $json, true, 2, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new PayoutException( PayoutException::DETAILS_UNREADABLE );
		}

		$method = is_array( $data ) && is_string( $data['method'] ?? null ) ? PayoutMethod::tryFrom( $data['method'] ) : null;

		if ( null === $method ) {
			throw new PayoutException( PayoutException::DETAILS_UNREADABLE );
		}

		try {
			return match ( $method ) {
				PayoutMethod::Bank     => self::bank( is_string( $data['registration'] ?? null ) ? $data['registration'] : '', is_string( $data['account'] ?? null ) ? $data['account'] : '' ),
				PayoutMethod::PayPal   => self::paypal( is_string( $data['account'] ?? null ) ? $data['account'] : '' ),
				PayoutMethod::GiftCard => throw new PayoutException( PayoutException::DETAILS_UNREADABLE ),
			};
		} catch ( PayoutException ) {
			throw new PayoutException( PayoutException::DETAILS_UNREADABLE );
		}
	}
}
