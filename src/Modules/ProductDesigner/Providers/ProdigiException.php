<?php
/**
 * A refused or failed Prodigi call.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Why a Prodigi call did not give a result (ADR-084). Messages and contexts
 * never hold the API key.
 */
final class ProdigiException extends RuntimeException {

	/** Prodigi is switched off or has no key. */
	public const NOT_CONFIGURED = 'prodigi_not_configured';

	/** The key was refused (401/403). */
	public const UNAUTHORIZED = 'prodigi_unauthorized';

	/** The product, asset or mockup does not exist (404). */
	public const NOT_FOUND = 'prodigi_not_found';

	/** Too many calls (429): try again after retry_after seconds. */
	public const RATE_LIMITED = 'prodigi_rate_limited';

	/** No answer, another status or an unreadable answer. */
	public const FAILED = 'prodigi_failed';

	/** A download or answer that is not what we accept. */
	public const INVALID = 'prodigi_invalid';

	/** No variant of the product is made in the EU. */
	public const NOT_IN_EU = 'prodigi_not_in_eu';

	/**
	 * Constructor.
	 *
	 * @param string $reason      Reason constant.
	 * @param int    $retry_after Seconds to wait (rate limited).
	 * @param string $call        The refused call, for the administrator.
	 * @param string $detail      Prodigi's own error message, shortened (never holds the key).
	 */
	public function __construct( private string $reason, public readonly int $retry_after = 0, public readonly string $call = '', public readonly string $detail = '' ) {
		parent::__construct( 'Prodigi call failed: ' . $reason );
	}

	/**
	 * The reason constant.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
