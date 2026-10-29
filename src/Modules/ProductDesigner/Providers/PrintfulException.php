<?php
/**
 * A refused or failed Printful call.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Why a Printful call did not give a result (ADR-081). Messages and
 * contexts never hold the token.
 */
final class PrintfulException extends RuntimeException {

	/** Printful is switched off or has no token. */
	public const NOT_CONFIGURED = 'printful_not_configured';

	/** The token was refused (401/403). */
	public const UNAUTHORIZED = 'printful_unauthorized';

	/** The product or task does not exist (404). */
	public const NOT_FOUND = 'printful_not_found';

	/** Too many calls (429): try again after retry_after seconds. */
	public const RATE_LIMITED = 'printful_rate_limited';

	/** No answer, another status or an unreadable answer. */
	public const FAILED = 'printful_failed';

	/** A download or answer that is not what we accept. */
	public const INVALID = 'printful_invalid';

	/**
	 * Constructor.
	 *
	 * @param string $reason      Reason constant.
	 * @param int    $retry_after Seconds to wait (rate limited).
	 * @param string $call        The refused call ("GET /products/71"), for the administrator (review 30.15).
	 * @param string $detail      Printful's own error message, shortened (never holds the token).
	 */
	public function __construct( private string $reason, public readonly int $retry_after = 0, public readonly string $call = '', public readonly string $detail = '' ) {
		parent::__construct( 'Printful call failed: ' . $reason );
	}

	/**
	 * The reason constant.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
