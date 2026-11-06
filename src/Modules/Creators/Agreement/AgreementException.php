<?php
/**
 * A refused change of the agreement or an acceptance.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Agreement;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a machine-readable reason; nothing was changed.
 */
final class AgreementException extends RuntimeException {

	public const NOT_AUTHORIZED = 'not_authorized';
	public const EMPTY_TEXT     = 'agreement_empty';
	public const PLACEHOLDERS   = 'agreement_placeholders';
	public const NOT_PUBLISHED  = 'agreement_not_published';
	public const OUTDATED       = 'agreement_outdated';
	public const ADULT_REQUIRED = 'adult_required';
	public const IMPERSONATING  = 'impersonating';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Agreement request rejected: ' . $reason . '.' );
	}

	/**
	 * Reason code (one of the class constants).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
