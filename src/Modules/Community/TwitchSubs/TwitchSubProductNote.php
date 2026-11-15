<?php
/**
 * The Twitch subscriber discount under a product's price.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use MerchBoard\Core\Database\DatabaseException;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-111 pkt. 3: on a product page whose product has an active campaign,
 * a line under the price tells the discount per tier, highest first, and
 * that it is taken off in the cart after a Twitch login (like Fourthwall).
 *
 * The line follows the price of the page's product: after WooCommerce's
 * price template (classic themes, and page builders' price widgets that use
 * it) and after the price block (block themes). The shortcode
 * [merchboard_twitch_discount_line] places it by hand where a page builder
 * renders the price its own way. Only the product of the page gets it
 * (never related products or lists), once per request. It is not added to
 * the price HTML itself: WooCommerce also puts that in the Store API data
 * it preloads in the page.
 */
final class TwitchSubProductNote {

	/**
	 * The shortcode.
	 */
	public const SHORTCODE = 'merchboard_twitch_discount_line';

	/**
	 * Whether the line was shown in this request.
	 *
	 * @var bool
	 */
	private bool $shown = false;

	/**
	 * Constructor.
	 *
	 * @param TwitchSubDiscount $discount The campaigns on offer.
	 */
	public function __construct( private TwitchSubDiscount $discount ) {
	}

	/**
	 * Hooks the line in after the price.
	 */
	public function register(): void {
		add_action( 'woocommerce_after_template_part', array( $this, 'after_template' ), 10, 1 );
		add_filter( 'render_block_woocommerce/product-price', array( $this, 'after_block' ), 10, 3 );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * `woocommerce_after_template_part`: after the single product's price.
	 *
	 * @param mixed $template_name Template.
	 */
	public function after_template( mixed $template_name ): void {
		if ( is_string( $template_name ) && 'single-product/price.php' === ltrim( $template_name, '/' ) ) {
			echo $this->html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in html().
		}
	}

	/**
	 * `render_block_woocommerce/product-price`: after the price block of the
	 * page's product (not a product list's block).
	 *
	 * @param mixed $content  Block HTML.
	 * @param mixed $block    Parsed block.
	 * @param mixed $instance Block instance.
	 * @return mixed
	 */
	public function after_block( mixed $content, mixed $block = null, mixed $instance = null ): mixed {
		$post_id = $instance instanceof WP_Block ? (int) ( $instance->context['postId'] ?? 0 ) : 0;

		if ( ! is_string( $content ) || ( $post_id > 0 && (int) get_queried_object_id() !== $post_id ) ) {
			return $content;
		}

		return $content . $this->html();
	}

	/**
	 * The shortcode.
	 */
	public function shortcode(): string {
		return $this->html();
	}

	/**
	 * The line as HTML on the page's product, once; '' otherwise.
	 */
	public function html(): string {
		if ( $this->shown || ! function_exists( 'is_product' ) || ! is_product() ) {
			return '';
		}

		$line = $this->line( (int) get_queried_object_id() );

		if ( '' === $line ) {
			return '';
		}

		$this->shown = true;

		return '<p class="merchboard-sub-note" style="margin:.35rem 0 .75rem;font-size:.9em;line-height:1.45;opacity:.85">' . esc_html( $line ) . '</p>';
	}

	/**
	 * The line for a parent product, or '' when it has no campaign today.
	 *
	 * @param int $product_id Parent product.
	 */
	public function line( int $product_id ): string {
		try {
			$offer = $this->discount->offer( $product_id );
		} catch ( DatabaseException ) {
			return '';
		}

		if ( null === $offer ) {
			return '';
		}

		$parts = array();

		foreach ( array( 3, 2, 1 ) as $tier ) {
			$bps = $offer['campaign']->bps( $tier );

			if ( $bps > 0 ) {
				/* translators: 1: discount in percent (e.g. 15 or 16,67), 2: subscriber tier (1–3). */
				$parts[] = sprintf( __( '%1$s %% for Tier %2$d', 'merchboard' ), TwitchSubCampaign::percent_label( $bps ), $tier );
			}
		}

		if ( array() === $parts ) {
			return '';
		}

		return sprintf(
			/* translators: %s: the discounts per tier, e.g. "15 % for Tier 3, 10 % for Tier 2 and 5 % for Tier 1". */
			__( 'Twitch subs get a discount on this product: %s.', 'merchboard' ),
			wp_sprintf( '%l', $parts )
		) . ' ' . __( 'Log in with Twitch in the cart, and the discount is taken off automatically.', 'merchboard' );
	}
}
