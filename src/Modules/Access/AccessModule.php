<?php
/**
 * Access module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * Creator access: CreatorContext (phase 8), the policy every creator-facing
 * feature uses, and the restrictions for pure creators (phase 12, ADR-026):
 * login redirect, wp-admin guard, hidden admin bar and no Application
 * Passwords. From step 27.2 (ADR-075) also "view as creator": Impersonation,
 * its guard and its admin entry points. Depends on the Creators module
 * (CreatorRepository) and the Dashboard module (DashboardRoute); services are
 * resolved when a hook fires.
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

		// "View as creator" (phase 27, step 27.2, ADR-075).
		$container->set( Impersonation::class, static fn( Container $c ): Impersonation => new Impersonation( $c->get( CreatorRepository::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( ImpersonationActions::class, static fn( Container $c ): ImpersonationActions => new ImpersonationActions( $c->get( Impersonation::class ), $c->get( DashboardRoute::class ), $c->get( Logger::class ) ) );
		( new ImpersonationGuard() )->register();
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( ImpersonationActions::class )->register();
			}
		);
		// An expired session ends on the next request; logging out ends it at once.
		add_action(
			'init',
			static function () use ( $container ): void {
				if ( null !== ImpersonationSession::stored( get_current_user_id() ) ) {
					self::change_session( $container, false );
				}
			}
		);
		add_action(
			'clear_auth_cookie',
			static function () use ( $container ): void {
				if ( null !== ImpersonationSession::stored( get_current_user_id() ) ) {
					self::change_session( $container, true );
				}
			}
		);

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

	/**
	 * Ends the logged-in user's session on logout, or when it has expired,
	 * from a hook; a database failure is logged and never breaks the request
	 * (the session then simply stays or expires).
	 *
	 * @param Container $container Shared service container.
	 * @param bool      $logout    Whether the user logs out (else: only an expired session).
	 */
	private static function change_session( Container $container, bool $logout ): void {
		try {
			$impersonation = $container->get( Impersonation::class );

			if ( $logout ) {
				$impersonation->end( Impersonation::ENDED_LOGOUT );
			} else {
				$impersonation->expire();
			}
		} catch ( DatabaseException $e ) {
			$container->get( Logger::class )->error( 'A "view as creator" session could not be changed.', $e->context() );
		}
	}
}
