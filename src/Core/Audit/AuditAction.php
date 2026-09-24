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
}
