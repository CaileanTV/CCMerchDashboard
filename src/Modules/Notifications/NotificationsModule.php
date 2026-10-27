<?php
/**
 * Notifications by e-mail and at the creator's bell.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Notifications\Admin\NotificationsAdminActions;
use MerchBoard\Modules\Notifications\Admin\NotificationsAdminPage;
use MerchBoard\Modules\Notifications\Email\EmailRenderer;
use MerchBoard\Modules\Notifications\Email\Mailer;
use MerchBoard\Modules\WooCommerce\ActionSchedulerQueue;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The Notifications module (phase 29, ADR-079), loaded with or without
 * WooCommerce: without Action Scheduler, e-mails are sent at the end of the
 * request instead of in the background.
 */
final class NotificationsModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'notifications';
	}

	/**
	 * Registers the services and hooks.
	 *
	 * @param Container $container Container.
	 */
	public function register( Container $container ): void {
		$container->set( NotificationTypes::class, static fn(): NotificationTypes => new NotificationTypes() );
		$container->set( NotificationSettings::class, static fn( Container $c ): NotificationSettings => new NotificationSettings( $c->get( NotificationTypes::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( NotificationPreferences::class, static fn(): NotificationPreferences => new NotificationPreferences() );
		$container->set( NotificationRepository::class, static fn( Container $c ): NotificationRepository => new NotificationRepository( $c->get( wpdb::class ) ) );
		$container->set(
			NotificationSender::class,
			static fn( Container $c ): NotificationSender => new NotificationSender(
				$c->get( NotificationRepository::class ),
				$c->get( NotificationTypes::class ),
				$c->get( CreatorRepository::class ),
				new EmailRenderer(),
				new Mailer(),
				$c->get( DashboardRoute::class ),
				new ActionSchedulerQueue(),
				$c->get( Logger::class )
			)
		);
		$container->set(
			Notifier::class,
			static fn( Container $c ): Notifier => new Notifier(
				$c->get( NotificationTypes::class ),
				$c->get( NotificationSettings::class ),
				$c->get( NotificationPreferences::class ),
				$c->get( NotificationRepository::class ),
				$c->get( CreatorRepository::class ),
				new ActionSchedulerQueue(),
				$c->get( NotificationSender::class ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			NotificationsAdminPage::class,
			static fn( Container $c ): NotificationsAdminPage => new NotificationsAdminPage(
				$c->get( NotificationTypes::class ),
				$c->get( NotificationSettings::class ),
				$c->get( NotificationSender::class ),
				$c->get( NotificationRepository::class ),
				$c->get( CreatorRepository::class )
			)
		);
		$container->set( NotificationsAdminActions::class, static fn( Container $c ): NotificationsAdminActions => new NotificationsAdminActions( $c->get( NotificationSettings::class ), $c->get( NotificationTypes::class ), $c->get( NotificationSender::class ), $c->get( Logger::class ) ) );
		$container->set(
			MeNotificationsController::class,
			static fn( Container $c ): MeNotificationsController => new MeNotificationsController(
				$c->get( CreatorContext::class ),
				$c->get( NotificationRepository::class ),
				$c->get( NotificationTypes::class ),
				$c->get( NotificationSettings::class ),
				$c->get( NotificationPreferences::class ),
				$c->get( Logger::class )
			)
		);
		$container->set( NotificationsCreatorCleanup::class, static fn( Container $c ): NotificationsCreatorCleanup => new NotificationsCreatorCleanup( $c->get( NotificationRepository::class ) ) );

		// Deleting a creator (ADR-076): their notifications.
		add_filter(
			'merchboard_creator_data_cleaners',
			static function ( mixed $cleaners ) use ( $container ): array {
				return array_merge( is_array( $cleaners ) ? $cleaners : array(), array( $container->get( NotificationsCreatorCleanup::class ) ) );
			},
			35
		);

		// Fired by the modules after their transaction committed.
		add_action(
			Notifier::ACTION,
			static function ( mixed $type, mixed $creator_id = null, mixed $data = array(), mixed $dedupe = '' ) use ( $container ): void {
				$container->get( Notifier::class )->notify( $type, $creator_id, $data, $dedupe );
			},
			10,
			4
		);

		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( NotificationsAdminPage::class )->add_menu();
			},
			NotificationsAdminPage::PRIORITY
		);
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( NotificationsAdminActions::class )->register();
			}
		);

		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( MeNotificationsController::class )->register_routes();
			}
		);

		add_action(
			NotificationSender::HOOK,
			static function ( mixed $id ) use ( $container ): void {
				$container->get( NotificationSender::class )->run( $id );
			}
		);
	}
}
