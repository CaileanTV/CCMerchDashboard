<?php
/**
 * Alerts module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminActions;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;
use MerchBoard\Modules\Alerts\Admin\AlertsSettingsActions;
use MerchBoard\Modules\Alerts\Admin\AlertsSettingsSection;
use MerchBoard\Modules\Alerts\Dashboard\AlertsDashboardSection;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Overlay\OverlayAssets;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeedController;
use MerchBoard\Modules\Alerts\Overlay\OverlayPage;
use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use MerchBoard\Modules\Alerts\Overlay\OverlayRoute;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;
use MerchBoard\Modules\Alerts\Rest\MeAlertsController;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductStore;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Purchase alerts, the integration of MerchAlert (ADR-065, docs/features/
 * alerts.md). Plugin only boots it together with the WooCommerce module,
 * whose product attribution it uses. Phase 25, step 2: alerts are recorded
 * when an order is paid, behind the kill switch in Settings → Alerts and
 * the MerchAlert guard. Step 3: the public overlay page for OBS and its
 * feed (new and legacy URL). The dashboard section, the chatbot and the
 * import follow in later steps.
 */
final class AlertsModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'alerts';
	}

	/**
	 * Registers the module's services.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set( AlertsAvailability::class, static fn(): AlertsAvailability => new AlertsAvailability() );
		$container->set( AlertEventRepository::class, static fn( Container $c ): AlertEventRepository => new AlertEventRepository( $c->get( wpdb::class ) ) );
		$container->set(
			AlertRouter::class,
			static fn( Container $c ): AlertRouter => new AlertRouter(
				new WooCommercePaidOrderReader(),
				$c->get( ProductCreatorLink::class ),
				$c->get( ProductStore::class ),
				$c->get( CreatorRepository::class ),
				$c->get( AlertEventRepository::class ),
				$c->get( AlertsAvailability::class ),
				$c->get( Logger::class )
			)
		);
		$container->set( AlertHooks::class, static fn( Container $c ): AlertHooks => new AlertHooks( $c->get( AlertRouter::class ) ) );
		$container->set(
			AlertSettings::class,
			static fn( Container $c ): AlertSettings => new AlertSettings( $c->get( AuditTrail::class ), $c->get( Transaction::class ) )
		);
		$container->set(
			AlertsSettingsActions::class,
			static fn( Container $c ): AlertsSettingsActions => new AlertsSettingsActions( $c->get( AlertSettings::class ), $c->get( Logger::class ) )
		);
		$container->set(
			AlertsSettingsSection::class,
			static fn( Container $c ): AlertsSettingsSection => new AlertsSettingsSection( $c->get( AlertsAvailability::class ) )
		);

		// Font library (phase 25, step 5; ADR-067).
		$container->set( AlertFonts::class, static fn( Container $c ): AlertFonts => new AlertFonts( $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( AlertsAdminPage::class, static fn( Container $c ): AlertsAdminPage => new AlertsAdminPage( $c->get( AlertFonts::class ) ) );
		$container->set( AlertsAdminActions::class, static fn( Container $c ): AlertsAdminActions => new AlertsAdminActions( $c->get( AlertFonts::class ), $c->get( Logger::class ) ) );

		// Overlay page and feed (phase 25, step 3).
		$container->set( AlertOverlayRepository::class, static fn( Container $c ): AlertOverlayRepository => new AlertOverlayRepository( $c->get( wpdb::class ) ) );
		$container->set( AlertTemplate::class, static fn(): AlertTemplate => new AlertTemplate() );
		$container->set( OverlayTestQueue::class, static fn(): OverlayTestQueue => new OverlayTestQueue() );
		$container->set(
			OverlayFeed::class,
			static fn( Container $c ): OverlayFeed => new OverlayFeed(
				$c->get( AlertOverlayRepository::class ),
				$c->get( AlertEventRepository::class ),
				$c->get( CreatorRepository::class ),
				$c->get( AlertTemplate::class ),
				null,
				null,
				$c->get( OverlayTestQueue::class )
			)
		);
		// The creator's overlay in the dashboard (phase 25, step 4).
		$container->set(
			AlertOverlays::class,
			static fn( Container $c ): AlertOverlays => new AlertOverlays(
				$c->get( AlertOverlayRepository::class ),
				$c->get( OverlayFeed::class ),
				$c->get( OverlayTestQueue::class ),
				$c->get( ProductStore::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				null,
				$c->get( AlertFonts::class )
			)
		);
		$container->set(
			MeAlertsController::class,
			static fn( Container $c ): MeAlertsController => new MeAlertsController( $c->get( CreatorContext::class ), $c->get( AlertOverlays::class ), $c->get( AlertsAvailability::class ), $c->get( Logger::class ), $c->get( AlertFonts::class ) )
		);
		( new AlertsDashboardSection() )->register();
		$container->set(
			OverlayFeedController::class,
			static fn( Container $c ): OverlayFeedController => new OverlayFeedController( $c->get( OverlayFeed::class ), new OverlayRateLimiter(), $c->get( Logger::class ) )
		);
		// MerchAlert answers its own URL while it is active (ADR-065 pkt. 5).
		$container->set( OverlayRoute::class, static fn( Container $c ): OverlayRoute => new OverlayRoute( ! $c->get( AlertsAvailability::class )->merchalert_active() ) );
		$container->set(
			OverlayAssets::class,
			static function ( Container $c ): OverlayAssets {
				$root = dirname( __DIR__, 3 ) . '/';

				return new OverlayAssets( $root, plugin_dir_url( $root . 'merchboard.php' ), $c->get( Logger::class ) );
			}
		);
		$container->set(
			OverlayPage::class,
			static fn( Container $c ): OverlayPage => new OverlayPage( $c->get( OverlayRoute::class ), $c->get( OverlayFeed::class ), $c->get( OverlayAssets::class ), $c->get( Logger::class ), $c->get( AlertFonts::class ) )
		);

		add_action(
			'init',
			static function () use ( $container ): void {
				$route = $container->get( OverlayRoute::class );
				$route->add_rewrite_rules();
				$route->ensure_rewrite_rules();
			}
		);
		add_filter( 'query_vars', static fn( mixed $vars ): mixed => $container->get( OverlayRoute::class )->add_query_vars( $vars ) );
		// Before redirect_canonical() (priority 10) and before any theme output.
		add_action(
			'template_redirect',
			static function () use ( $container ): void {
				$container->get( OverlayPage::class )->handle_request();
			},
			0
		);
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( OverlayFeedController::class )->register_routes();
				$container->get( MeAlertsController::class )->register_routes();
			}
		);

		// Every request: orders are paid in checkout, wp-admin, REST, WP-CLI
		// and by payment gateways' callbacks.
		$container->get( AlertHooks::class )->register();

		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( AlertsAdminPage::class )->add_menu();
			},
			AlertsAdminPage::PRIORITY
		);

		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( AlertsSettingsActions::class )->register();
				$container->get( AlertsAdminActions::class )->register();
				$container->get( AlertsSettingsSection::class )->register();
			}
		);
	}
}
