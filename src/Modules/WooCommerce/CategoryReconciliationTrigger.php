<?php
/**
 * Why a category reconciliation runs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The event behind a reconciliation run (ADR-033). It only chooses the audit
 * reason of a new link and of a removal; the outcome itself depends on the
 * product's current state alone. Moves are always `category_moved`, removals
 * because of ambiguity always `ambiguous`.
 */
enum CategoryReconciliationTrigger: string {

	/**
	 * A product's categories changed.
	 */
	case CategoryChanged = 'category_changed';

	/**
	 * A full reconciliation of every relevant product (the safety net for
	 * changes no hook sees). Uses the category reasons.
	 */
	case FullSync = 'full_sync';

	/**
	 * A category was mapped to a creator.
	 */
	case MappingCreated = 'mapping_created';

	/**
	 * A category mapping was removed.
	 */
	case MappingRemoved = 'mapping_removed';

	/**
	 * A creator became active again.
	 */
	case CreatorActivated = 'creator_activated';

	/**
	 * A mapped category was deleted.
	 */
	case TermDeleted = 'term_deleted';

	/**
	 * Audit reason of a new category link.
	 */
	public function link_reason(): string {
		return match ( $this ) {
			self::MappingCreated   => 'mapping_created',
			self::CreatorActivated => 'creator_activated',
			default                => 'category_added',
		};
	}

	/**
	 * Audit reason of a removed category link that no category justifies.
	 */
	public function unlink_reason(): string {
		return match ( $this ) {
			self::MappingRemoved => 'mapping_removed',
			self::TermDeleted    => 'term_deleted',
			default              => 'category_removed',
		};
	}
}
