<?php
/**
 * Background jobs cancelled when the plugin goes away.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Lifecycle;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Cancels the pending sales ledger backfill jobs on deactivation and on
 * uninstall (ADR-038, status note "ROADMAP trin 7", D6), so no page is left
 * that would run without the plugin. Only this hook: the cleanup of other
 * Merch Dashboard jobs is unchanged. A page that is running at that moment
 * cannot be cancelled by Action Scheduler; it finishes, and a page it queues
 * is cancelled by the next deactivation or the uninstall.
 *
 * Deactivation and uninstall run without the WooCommerce module, so the hook
 * is named here (a test keeps it equal to
 * Modules\WooCommerce\Sales\SalesBackfillScheduler::HOOK, like
 * Uninstaller::ORDER_META_KEYS). Without Action Scheduler nothing happens.
 */
final class ScheduledJobs {

	/**
	 * Action Scheduler hook of a sales ledger backfill page.
	 */
	public const SALES_BACKFILL_HOOK = 'merchboard_sales_backfill';

	/**
	 * Action Scheduler hooks of the alerts' chat messages and webhook
	 * deliveries (Modules\Alerts\Chatbot\ChatSender::HOOK and
	 * Modules\Alerts\Webhooks\WebhookDispatcher::HOOK; a test keeps them
	 * equal). Cancelled on uninstall with the opt-in (25.13 review).
	 */
	public const ALERT_HOOKS = array( 'merchboard_alert_chat_send', 'merchboard_alert_webhook_send' );

	/**
	 * Action Scheduler hooks of the notifications (Modules\Notifications\
	 * NotificationSender::HOOK; a test keeps them equal, ADR-079). Cancelled on
	 * uninstall with the opt-in, like the alerts' jobs.
	 */
	public const NOTIFICATION_HOOKS = array( 'merchboard_notification_send', 'merchboard_notification_balance_check' );

	/**
	 * Action Scheduler hooks of the product designer: the apply pipeline,
	 * the repricing and the retirement (Modules\ProductDesigner\Designs\
	 * DesignerApply::HOOK, DesignerRepricing::HOOK, DesignerRetirement::HOOK and
	 * Providers\PrintfulMockups::HOOK;
	 * a test keeps them equal, ADR-080). Cancelled on uninstall with the opt-in.
	 */
	public const DESIGNER_HOOKS = array( 'merchboard_designer_apply', 'merchboard_designer_reprice', 'merchboard_designer_retire', 'merchboard_designer_printful' );

	/**
	 * Cancels the pending backfill pages; never throws.
	 */
	public static function cancel_sales_backfill(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		try {
			as_unschedule_all_actions( self::SALES_BACKFILL_HOOK );
		} catch ( Throwable ) {
			return;
		}
	}

	/**
	 * Cancels the pending chat messages, webhook deliveries and notification
	 * e-mails; never throws.
	 */
	public static function cancel_alert_jobs(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( array_merge( self::ALERT_HOOKS, self::NOTIFICATION_HOOKS, self::DESIGNER_HOOKS ) as $hook ) {
			try {
				as_unschedule_all_actions( $hook );
			} catch ( Throwable ) {
				continue;
			}
		}
	}
}
