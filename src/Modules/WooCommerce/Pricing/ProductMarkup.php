<?php
/**
 * A product's own creator share.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * One percentage per parent product, bound to the creator who owned the
 * product when it was set (ADR-049). It applies to every variation and
 * replaces the creator's default markup; it is only valid while that
 * creator still owns the product.
 */
final class ProductMarkup {

	/**
	 * Constructor.
	 *
	 * @param int                    $product_id Parent product.
	 * @param int                    $creator_id Owner when the share was set.
	 * @param MarkupBps              $markup     Share of the sale price.
	 * @param DateTimeImmutable|null $created_at Creation time (UTC), null before it is stored.
	 * @param DateTimeImmutable|null $updated_at Last change (UTC), null before it is stored.
	 * @throws InvalidArgumentException When an id is not positive.
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly int $creator_id,
		public readonly MarkupBps $markup,
		public readonly ?DateTimeImmutable $created_at = null,
		public readonly ?DateTimeImmutable $updated_at = null
	) {
		if ( $product_id < 1 || $creator_id < 1 ) {
			throw new InvalidArgumentException( 'Product and creator ids must be positive.' );
		}
	}

	/**
	 * Whether the share is valid for the product's current owner.
	 *
	 * @param int|null $owner_creator_id Current owner, null without a creator.
	 */
	public function belongs_to( ?int $owner_creator_id ): bool {
		return $owner_creator_id === $this->creator_id;
	}
}
