<?php
/**
 * Community Features: the Twitch subscriber discount (phase 43) and Twitch
 * gifting (phase 44).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotException;
use MerchBoard\Modules\Alerts\Chatbot\ChatSender;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerLogin;
use MerchBoard\Modules\Alerts\Chatbot\TwitchLiveStatus;
use MerchBoard\Modules\Alerts\Rest\ShopTwitchController;
use MerchBoard\Modules\Community\Admin\GiftingTestModeSection;
use MerchBoard\Modules\Community\Dashboard\CommunityDashboardSection;
use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Community\Gifting\GiftClaim;
use MerchBoard\Modules\Community\Gifting\GiftCodes;
use MerchBoard\Modules\Community\Gifting\GiftCreatorCleanup;
use MerchBoard\Modules\Community\Gifting\GiftDraws;
use MerchBoard\Modules\Community\Gifting\GiftingSettingsRepository;
use MerchBoard\Modules\Community\Gifting\GiftNotices;
use MerchBoard\Modules\Community\Gifting\GiftOrderInfo;
use MerchBoard\Modules\Community\Gifting\GiftPrivacy;
use MerchBoard\Modules\Community\Gifting\GiftPurchase;
use MerchBoard\Modules\Community\Gifting\GiftRepository;
use MerchBoard\Modules\Community\Rest\MeTwitchGiftingController;
use MerchBoard\Modules\Community\Rest\MeTwitchSubDiscountController;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCheckout;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCoupon;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscount;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountRepository;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubPrivacy;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubOrderInfo;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubShopCard;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponCode;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfitDiscount;
use MerchBoard\Modules\WooCommerce\JobQueue;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use WC_Cart;
use WC_Product;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The creators' Community Features (ROADMAP "Community Features"; ADR-106):
 * a discount for the creator's Twitch subscribers, given in the shop's cart
 * after the customer's Twitch login (Alerts module) and paid from the
 * creator's profit (the WooCommerce module's ledger), and gifts bought in the
 * shop and drawn in the creator's Twitch chat (ADR-107). Needs WooCommerce and
 * the Alerts module, so it is registered after them.
 */
final class CommunityModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'community';
	}

	/**
	 * Registers services and hooks.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set(
			TwitchSubDiscountRepository::class,
			static fn( Container $c ): TwitchSubDiscountRepository => new TwitchSubDiscountRepository(
				$c->get( CreatorMetaStore::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				static fn( int $creator_id, int $product_id ): bool => $c->get( CreatorProducts::class )->owns( $creator_id, $product_id ),
				static fn( int $creator_id ): bool => $c->get( ChatbotChannelRepository::class )->find( $creator_id, ChatPlatform::Twitch )->connected(),
				static fn( int $creator_id, ?array $product_ids ): array => self::profit_percents( $c, $creator_id, $product_ids )
			)
		);
		$container->set(
			TwitchSubDiscount::class,
			static fn( Container $c ): TwitchSubDiscount => new TwitchSubDiscount(
				$c->get( TwitchSubDiscountRepository::class ),
				$c->get( TwitchCustomerLogin::class ),
				static fn( int $product_id ): ?int => $c->get( ProductCreatorLink::class )->creator_id( $product_id ),
				static function ( int $creator_id ) use ( $c ): ?string {
					$channel = $c->get( ChatbotChannelRepository::class )->find( $creator_id, ChatPlatform::Twitch );

					return $channel->connected() ? $channel->broadcaster_id : null;
				},
				static fn(): array => self::coded_creators( $c ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			TwitchSubCoupon::class,
			static fn( Container $c ): TwitchSubCoupon => new TwitchSubCoupon( $c->get( TwitchSubDiscount::class ), $c->get( CreatorProfitDiscount::class ), $c->get( TwitchCustomerLogin::class ), $c->get( Logger::class ) )
		);
		$container->set( TwitchSubCheckout::class, static fn( Container $c ): TwitchSubCheckout => new TwitchSubCheckout( $c->get( TwitchSubDiscount::class ) ) );

		self::register_gifting( $container );

		$container->set(
			MeTwitchSubDiscountController::class,
			static fn( Container $c ): MeTwitchSubDiscountController => new MeTwitchSubDiscountController(
				$c->get( CreatorContext::class ),
				$c->get( TwitchSubDiscountRepository::class ),
				static function ( int $creator_id ) use ( $c ): ?string {
					$channel = $c->get( ChatbotChannelRepository::class )->find( $creator_id, ChatPlatform::Twitch );

					return $channel->connected() ? $channel->broadcaster_login : null;
				},
				static fn(): bool => $c->get( TwitchAccount::class )->ready(),
				$c->get( Logger::class )
			)
		);

		// Every request (carts are priced on the front end, in the Store API and in AJAX), once
		// WordPress is loaded: the services need its salts, and carts are read later (wp_loaded).
		add_action(
			'init',
			static function () use ( $container ): void {
				$container->get( TwitchSubCoupon::class )->register();
				$container->get( TwitchSubCheckout::class )->register();
				// The Twitch login card in the cart and the checkout (its hooks only fire on the front end).
				( new TwitchSubShopCard( $container->get( TwitchSubDiscount::class ), $container->get( TwitchCustomerLogin::class ), $container->get( TwitchCustomerSession::class ) ) )->register();
				// Gifting (ADR-107): the purchase, the draws in the chat and jobs, the code and the claim link.
				$container->get( GiftPurchase::class )->register();
				$container->get( GiftDraws::class )->register();
				$container->get( GiftCodes::class )->register();
				$container->get( GiftClaim::class )->register();
			},
			5
		);
		( new TwitchSubPrivacy() )->register();
		// Gifts (ADR-107): who hears about them, the order page, privacy and creator deletion.
		( new GiftNotices(
			static fn( int $creator_id ): string => $container->get( CreatorRepository::class )->find( $creator_id )->display_name ?? '',
			self::product_name( ... ),
			self::product_url( ... ),
			$container->get( Logger::class )
		) )->register();
		( new GiftOrderInfo( $container->get( GiftRepository::class ) ) )->register();
		( new GiftPrivacy( $container->get( GiftRepository::class ) ) )->register();
		add_filter(
			'merchboard_creator_data_cleaners',
			static fn( mixed $cleaners ): array => array_merge( is_array( $cleaners ) ? $cleaners : array(), array( new GiftCreatorCleanup( $container->get( GiftRepository::class ), $container->get( GiftDraws::class ) ) ) ),
			30
		);
		// The customer's Twitch account and tiers on the administrator's order page.
		( new TwitchSubOrderInfo( static fn( int $creator_id ): string => $container->get( CreatorRepository::class )->find( $creator_id )->display_name ?? '' ) )->register();
		( new CommunityDashboardSection() )->register();
		// The test mode of a creator's gifting, on the creator's edit page (administrators).
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				( new GiftingTestModeSection( $container->get( GiftingSettingsRepository::class ), $container->get( CreatorRepository::class ), $container->get( Logger::class ) ) )->register();
			}
		);

		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( MeTwitchSubDiscountController::class )->register_routes();
				$container->get( MeTwitchGiftingController::class )->register_routes();
			}
		);
	}

	/**
	 * Registers the gifting services (ADR-107).
	 *
	 * @param Container $container Shared service container.
	 */
	private static function register_gifting( Container $container ): void {
		$container->set( GiftRepository::class, static fn( Container $c ): GiftRepository => new GiftRepository( $c->get( wpdb::class ), $c->get( Transaction::class ) ) );
		$container->set(
			GiftingSettingsRepository::class,
			static fn( Container $c ): GiftingSettingsRepository => new GiftingSettingsRepository(
				$c->get( CreatorMetaStore::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				static fn( int $creator_id ): bool => null !== self::broadcaster( $c, $creator_id )
			)
		);
		$container->set( GiftCodes::class, static fn( Container $c ): GiftCodes => new GiftCodes( $c->get( GiftRepository::class ), $c->get( Logger::class ) ) );
		$container->set(
			GiftDraws::class,
			static fn( Container $c ): GiftDraws => new GiftDraws(
				$c->get( GiftRepository::class ),
				$c->get( GiftingSettingsRepository::class ),
				$c->get( GiftCodes::class ),
				static fn( int $creator_id, string $message ): bool => self::send( $c, $creator_id, $message ),
				static fn( int $creator_id ): bool => true === self::live( $c, $creator_id ),
				static fn( string $token ): string => ShopTwitchController::target_url( 'claim:' . $token ),
				self::product_name( ... ),
				static fn( string $hook, array $args, int $delay ): bool => $c->get( JobQueue::class )->enqueue( $hook, $args, $delay ),
				self::notify( ... ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			GiftPurchase::class,
			static fn( Container $c ): GiftPurchase => new GiftPurchase(
				$c->get( GiftingSettingsRepository::class ),
				$c->get( GiftRepository::class ),
				static fn( int $product_id ): ?int => $c->get( ProductCreatorLink::class )->creator_id( $product_id ),
				static fn( int $creator_id ): ?bool => self::live( $c, $creator_id ),
				static function ( Gift $gift ) use ( $c ): void {
					$c->get( GiftDraws::class )->paid( $gift );
				},
				$c->get( Logger::class )
			)
		);
		$container->set(
			MeTwitchGiftingController::class,
			static fn( Container $c ): MeTwitchGiftingController => new MeTwitchGiftingController(
				$c->get( CreatorContext::class ),
				$c->get( GiftingSettingsRepository::class ),
				$c->get( GiftRepository::class ),
				$c->get( GiftDraws::class ),
				static function ( int $creator_id ) use ( $c ): ?string {
					try {
						$channel = $c->get( ChatbotChannelRepository::class )->find( $creator_id, ChatPlatform::Twitch );
					} catch ( DatabaseException ) {
						return null;
					}

					return $channel->connected() ? $channel->broadcaster_login : null;
				},
				static fn(): bool => $c->get( TwitchAccount::class )->ready(),
				static fn( int $creator_id ): ?bool => self::live( $c, $creator_id ),
				self::product_name( ... ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			GiftClaim::class,
			static fn( Container $c ): GiftClaim => new GiftClaim(
				$c->get( GiftRepository::class ),
				$c->get( GiftCodes::class ),
				$c->get( TwitchCustomerSession::class ),
				static fn( int $product_id ): array => array(
					'name'  => self::product_name( $product_id ),
					'url'   => self::product_url( $product_id ),
					'image' => (string) get_the_post_thumbnail_url( $product_id, 'medium' ),
				),
				self::notify( ... ),
				$c->get( Logger::class )
			)
		);
	}

	/**
	 * Tells about a gift (`bought`, `won`, `claimed`, `returned`): the
	 * notifications and e-mails listen to `merchboard_gift_event`.
	 *
	 * @param string                $event Event.
	 * @param Gift                  $gift  The gift.
	 * @param array<string, string> $data  Event data (winner, code).
	 */
	private static function notify( string $event, Gift $gift, array $data ): void {
		// GiftDraws::EVENT_HOOK.
		do_action( 'merchboard_gift_event', $event, $gift, $data );
	}

	/**
	 * A product's page (the home page when it is gone).
	 *
	 * @param int $product_id Product.
	 */
	private static function product_url( int $product_id ): string {
		$url = get_permalink( $product_id );

		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * A product's name ('' when it is gone).
	 *
	 * @param int $product_id Product.
	 */
	private static function product_name( int $product_id ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		return $product instanceof WC_Product ? (string) $product->get_name() : '';
	}

	/**
	 * A creator's connected Twitch channel id, or null.
	 *
	 * @param Container $c          Container.
	 * @param int       $creator_id Creator.
	 */
	private static function broadcaster( Container $c, int $creator_id ): ?string {
		try {
			$channel = $c->get( ChatbotChannelRepository::class )->find( $creator_id, ChatPlatform::Twitch );
		} catch ( DatabaseException ) {
			return null;
		}

		return $channel->connected() ? $channel->broadcaster_id : null;
	}

	/**
	 * Whether a creator is live on Twitch; null without a connected channel.
	 * In the test mode (set by an administrator) the creator counts as live.
	 *
	 * @param Container $c          Container.
	 * @param int       $creator_id Creator.
	 */
	private static function live( Container $c, int $creator_id ): ?bool {
		$broadcaster = self::broadcaster( $c, $creator_id );

		if ( null === $broadcaster ) {
			return null;
		}

		return $c->get( GiftingSettingsRepository::class )->find( $creator_id )->test_mode || $c->get( TwitchLiveStatus::class )->live( $broadcaster );
	}

	/**
	 * Sends a chat message in a creator's Twitch channel now (the gift
	 * messages go out even when the creator's order messages are off).
	 *
	 * @param Container $c          Container.
	 * @param int       $creator_id Creator.
	 * @param string    $message    Message.
	 * @return bool Whether Twitch took it.
	 */
	private static function send( Container $c, int $creator_id, string $message ): bool {
		try {
			$channel = $c->get( ChatbotChannelRepository::class )->find( $creator_id, ChatPlatform::Twitch );

			return 'sent' === $c->get( ChatSender::class )->send_now( $channel, $message )['result'];
		} catch ( ChatbotException | DatabaseException $e ) {
			$c->get( Logger::class )->error(
				'A gift message could not be sent.',
				array(
					'creator_id' => $creator_id,
					'reason'     => $e instanceof ChatbotException ? $e->reason() : $e->getMessage(),
				)
			);

			return false;
		}
	}

	/**
	 * The creator's profit percent in basis points (rounded down) per parent product:
	 * the creator's profit on a unit priced 100 (the share, or a designer
	 * product's profit ÷ list price), as the cap per line uses it. Products
	 * that are not the creator's are left out.
	 *
	 * @param Container      $c           Container.
	 * @param int            $creator_id  Creator.
	 * @param list<int>|null $product_ids Products; null for all the creator's.
	 * @return array<int, int>
	 */
	private static function profit_percents( Container $c, int $creator_id, ?array $product_ids ): array {
		if ( null === $product_ids ) {
			$products    = $c->get( CreatorProducts::class );
			$product_ids = array();
			$pages       = (int) ceil( $products->count( $creator_id ) / Pagination::MAX_PER_PAGE );

			for ( $page = 1; $page <= $pages; $page++ ) {
				array_push( $product_ids, ...$products->ids( $creator_id, new Pagination( $page, Pagination::MAX_PER_PAGE ) ) );
			}
		}

		$percents = array();

		foreach ( $product_ids as $product_id ) {
			$profit = $c->get( CreatorProfitDiscount::class )->per_unit( $product_id, $creator_id, 100.0 );

			if ( null !== $profit ) {
				$percents[ $product_id ] = (int) floor( $profit * 100 + 1e-6 );
			}
		}

		return $percents;
	}

	/**
	 * The creators whose own creator code is in the cart (ADR-068).
	 *
	 * @param Container $c Container.
	 * @return list<int>
	 */
	private static function coded_creators( Container $c ): array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart instanceof WC_Cart ) {
			return array();
		}

		static $known = array();
		$creators     = array();

		foreach ( $cart->get_applied_coupons() as $code ) {
			$code = CouponCode::normalize( (string) $code );

			if ( ! array_key_exists( $code, $known ) ) {
				$known[ $code ] = $c->get( CreatorCodeRepository::class )->active_for_code( $code )?->creator_id;
			}

			if ( null !== $known[ $code ] ) {
				$creators[ $known[ $code ] ] = $known[ $code ];
			}
		}

		return array_values( $creators );
	}
}
