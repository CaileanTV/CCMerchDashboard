<?php
/**
 * Admin shell module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin;

use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Container;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Module;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\UserAccounts;

defined( 'ABSPATH' ) || exit;

/**
 * The top-level "Merch Dashboard" menu, Overview (phase 10) and the
 * read-only audit log (phase 11). Depends on the Creators module for
 * CreatorRepository and UserAccounts.
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
				$c->get( RoleManager::class ),
				$c->get( AuditRepository::class )
			)
		);

		$container->set(
			AdminMenu::class,
			static fn( Container $c ): AdminMenu => new AdminMenu( $c->get( OverviewPage::class ) )
		);

		$container->set(
			AuditLogPage::class,
			static fn( Container $c ): AuditLogPage => new AuditLogPage(
				$c->get( AuditRepository::class ),
				$c->get( CreatorRepository::class ),
				$c->get( UserAccounts::class ),
				$c->get( Logger::class )
			)
		);

		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( AdminMenu::class )->register();
			},
			AdminMenu::PRIORITY
		);

		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( AuditLogPage::class )->add_menu();
			},
			AuditLogPage::PRIORITY
		);
	}
}
