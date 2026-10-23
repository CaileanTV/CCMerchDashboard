<?php
/**
 * Rejected change of the standard overlays.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Presets;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable reason code for the REST answer.
 */
final class OverlayPresetException extends DomainException {

	/**
	 * The standard overlay does not exist (any more).
	 */
	public const UNKNOWN_PRESET = 'unknown_preset';

	/**
	 * The most standard overlays exist already.
	 */
	public const FULL = 'presets_full';

	/**
	 * The name is empty or too long.
	 */
	public const INVALID_NAME = 'invalid_name';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Standard overlay rejected: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * The request field the reason belongs to.
	 */
	public function field(): string {
		return self::INVALID_NAME === $this->reason ? 'name' : 'preset';
	}
}
