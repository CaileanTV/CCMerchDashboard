<?php
/**
 * A refused migration from Product Vendors.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Why the dry run or the migration from Product Vendors was refused; the
 * reason is the outcome code of the admin page.
 */
final class VendorImportException extends RuntimeException {

	public const NOT_AUTHORIZED = 'not_authorized';
	public const INACTIVE       = 'vendors_inactive';
	public const NO_DATA        = 'vendors_no_data';
	public const UNREADABLE     = 'vendors_unreadable';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'The migration from Product Vendors was refused: ' . $reason . '.' );
	}

	/**
	 * The reason (outcome code).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
