<?php
/**
 * Creators module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\Creators\Admin\CreatorDeletionActions;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Creators\Admin\CreatorAdminActions;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Admin\CreatorUserSearch;
use MerchBoard\Modules\Creators\Admin\UserProfileSection;
use MerchBoard\Modules\Creators\Proposals\CategoryProfileTarget;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalRepository;
use MerchBoard\Modules\Creators\Proposals\CategoryProposals;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalsCleanup;
use MerchBoard\Modules\Creators\Proposals\ProposalFiles;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Creator domain: data layer (phase 3), administration with audit trail
 * (phase 6), the reaction to deleted WordPress users (phase 8) and the
 * creator's own profile with creator meta (phase 14, CreatorProfileService).
 * Creator access is decided by the Access module (CreatorContext); the REST
 * endpoints live in the Dashboard module.
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
				$c->get( UserAccounts::class ),
				$c->get( Logger::class )
			)
		);

		$container->set(
			CreatorUserSearch::class,
			static fn( Container $c ): CreatorUserSearch => new CreatorUserSearch(
				$c->get( UserAccounts::class ),
				$c->get( CreatorRepository::class )
			)
		);

		$container->set(
			UserProfileSection::class,
			static fn( Container $c ): UserProfileSection => new UserProfileSection( $c->get( CreatorRepository::class ) )
		);

		// Deleting a creator (phase 27, step 27.3, ADR-076); modules add their cleaners.
		$container->set(
			CreatorDeletion::class,
			static fn( Container $c ): CreatorDeletion => new CreatorDeletion(
				$c->get( CreatorRepository::class ),
				$c->get( CreatorService::class ),
				$c->get( CreatorMetaStore::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( wpdb::class ),
				array_values( array_filter( (array) apply_filters( 'merchboard_creator_data_cleaners', array() ), static fn( mixed $cleaner ): bool => $cleaner instanceof CreatorDataCleaner ) ),
				static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			)
		);
		$container->set( CreatorDeletionActions::class, static fn( Container $c ): CreatorDeletionActions => new CreatorDeletionActions( $c->get( CreatorDeletion::class ), $c->get( Logger::class ) ) );

		$container->set(
			CreatorsAdminPage::class,
			static fn( Container $c ): CreatorsAdminPage => new CreatorsAdminPage(
				$c->get( CreatorRepository::class ),
				$c->get( UserAccounts::class ),
				$c->get( TransactionSupport::class ),
				$c->get( CreatorDeletion::class )
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
				$container->get( CreatorDeletionActions::class )->register();
				$container->get( CreatorUserSearch::class )->register();
				$container->get( UserProfileSection::class )->register();
			}
		);

		$container->set(
			DeletedUserHandler::class,
			static fn( Container $c ): DeletedUserHandler => new DeletedUserHandler(
				$c->get( CreatorService::class ),
				$c->get( Logger::class )
			)
		);

		$container->set(
			CreatorMetaStore::class,
			static fn( Container $c ): CreatorMetaStore => new CreatorMetaStore( $c->get( wpdb::class ) )
		);

		$container->set(
			CreatorProfileService::class,
			static fn( Container $c ): CreatorProfileService => new CreatorProfileService(
				$c->get( CreatorRepository::class ),
				$c->get( CreatorMetaStore::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class )
			)
		);

		// The creator's proposals for their category (phase 28, ADR-077); the WooCommerce module provides the category.
		$container->set( CategoryProposalRepository::class, static fn( Container $c ): CategoryProposalRepository => new CategoryProposalRepository( $c->get( wpdb::class ) ) );
		$container->set( ProposalFiles::class, static fn(): ProposalFiles => new ProposalFiles() );
		$container->set(
			CategoryProposals::class,
			static function ( Container $c ): CategoryProposals {
				$target = apply_filters( 'merchboard_category_profile_target', null );

				return new CategoryProposals(
					$c->get( CategoryProposalRepository::class ),
					$c->get( ProposalFiles::class ),
					$c->get( CreatorRepository::class ),
					$c->get( AuditTrail::class ),
					$c->get( Transaction::class ),
					$c->get( wpdb::class ),
					$target instanceof CategoryProfileTarget ? $target : null,
					static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
				);
			}
		);
		$container->set( CategoryProposalsCleanup::class, static fn( Container $c ): CategoryProposalsCleanup => new CategoryProposalsCleanup( $c->get( CategoryProposalRepository::class ), $c->get( ProposalFiles::class ) ) );
		add_filter(
			'merchboard_creator_data_cleaners',
			static function ( mixed $cleaners ) use ( $container ): array {
				return array_merge( is_array( $cleaners ) ? $cleaners : array(), array( $container->get( CategoryProposalsCleanup::class ) ) );
			},
			5
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
