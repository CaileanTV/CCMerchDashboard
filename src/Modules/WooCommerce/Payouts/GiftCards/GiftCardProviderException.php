<?php
/**
 * A gift card could not be read.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A failed read of a gift card adapter with a fixed code (ADR-057). The
 * message never contains a card's code.
 */
final class GiftCardProviderException extends RuntimeException {

	/**
	 * The plugin cannot be used.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Several cards have the code.
	 */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * The card's data cannot be read exactly (never read as 0).
	 */
	public const INVALID_DATA = 'invalid_data';

	/**
	 * The read failed.
	 */
	public const READ_FAILED = 'read_failed';

	/**
	 * Constructor.
	 *
	 * @param string $code_name One of the class constants.
	 */
	public function __construct( private string $code_name ) {
		parent::__construct( 'Gift card read failed: ' . $code_name );
	}

	/**
	 * The failure code.
	 */
	public function reason(): string {
		return $this->code_name;
	}
}
