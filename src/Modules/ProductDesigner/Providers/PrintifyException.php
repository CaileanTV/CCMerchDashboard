<?php
/**
 * A refused or failed Printify call.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Why a Printify call did not give a result (ADR-087). Messages and contexts
 * never hold the token.
 */
final class PrintifyException extends RuntimeException {

	/** Printify is switched off or has no token. */
	public const NOT_CONFIGURED = 'printify_not_configured';

	/** The token was refused (401/403). */
	public const UNAUTHORIZED = 'printify_unauthorized';

	/** The product or producer does not exist (404). */
	public const NOT_FOUND = 'printify_not_found';

	/** Too many calls (429): try again after retry_after seconds. */
	public const RATE_LIMITED = 'printify_rate_limited';

	/** No answer, another status or an unreadable answer. */
	public const FAILED = 'printify_failed';

	/** An answer or reference that is not what we accept. */
	public const INVALID = 'printify_invalid';

	/**
	 * Constructor.
	 *
	 * @param string $reason      Reason constant.
	 * @param int    $retry_after Seconds to wait (rate limited).
	 * @param string $call        The refused call, for the administrator.
	 * @param string $detail      Printify's own error message, shortened (never holds the token).
	 */
	public function __construct( private string $reason, public readonly int $retry_after = 0, public readonly string $call = '', public readonly string $detail = '' ) {
		parent::__construct( 'Printify call failed: ' . $reason );
	}

	/**
	 * The reason constant.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
