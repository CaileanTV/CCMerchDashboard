<?php
/**
 * The winner claims a gift.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\CommunityLink;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\Alerts\Rest\ShopTwitchController;
use Throwable;
use WC_Cart;
use WC_Session;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 6 (with the status note of 2026-11-11): the bot's link
 * (`?merchboard_gift_claim=<token>`) opens a page with the prize, the winner
 * and the deadline, and a button "Log in with Twitch". The button
 * (`&merchboard_gift_redeem=1`) asks for the winner's Twitch login (ADR-106),
 * comes back to itself and only gives the gift code to the Twitch account
 * that won. The code is created then; the winner is sent to the product, and
 * the code is applied as soon as the product is in the cart. A winner who
 * comes back sees the code again.
 */
final class GiftClaim {

	/**
	 * Session key of a code to apply.
	 */
	private const SESSION_KEY = 'merchboard_gift_code';

	/**
	 * A product's name, page and image.
	 *
	 * @var Closure(int): array{name: string, url: string, image: string}
	 */
	private Closure $product;

	/**
	 * Tells about a gift.
	 *
	 * @var Closure(string, Gift, array<string, string>): void
	 */
	private Closure $notify;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): array{name: string, url: string, image: string} $product
	 * @phpstan-param Closure(string, Gift, array<string, string>): void $notify
	 *
	 * @param GiftRepository         $gifts   Gifts.
	 * @param GiftCodes              $codes   Gift codes.
	 * @param TwitchCustomerSession  $session The customer's Twitch login.
	 * @param Closure                $product A product's name, page and image.
	 * @param Closure                $notify  Tells the creator and the buyer.
	 * @param Logger                 $logger  Technical log.
	 * @param FreeChoiceDesigns|null $designs What a winner may choose with free choice (ADR-120).
	 */
	public function __construct( private GiftRepository $gifts, private GiftCodes $codes, private TwitchCustomerSession $session, Closure $product, Closure $notify, private Logger $logger, private ?FreeChoiceDesigns $designs = null ) {
		$this->product = $product;
		$this->notify  = $notify;
	}

	/**
	 * Hooks the claim link and the code into the shop.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'claim' ), 5 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'apply' ), 20 );
	}

	/**
	 * `template_redirect`: the claim page, or the button behind it.
	 */
	public function claim(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The token is the secret; Twitch login proves the winner.
		// Under the chosen name, an earlier one or the original one (links already passed on).
		$token  = CommunityLink::token( wp_unslash( $_GET ) );
		$redeem = isset( $_GET[ ShopTwitchController::REDEEM_QUERY ] );
		// phpcs:enable

		if ( '' === $token ) {
			return;
		}

		if ( $redeem ) {
			try {
				$location = $this->redeem( $token );
			} catch ( DatabaseException $e ) {
				$this->logger->error( 'A gift could not be claimed.', $e->context() );
				$location = ShopTwitchController::target_url( 'claim:' . $token );
			}

			wp_safe_redirect( $location );
			exit;
		}

		try {
			$html = $this->render( $this->view( $token ) );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A gift page could not be shown.', $e->context() );
			$html = $this->render( array( 'state' => 'error' ) + $this->view_base() );
		}

		foreach ( self::headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes every value.
		exit;
	}

	/**
	 * `woocommerce_add_to_cart`: applies a claimed gift code once its
	 * product is in the cart.
	 */
	public function apply(): void {
		$cart    = function_exists( 'WC' ) ? WC()->cart : null;
		$session = self::session();
		$code    = null === $session ? null : $session->get( self::SESSION_KEY );

		if ( null === $session || ! $cart instanceof WC_Cart || ! is_string( $code ) || '' === $code || in_array( $code, $cart->get_applied_coupons(), true ) || null !== GiftPurchase::gift_item( $cart ) ) {
			return;
		}

		if ( $cart->apply_coupon( $code ) ) {
			$session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * The headers of the claim page: no caching, no index and no referrer
	 * (the token is in the address).
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return array(
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' https: data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
			'Referrer-Policy'         => 'no-referrer',
			'X-Content-Type-Options'  => 'nosniff',
			'X-Robots-Tag'            => 'noindex, nofollow',
		);
	}

	/**
	 * What the claim page shows. States: `invalid` (unknown, too late, given
	 * back), `login` (log in with Twitch), `redeem` (logged in as the winner),
	 * `other` (logged in with another Twitch account) and `error`.
	 *
	 * @param string $token Claim token.
	 * @return array<string, mixed>
	 * @throws DatabaseException When the gift cannot be read.
	 */
	public function view( string $token ): array {
		$base = $this->view_base();
		$gift = $this->gifts->find_by_token( $token );

		if ( null === $gift || ( ! self::claimed( $gift ) && ! self::open( $gift ) ) ) {
			return array( 'state' => 'invalid' ) + $base;
		}

		$lock    = $gift->free_choice;
		$product = ( $this->product )( $gift->product_id );
		$image   = null === $lock ? '' : ( $this->product )( $lock->collection_id )['image'];
		$view    = array(
			'product'  => $lock->name ?? $product['name'],
			'image'    => '' === $image ? $product['image'] : $image,
			'free'     => null !== $lock,
			'winner'   => (string) $gift->winner_login,
			'claimed'  => self::claimed( $gift ),
			'deadline' => null === $gift->claim_deadline ? '' : (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) strtotime( $gift->claim_deadline . ' UTC' ) ),
			'redeem'   => ShopTwitchController::target_url( 'redeem:' . $token ),
		) + $base;

		// A giveaway's link (ADR-110): whoever has it redeems it, without Twitch.
		if ( self::link( $gift ) ) {
			return array( 'state' => 'link' ) + $view;
		}

		$account = $this->session->account();

		if ( null === $account || null === $this->session->token() ) {
			return array( 'state' => 'login' ) + $view;
		}

		if ( $account['id'] !== $gift->winner_twitch_id ) {
			return array(
				'state'   => 'other',
				'account' => $account['login'],
				'logout'  => ShopTwitchController::link( 'logout', 'claim:' . $token ),
			) + $view;
		}

		return array( 'state' => 'redeem' ) + $view;
	}

	/**
	 * The claim page.
	 *
	 * @param array<string, mixed> $merchboard_view View data.
	 * @throws Throwable When the template fails (after its output is discarded).
	 */
	public function render( array $merchboard_view ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Read by the template.
		ob_start();

		try {
			require dirname( __DIR__, 4 ) . '/templates/community/gift-claim.php';

			return (string) ob_get_clean();
		} catch ( Throwable $e ) {
			ob_end_clean();

			throw $e;
		}
	}

	/**
	 * Where the button leads: Twitch login (back here), the claim page when
	 * something is wrong, or the product with the code.
	 *
	 * @param string $token Claim token.
	 * @throws DatabaseException When the gift cannot be read or stored.
	 */
	public function redeem( string $token ): string {
		$page = ShopTwitchController::target_url( 'claim:' . $token );
		$gift = $this->gifts->find_by_token( $token );

		if ( null === $gift || ( ! self::claimed( $gift ) && ! self::open( $gift ) ) ) {
			return $page;
		}

		// A giveaway's link (ADR-110) needs no Twitch login.
		if ( ! self::link( $gift ) ) {
			$account = $this->session->account();

			if ( null === $account || null === $this->session->token() ) {
				// Twitch login first; it comes back to this button.
				return ShopTwitchController::link( 'login', 'redeem:' . $token );
			}

			if ( $account['id'] !== $gift->winner_twitch_id ) {
				// The page tells who is logged in and offers to log out.
				return $page;
			}
		}

		if ( self::claimed( $gift ) ) {
			// The winner came back: the same code again.
			$code = $this->codes->code( (int) $gift->coupon_id );

			if ( '' === $code ) {
				return $page;
			}
		} else {
			$coupon = $this->codes->create( $gift );

			if ( ! $this->gifts->claim( $gift->id, $coupon['id'] ) ) {
				wp_delete_post( $coupon['id'], true );

				return $page;
			}

			$code = $coupon['code'];
			( $this->notify )( 'claimed', $gift, array( 'code' => $code ) );
		}

		self::session()?->set( self::SESSION_KEY, $code );

		if ( null !== $gift->free_choice && null !== $this->designs ) {
			return $this->to_choice( $gift, $gift->free_choice, $this->designs, $code );
		}

		$product = ( $this->product )( $gift->product_id );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: the product given, 2: the gift code. */
					__( 'Congratulations! Your gift is %1$s. Choose size and colour and add it to the cart; your gift code %2$s is used there and is only for that design. You pay only if you choose a dearer variant or shipping.', 'merchboard' ),
					esc_html( $product['name'] ),
					$code
				),
				'success'
			);
		}

		return $product['url'];
	}

	/**
	 * Free choice (ADR-120): the winner hears which designs the code is for,
	 * each with its link, and is sent to the collection, or to the first of
	 * them when the collection's page is gone.
	 *
	 * @param Gift              $gift    The gift.
	 * @param FreeChoiceLock    $lock    What was locked at the purchase.
	 * @param FreeChoiceDesigns $designs What a winner may choose.
	 * @param string            $code    The gift code.
	 * @return string Where the winner is sent.
	 */
	private function to_choice( Gift $gift, FreeChoiceLock $lock, FreeChoiceDesigns $designs, string $code ): string {
		$links = array();
		$url   = null;

		foreach ( $designs->to_choose( $lock ) as $id ) {
			$design  = ( $this->product )( $id );
			$url   ??= $design['url'];
			$links[] = '<a href="' . esc_url( $design['url'] ) . '">' . esc_html( $design['name'] ) . '</a>';
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: the collection's name, 2: the designs' names as links, 3: the gift code. */
					__( 'Congratulations! Your gift is free choice in %1$s. You can choose between: %2$s. Choose design, size and colour and add it to the cart; your gift code %3$s is used there and is only for these designs. You pay only if you choose a dearer variant or shipping.', 'merchboard' ),
					esc_html( $lock->name ),
					implode( ', ', $links ),
					$code
				),
				'success'
			);
		}

		if ( $designs->has_page( $lock ) ) {
			$url = ( $this->product )( $lock->collection_id )['url'];
		}

		return $url ?? ( $this->product )( $gift->product_id )['url'];
	}

	/**
	 * The page's fixed parts: the site and the shop.
	 *
	 * @return array{site: string, shop: string}
	 */
	private function view_base(): array {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';

		return array(
			'site' => (string) get_bloginfo( 'name' ),
			'shop' => '' === $shop ? home_url( '/' ) : $shop,
		);
	}

	/**
	 * Whether the gift was claimed and has its code.
	 *
	 * @param Gift $gift Gift.
	 */
	private static function claimed( Gift $gift ): bool {
		return Gift::CLAIMED === $gift->status && null !== $gift->coupon_id;
	}

	/**
	 * Whether the gift was won and can still be claimed.
	 *
	 * @param Gift $gift Gift.
	 */
	private static function open( Gift $gift ): bool {
		return Gift::WON === $gift->status && ( null === $gift->claim_deadline || $gift->claim_deadline >= GiftRepository::now() );
	}

	/**
	 * Whether the gift is a giveaway's link (ADR-110), redeemed by whoever has
	 * it: a giveaway's gift without a Twitch winner.
	 *
	 * @param Gift $gift Gift.
	 */
	private static function link( Gift $gift ): bool {
		return $gift->giveaway() && null === $gift->winner_twitch_id;
	}

	/**
	 * The customer's WooCommerce session, or null.
	 */
	private static function session(): ?WC_Session {
		$session = function_exists( 'WC' ) ? WC()->session : null;

		return $session instanceof WC_Session ? $session : null;
	}
}
