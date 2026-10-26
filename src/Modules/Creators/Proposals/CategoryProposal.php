<?php
/**
 * One category proposal.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A creator's proposed value for one field (ADR-077). The value is text
 * (name, description, link, social link), or for an image the stored file
 * as JSON (`{file, mime, width, height}`); '' proposes to remove the
 * current value. Times are UTC.
 */
final class CategoryProposal {

	/**
	 * Constructor.
	 *
	 * @param int                    $id         Proposal id.
	 * @param int                    $creator_id Creator.
	 * @param ProposalField          $field      Field.
	 * @param string                 $value      Proposed value ('' = remove).
	 * @param ProposalStatus         $status     State.
	 * @param string|null            $reason     The administrator's note on a rejection.
	 * @param DateTimeImmutable      $created_at When it was proposed.
	 * @param int|null               $created_by Who proposed it (null: the update from the profile before 1.3.0).
	 * @param DateTimeImmutable|null $decided_at When it was decided, withdrawn or replaced.
	 * @param int|null               $decided_by Who decided it.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $creator_id,
		public readonly ProposalField $field,
		public readonly string $value,
		public readonly ProposalStatus $status,
		public readonly ?string $reason,
		public readonly DateTimeImmutable $created_at,
		public readonly ?int $created_by,
		public readonly ?DateTimeImmutable $decided_at,
		public readonly ?int $decided_by
	) {
	}

	/**
	 * The stored image of a file proposal, or null (text, removal or a
	 * corrupt value).
	 */
	public function file(): ?ProposalFile {
		return $this->field->is_file() ? ProposalFile::from_json( $this->value ) : null;
	}

	/**
	 * Whether it proposes to remove the current value.
	 */
	public function is_removal(): bool {
		return '' === $this->value;
	}
}
