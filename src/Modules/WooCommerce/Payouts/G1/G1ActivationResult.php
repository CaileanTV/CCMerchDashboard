<?php
/**
 * Outcome of a G1 activation attempt.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\G1;

defined( 'ABSPATH' ) || exit;

/**
 * The result codes are F-5's (E3.6): `completed`, `conflict` (already
 * active, B2), `busy` (lock conflict, D-1), `outcome_unknown` (D-3, D-5, a
 * changed connection, a failed COMMIT or ROLLBACK) and `error`. A
 * `conflict` carries the stored activation, so the caller of a later
 * attempt can reconcile an earlier unknown outcome (B1). An `error` carries
 * a fixed reason code; no message ever contains data.
 */
final readonly class G1ActivationResult {

	public const COMPLETED       = 'completed';
	public const CONFLICT        = 'conflict';
	public const BUSY            = 'busy';
	public const OUTCOME_UNKNOWN = 'outcome_unknown';
	public const ERROR           = 'error';

	/**
	 * Constructor.
	 *
	 * @param string      $code         One of the constants.
	 * @param string|null $reason       Fixed reason code of an `error`, else null.
	 * @param string|null $cutoff_utc   Stored cut-off (UTC, 'Y-m-d H:i:s') of `completed` and `conflict`.
	 * @param string|null $activated_at Stored activation time (UTC) of `completed` and `conflict`.
	 * @param int|null    $activated_by Stored activating user of `completed` and `conflict`.
	 */
	private function __construct(
		public string $code,
		public ?string $reason = null,
		public ?string $cutoff_utc = null,
		public ?string $activated_at = null,
		public ?int $activated_by = null
	) {
	}

	/**
	 * G1 was activated by this attempt.
	 *
	 * @param string $cutoff_utc   Stored cut-off.
	 * @param string $activated_at Stored activation time.
	 * @param int    $activated_by Activating user.
	 */
	public static function completed( string $cutoff_utc, string $activated_at, int $activated_by ): self {
		return new self( self::COMPLETED, null, $cutoff_utc, $activated_at, $activated_by );
	}

	/**
	 * G1 was already active; nothing was changed.
	 *
	 * @param string|null $cutoff_utc   Stored cut-off.
	 * @param string      $activated_at Stored activation time.
	 * @param int|null    $activated_by Stored activating user.
	 */
	public static function conflict( ?string $cutoff_utc, string $activated_at, ?int $activated_by ): self {
		return new self( self::CONFLICT, null, $cutoff_utc, $activated_at, $activated_by );
	}

	/**
	 * A lock conflict; nothing was changed, a later attempt may succeed.
	 */
	public static function busy(): self {
		return new self( self::BUSY );
	}

	/**
	 * Whether G1 is now active cannot be told; a later attempt reads the
	 * stored activation.
	 */
	public static function outcome_unknown(): self {
		return new self( self::OUTCOME_UNKNOWN );
	}

	/**
	 * Refused or failed; nothing was changed.
	 *
	 * @param string $reason Fixed reason code.
	 */
	public static function error( string $reason ): self {
		return new self( self::ERROR, $reason );
	}
}
