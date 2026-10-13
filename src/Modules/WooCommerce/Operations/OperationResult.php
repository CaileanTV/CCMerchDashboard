<?php
/**
 * Outcome of an operation attempt or reconciliation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * F-5's result codes with D-1 to D-5:
 *
 * - `completed`: the operation is in the registry with the same kind, order
 *   and payload hash (applied now or earlier); final.
 * - `retry_safe`: only from a reconciliation; the operation is not in the
 *   registry and the expected prior state still holds, so one new attempt
 *   with the same id may be made.
 * - `conflict`: not in the registry and the state has changed; final.
 * - `operation_mismatch`: the id is in the registry for another kind, order
 *   or payload (D-2); final.
 * - `busy`: a lock was not available (the order lock, 1205, 1213; D-1); a
 *   new attempt starts with the pre-check.
 * - `outcome_unknown`: whether the operation was applied cannot be told
 *   (D-3, D-5, a failed COMMIT or ROLLBACK); reconcile.
 * - `error`: refused or failed with nothing applied; final. Carries a fixed
 *   reason code; no message ever contains data.
 */
final readonly class OperationResult {

	public const COMPLETED          = 'completed';
	public const RETRY_SAFE         = 'retry_safe';
	public const CONFLICT           = 'conflict';
	public const OPERATION_MISMATCH = 'operation_mismatch';
	public const BUSY               = 'busy';
	public const OUTCOME_UNKNOWN    = 'outcome_unknown';
	public const ERROR              = 'error';

	/**
	 * Constructor.
	 *
	 * @param string      $code   One of the constants.
	 * @param string|null $reason Fixed reason code of an `error`, else null.
	 */
	private function __construct( public string $code, public ?string $reason = null ) {
	}

	/**
	 * A result without a reason.
	 *
	 * @param string $code One of the constants except ERROR.
	 */
	public static function of( string $code ): self {
		return new self( $code );
	}

	/**
	 * Refused or failed; nothing was applied.
	 *
	 * @param string $reason Fixed reason code.
	 */
	public static function error( string $reason ): self {
		return new self( self::ERROR, $reason );
	}
}
