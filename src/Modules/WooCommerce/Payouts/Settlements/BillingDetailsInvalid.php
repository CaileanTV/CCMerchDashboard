<?php
/**
 * Submitted master data with invalid fields.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Master data the creator submitted (ADR-104) with one code per invalid
 * field: `required`, `too_long`, `invalid` or `invalid_cvr`. Nothing was
 * stored. The message never contains a value.
 */
final class BillingDetailsInvalid extends DomainException {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $fields Field => code.
	 */
	public function __construct( private array $fields ) {
		parent::__construct( 'Invalid master data: ' . implode( ', ', array_keys( $fields ) ) );
	}

	/**
	 * Field => code.
	 *
	 * @return array<string, string>
	 */
	public function fields(): array {
		return $this->fields;
	}
}
