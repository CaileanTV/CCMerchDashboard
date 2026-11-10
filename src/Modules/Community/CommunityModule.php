<?php
/**
 * Community Features: the Twitch subscriber discount (phase 43).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerLogin;
use MerchBoard\Modules\Community\Dashboard\CommunityDashboardSection;
use MerchBoard\Modules\Community\Rest\MeTwitchSubDiscountController;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCheckout;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCoupon;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscount;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountRepository;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubPrivacy;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponCode;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfitDiscount;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use WC_Cart;

defined( 'ABSPATH' ) || exit;

/**
 * The creators' Community Features (ROADMAP "Community Features"; ADR-106):
 * a discount for the creator's Twitch subscribers, given in the shop's cart
 * after the customer's Twitch login (Alerts module) and paid from the
 * creator's profit (the WooCommerce module's ledger). Needs WooCommerce and
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

		// Every request: carts are priced on the front end, in the Store API and in AJAX.
		$container->get( TwitchSubCoupon::class )->register();
		$container->get( TwitchSubCheckout::class )->register();
		( new TwitchSubPrivacy() )->register();
		( new CommunityDashboardSection() )->register();

		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( MeTwitchSubDiscountController::class )->register_routes();
			}
		);
	}

	/**
	 * The creator's profit percent (whole, rounded down) per parent product:
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
				$percents[ $product_id ] = (int) floor( $profit + 1e-9 );
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
