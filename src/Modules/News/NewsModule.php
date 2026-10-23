<?php
/**
 * The News module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\News\Admin\NewsAdminActions;
use MerchBoard\Modules\News\Admin\NewsAdminPage;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * "What's new" for the creators (step 25.11, ADR-072, docs/features/news.md):
 * the administrator writes and publishes messages under Merch Dashboard →
 * News; every active creator sees the newest once as a popup in the
 * dashboard, and every message on the page "News". No WooCommerce needed.
 */
final class NewsModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'news';
	}

	/**
	 * Registers services and hooks.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set( NewsRepository::class, static fn( Container $c ): NewsRepository => new NewsRepository( $c->get( wpdb::class ) ) );
		$container->set( NewsService::class, static fn( Container $c ): NewsService => new NewsService( $c->get( NewsRepository::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( NewsAdminPage::class, static fn( Container $c ): NewsAdminPage => new NewsAdminPage( $c->get( NewsRepository::class ), $c->get( CreatorRepository::class ) ) );
		$container->set( NewsAdminActions::class, static fn( Container $c ): NewsAdminActions => new NewsAdminActions( $c->get( NewsService::class ), $c->get( Logger::class ) ) );
		$container->set( MeNewsController::class, static fn( Container $c ): MeNewsController => new MeNewsController( $c->get( CreatorContext::class ), $c->get( NewsRepository::class ), $c->get( Logger::class ) ) );

		( new NewsDashboardSection() )->register();

		// Between the menus Alerts and Audit log.
		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( NewsAdminPage::class )->add_menu();
			},
			28
		);
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( NewsAdminActions::class )->register();
			}
		);
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( MeNewsController::class )->register_routes();
			}
		);
	}
}
