<?php
/**
 * Rejected creator ↔ product category mapping request.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a machine-readable reason; nothing was changed.
 */
final class CreatorCategoryException extends RuntimeException {

	public const NOT_AUTHORIZED            = 'not_authorized';
	public const CREATOR_NOT_FOUND         = 'creator_not_found';
	public const CREATOR_INACTIVE          = 'creator_inactive';
	public const TERM_NOT_FOUND            = 'term_not_found';
	public const NOT_PRODUCT_CATEGORY      = 'not_product_category';
	public const DEFAULT_CATEGORY          = 'default_category';
	public const CATEGORY_MAPPED_ELSEWHERE = 'category_mapped_elsewhere';
	public const CREATOR_HAS_CATEGORY      = 'creator_has_category';
	public const ALREADY_MAPPED            = 'already_mapped';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( private string $reason ) {
		parent::__construct( 'Creator category request rejected: ' . $reason . '.' );
	}

	/**
	 * Reason code (one of the class constants).
	 */
	public function reason(): string {
		return $this->reason;
	}
}
