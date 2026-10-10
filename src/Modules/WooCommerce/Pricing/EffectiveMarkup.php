<?php
/**
 * The creator share that applies to a product now.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * The product's owner and the share of the sale price that applies to it
 * (ADR-049): the product's own share when it was set for this owner,
 * otherwise the owner's default markup. A product share set for a previous
 * owner is kept as `ignored` for display only; it never applies.
 */
final readonly class EffectiveMarkup {

	/**
	 * Constructor.
	 *
	 * @param int                $creator_id Current owner.
	 * @param MarkupBps          $markup     Share that applies.
	 * @param MarkupSource       $source     Where it comes from.
	 * @param ProductMarkup|null $ignored    A product share of a previous owner (not applied).
	 */
	public function __construct(
		public int $creator_id,
		public MarkupBps $markup,
		public MarkupSource $source,
		public ?ProductMarkup $ignored = null
	) {
	}

	/**
	 * Resolves the share from the owner's default and the product's share.
	 *
	 * @param int                $creator_id     Current owner.
	 * @param MarkupBps          $creator_markup The owner's default markup.
	 * @param ProductMarkup|null $product        The parent product's share, if any.
	 */
	public static function resolve( int $creator_id, MarkupBps $creator_markup, ?ProductMarkup $product ): self {
		if ( null !== $product && $product->belongs_to( $creator_id ) ) {
			return new self( $creator_id, $product->markup, MarkupSource::Product );
		}

		return new self( $creator_id, $creator_markup, MarkupSource::Creator, $product );
	}
}
