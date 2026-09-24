<?php
/**
 * Dashboard module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

use MerchBoard\Core\Container;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Access\CreatorContext;

defined( 'ABSPATH' ) || exit;

/**
 * The creator dashboard's entry point (phase 12, docs/FRONTEND.md): its own
 * route and a standalone shell outside the theme. Depends on the Access
 * module for CreatorContext.
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
			ShellController::class,
			static fn( Container $c ): ShellController => new ShellController(
				$c->get( DashboardRoute::class ),
				$c->get( CreatorContext::class )
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
	}
}
