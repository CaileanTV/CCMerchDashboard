<?php
/**
 * Rejected change of a creator's saved designs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Designs;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable reason code for the REST answer.
 */
final class CreatorDesignException extends DomainException {

	/**
	 * The design does not exist (any more).
	 */
	public const UNKNOWN_DESIGN = 'unknown_design';

	/**
	 * The creator has the most designs already.
	 */
	public const FULL = 'designs_full';

	/**
	 * The name is empty or too long.
	 */
	public const INVALID_NAME = 'invalid_name';

	/**
	 * The creator has no overlay yet.
	 */
	public const NO_OVERLAY = 'no_overlay';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Design rejected: ' . $reason . '.' );
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
		return self::INVALID_NAME === $this->reason ? 'name' : 'design';
	}
}
