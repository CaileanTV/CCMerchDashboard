<?php
/**
 * Where approved category proposals are written.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's product category (ADR-077 pkt. 8): read as it is now and
 * written when an administrator approves a proposal. The WooCommerce module
 * provides it with the filter `merchboard_category_profile_target`; without
 * it only the name can be proposed.
 */
interface CategoryProfileTarget {

	/**
	 * Whether the banner plugin (CCMerch Kategoribannere) is active, so its
	 * banner, link and social links can be read and written.
	 */
	public function has_banner_plugin(): bool;

	/**
	 * The creator's category now, or null when it has none.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function current( int $creator_id ): ?CategoryProfile;

	/**
	 * Writes an approved value to the creator's category: the text, or for an
	 * image the attachment id ('' removes it). Term meta first, then the term
	 * itself, so other plugins' hooks on the category see the new values.
	 *
	 * @param int           $creator_id Creator.
	 * @param ProposalField $field      Field (not the name alone: the category's name).
	 * @param string        $value      Value.
	 * @return int The image it replaced (attachment id), 0 for none or a text field.
	 * @throws CategoryProposalException When the creator has no category (NO_CATEGORY) or the banner plugin is missing (UNAVAILABLE).
	 * @throws DatabaseException When it cannot be written.
	 */
	public function apply( int $creator_id, ProposalField $field, string $value ): int;
}
