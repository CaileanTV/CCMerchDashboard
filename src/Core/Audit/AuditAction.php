<?php
/**
 * Audit action types.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * The only valid values of `merchboard_audit_log.action` (ADR-023).
 *
 * One record per semantic change: an edit that renames a creator and
 * deactivates it writes `creator_updated` and `creator_deactivated`.
 */
enum AuditAction: string {

	/**
	 * Creator created (context: initial status, linked user id or null).
	 */
	case CreatorCreated = 'creator_created';

	/**
	 * Creator attributes changed (context: names of the changed fields, no values).
	 */
	case CreatorUpdated = 'creator_updated';

	/**
	 * Creator status changed to active (context: previous status).
	 */
	case CreatorActivated = 'creator_activated';

	/**
	 * Creator status changed to inactive (context: previous status).
	 */
	case CreatorDeactivated = 'creator_deactivated';

	/**
	 * WordPress user linked to a creator (context: user id).
	 */
	case CreatorUserLinked = 'creator_user_linked';

	/**
	 * WordPress user unlinked from a creator (context: previous user id).
	 */
	case CreatorUserUnlinked = 'creator_user_unlinked';

	/**
	 * A plugin setting changed (creator id NULL; context: setting name and the
	 * new value, which is never personal data).
	 */
	case SettingsUpdated = 'settings_updated';

	/**
	 * A WooCommerce product was linked to the creator (context: product id, the
	 * previous creator id or null, and the write path; ADR-030/031).
	 */
	case ProductCreatorLinked = 'product_creator_linked';

	/**
	 * A WooCommerce product's link to the creator was removed or moved to another
	 * creator (context: product id and the write path). The creator id is NULL
	 * when the removed link was not a valid creator reference.
	 */
	case ProductCreatorUnlinked = 'product_creator_unlinked';

	/**
	 * A WooCommerce product category was mapped to the creator (context: term
	 * id; ADR-033). The mapping is not ownership of any product.
	 */
	case CreatorCategoryLinked = 'creator_category_linked';

	/**
	 * A product category mapping of the creator was removed (context: term id
	 * and reason; ADR-033). Product links are not changed by this.
	 */
	case CreatorCategoryUnlinked = 'creator_category_unlinked';

	/**
	 * A full category reconciliation run finished all its batches (context:
	 * run id, dry-run status, trigger and the count per outcome; ADR-033). Not
	 * tied to a creator; system work with no actor. Dry runs write none.
	 */
	case CategorySyncCompleted = 'category_sync_completed';

	/**
	 * A sales ledger backfill run finished all its pages (context: run id,
	 * the user who started it, if known, and counts only: orders scanned, per
	 * outcome, rows per type and failures per code; ADR-038, status note
	 * "ROADMAP trin 7"). No order ids, no amounts. System work with no actor.
	 * Dry runs write none.
	 */
	case SalesBackfillCompleted = 'sales_backfill_completed';
}
