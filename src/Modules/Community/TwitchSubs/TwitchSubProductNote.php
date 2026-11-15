<?php
/**
 * The Twitch subscriber discount under a product's price.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use MerchBoard\Core\Database\DatabaseException;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-111 pkt. 3: on a product page whose product has an active campaign,
 * a line under the price tells the discount per tier, highest first, and
 * that it is taken off in the cart after a Twitch login (like Fourthwall).
 *
 * The line is added to the main product's price HTML, so it also shows when
 * the page is built with a page builder's price widget. Only the product of
 * the page gets it (never related products or lists), once per request.
 */
final class TwitchSubProductNote {

	/**
	 * Whether the line was added in this request.
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
	 * Hooks the line into the price.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 20, 2 );
	}

	/**
	 * `woocommerce_get_price_html`.
	 *
	 * @param mixed $html    Price HTML.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public function price_html( mixed $html, mixed $product ): mixed {
		if ( $this->shown || ! is_string( $html ) || ! $product instanceof WC_Product || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $html;
		}

		$parent = (int) $product->get_parent_id() > 0 ? (int) $product->get_parent_id() : (int) $product->get_id();

		if ( (int) get_queried_object_id() !== $parent ) {
			return $html;
		}

		$line = $this->line( $parent );

		if ( '' === $line ) {
			return $html;
		}

		$this->shown = true;

		return $html . '<span class="merchboard-sub-note" style="display:block;margin-top:.35rem;font-size:.85em;line-height:1.45;opacity:.85">' . esc_html( $line ) . '</span>';
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
