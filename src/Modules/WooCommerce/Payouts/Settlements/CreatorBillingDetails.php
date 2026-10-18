<?php
/**
 * A creator's master data for settlement documents.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's master data on settlement documents (ADR-062 K09): the
 * legal name (person or business), the address and the CVR/SE number when
 * the creator has one. Every field is optional and '' when not registered;
 * nothing is ever filled in from other data. Stored as creator meta
 * (`billing_details`) and copied into a document's snapshot when it is
 * issued, so a later change never changes an issued document.
 *
 * A CVR or SE number is eight digits (spaces are removed). Texts are single
 * lines without control characters. Whether the field list meets the formal
 * requirements of self-billing is not validated (ADR-062, reservation 3).
 */
final readonly class CreatorBillingDetails {

	/**
	 * Fields with their longest length, in display order.
	 */
	public const FIELDS = array(
		'legal_name' => 191,
		'address_1'  => 191,
		'address_2'  => 191,
		'postcode'   => 20,
		'city'       => 100,
		'country'    => 100,
		'cvr'        => 8,
	);

	/**
	 * Constructor.
	 *
	 * @param string $legal_name Legal name of the person or business.
	 * @param string $address_1  Street and number.
	 * @param string $address_2  Second address line.
	 * @param string $postcode   Postcode.
	 * @param string $city       City.
	 * @param string $country    Country.
	 * @param string $cvr        CVR or SE number (eight digits), '' when none.
	 */
	public function __construct(
		public string $legal_name = '',
		public string $address_1 = '',
		public string $address_2 = '',
		public string $postcode = '',
		public string $city = '',
		public string $country = '',
		public string $cvr = ''
	) {
	}

	/**
	 * Validated master data from submitted values (missing keys are empty).
	 *
	 * @param array<string, string> $values Field => value.
	 * @throws PayoutException `invalid_cvr` for a CVR/SE number that is not eight digits, `invalid_billing_details` for a text that is too long or has control characters.
	 */
	public static function from_input( array $values ): self {
		$clean = array();

		foreach ( self::FIELDS as $field => $max ) {
			$value = trim( $values[ $field ] ?? '' );

			if ( 'cvr' === $field ) {
				$value = preg_replace( '/\s+/', '', $value ) ?? '';

				if ( '' !== $value && 1 !== preg_match( '/^\d{8}$/', $value ) ) {
					throw new PayoutException( PayoutException::INVALID_CVR );
				}
			} elseif ( mb_strlen( $value ) > $max || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || 1 !== preg_match( '//u', $value ) ) {
				throw new PayoutException( PayoutException::INVALID_BILLING_DETAILS );
			}

			$clean[ $field ] = $value;
		}

		return new self( $clean['legal_name'], $clean['address_1'], $clean['address_2'], $clean['postcode'], $clean['city'], $clean['country'], $clean['cvr'] );
	}

	/**
	 * Master data as stored; anything that is not a string is ignored.
	 *
	 * @param mixed $stored Stored value (array or null).
	 */
	public static function from_stored( mixed $stored ): self {
		$values = array();

		foreach ( array_keys( self::FIELDS ) as $field ) {
			$values[ $field ] = is_array( $stored ) && is_string( $stored[ $field ] ?? null ) ? $stored[ $field ] : '';
		}

		return new self( $values['legal_name'], $values['address_1'], $values['address_2'], $values['postcode'], $values['city'], $values['country'], $values['cvr'] );
	}

	/**
	 * The values to store: only the registered fields (empty = nothing stored).
	 *
	 * @return array<string, string>
	 */
	public function to_stored(): array {
		return array_filter( $this->values(), static fn( string $value ): bool => '' !== $value );
	}

	/**
	 * Every field with its value.
	 *
	 * @return array{legal_name: string, address_1: string, address_2: string, postcode: string, city: string, country: string, cvr: string}
	 */
	public function values(): array {
		return array(
			'legal_name' => $this->legal_name,
			'address_1'  => $this->address_1,
			'address_2'  => $this->address_2,
			'postcode'   => $this->postcode,
			'city'       => $this->city,
			'country'    => $this->country,
			'cvr'        => $this->cvr,
		);
	}

	/**
	 * The address as lines (empty lines left out), [] when none is registered.
	 *
	 * @return list<string>
	 */
	public function address_lines(): array {
		$lines = array( $this->address_1, $this->address_2, trim( $this->postcode . ' ' . $this->city ), $this->country );

		return array_values( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * The names of the fields whose value differs from another one's.
	 *
	 * @param self $other Other master data.
	 * @return list<string>
	 */
	public function changed_fields( self $other ): array {
		$mine   = $this->values();
		$theirs = $other->values();

		return array_values( array_filter( array_keys( $mine ), static fn( string $field ): bool => $mine[ $field ] !== $theirs[ $field ] ) );
	}
}
