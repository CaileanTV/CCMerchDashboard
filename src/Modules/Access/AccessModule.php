<?php
/**
 * Access module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Core\Container;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * Creator access: CreatorContext (phase 8), the policy every creator-facing
 * feature uses, and the restrictions for pure creators (phase 12, ADR-026):
 * login redirect, wp-admin guard, hidden admin bar and no Application
 * Passwords. Depends on the Creators module (CreatorRepository) and the
 * Dashboard module (DashboardRoute); services are resolved when a hook fires.
 */
final class AccessModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'access';
	}

	/**
	 * Registers the access policy and the pure-creator restrictions.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set(
			CreatorContext::class,
			static fn( Container $c ): CreatorContext => new CreatorContext(
				$c->get( CreatorRepository::class ),
				$c->get( Logger::class )
			)
		);

		$container->set( CreatorRestrictions::class, static fn(): CreatorRestrictions => new CreatorRestrictions() );

		$container->set(
			LoginRedirect::class,
			static fn( Container $c ): LoginRedirect => new LoginRedirect(
				$c->get( CreatorRestrictions::class ),
				$c->get( DashboardRoute::class )
			)
		);

		$container->set(
			AdminAreaGuard::class,
			static fn( Container $c ): AdminAreaGuard => new AdminAreaGuard(
				$c->get( CreatorRestrictions::class ),
				$c->get( DashboardRoute::class )
			)
		);

		add_filter(
			'login_redirect',
			static fn( mixed $redirect_to, mixed $requested, mixed $user ): mixed => $container->get( LoginRedirect::class )->filter_login_redirect( $redirect_to, $requested, $user ),
			100,
			3
		);

		add_filter(
			'woocommerce_login_redirect',
			static fn( mixed $redirect, mixed $user ): mixed => $container->get( LoginRedirect::class )->filter_woocommerce_login_redirect( $redirect, $user ),
			100,
			2
		);

		$guard = static function () use ( $container ): void {
			$container->get( AdminAreaGuard::class )->maybe_redirect();
		};

		// Before WooCommerce's redirect of non-staff users to My Account (priority 10).
		add_action( 'admin_init', $guard, 0 );

		// Screens the user may not open are refused (403) before `admin_init`.
		add_action( 'admin_page_access_denied', $guard, 0 );

		add_filter(
			'show_admin_bar',
			static fn( mixed $show ): bool => $container->get( CreatorRestrictions::class )->filter_admin_bar( $show ),
			100
		);

		add_filter(
			'wp_is_application_passwords_available_for_user',
			static fn( mixed $available, mixed $user ): bool => $container->get( CreatorRestrictions::class )->filter_application_passwords( $available, $user ),
			100,
			2
		);
	}
}
