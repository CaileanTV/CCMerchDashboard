<?php
/**
 * Restrictions for pure creator accounts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Core\Security\Capabilities;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which users are "pure creators" and applies the restrictions that
 * keep them out of WordPress' own interfaces (ADR-026):
 *
 * A pure creator has `merchboard_access_dashboard` and none of
 * `merchboard_manage`, `edit_posts` or `manage_woocommerce`. That covers every
 * holder of the creator role, whether the creator is active or not; editors,
 * shop managers and administrators who are also creators keep wp-admin, the
 * admin bar and Application Passwords.
 *
 * This is about WordPress interfaces, not about dashboard access: whether a
 * user may use the dashboard is decided only by CreatorContext (an inactive
 * pure creator is restricted here and denied there).
 *
 * Only capability checks; no database queries beyond WordPress' user cache,
 * so there is nothing to fail open on.
 */
final class CreatorRestrictions {

	/**
	 * Whether the restrictions apply to a user.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public function applies_to( int $user_id ): bool {
		// user_can() is false for users that do not exist.
		return $user_id > 0
			&& user_can( $user_id, Capabilities::ACCESS_DASHBOARD )
			&& ! user_can( $user_id, Capabilities::MANAGE )
			&& ! user_can( $user_id, Capabilities::EDIT_POSTS )
			&& ! user_can( $user_id, Capabilities::MANAGE_WOOCOMMERCE );
	}

	/**
	 * Hides the admin bar for pure creators (`show_admin_bar`).
	 *
	 * @param mixed $show Whether to show the admin bar.
	 */
	public function filter_admin_bar( mixed $show ): bool {
		return (bool) $show && ! $this->applies_to( get_current_user_id() );
	}

	/**
	 * Disables Application Passwords for pure creators
	 * (`wp_is_application_passwords_available_for_user`). WordPress checks this
	 * both when a password is created and when one is used to authenticate, so
	 * passwords created before the user became a pure creator stop working too.
	 *
	 * @param mixed $available Whether Application Passwords are available.
	 * @param mixed $user      The user.
	 */
	public function filter_application_passwords( mixed $available, mixed $user ): bool {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return (bool) $available && ! $this->applies_to( (int) $user->ID );
	}
}
