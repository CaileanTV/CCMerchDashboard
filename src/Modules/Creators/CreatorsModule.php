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
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Admin\AgreementSettingsSection;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\Agreement\AgreementRepository;
use MerchBoard\Modules\Creators\Rest\MeAgreementController;
use MerchBoard\Modules\Creators\Rest\MeGoLiveController;
use MerchBoard\Modules\Creators\Admin\GoLivePage;
use MerchBoard\Modules\Creators\GoLive\GoLiveRepository;
use MerchBoard\Modules\Creators\GoLive\GoLiveRequests;
use MerchBoard\Modules\Creators\GoLive\GoLiveShop;
use MerchBoard\Modules\Creators\GoLive\NullGoLiveShop;
use MerchBoard\Modules\Creators\Signup\SignupCleanup;
use MerchBoard\Modules\Creators\Admin\SignupSettingsSection;
use MerchBoard\Modules\Creators\Signup\CreatorSignup;
use MerchBoard\Modules\Creators\Signup\SignupMails;
use MerchBoard\Modules\Creators\Signup\SignupPage;
use MerchBoard\Modules\Creators\Signup\SignupRepository;
use MerchBoard\Modules\Creators\Signup\SignupSettings;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Notifications\Email\EmailRenderer;
use MerchBoard\Modules\Notifications\Email\Mailer;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Modules\Admin\Approvals\ApprovalsPage;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Creators\Admin\CreatorAdminActions;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Admin\CreatorUserSearch;
use MerchBoard\Modules\Creators\Admin\UserProfileSection;
use MerchBoard\Modules\Creators\Admin\CategoryProposalActions;
use MerchBoard\Modules\Creators\Admin\CategoryProposalsPage;
use MerchBoard\Modules\Creators\Admin\ProposalImageDownload;
use MerchBoard\Modules\Creators\Proposals\CategoryProfileTarget;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalRepository;
use MerchBoard\Modules\Creators\Proposals\CategoryProposals;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalsCleanup;
use MerchBoard\Modules\Creators\Proposals\ProposalFiles;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Creator domain: data layer (phase 3), administration with audit trail
 * (phase 6), the reaction to deleted WordPress users (phase 8), creator meta
 * (phase 14) and the creator's proposals for their category (phase 28,
 * ADR-077).
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

		// The creators' agreement (ADR-092): versions, acceptances, the admin tab and /me/agreement.
		$container->set( AgreementRepository::class, static fn( Container $c ): AgreementRepository => new AgreementRepository( $c->get( wpdb::class ) ) );
		$container->set( Agreement::class, static fn( Container $c ): Agreement => new Agreement( $c->get( AgreementRepository::class ), $c->get( CreatorRepository::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( AgreementSettingsSection::class, static fn( Container $c ): AgreementSettingsSection => new AgreementSettingsSection( $c->get( Agreement::class ), $c->get( AgreementRepository::class ), $c->get( Logger::class ) ) );
		$container->set( MeAgreementController::class, static fn( Container $c ): MeAgreementController => new MeAgreementController( $c->get( CreatorContext::class ), $c->get( Agreement::class ), $c->get( Logger::class ) ) );
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( MeAgreementController::class )->register_routes();
				$container->get( MeGoLiveController::class )->register_routes();
			}
		);
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( SignupSettingsSection::class )->register();
				$container->get( AgreementSettingsSection::class )->register();
				$container->get( GoLivePage::class )->register();
			}
		);

		// Creators who sign themselves up (ADR-091): the public page, the e-mail check and the clean-up.
		$container->set( SignupRepository::class, static fn( Container $c ): SignupRepository => new SignupRepository( $c->get( wpdb::class ) ) );
		$container->set(
			CreatorSignup::class,
			static fn( Container $c ): CreatorSignup => new CreatorSignup(
				$c->get( CreatorRepository::class ),
				$c->get( SignupRepository::class ),
				$c->get( Agreement::class ),
				$c->get( AgreementRepository::class ),
				$c->get( UserAccounts::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				new SignupMails( new EmailRenderer(), new Mailer() ),
				$c->get( Logger::class ),
				$c->get( CreatorMetaStore::class )
			)
		);
		$container->set( SignupPage::class, static fn( Container $c ): SignupPage => new SignupPage( $c->get( DashboardRoute::class ), $c->get( CreatorSignup::class ), $c->get( Agreement::class ), $c->get( CreatorRepository::class ), $c->get( Logger::class ) ) );
		$container->set( SignupSettingsSection::class, static fn( Container $c ): SignupSettingsSection => new SignupSettingsSection( $c->get( Agreement::class ), $c->get( SignupPage::class ), $c->get( Logger::class ) ) );
		// Going live (ADR-091, ADR-093). The WooCommerce module replaces the null shop.
		$container->set( GoLiveShop::class, static fn(): GoLiveShop => new NullGoLiveShop() );
		$container->set( GoLiveRepository::class, static fn( Container $c ): GoLiveRepository => new GoLiveRepository( $c->get( wpdb::class ) ) );
		$container->set( GoLiveRequests::class, static fn( Container $c ): GoLiveRequests => new GoLiveRequests( $c->get( GoLiveRepository::class ), $c->get( CreatorRepository::class ), $c->get( Agreement::class ), static fn(): GoLiveShop => $c->get( GoLiveShop::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ), $c->get( CreatorMetaStore::class ) ) );
		$container->set( GoLivePage::class, static fn( Container $c ): GoLivePage => new GoLivePage( $c->get( GoLiveRequests::class ), $c->get( GoLiveRepository::class ), $c->get( CreatorRepository::class ), $c->get( SignupRepository::class ), $c->get( AgreementRepository::class ), $c->get( GoLiveShop::class ), $c->get( Logger::class ) ) );
		$container->set( MeGoLiveController::class, static fn( Container $c ): MeGoLiveController => new MeGoLiveController( $c->get( CreatorContext::class ), $c->get( GoLiveRequests::class ), $c->get( Logger::class ) ) );
		add_filter(
			ApprovalsPage::FILTER,
			static function ( mixed $tabs ) use ( $container ): array {
				return array_merge( is_array( $tabs ) ? $tabs : array(), array( $container->get( GoLivePage::class )->approval_tab() ) );
			}
		);
		add_filter(
			'merchboard_creator_data_cleaners',
			static function ( mixed $cleaners ) use ( $container ): array {
				return array_merge( is_array( $cleaners ) ? $cleaners : array(), array( new SignupCleanup( $container->get( SignupRepository::class ), $container->get( GoLiveRepository::class ) ) ) );
			},
			5
		);
		// Before the dashboard shell (priority 0): the one dashboard page without a login.
		add_action(
			'template_redirect',
			static function () use ( $container ): void {
				$container->get( SignupPage::class )->handle_request();
			},
			-1
		);
		// The first password proves the e-mail: the user gets the creator role.
		// `password_reset` fires in WordPress' reset and in WooCommerce's (review 32.7).
		add_action(
			'password_reset',
			static function ( mixed $user ) use ( $container ): void {
				$container->get( CreatorSignup::class )->verified( $user );
			}
		);
		// Unverified sign-ups are removed after 7 days; checked at most once a day, after the response.
		add_action(
			'shutdown',
			static function () use ( $container ): void {
				// Also when sign-up was turned off meanwhile: old sign-ups still expire (review 32.7).
				if ( false !== get_transient( 'merchboard_signup_cleanup' ) ) {
					return;
				}

				set_transient( 'merchboard_signup_cleanup', 1, DAY_IN_SECONDS );
				$container->get( CreatorSignup::class )->clean_up();
			}
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

		// The creator's proposals for their category (phase 28, ADR-077); the WooCommerce module provides the category.
		$container->set( CategoryProposalRepository::class, static fn( Container $c ): CategoryProposalRepository => new CategoryProposalRepository( $c->get( wpdb::class ) ) );
		$container->set( ProposalFiles::class, static fn(): ProposalFiles => new ProposalFiles() );
		$container->set(
			CategoryProposals::class,
			static function ( Container $c ): CategoryProposals {
				return new CategoryProposals(
					$c->get( CategoryProposalRepository::class ),
					$c->get( ProposalFiles::class ),
					$c->get( CreatorRepository::class ),
					$c->get( AuditTrail::class ),
					$c->get( Transaction::class ),
					$c->get( wpdb::class ),
					static function (): ?CategoryProfileTarget {
						$target = apply_filters( 'merchboard_category_profile_target', null );

						return $target instanceof CategoryProfileTarget ? $target : null;
					},
					static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
				);
			}
		);
		// Proposals approved before go-live are written on the new category (ADR-101).
		add_action(
			GoLiveRequests::WENT_LIVE,
			static function ( mixed $creator_id ) use ( $container ): void {
				$container->get( CategoryProposals::class )->apply_accepted( $creator_id );
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

		// The administrator decides the proposals (step 28.4).
		$container->set( CategoryProposalsPage::class, static fn( Container $c ): CategoryProposalsPage => new CategoryProposalsPage( $c->get( CategoryProposals::class ), $c->get( CategoryProposalRepository::class ), $c->get( CreatorRepository::class ), $c->get( Logger::class ) ) );
		$container->set( CategoryProposalActions::class, static fn( Container $c ): CategoryProposalActions => new CategoryProposalActions( $c->get( CategoryProposals::class ), $c->get( Logger::class ) ) );
		$container->set( ProposalImageDownload::class, static fn( Container $c ): ProposalImageDownload => new ProposalImageDownload( $c->get( CategoryProposals::class ), $c->get( ProposalFiles::class ) ) );
		add_filter(
			ApprovalsPage::FILTER,
			static function ( mixed $tabs ) use ( $container ): array {
				return array_merge( is_array( $tabs ) ? $tabs : array(), array( $container->get( CategoryProposalsPage::class )->approval_tab() ) );
			}
		);
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( CategoryProposalsPage::class )->register();
				$container->get( CategoryProposalActions::class )->register();
				$container->get( ProposalImageDownload::class )->register();
			}
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
