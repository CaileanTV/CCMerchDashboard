<?php
/**
 * Rejected alert sound.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Sounds;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable reason code for the dashboard (`data.fields`).
 */
final class AlertSoundException extends DomainException {

	public const NO_FILE          = 'no_file';
	public const INVALID_TYPE     = 'invalid_type';
	public const TOO_LARGE        = 'too_large';
	public const TOO_LONG         = 'too_long';
	public const UNREADABLE       = 'unreadable';
	public const UNKNOWN_CATEGORY = 'unknown_category';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Alert sound rejected: ' . $reason . '.' );
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
		return self::UNKNOWN_CATEGORY === $this->reason ? 'slot' : 'file';
	}
}
