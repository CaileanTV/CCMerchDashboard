<?php
/**
 * Login redirect for pure creators.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Modules\Dashboard\DashboardRoute;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Sends pure creators (CreatorRestrictions) to the dashboard after login,
 * from wp-login.php (`login_redirect`) and from WooCommerce's My Account form
 * (`woocommerce_login_redirect`).
 *
 * A requested redirect is kept only when it is a same-site dashboard URL
 * (deep link); it is rebuilt from the normalized path. Everything else
 * (wp-admin, other pages, other hosts, malformed URLs) becomes the dashboard
 * start page. All other users keep WordPress' and WooCommerce's behaviour.
 */
final class LoginRedirect {

	/**
	 * Constructor.
	 *
	 * @param CreatorRestrictions $restrictions Pure-creator policy.
	 * @param DashboardRoute      $route        Dashboard route.
	 */
	public function __construct( private CreatorRestrictions $restrictions, private DashboardRoute $route ) {
	}

	/**
	 * `login_redirect` filter.
	 *
	 * @param mixed $redirect_to Redirect target so far.
	 * @param mixed $requested   Requested redirect target (`redirect_to`).
	 * @param mixed $user        Logged-in user or WP_Error.
	 * @return mixed
	 */
	public function filter_login_redirect( mixed $redirect_to, mixed $requested, mixed $user ): mixed {
		return $this->target( $user, is_string( $requested ) ? $requested : '' ) ?? $redirect_to;
	}

	/**
	 * `woocommerce_login_redirect` filter.
	 *
	 * @param mixed $redirect Redirect target (already includes WooCommerce's `redirect` field).
	 * @param mixed $user     Logged-in user.
	 * @return mixed
	 */
	public function filter_woocommerce_login_redirect( mixed $redirect, mixed $user ): mixed {
		return $this->target( $user, is_string( $redirect ) ? $redirect : '' ) ?? $redirect;
	}

	/**
	 * The dashboard target for a pure creator, or null for other users.
	 *
	 * @param mixed  $user      User.
	 * @param string $requested Requested target.
	 */
	public function target( mixed $user, string $requested ): ?string {
		if ( ! $user instanceof WP_User || ! $this->restrictions->applies_to( (int) $user->ID ) ) {
			return null;
		}

		return $this->route->dashboard_url_for( $requested ) ?? $this->route->url();
	}
}
