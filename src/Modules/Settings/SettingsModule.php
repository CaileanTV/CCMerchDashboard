<?php
/**
 * Settings module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Settings;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Settings\Admin\SettingsActions;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Settings": the opt-in for permanent data deletion on
 * uninstall (phase 9) and the creator dashboard slug (phase 12). The page is
 * a submenu of the Merch Dashboard menu.
 */
final class SettingsModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'settings';
	}

	/**
	 * Registers services and admin hooks.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set(
			SettingsService::class,
			static fn( Container $c ): SettingsService => new SettingsService(
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				new SlugAvailability()
			)
		);

		$container->set(
			SettingsActions::class,
			static fn( Container $c ): SettingsActions => new SettingsActions(
				$c->get( SettingsService::class ),
				$c->get( Logger::class )
			)
		);

		$container->set( SettingsPage::class, static fn(): SettingsPage => new SettingsPage() );

		// After the Creators module has added the top-level menu.
		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( SettingsPage::class )->add_menu();
			},
			20
		);

		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( SettingsActions::class )->register();
			}
		);
	}
}
