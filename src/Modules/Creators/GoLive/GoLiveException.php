<?php
/**
 * A refused go-live request or decision.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\GoLive;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a machine-readable reason.
 */
final class GoLiveException extends RuntimeException {

	public const NOT_AUTHORIZED = 'not_authorized';
	public const ALREADY_LIVE   = 'already_live';
	public const PENDING        = 'request_pending';
	public const AGREEMENT      = 'agreement_required';
	public const NOT_PENDING    = 'not_pending';
	public const NOTE           = 'note_required';
	public const NAME           = 'category_name';
	public const MARKUP         = 'invalid_markup';
	// No longer refused (ADR-101: a category may be at the top level); kept for the notice of an old link.
	public const PARENT_MISSING   = 'parent_category_missing';
	public const SHOP_UNAVAILABLE = 'shop_unavailable';
	public const CATEGORY_FAILED  = 'category_failed';
	public const FRONT_GROUP      = 'invalid_front_group';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Go-live rejected: ' . $reason . '.' );
	}

	/**
	 * Reason code (one of the class constants).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
