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
 * Carries a stable reason code for the dashboard (`data.fields`) and the
 * admin result notice.
 */
final class AlertSoundException extends DomainException {

	public const NO_FILE          = 'no_file';
	public const INVALID_TYPE     = 'invalid_type';
	public const TOO_LARGE        = 'too_large';
	public const TOO_LONG         = 'too_long';
	public const UNREADABLE       = 'unreadable';
	public const UNKNOWN_CATEGORY = 'unknown_category';
	public const UNKNOWN_SOUND    = 'unknown_sound';
	public const LIBRARY_FULL     = 'library_full';
	public const NOT_AUTHORIZED   = 'not_authorized';

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
		return match ( $this->reason ) {
			self::UNKNOWN_CATEGORY => 'slot',
			self::UNKNOWN_SOUND    => 'library',
			default                => 'file',
		};
	}
}
