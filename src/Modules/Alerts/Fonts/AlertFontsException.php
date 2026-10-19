<?php
/**
 * Rejected change of the alert font library.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Fonts;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable reason code for the admin result notice.
 */
final class AlertFontsException extends DomainException {

	public const NOT_AUTHORIZED = 'not_authorized';
	public const INVALID_KEY    = 'invalid_key';
	public const NO_KEY         = 'no_key';
	public const UNAVAILABLE    = 'unavailable';
	public const UNKNOWN_FONT   = 'unknown_font';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Alert font library rejected: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
