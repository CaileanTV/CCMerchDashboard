<?php
/**
 * One creator ↔ product category mapping.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_creator_categories` (ADR-033): the `product_cat` term
 * is mapped to the creator. An administrative rule, never ownership of a
 * product; ownership is only `_merchboard_creator_id`.
 */
final class CreatorCategory {

	/**
	 * Constructor.
	 *
	 * @param int               $term_id    `product_cat` term id.
	 * @param int               $creator_id Creator id.
	 * @param DateTimeImmutable $created_at Creation time (UTC).
	 * @param int|null          $created_by WordPress user who created it, if known.
	 */
	public function __construct(
		public readonly int $term_id,
		public readonly int $creator_id,
		public readonly DateTimeImmutable $created_at,
		public readonly ?int $created_by
	) {
	}
}
