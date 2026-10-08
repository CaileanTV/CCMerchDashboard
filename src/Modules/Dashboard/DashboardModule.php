<?php
/**
 * Dashboard module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

use MerchBoard\Core\Container;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorProfileService;
use MerchBoard\Modules\Dashboard\Branding\BrandLogo;
use MerchBoard\Modules\Dashboard\Branding\LoginBranding;
use MerchBoard\Modules\Dashboard\Branding\LogoAttachment;
use MerchBoard\Modules\Dashboard\Rest\MeController;

defined( 'ABSPATH' ) || exit;

/**
 * The creator dashboard's entry point (phase 12, docs/FRONTEND.md): its own
 * route and a standalone shell outside the theme that loads the React app
 * (phase 13, DashboardAssets), and the creator-scope REST routes (phase 14,
 * Rest\MeController). Depends on the Access module for CreatorContext and on
 * the Creators module for CreatorProfileService.
 */
final class DashboardModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'dashboard';
	}

	/**
	 * Registers the route and the shell.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set( DashboardRoute::class, static fn(): DashboardRoute => new DashboardRoute() );

		$container->set(
			DashboardAssets::class,
			static function ( Container $c ): DashboardAssets {
				$root = dirname( __DIR__, 3 ) . '/';

				return new DashboardAssets( $root, plugin_dir_url( $root . 'merchboard.php' ), $c->get( Logger::class ) );
			}
		);

		$container->set( ThemePreference::class, static fn(): ThemePreference => new ThemePreference() );

		// Branding (ADR-042): one logo for the dashboard and the login screen.
		$container->set( LogoAttachment::class, static fn(): LogoAttachment => new LogoAttachment() );
		$container->set( BrandLogo::class, static fn( Container $c ): BrandLogo => new BrandLogo( $c->get( LogoAttachment::class ), dirname( __DIR__, 3 ) . '/' ) );
		$container->set(
			LoginBranding::class,
			static fn( Container $c ): LoginBranding => new LoginBranding( $c->get( DashboardRoute::class ), $c->get( BrandLogo::class ), dirname( __DIR__, 3 ) . '/' )
		);

		// WordPress' own login screen, branded only in Merch Dashboard context.
		$container->get( LoginBranding::class )->register();

		$container->set( DashboardSections::class, static fn( Container $c ): DashboardSections => new DashboardSections( $c->get( Logger::class ) ) );

		$container->set(
			ShellController::class,
			static fn( Container $c ): ShellController => new ShellController(
				$c->get( DashboardRoute::class ),
				$c->get( CreatorContext::class ),
				$c->get( DashboardAssets::class ),
				$c->get( DashboardSections::class ),
				$c->get( ThemePreference::class ),
				$c->get( BrandLogo::class )
			)
		);

		add_action(
			'init',
			static function () use ( $container ): void {
				$route = $container->get( DashboardRoute::class );
				$route->add_rewrite_rule();
				$route->ensure_rewrite_rules();
			}
		);

		add_filter(
			'query_vars',
			static fn( mixed $vars ): mixed => $container->get( DashboardRoute::class )->add_query_vars( $vars )
		);

		// Before redirect_canonical() (priority 10) and before any theme output.
		add_action(
			'template_redirect',
			static function () use ( $container ): void {
				$container->get( ShellController::class )->handle_request();
			},
			0
		);

		add_action( 'merchboard_dashboard_slug_changed', array( DashboardRoute::class, 'invalidate_rewrite_rules' ) );

		$container->set(
			MeController::class,
			static fn( Container $c ): MeController => new MeController(
				$c->get( CreatorContext::class ),
				$c->get( CreatorProfileService::class ),
				$c->get( ThemePreference::class ),
				$c->get( Logger::class )
			)
		);

		// REST API (phase 14): creator-scope routes of merchboard/v1.
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( MeController::class )->register_routes();
			}
		);
	}
}
