<?php
/**
 * Keeps pure creators out of wp-admin.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects pure creators (CreatorRestrictions) from any wp-admin screen,
 * including profile.php, to the dashboard. Runs on `admin_init` at priority 0,
 * before WooCommerce's own redirect to My Account, and on
 * `admin_page_access_denied` for screens WordPress refuses before
 * `admin_init` (e.g. options-general.php), so those redirect too instead of
 * showing WordPress' 403 page.
 *
 * Never touched: AJAX (admin-ajax.php), admin-post.php, async-upload.php,
 * cron, REST and CLI requests, and all users who are not pure creators.
 * Login and logout (wp-login.php) do not run `admin_init`.
 */
final class AdminAreaGuard {

	/**
	 * Admin entry points that serve requests from outside wp-admin screens.
	 */
	private const EXEMPT_SCRIPTS = array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php' );

	/**
	 * Constructor.
	 *
	 * @param CreatorRestrictions $restrictions Pure-creator policy.
	 * @param DashboardRoute      $route        Dashboard route.
	 */
	public function __construct( private CreatorRestrictions $restrictions, private DashboardRoute $route ) {
	}

	/**
	 * WordPress entry point (`admin_init`).
	 */
	public function maybe_redirect(): void {
		global $pagenow;

		if ( ! $this->should_redirect( get_current_user_id(), is_string( $pagenow ) ? $pagenow : '' ) ) {
			return;
		}

		wp_safe_redirect( $this->route->url() );
		exit;
	}

	/**
	 * Whether a request for an admin script must be redirected.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $script  Requested admin script ($pagenow).
	 */
	public function should_redirect( int $user_id, string $script ): bool {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		if ( in_array( $script, self::EXEMPT_SCRIPTS, true ) ) {
			return false;
		}

		return $this->restrictions->applies_to( $user_id );
	}
}
