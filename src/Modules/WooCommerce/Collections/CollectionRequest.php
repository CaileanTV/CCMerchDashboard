<?php
/**
 * A request to have designs collected.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `merchboard_collection_requests` with its designs (ADR-120).
 */
final readonly class CollectionRequest {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<array{id: int, name: string}> $designs
	 *
	 * @param int                     $id            Request.
	 * @param int                     $creator_id    Creator.
	 * @param CollectionRequestKind   $kind          A new collection or an addition.
	 * @param string                  $name          The collection's name when requested.
	 * @param int|null                $collection_id The collection; a new one's when its draft is made.
	 * @param string                  $token         Marks the draft. Never leaves the server.
	 * @param CollectionRequestStatus $status        State.
	 * @param string|null             $note          The administrator's note to the creator.
	 * @param array[]                 $designs       The designs: product and its name when requested.
	 * @param DateTimeImmutable       $requested_at  When, UTC.
	 * @param DateTimeImmutable|null  $approved_at   When it was approved, UTC.
	 * @param DateTimeImmutable|null  $closed_at     When it was closed, UTC.
	 */
	public function __construct(
		public int $id,
		public int $creator_id,
		public CollectionRequestKind $kind,
		public string $name,
		public ?int $collection_id,
		public string $token,
		public CollectionRequestStatus $status,
		public ?string $note,
		public array $designs,
		public DateTimeImmutable $requested_at,
		public ?DateTimeImmutable $approved_at,
		public ?DateTimeImmutable $closed_at
	) {
	}

	/**
	 * The designs' products.
	 *
	 * @return list<int>
	 */
	public function product_ids(): array {
		return array_column( $this->designs, 'id' );
	}
}
