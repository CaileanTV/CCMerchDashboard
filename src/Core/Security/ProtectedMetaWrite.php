<?php
/**
 * Authorization for one internal write of the protected product meta.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Security;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * One explicitly approved internal operation that may write the protected
 * product meta (`_merchboard_creator_id`, `_merchboard_creator_link_source`)
 * of exactly one product, for a named purpose.
 *
 * Only ProtectedProductMeta::run() gives it effect, and only for the duration
 * of that call; the guard accepts each instance once. It carries no user: the
 * caller authorizes before creating it (ProductCreatorLink checks
 * `edit_product`, the creator and the product), so a system run without a
 * current user (ADR-033's category reconciliation) uses the same mechanism.
 */
final class ProtectedMetaWrite {

	/**
	 * Constructor.
	 *
	 * @param ProtectedMetaPurpose $purpose    Approved write path.
	 * @param int                  $product_id Parent product whose meta may be written.
	 * @throws InvalidArgumentException When the product id is not positive.
	 */
	private function __construct(
		public readonly ProtectedMetaPurpose $purpose,
		public readonly int $product_id
	) {
		if ( $product_id < 1 ) {
			throw new InvalidArgumentException( 'A protected meta write needs a product id.' );
		}
	}

	/**
	 * A manual change through ProductCreatorLink, after its authorization.
	 *
	 * @param int $product_id Parent product id.
	 */
	public static function for_manual_link( int $product_id ): self {
		return new self( ProtectedMetaPurpose::ManualLink, $product_id );
	}

	/**
	 * ADR-033's system category reconciliation (no current user). Not used
	 * before that step is implemented.
	 *
	 * @param int $product_id Parent product id.
	 */
	public static function for_category_reconciliation( int $product_id ): self {
		return new self( ProtectedMetaPurpose::CategoryReconciliation, $product_id );
	}

	/**
	 * Whether this write covers meta of the given post.
	 *
	 * @param int $post_id Post id the meta belongs to.
	 */
	public function covers( int $post_id ): bool {
		return $post_id === $this->product_id;
	}
}
