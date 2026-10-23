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
use MerchBoard\Modules\Alerts\Admin\ChatbotAdminActions;
use MerchBoard\Modules\Alerts\Admin\ChatbotAdminTab;
use MerchBoard\Modules\Alerts\Admin\CompanyDetailsSection;
use MerchBoard\Modules\Alerts\Admin\CreatorSoundLimitsActions;
use MerchBoard\Modules\Alerts\Admin\CreatorSoundLimitsSection;
use MerchBoard\Modules\Alerts\Admin\OrderLogTab;
use MerchBoard\Modules\Alerts\Admin\GlobalOverlayTab;
use MerchBoard\Modules\Alerts\GlobalOverlay\AdminGlobalOverlayController;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayFeed;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayFeedController;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayImages;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayPage;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayRoute;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayService;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlaySounds;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayStore;
use MerchBoard\Modules\Alerts\Overlay\AlertRenderer;
use MerchBoard\Modules\Alerts\Admin\YouTubeLegalTab;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\ChatLogRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessageRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessages;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatforms;
use MerchBoard\Modules\Alerts\Chatbot\ChatSender;
use MerchBoard\Modules\Alerts\Chatbot\ChatSubscriptions;
use MerchBoard\Modules\Alerts\Chatbot\CreatorChatbot;
use MerchBoard\Modules\Alerts\Chatbot\CreatorTokens;
use MerchBoard\Modules\Alerts\Chatbot\KickAccount;
use MerchBoard\Modules\Alerts\Chatbot\KickApi;
use MerchBoard\Modules\Alerts\Chatbot\KickOAuth;
use MerchBoard\Modules\Alerts\Chatbot\KickPlatform;
use MerchBoard\Modules\Alerts\Chatbot\KickWebhookVerifier;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchApi;
use MerchBoard\Modules\Alerts\Chatbot\TwitchOAuth;
use MerchBoard\Modules\Alerts\Chatbot\TwitchPlatform;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeAccount;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeApi;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeOAuth;
use MerchBoard\Modules\Alerts\Chatbot\YouTubePlatform;
use MerchBoard\Modules\Alerts\Dashboard\AlertsDashboardSection;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\History\AlertHistory;
use MerchBoard\Modules\Alerts\Images\AlertImageLibrary;
use MerchBoard\Modules\Alerts\Checkout\CheckoutUsernameField;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Legal\LegalPage;
use MerchBoard\Modules\Alerts\Legal\YouTubeLegal;
use MerchBoard\Modules\Alerts\Legal\YouTubeTerms;
use MerchBoard\Modules\Alerts\Images\ImageFiles;
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
use MerchBoard\Modules\Alerts\Rest\ChatbotChatController;
use MerchBoard\Modules\Alerts\Rest\MeAlertsHistoryController;
use MerchBoard\Modules\Alerts\Rest\KickController;
use MerchBoard\Modules\Alerts\Rest\MeChatbotController;
use MerchBoard\Modules\Alerts\Rest\TwitchController;
use MerchBoard\Modules\Alerts\Rest\YouTubeController;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundLibrary;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Alerts\Sounds\CreatorSoundLimits;
use MerchBoard\Modules\Alerts\Sounds\SoundFiles;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\WooCommerce\JobQueue;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductStore;
use WP_Term;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Purchase alerts, the integration of MerchAlert (ADR-065, docs/features/
 * alerts.md). Plugin only boots it together with the WooCommerce module,
 * whose product attribution it uses. Phase 25, step 2: alerts are recorded
 * when an order is paid, behind the kill switch in Settings → Alerts and
 * the MerchAlert guard. Step 3: the public overlay page for OBS and its
 * feed (new and legacy URL). Step 4: the dashboard section. Step 5: the
 * designer and the font library. Step 6: sounds. The chatbot and the
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
		$container->set( AlertsAdminPage::class, static fn( Container $c ): AlertsAdminPage => new AlertsAdminPage( $c->get( AlertFonts::class ), $c->get( AlertSoundLibrary::class ), $c->get( AlertImageLibrary::class ), $c->get( ChatbotAdminTab::class ), $c->get( YouTubeLegalTab::class ), new OrderLogTab( $c->get( AlertEventRepository::class ), $c->get( ChatMessageRepository::class ), $c->get( CreatorRepository::class ), $c->get( AlertsAvailability::class ) ), $c->get( GlobalOverlayTab::class ) ) );
		$container->set( AlertsAdminActions::class, static fn( Container $c ): AlertsAdminActions => new AlertsAdminActions( $c->get( AlertFonts::class ), $c->get( Logger::class ), $c->get( AlertSoundLibrary::class ), $c->get( AlertImageLibrary::class ) ) );

		// Overlay page and feed (phase 25, step 3).
		$container->set( AlertOverlayRepository::class, static fn( Container $c ): AlertOverlayRepository => new AlertOverlayRepository( $c->get( wpdb::class ) ) );
		$container->set( AlertTemplate::class, static fn(): AlertTemplate => new AlertTemplate() );
		$container->set( OverlayTestQueue::class, static fn(): OverlayTestQueue => new OverlayTestQueue() );
		// Sounds (phase 25, step 6) and the administrator's library (step 6.3).
		$container->set( SoundFiles::class, static fn(): SoundFiles => new SoundFiles() );
		$container->set(
			AlertSoundLibrary::class,
			static fn( Container $c ): AlertSoundLibrary => new AlertSoundLibrary( $c->get( SoundFiles::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) )
		);
		// A creator's own sound limits (step 9.2).
		$container->set(
			CreatorSoundLimits::class,
			static fn( Container $c ): CreatorSoundLimits => new CreatorSoundLimits( $c->get( CreatorMetaStore::class ), $c->get( CreatorRepository::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) )
		);
		$container->set( CreatorSoundLimitsActions::class, static fn( Container $c ): CreatorSoundLimitsActions => new CreatorSoundLimitsActions( $c->get( CreatorSoundLimits::class ), $c->get( Logger::class ) ) );
		$container->set( CreatorSoundLimitsSection::class, static fn( Container $c ): CreatorSoundLimitsSection => new CreatorSoundLimitsSection( $c->get( CreatorSoundLimits::class ) ) );
		$container->set(
			AlertSounds::class,
			static fn( Container $c ): AlertSounds => new AlertSounds(
				$c->get( AlertOverlayRepository::class ),
				static function ( int $creator_id ) use ( $c ): array {
					$names = array();

					foreach ( $c->get( CreatorCategoryRepository::class )->for_creator( $creator_id ) as $category ) {
						$term = get_term( $category->term_id, 'product_cat' );

						if ( $term instanceof WP_Term ) {
							$names[ $category->term_id ] = $term->name;
						}
					}

					return $names;
				},
				$c->get( SoundFiles::class ),
				$c->get( AlertSoundLibrary::class ),
				null,
				$c->get( CreatorSoundLimits::class )
			)
		);
		// Images that fill the alert text (phase 25, step 6.10) and the administrator's library.
		$container->set( ImageFiles::class, static fn(): ImageFiles => new ImageFiles() );
		$container->set(
			AlertImageLibrary::class,
			static fn( Container $c ): AlertImageLibrary => new AlertImageLibrary( $c->get( ImageFiles::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) )
		);
		$container->set(
			AlertImages::class,
			static fn( Container $c ): AlertImages => new AlertImages( $c->get( AlertOverlayRepository::class ), $c->get( ImageFiles::class ), $c->get( AlertImageLibrary::class ) )
		);
		$container->set(
			OverlayFeed::class,
			static fn( Container $c ): OverlayFeed => new OverlayFeed(
				$c->get( AlertOverlayRepository::class ),
				$c->get( AlertEventRepository::class ),
				$c->get( CreatorRepository::class ),
				$c->get( AlertTemplate::class ),
				null,
				null,
				$c->get( OverlayTestQueue::class ),
				$c->get( AlertSounds::class )
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
				$c->get( AlertFonts::class ),
				$c->get( AlertImages::class )
			)
		);
		$container->set(
			MeAlertsController::class,
			static fn( Container $c ): MeAlertsController => new MeAlertsController( $c->get( CreatorContext::class ), $c->get( AlertOverlays::class ), $c->get( AlertsAvailability::class ), $c->get( Logger::class ), $c->get( AlertFonts::class ), $c->get( AlertSounds::class ), $c->get( AlertImages::class ) )
		);
		// The chatbot on Twitch, Kick and YouTube (phase 25, steps 8, 8.2 and 8.4; ADR-066, ADR-069, ADR-070).
		$container->set( TwitchApi::class, static fn(): TwitchApi => new TwitchApi() );
		$container->set(
			TwitchAccount::class,
			static fn( Container $c ): TwitchAccount => new TwitchAccount( $c->get( TwitchApi::class ), new ChatCipher( ChatPlatform::Twitch ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) )
		);
		$container->set( KickApi::class, static fn(): KickApi => new KickApi() );
		$container->set(
			KickAccount::class,
			static fn( Container $c ): KickAccount => new KickAccount( $c->get( KickApi::class ), new ChatCipher( ChatPlatform::Kick ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) )
		);
		$container->set( YouTubeApi::class, static fn(): YouTubeApi => new YouTubeApi() );
		$container->set(
			YouTubeAccount::class,
			static fn( Container $c ): YouTubeAccount => new YouTubeAccount( $c->get( YouTubeApi::class ), new ChatCipher( ChatPlatform::YouTube ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) )
		);
		$container->set(
			YouTubePlatform::class,
			static fn( Container $c ): YouTubePlatform => new YouTubePlatform( $c->get( YouTubeAccount::class ), $c->get( YouTubeApi::class ), self::youtube_tokens( $c ), $c->get( ChatbotChannelRepository::class ), $c->get( YouTubeTerms::class ), $c->get( Logger::class ) )
		);
		$container->set( ChatbotFormat::class, static fn( Container $c ): ChatbotFormat => new ChatbotFormat( $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( ChatbotChannelRepository::class, static fn( Container $c ): ChatbotChannelRepository => new ChatbotChannelRepository( $c->get( wpdb::class ) ) );
		$container->set( ChatMessageRepository::class, static fn( Container $c ): ChatMessageRepository => new ChatMessageRepository( $c->get( wpdb::class ) ) );
		$container->set( ChatLogRepository::class, static fn( Container $c ): ChatLogRepository => new ChatLogRepository( $c->get( wpdb::class ) ) );
		$container->set( ChatMessages::class, static fn( Container $c ): ChatMessages => new ChatMessages( $c->get( AlertTemplate::class ) ) );
		$container->set(
			ChatPlatforms::class,
			static fn( Container $c ): ChatPlatforms => new ChatPlatforms(
				new TwitchPlatform( $c->get( TwitchAccount::class ), $c->get( TwitchApi::class ) ),
				new KickPlatform( $c->get( KickAccount::class ), $c->get( KickApi::class ), self::kick_tokens( $c ), $c->get( ChatbotChannelRepository::class ) ),
				$c->get( YouTubePlatform::class )
			)
		);
		$container->set(
			ChatSubscriptions::class,
			static fn( Container $c ): ChatSubscriptions => new ChatSubscriptions( $c->get( ChatPlatforms::class ), $c->get( ChatbotChannelRepository::class ), $c->get( ChatLogRepository::class ) )
		);
		$container->set(
			ChatSender::class,
			static fn( Container $c ): ChatSender => new ChatSender(
				$c->get( ChatPlatforms::class ),
				$c->get( ChatbotChannelRepository::class ),
				$c->get( ChatMessageRepository::class ),
				$c->get( ChatbotFormat::class ),
				$c->get( ChatMessages::class ),
				$c->get( AlertEventRepository::class ),
				$c->get( AlertsAvailability::class ),
				$c->get( JobQueue::class ),
				$c->get( Logger::class )
			)
		);
		$dashboard_url = static fn( string $path ): string => $container->get( DashboardRoute::class )->url( $path );
		// The YouTube chatbot's privacy policy and terms (phase 25, step 8.5, ADR-071).
		$container->set( YouTubeLegal::class, static fn( Container $c ): YouTubeLegal => new YouTubeLegal( $c->get( AuditTrail::class ), $c->get( Transaction::class ), $dashboard_url ) );
		$container->set(
			YouTubeTerms::class,
			static fn( Container $c ): YouTubeTerms => new YouTubeTerms( $c->get( YouTubeLegal::class ), $c->get( YouTubeAccount::class ), $c->get( CreatorRepository::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) )
		);
		$container->set( LegalPage::class, static fn( Container $c ): LegalPage => new LegalPage( $c->get( DashboardRoute::class ), $c->get( YouTubeLegal::class ) ) );
		$container->set( YouTubeLegalTab::class, static fn( Container $c ): YouTubeLegalTab => new YouTubeLegalTab( $c->get( YouTubeLegal::class ) ) );
		$container->set(
			TwitchOAuth::class,
			static fn( Container $c ): TwitchOAuth => new TwitchOAuth(
				$c->get( TwitchAccount::class ),
				$c->get( TwitchApi::class ),
				$c->get( ChatbotChannelRepository::class ),
				$c->get( ChatSubscriptions::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$dashboard_url
			)
		);
		$container->set(
			KickOAuth::class,
			static fn( Container $c ): KickOAuth => new KickOAuth(
				$c->get( KickAccount::class ),
				$c->get( KickApi::class ),
				self::kick_tokens( $c ),
				$c->get( ChatbotChannelRepository::class ),
				$c->get( ChatSubscriptions::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$dashboard_url,
				$c->get( Logger::class )
			)
		);
		$container->set(
			YouTubeOAuth::class,
			static fn( Container $c ): YouTubeOAuth => new YouTubeOAuth(
				$c->get( YouTubeAccount::class ),
				$c->get( YouTubeApi::class ),
				self::youtube_tokens( $c ),
				$c->get( ChatbotChannelRepository::class ),
				$c->get( ChatSubscriptions::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$dashboard_url,
				$c->get( Logger::class ),
				$c->get( YouTubeTerms::class )
			)
		);
		$container->set(
			CreatorChatbot::class,
			static fn( Container $c ): CreatorChatbot => new CreatorChatbot(
				$c->get( ChatPlatforms::class ),
				$c->get( ChatbotChannelRepository::class ),
				$c->get( ChatMessageRepository::class ),
				$c->get( ChatbotFormat::class ),
				$c->get( ChatMessages::class ),
				$c->get( ChatSubscriptions::class ),
				$c->get( ChatSender::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( YouTubeTerms::class )
			)
		);
		$container->set(
			TwitchController::class,
			static fn( Container $c ): TwitchController => new TwitchController( $c->get( TwitchOAuth::class ), $c->get( TwitchAccount::class ), $c->get( ChatbotChannelRepository::class ), $c->get( ChatLogRepository::class ), $c->get( Logger::class ) )
		);
		$container->set(
			KickController::class,
			static fn( Container $c ): KickController => new KickController( $c->get( KickOAuth::class ), new KickWebhookVerifier( $c->get( KickApi::class ) ), $c->get( ChatbotChannelRepository::class ), $c->get( ChatLogRepository::class ), $c->get( Logger::class ) )
		);
		$container->set(
			YouTubeController::class,
			static fn( Container $c ): YouTubeController => new YouTubeController( $c->get( YouTubeOAuth::class ), $c->get( Logger::class ) )
		);
		$container->set(
			ChatbotChatController::class,
			static fn( Container $c ): ChatbotChatController => new ChatbotChatController( $c->get( ChatbotChannelRepository::class ), $c->get( ChatLogRepository::class ), $c->get( Logger::class ) )
		);
		$container->set(
			MeChatbotController::class,
			static fn( Container $c ): MeChatbotController => new MeChatbotController( $c->get( CreatorContext::class ), $c->get( CreatorChatbot::class ), $c->get( TwitchOAuth::class ), $c->get( KickOAuth::class ), $c->get( YouTubeOAuth::class ), $c->get( Logger::class ) )
		);
		$container->set(
			ChatbotAdminTab::class,
			static function ( Container $c ): ChatbotAdminTab {
				$root = dirname( __DIR__, 3 ) . '/';

				return new ChatbotAdminTab( $c->get( TwitchAccount::class ), $c->get( KickAccount::class ), $c->get( YouTubeAccount::class ), $c->get( YouTubePlatform::class ), $c->get( ChatbotFormat::class ), $c->get( ChatbotChannelRepository::class ), $c->get( ChatMessageRepository::class ), $c->get( CreatorRepository::class ), $root, plugin_dir_url( $root . 'merchboard.php' ), $c->get( YouTubeTerms::class ) );
			}
		);
		$container->set(
			ChatbotAdminActions::class,
			static fn( Container $c ): ChatbotAdminActions => new ChatbotAdminActions(
				$c->get( TwitchAccount::class ),
				$c->get( KickAccount::class ),
				$c->get( YouTubeAccount::class ),
				$c->get( YouTubeOAuth::class ),
				$c->get( TwitchOAuth::class ),
				$c->get( ChatbotFormat::class ),
				$c->get( ChatSubscriptions::class ),
				$c->get( ChatbotChannelRepository::class ),
				$c->get( ChatSender::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( Logger::class ),
				$c->get( YouTubeLegal::class ),
				$c->get( YouTubeTerms::class )
			)
		);
		// The history: resend and "Stop all alerts" (phase 25, step 7).
		$container->set(
			AlertHistory::class,
			static fn( Container $c ): AlertHistory => new AlertHistory( $c->get( AlertEventRepository::class ), $c->get( AlertOverlayRepository::class ), $c->get( OverlayFeed::class ), $c->get( OverlayTestQueue::class ), $c->get( ChatSender::class ) )
		);
		$container->set(
			MeAlertsHistoryController::class,
			static fn( Container $c ): MeAlertsHistoryController => new MeAlertsHistoryController( $c->get( CreatorContext::class ), $c->get( AlertHistory::class ), $c->get( Logger::class ) )
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
			static fn( Container $c ): OverlayPage => new OverlayPage( $c->get( OverlayRoute::class ), $c->get( OverlayFeed::class ), $c->get( OverlayAssets::class ), $c->get( Logger::class ), $c->get( AlertFonts::class ), $c->get( AlertImages::class ) )
		);

		// The global overlay (step 25.9.4): its own option, route, feed, queue, sounds and images; nothing shared with a creator's overlay but the code.
		$container->set( GlobalOverlayStore::class, static fn(): GlobalOverlayStore => new GlobalOverlayStore() );
		$container->set( GlobalOverlaySounds::class, static fn( Container $c ): GlobalOverlaySounds => new GlobalOverlaySounds( $c->get( GlobalOverlayStore::class ), $c->get( SoundFiles::class ), $c->get( AlertSoundLibrary::class ) ) );
		$container->set( GlobalOverlayImages::class, static fn( Container $c ): GlobalOverlayImages => new GlobalOverlayImages( $c->get( GlobalOverlayStore::class ), $c->get( ImageFiles::class ), $c->get( AlertImageLibrary::class ) ) );
		$container->set(
			GlobalOverlayFeed::class,
			static fn( Container $c ): GlobalOverlayFeed => new GlobalOverlayFeed( $c->get( GlobalOverlayStore::class ), $c->get( AlertEventRepository::class ), new AlertRenderer( $c->get( AlertTemplate::class ) ), $c->get( AlertsAvailability::class ), new OverlayTestQueue( 'global' ), $c->get( GlobalOverlaySounds::class ) )
		);
		$container->set(
			GlobalOverlayService::class,
			static fn( Container $c ): GlobalOverlayService => new GlobalOverlayService( $c->get( GlobalOverlayStore::class ), $c->get( GlobalOverlayFeed::class ), new OverlayTestQueue( 'global' ), $c->get( AlertEventRepository::class ), $c->get( AuditTrail::class ), $c->get( AlertFonts::class ), $c->get( GlobalOverlayImages::class ) )
		);
		$container->set(
			AdminGlobalOverlayController::class,
			static fn( Container $c ): AdminGlobalOverlayController => new AdminGlobalOverlayController( $c->get( GlobalOverlayService::class ), $c->get( AlertsAvailability::class ), $c->get( Logger::class ), $c->get( AlertFonts::class ), $c->get( GlobalOverlaySounds::class ), $c->get( GlobalOverlayImages::class ) )
		);
		$container->set( GlobalOverlayFeedController::class, static fn( Container $c ): GlobalOverlayFeedController => new GlobalOverlayFeedController( $c->get( GlobalOverlayFeed::class ), new OverlayRateLimiter(), $c->get( Logger::class ) ) );
		$container->set( GlobalOverlayRoute::class, static fn( Container $c ): GlobalOverlayRoute => new GlobalOverlayRoute( ! $c->get( AlertsAvailability::class )->merchalert_active() ) );
		$container->set( GlobalOverlayPage::class, static fn( Container $c ): GlobalOverlayPage => new GlobalOverlayPage( $c->get( GlobalOverlayRoute::class ), $c->get( GlobalOverlayFeed::class ), $c->get( OverlayPage::class ), $c->get( Logger::class ), $c->get( GlobalOverlayImages::class ) ) );
		$container->set(
			GlobalOverlayTab::class,
			static function ( Container $c ): GlobalOverlayTab {
				$root = dirname( __DIR__, 3 ) . '/';

				return new GlobalOverlayTab( $root, plugin_dir_url( $root . 'merchboard.php' ), $c->get( Logger::class ) );
			}
		);

		add_action(
			'init',
			static function () use ( $container ): void {
				foreach ( array( $container->get( OverlayRoute::class ), $container->get( GlobalOverlayRoute::class ) ) as $route ) {
					$route->add_rewrite_rules();
					$route->ensure_rewrite_rules();
				}
			}
		);
		add_filter( 'query_vars', static fn( mixed $vars ): mixed => $container->get( GlobalOverlayRoute::class )->add_query_vars( $container->get( OverlayRoute::class )->add_query_vars( $vars ) ) );
		// Before redirect_canonical() (priority 10) and before any theme output.
		add_action(
			'template_redirect',
			static function () use ( $container ): void {
				$container->get( OverlayPage::class )->handle_request();
				$container->get( GlobalOverlayPage::class )->handle_request();
			},
			0
		);
		// The public pages of the YouTube chatbot's privacy policy and terms, before the dashboard (priority 0) requires a login.
		add_action(
			'template_redirect',
			static function () use ( $container ): void {
				$container->get( LegalPage::class )->handle_request();
			},
			-1
		);
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( OverlayFeedController::class )->register_routes();
				$container->get( GlobalOverlayFeedController::class )->register_routes();
				$container->get( AdminGlobalOverlayController::class )->register_routes();
				$container->get( MeAlertsController::class )->register_routes();
				$container->get( MeAlertsHistoryController::class )->register_routes();
				$container->get( MeChatbotController::class )->register_routes();
				$container->get( TwitchController::class )->register_routes();
				$container->get( KickController::class )->register_routes();
				$container->get( YouTubeController::class )->register_routes();
				$container->get( ChatbotChatController::class )->register_routes();
			}
		);

		// Every request: orders are paid in checkout, wp-admin, REST, WP-CLI
		// and by payment gateways' callbacks.
		$container->get( AlertHooks::class )->register();
		// The optional username in the checkout (step 25.9.1); each hook checks the settings when it runs.
		( new CheckoutUsernameField( static fn( int $product_id ): ?int => $container->get( ProductCreatorLink::class )->creator_id( $product_id ) ) )->register();

		// Chat messages: queued after the alerts are recorded (priority 10), sent by Action Scheduler.
		foreach ( array( 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $status ) {
			add_action(
				$status,
				static function ( mixed $order_id ) use ( $container ): void {
					$container->get( ChatSender::class )->order_paid( $order_id );
				},
				20
			);
		}

		add_action(
			ChatSender::HOOK,
			static function ( mixed $creator_id, mixed $order_id, mixed $platform = 'twitch' ) use ( $container ): void {
				$container->get( ChatSender::class )->run( $creator_id, $order_id, $platform );
			},
			10,
			3
		);

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
				$container->get( ChatbotAdminActions::class )->register();
				$container->get( AlertsSettingsSection::class )->register();
				( new CompanyDetailsSection() )->register();
				$container->get( CreatorSoundLimitsActions::class )->register();
				$container->get( CreatorSoundLimitsSection::class )->register();
			}
		);
	}

	/**
	 * The creators' Google tokens (ADR-070 pkt. 4): renewed and revoked at
	 * Google.
	 *
	 * @param Container $c Service container.
	 */
	private static function youtube_tokens( Container $c ): CreatorTokens {
		$account = $c->get( YouTubeAccount::class );
		$api     = $c->get( YouTubeApi::class );

		return new CreatorTokens(
			ChatPlatform::YouTube,
			new ChatCipher( ChatPlatform::YouTube ),
			$c->get( ChatbotChannelRepository::class ),
			static fn( string $refresh ): array => $api->refresh( $account->client_id(), $account->require_secret(), $refresh ),
			static function ( string $token ) use ( $api ): void {
				$api->revoke( $token );
			}
		);
	}

	/**
	 * The creators' Kick tokens (ADR-069 pkt. 5): renewed and revoked at Kick.
	 *
	 * @param Container $c Service container.
	 */
	private static function kick_tokens( Container $c ): CreatorTokens {
		$account = $c->get( KickAccount::class );
		$api     = $c->get( KickApi::class );

		return new CreatorTokens(
			ChatPlatform::Kick,
			new ChatCipher( ChatPlatform::Kick ),
			$c->get( ChatbotChannelRepository::class ),
			static fn( string $refresh ): array => $api->refresh( $account->client_id(), $account->require_secret(), $refresh ),
			static function ( string $token, string $hint ) use ( $api ): void {
				$api->revoke( $token, $hint );
			}
		);
	}
}
