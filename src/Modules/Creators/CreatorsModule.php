<?php
/**
 * Creators module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Creators\Admin\CreatorAdminActions;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Creator domain: data layer (phase 3), administration with audit trail
 * (phase 6) and the reaction to deleted WordPress users (phase 8). Creator
 * access is decided by the Access module (CreatorContext); the dashboard and
 * the REST API follow in later roadmap phases (see docs/ROADMAP.md).
 */
final class CreatorsModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'creators';
	}

	/**
	 * Registers services and admin hooks. Services are created lazily when a
	 * hook fires.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set(
			CreatorRepository::class,
			static fn( Container $c ): CreatorRepository => new CreatorRepository( $c->get( wpdb::class ) )
		);

		$container->set(
			UserAccounts::class,
			static fn(): UserAccounts => new WordPressUserAccounts()
		);

		$container->set(
			CreatorService::class,
			static fn( Container $c ): CreatorService => new CreatorService(
				$c->get( CreatorRepository::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( UserAccounts::class )
			)
		);

		$container->set(
			CreatorAdminActions::class,
			static fn( Container $c ): CreatorAdminActions => new CreatorAdminActions(
				$c->get( CreatorService::class ),
				$c->get( Logger::class )
			)
		);

		$container->set(
			CreatorsAdminPage::class,
			static fn( Container $c ): CreatorsAdminPage => new CreatorsAdminPage(
				$c->get( CreatorRepository::class ),
				$c->get( UserAccounts::class ),
				$c->get( TransactionSupport::class )
			)
		);

		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( CreatorsAdminPage::class )->add_menu();
			}
		);

		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( CreatorAdminActions::class )->register();
			}
		);

		$container->set(
			DeletedUserHandler::class,
			static fn( Container $c ): DeletedUserHandler => new DeletedUserHandler(
				$c->get( CreatorService::class ),
				$c->get( Logger::class )
			)
		);

		// Fires after WordPress deleted a user, whatever triggered the deletion.
		add_action(
			'deleted_user',
			static function ( mixed $user_id ) use ( $container ): void {
				$container->get( DeletedUserHandler::class )->handle( $user_id );
			}
		);
	}
}
