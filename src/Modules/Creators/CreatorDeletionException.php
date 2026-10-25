<?php
/**
 * Refused creator deletions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Why a creator was not deleted (nothing changed). Reasons are stable codes
 * for the admin notice, never shown as text.
 */
final class CreatorDeletionException extends DomainException {

	public const NOT_AUTHORIZED    = 'delete_not_authorized';
	public const NOT_FOUND         = 'delete_not_found';
	public const ALREADY_DELETED   = 'delete_already_deleted';
	public const NOT_CONFIRMED     = 'delete_not_confirmed';
	public const PAYOUTS_PENDING   = 'delete_payouts_pending';
	public const NEEDS_WOOCOMMERCE = 'delete_needs_woocommerce';
	public const BUSY              = 'delete_busy';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Creator deletion refused: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
