<?php
/**
 * Admin shell module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin;

use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Module;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\UserAccounts;

defined( 'ABSPATH' ) || exit;

/**
 * The top-level "Merch Dashboard" menu and Overview (phase 10). Depends on
 * the Creators module for CreatorRepository and UserAccounts.
 */
final class AdminModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'admin';
	}

	/**
	 * Registers the menu and Overview.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set(
			OverviewPage::class,
			static fn( Container $c ): OverviewPage => new OverviewPage(
				$c->get( CreatorRepository::class ),
				$c->get( UserAccounts::class ),
				$c->get( Migrator::class ),
				$c->get( TransactionSupport::class ),
				$c->get( RoleManager::class )
			)
		);

		$container->set(
			AdminMenu::class,
			static fn( Container $c ): AdminMenu => new AdminMenu( $c->get( OverviewPage::class ) )
		);

		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( AdminMenu::class )->register();
			},
			AdminMenu::PRIORITY
		);
	}
}
