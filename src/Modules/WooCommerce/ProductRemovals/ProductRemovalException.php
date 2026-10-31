<?php
/**
 * A refused request to remove a legacy product, or a refused decision on one.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\ProductRemovals;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Business rule violations of ProductRemovals (ADR-083). The reason is a
 * fixed code; it never carries input or personal data.
 */
final class ProductRemovalException extends DomainException {

	public const NOT_AUTHORIZED   = 'not_authorized';
	public const NOT_FOUND        = 'not_found';
	public const DESIGNER_PRODUCT = 'designer_product';
	public const NOT_PENDING      = 'not_pending';
	public const NOTE_REQUIRED    = 'note_required';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Product removal request rejected: ' . $reason . '.' );
	}

	/**
	 * The reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
