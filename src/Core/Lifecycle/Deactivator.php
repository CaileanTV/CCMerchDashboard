<?php
/**
 * Plugin deactivation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Lifecycle;

use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation never deletes data (ADR-025). It makes WordPress rebuild its
 * rewrite rules on the next request, when the plugin is no longer loaded, so
 * the dashboard route disappears with the plugin, and it cancels pending
 * sales ledger backfill pages (ScheduledJobs); a stopped run can be resumed
 * after reactivation.
 */
final class Deactivator {

	/**
	 * Runs on deactivation.
	 */
	public function deactivate(): void {
		DashboardRoute::invalidate_rewrite_rules();
		ScheduledJobs::cancel_sales_backfill();
	}
}
