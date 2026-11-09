<?php
/**
 * Dashboard module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Proposals\CategoryProposals;
use MerchBoard\Modules\Creators\Proposals\ProposalFiles;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\Signup\CreatorSignup;
use MerchBoard\Modules\Creators\Signup\SignupPage;
use MerchBoard\Modules\Dashboard\Branding\BrandIcons;
use MerchBoard\Modules\Dashboard\Branding\BrandingSettings;
use MerchBoard\Modules\Dashboard\Branding\BrandLogo;
use MerchBoard\Modules\Dashboard\Branding\LoginBranding;
use MerchBoard\Modules\Dashboard\Branding\LogoAttachment;
use MerchBoard\Modules\Dashboard\Branding\ManifestPage;
use Throwable;
use MerchBoard\Modules\Dashboard\Rest\MeController;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The creator dashboard's entry point (phase 12, docs/FRONTEND.md): its own
 * route and a standalone shell outside the theme that loads the React app
 * (phase 13, DashboardAssets), and the creator-scope REST routes (phase 14,
 * Rest\MeController). Depends on the Access module for CreatorContext and on
 * the Creators module for the category proposals (ADR-077).
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
		$container->set( BrandIcons::class, static fn( Container $c ): BrandIcons => new BrandIcons( $c->get( BrandLogo::class ), $c->get( DashboardRoute::class ) ) );
		$container->set( ManifestPage::class, static fn( Container $c ): ManifestPage => new ManifestPage( $c->get( DashboardRoute::class ), $c->get( BrandIcons::class ) ) );
		$container->set(
			LoginBranding::class,
			static fn( Container $c ): LoginBranding => new LoginBranding(
				$c->get( DashboardRoute::class ),
				$c->get( BrandLogo::class ),
				$c->get( BrandIcons::class ),
				dirname( __DIR__, 3 ) . '/',
				// A user linked to a creator, also a pending signup (ADR-103 pkt. 2); read only when a reset screen asks.
				static function ( int $user_id ) use ( $c ): bool {
					try {
						return null !== $c->get( CreatorRepository::class )->find_by_user_id( $user_id );
					} catch ( Throwable ) {
						return false;
					}
				},
				static function () use ( $c ): ?string {
					try {
						return $c->get( CreatorSignup::class )->open() ? $c->get( DashboardRoute::class )->url( SignupPage::PATH ) : null;
					} catch ( Throwable ) {
						return null;
					}
				}
			)
		);

		// MitCCMerch's own login screen on wp-login.php, only in its context (ADR-103).
		$container->get( LoginBranding::class )->register();

		// The tab "Branding" of the settings (ADR-103).
		$container->set(
			BrandingSettings::class,
			static fn( Container $c ): BrandingSettings => new BrandingSettings( $c->get( AuditTrail::class ), $c->get( Transaction::class ), $c->get( LogoAttachment::class ), $c->get( BrandLogo::class ), $c->get( DashboardRoute::class ), $c->get( Logger::class ) )
		);

		// Registered lazily: the service is built only when the tab or its form is used.
		SettingsPage::add_tab( BrandingSettings::TAB, static fn(): string => __( 'Branding', 'merchboard' ) );
		add_action(
			'merchboard_settings_sections',
			static function ( mixed $tab ) use ( $container ): void {
				if ( BrandingSettings::TAB === $tab ) {
					$container->get( BrandingSettings::class )->render( $tab );
				}
			}
		);
		add_action(
			'admin_post_' . BrandingSettings::ACTION,
			static function () use ( $container ): void {
				$container->get( BrandingSettings::class )->handle_request();
			}
		);

		// Favicon and app icon in the heads of MitCCMerch's own pages (ADR-103).
		add_filter( 'merchboard_page_icons', static fn(): string => $container->get( BrandIcons::class )->links() );

		// The web manifest for the home screen, before the shell's login requirement (ADR-103).
		add_action(
			'template_redirect',
			static function () use ( $container ): void {
				$container->get( ManifestPage::class )->handle_request();
			},
			-1
		);

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
				$c->get( CategoryProposals::class ),
				$c->get( ProposalFiles::class ),
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
