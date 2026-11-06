<?php
/**
 * Creator entity.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use DateTimeImmutable;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A creator as a business entity, owned by Merch Dashboard.
 *
 * `id` is the creator's own identity and the key future domain data (sales,
 * payouts, product links) refers to. `user_id` is only an optional link to the
 * WordPress account used for login; it is not the creator's identity.
 */
final readonly class Creator {

	/**
	 * Constructor.
	 *
	 * @param int                    $id           Creator id (primary key).
	 * @param int|null               $user_id      Linked WordPress user id, or null when not linked.
	 * @param string                 $display_name Name shown for the creator.
	 * @param CreatorStatus          $status       Current status.
	 * @param DateTimeImmutable      $created_at   Creation time (UTC).
	 * @param DateTimeImmutable      $updated_at   Last change (UTC).
	 * @param DateTimeImmutable|null $deleted_at When the creator was deleted and anonymized (UTC; phase 27, ADR-076), or null.
	 * @param bool                   $awaiting_live      Whether the creator signed up and has not gone live in the shop yet (ADR-091).
	 * @param bool                   $agreement_required Whether the creator must accept the agreement (ADR-092).
	 * @throws InvalidArgumentException When an id is not a positive integer.
	 */
	public function __construct(
		public int $id,
		public ?int $user_id,
		public string $display_name,
		public CreatorStatus $status,
		public DateTimeImmutable $created_at,
		public DateTimeImmutable $updated_at,
		public ?DateTimeImmutable $deleted_at = null,
		public bool $awaiting_live = false,
		public bool $agreement_required = false
	) {
		if ( $id < 1 ) {
			throw new InvalidArgumentException( 'Creator id must be a positive integer.' );
		}

		if ( null !== $user_id && $user_id < 1 ) {
			throw new InvalidArgumentException( 'User id must be a positive integer or null.' );
		}
	}

	/**
	 * Whether the creator is active.
	 */
	public function is_active(): bool {
		return CreatorStatus::Active === $this->status;
	}

	/**
	 * Whether the creator is live in the shop. A creator who signed up stays
	 * out of the shop until an administrator approves going live (ADR-091):
	 * no category, product link, discount code or designer product.
	 */
	public function is_live(): bool {
		return ! $this->awaiting_live;
	}

	/**
	 * Whether the creator was deleted (kept anonymized for its history, ADR-076).
	 */
	public function is_deleted(): bool {
		return null !== $this->deleted_at;
	}
}
