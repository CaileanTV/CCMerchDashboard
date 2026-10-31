<?php
/**
 * A creator's request to remove a legacy product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\ProductRemovals;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `merchboard_product_removal_requests` (ADR-083).
 */
final readonly class ProductRemovalRequest {

	/**
	 * Constructor.
	 *
	 * @param int                    $id           Request.
	 * @param int                    $creator_id   Creator.
	 * @param int                    $product_id   WooCommerce parent product.
	 * @param string                 $product_name The product's name when requested.
	 * @param ProductRemovalStatus   $status       State.
	 * @param string|null            $note         The administrator's note to the creator.
	 * @param DateTimeImmutable      $requested_at When, UTC.
	 * @param int|null               $requested_by WordPress user.
	 * @param DateTimeImmutable|null $handled_at   When it was closed, UTC.
	 * @param int|null               $handled_by   WordPress user.
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public int $product_id,
		public string $product_name,
		public ProductRemovalStatus $status,
		public ?string $note,
		public DateTimeImmutable $requested_at,
		public ?int $requested_by,
		public ?DateTimeImmutable $handled_at,
		public ?int $handled_by
	) {
	}
}
