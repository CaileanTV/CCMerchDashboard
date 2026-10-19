<?php
/**
 * Rejected alert image.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Images;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable reason code for the dashboard (`data.fields`) and the
 * admin result notice.
 */
final class AlertImageException extends DomainException {

	public const NO_FILE        = 'no_file';
	public const INVALID_TYPE   = 'invalid_type';
	public const TOO_LARGE      = 'too_large';
	public const TOO_BIG        = 'too_big';
	public const UNREADABLE     = 'unreadable';
	public const UNKNOWN_IMAGE  = 'unknown_image';
	public const LIBRARY_FULL   = 'library_full';
	public const OWN_FULL       = 'own_full';
	public const NOT_AUTHORIZED = 'not_authorized';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Alert image rejected: ' . $reason . '.' );
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
		return self::UNKNOWN_IMAGE === $this->reason ? 'image' : 'file';
	}
}
