<?php
/**
 * A refused or failed Gelato call.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Why a Gelato call did not give a result (ADR-082). Messages and
 * contexts never hold the API key.
 */
final class GelatoException extends RuntimeException {

	/** Gelato is switched off or has no key or store. */
	public const NOT_CONFIGURED = 'gelato_not_configured';

	/** The key was refused (401/403). */
	public const UNAUTHORIZED = 'gelato_unauthorized';

	/** The template or product does not exist (404). */
	public const NOT_FOUND = 'gelato_not_found';

	/** Too many calls (429): try again after retry_after seconds. */
	public const RATE_LIMITED = 'gelato_rate_limited';

	/** No answer, another status or an unreadable answer. */
	public const FAILED = 'gelato_failed';

	/** A download or answer that is not what we accept. */
	public const INVALID = 'gelato_invalid';

	/**
	 * Constructor.
	 *
	 * @param string $reason      Reason constant.
	 * @param int    $retry_after Seconds to wait (rate limited).
	 */
	public function __construct( private string $reason, public readonly int $retry_after = 0 ) {
		parent::__construct( 'Gelato call failed: ' . $reason );
	}

	/**
	 * The reason constant.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
