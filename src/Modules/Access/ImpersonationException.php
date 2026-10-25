<?php
/**
 * Refused "view as creator" requests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Why an impersonation was refused (nothing changed). Reasons are stable
 * codes for the admin notice, never shown as text.
 */
final class ImpersonationException extends DomainException {

	public const NOT_AUTHORIZED = 'impersonation_not_authorized';
	public const UNAVAILABLE    = 'impersonation_unavailable';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Impersonation refused: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
