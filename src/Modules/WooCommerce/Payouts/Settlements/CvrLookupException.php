<?php
/**
 * A CVR lookup that gave no data.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * A CVR lookup without data (ADR-105), with a code of CvrLookup. The
 * message never contains the number or the answer.
 */
final class CvrLookupException extends DomainException {

	/**
	 * Constructor.
	 *
	 * @param string $reason One of CvrLookup's codes.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'CVR lookup: ' . $reason );
	}

	/**
	 * The code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
