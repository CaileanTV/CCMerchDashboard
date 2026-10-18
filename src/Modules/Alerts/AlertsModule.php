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
use MerchBoard\Modules\Alerts\Admin\AlertsSettingsActions;
use MerchBoard\Modules\Alerts\Admin\AlertsSettingsSection;
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
 * the MerchAlert guard. The overlay, the dashboard section, the chatbot and
 * the import follow in later steps.
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

		// Every request: orders are paid in checkout, wp-admin, REST, WP-CLI
		// and by payment gateways' callbacks.
		$container->get( AlertHooks::class )->register();

		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( AlertsSettingsActions::class )->register();
				$container->get( AlertsSettingsSection::class )->register();
			}
		);
	}
}
