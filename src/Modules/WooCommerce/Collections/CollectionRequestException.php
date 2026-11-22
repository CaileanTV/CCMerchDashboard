<?php
/**
 * Business rule violations of the collection requests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120. The reason is a fixed code; it never carries input or personal
 * data.
 */
final class CollectionRequestException extends DomainException {

	public const NOT_AUTHORIZED     = 'not_authorized';
	public const NOT_FOUND          = 'not_found';
	public const CREATOR_NOT_LIVE   = 'creator_not_live';
	public const NAME_REQUIRED      = 'name_required';
	public const TOO_FEW            = 'too_few';
	public const TOO_MANY           = 'too_many';
	public const PRODUCT_NOT_USABLE = 'product_not_usable';
	public const PRODUCT_REQUESTED  = 'product_requested';
	public const COLLECTION_UNKNOWN = 'collection_unknown';
	public const NOT_PENDING        = 'not_pending';
	public const NOT_OPEN           = 'not_open';
	public const ALREADY_DONE       = 'already_done';
	public const NOTE_REQUIRED      = 'note_required';
	public const BUSY               = 'busy';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Collection request rejected: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
