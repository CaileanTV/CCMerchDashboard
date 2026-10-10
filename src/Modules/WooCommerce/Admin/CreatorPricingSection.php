<?php
/**
 * "Pricing" section on the creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorShare;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\EffectiveMarkup;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\PricingException;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Pricing\PricingUnitRecord;
use MerchBoard\Modules\WooCommerce\ProductRecord;
use MerchBoard\Modules\WooCommerce\ProductStore;

defined( 'ABSPATH' ) || exit;

/**
 * Admin pricing of one creator (ADR-048, ADR-049): the creator's markup
 * (share of the WooCommerce sale price), the VAT status, and every product
 * the creator owns with the share that applies to it, its current
 * WooCommerce prices (read only) and an example of profit and payout at the
 * current price. Each parent product can get its own markup. Read-only
 * rendering: every change goes through CreatorPricingActions and
 * CreatorPricingSettings. Nothing on this page writes a WooCommerce price.
 *
 * Shown only with `merchboard_manage_creator_pricing`. The products are the
 * ones linked to the creator (ProductStore::linked_to, the ownership
 * source); each parent product's share is resolved once for all its units.
 * The list scrolls within the page when it is long.
 */
final class CreatorPricingSection {

	/**
	 * Constructor.
	 *
	 * @param ProductStore              $products Linked products of the creator.
	 * @param PricingProductStore       $units    WooCommerce prices (read only).
	 * @param MarkupResolver            $resolver The share that applies to a product.
	 * @param CreatorMarkupRepository   $markups  Default markups.
	 * @param CreatorVatRepository|null $vat      Creators' VAT status (ADR-048).
	 */
	public function __construct(
		private ProductStore $products,
		private PricingProductStore $units,
		private MarkupResolver $resolver,
		private CreatorMarkupRepository $markups,
		private ?CreatorVatRepository $vat = null
	) {
	}

	/**
	 * Registers the section on the creator's edit page, above the products.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render' ), 8 );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'warning'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'markup_saved'                            => array( __( 'Creator markup saved. It applies to order lines created from now on; no WooCommerce price and no earlier order line was changed.', 'merchboard' ), 'success' ),
			'markup_unchanged'                        => array( __( 'The creator markup was not changed.', 'merchboard' ), 'info' ),
			'vat_saved'                               => array( __( 'VAT status saved. It applies to order lines created from now on; prices, earlier order lines and earlier payouts are not changed.', 'merchboard' ), 'success' ),
			'vat_unchanged'                           => array( __( 'The VAT status was not changed.', 'merchboard' ), 'info' ),
			CreatorPricingActions::INVALID_VAT_STATUS => array( __( 'Not saved: choose Yes or No.', 'merchboard' ), 'error' ),
			'product_markup_saved'                    => array( __( 'Product markup saved. It applies to order lines created from now on; the WooCommerce price was not changed.', 'merchboard' ), 'success' ),
			'product_markup_unchanged'                => array( __( 'The product markup was not changed.', 'merchboard' ), 'info' ),
			'product_markup_removed'                  => array( __( 'Product markup removed: the creator markup applies again to order lines created from now on.', 'merchboard' ), 'success' ),
			'product_markup_none'                     => array( __( 'The product has no product markup.', 'merchboard' ), 'info' ),
			PricingException::NOT_AUTHORIZED          => array( __( 'You are not allowed to change the pricing of this product.', 'merchboard' ), 'error' ),
			PricingException::PRODUCT_NOT_FOUND       => array( __( 'The product does not exist.', 'merchboard' ), 'error' ),
			PricingException::CREATOR_NOT_FOUND       => array( __( 'The creator does not exist.', 'merchboard' ), 'error' ),
			PricingException::INVALID_MARKUP          => array( __( 'Not saved: enter a markup between 0 and 100 with at most two decimals.', 'merchboard' ), 'error' ),
			PricingException::NO_OWNER                => array( __( 'The product has no creator.', 'merchboard' ), 'error' ),
			PricingException::NOT_OWNED               => array( __( 'The product does not belong to this creator, so nothing was changed here.', 'merchboard' ), 'error' ),
			PricingException::UNSUPPORTED_PRODUCT     => array( __( 'A product markup can only be set for a simple or variable product; variations use their parent\'s markup.', 'merchboard' ), 'error' ),
			'error'                                   => array( __( 'A technical error occurred. Nothing was changed; try again later.', 'merchboard' ), 'error' ),
			default                                   => null,
		};
	}

	/**
	 * Renders the section.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	public function render( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			return;
		}

		echo '<hr><h2 id="merchboard-pricing">' . esc_html__( 'Pricing', 'merchboard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The WooCommerce sale price is the final customer price and already includes the creator profit and VAT. Creator profit = sale price × markup %. Merch Dashboard never adds anything to the price and never changes WooCommerce prices.', 'merchboard' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'For each order line, the profit is calculated from the amount the customer actually paid including VAT, with the markup and VAT status that applied when the order line was created.', 'merchboard' ) . '</p>';

		// Read-only display parameter; nothing is changed by this request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$results = isset( $_GET[ CreatorPricingActions::RESULTS_ARG ] ) ? CreatorPricingActions::parse_results( sanitize_text_field( wp_unslash( $_GET[ CreatorPricingActions::RESULTS_ARG ] ) ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->render_results( $results );
		$this->render_markup( $creator );
		$this->render_vat( $creator );
		$this->render_products( $creator );
	}

	/**
	 * Outcomes of the previous request.
	 *
	 * @param array<int, string> $results Id (0 = creator) => code.
	 */
	private function render_results( array $results ): void {
		foreach ( $results as $id => $code ) {
			$message = self::result_message( $code );

			if ( null === $message ) {
				continue;
			}

			/* translators: 1: product id, 2: outcome message. */
			$text = $id > 0 ? sprintf( __( 'Product #%1$d: %2$s', 'merchboard' ), $id, $message[0] ) : $message[0];

			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $message[1] ), esc_html( $text ) );
		}
	}

	/**
	 * The creator markup form.
	 *
	 * @param Creator $creator Creator.
	 */
	private function render_markup( Creator $creator ): void {
		$markup = $this->markups->find( $creator->id );

		echo '<h3>' . esc_html__( 'Creator markup', 'merchboard' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="merchboard-pricing-markup">';
		self::hidden( CreatorPricingActions::MARKUP_ACTION, $creator->id );
		echo '<label for="merchboard-markup">' . esc_html__( 'Creator share of the sale price (%)', 'merchboard' ) . '</label> ';
		echo '<input type="text" inputmode="decimal" id="merchboard-markup" name="markup" value="' . esc_attr( null === $markup ? '0.00' : $markup->percent() ) . '" class="small-text" required pattern="\d{1,3}([.,]\d{1,2})?"> % ';
		echo '<button type="submit" class="button">' . esc_html__( 'Save markup', 'merchboard' ) . '</button>';
		echo '<p class="description">' . esc_html__( '0–100 %, at most two decimals. Example: a sale price of 100.00 at 20 % gives a creator profit of 20.00. It applies to products without their own product markup.', 'merchboard' ) . '</p>';
		echo '</form>';
	}

	/**
	 * The creator's VAT status (ADR-048, ADR-049).
	 *
	 * @param Creator $creator Creator.
	 */
	private function render_vat( Creator $creator ): void {
		if ( null === $this->vat ) {
			return;
		}

		$registered = $this->vat->find( $creator->id ) ?? false;

		echo '<h3>' . esc_html__( 'VAT registered', 'merchboard' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="merchboard-pricing-vat">';
		self::hidden( CreatorPricingActions::VAT_ACTION, $creator->id );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'VAT registered', 'merchboard' ) . '</legend>';
		echo '<label><input type="radio" name="vat_registered" value="1"' . checked( $registered, true, false ) . '> ' . esc_html__( 'Yes', 'merchboard' ) . '</label> ';
		echo '<label><input type="radio" name="vat_registered" value="0"' . checked( $registered, false, false ) . '> ' . esc_html__( 'No', 'merchboard' ) . '</label> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Save VAT status', 'merchboard' ) . '</button></fieldset>';
		echo '<p class="description">' . esc_html__( 'Yes: the creator is paid the full creator profit.', 'merchboard' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'No: the creator is paid the creator profit minus 20 %, i.e. 80 % of the profit.', 'merchboard' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Changing the VAT status changes no product prices and no earlier order lines or payouts: each order line keeps the status it had when it was created.', 'merchboard' ) . '</p>';
		echo '</form>';
	}

	/**
	 * The creator's products with their markup.
	 *
	 * @param Creator $creator Creator.
	 */
	private function render_products( Creator $creator ): void {
		$linked = $this->products->linked_to( $creator->id, CreatorProductsSection::LIST_LIMIT );

		echo '<h3>' . esc_html__( 'Product markup', 'merchboard' ) . '</h3>';

		if ( array() === $linked ) {
			echo '<p>' . esc_html__( 'No products are linked to this creator yet.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'The examples use the current WooCommerce price as stored and the creator\'s current VAT status. Actual profit and payout are calculated per order line from the amount paid.', 'merchboard' ) . '</p>';
		echo '<div class="merchboard-pricing-products" style="max-height:70vh;overflow:auto">';
		echo '<table class="widefat striped merchboard-pricing-table"><thead><tr>';

		foreach ( array( __( 'Product', 'merchboard' ), __( 'Markup', 'merchboard' ), __( 'WooCommerce prices', 'merchboard' ), __( 'Example profit', 'merchboard' ), __( 'Example payout', 'merchboard' ), __( 'Actions', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		$vat_registered = $this->vat?->find( $creator->id ) ?? false;

		foreach ( $linked as $product ) {
			$this->render_product( $creator, $product, $vat_registered );
		}

		echo '</tbody></table></div>';

		if ( count( $linked ) >= CreatorProductsSection::LIST_LIMIT ) {
			/* translators: %d: number of products shown. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Only the first %d products are shown.', 'merchboard' ), CreatorProductsSection::LIST_LIMIT ) ) . '</p>';
		}
	}

	/**
	 * One parent product: its markup and form, then one row per sellable
	 * unit (the product, or each variation).
	 *
	 * @param Creator       $creator        Creator.
	 * @param ProductRecord $product        Parent product.
	 * @param bool          $vat_registered The creator's current VAT status (examples only).
	 */
	private function render_product( Creator $creator, ProductRecord $product, bool $vat_registered ): void {
		$parent = $this->units->find( $product->id );
		$markup = $this->resolver->resolve( $product->id );

		if ( null === $parent || $parent->is_variation() || null === $markup || $markup->creator_id !== $creator->id ) {
			return;
		}

		$units = array();

		foreach ( $this->units->variation_ids( $product->id ) as $variation_id ) {
			$unit = $this->units->find( $variation_id );

			if ( null !== $unit && $unit->product_id() === $product->id ) {
				$units[] = $unit;
			}
		}

		$variable = array() !== $units;
		$currency = $this->units->currency();
		$decimals = $this->units->decimals();

		if ( $variable ) {
			echo '<tr class="merchboard-pricing-parent"><td><strong>' . esc_html( self::product_name( $product ) ) . '</strong><br><span class="description">' . esc_html__( 'Variable product: one markup for all variations', 'merchboard' ) . '</span></td>';
			echo '<td>' . self::markup_cell( $markup ) . '</td><td colspan="3"></td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in markup_cell().
			self::render_markup_form( $creator, $product->id, $markup );
			echo '</td></tr>';
		} else {
			$units[] = $parent;
		}

		foreach ( $units as $unit ) {
			$name = $variable
				? ( '' === $unit->name ? '#' . $unit->id : $unit->name . ' (#' . $unit->id . ')' )
				: self::product_name( $product );

			$price  = $unit->active_units() ?? $unit->regular_units();
			$profit = self::example_profit( $price, $markup, $decimals );
			$payout = null === $profit ? null : self::example_payout( $profit, $vat_registered, $decimals );

			echo '<tr><td>' . ( $variable ? '— ' : '' ) . esc_html( $name ) . '<br><span class="description">' . esc_html( self::type_label( $unit->type ) ) . '</span></td>';
			echo '<td>' . ( $variable ? esc_html__( 'Parent markup', 'merchboard' ) : self::markup_cell( $markup ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in markup_cell().
			echo '<td>' . self::woocommerce_prices( $unit, $currency, $decimals ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in woocommerce_prices().
			echo '<td>' . esc_html( PricingLabels::money( $profit, $currency, $decimals ) ) . '</td>';
			echo '<td>' . esc_html( PricingLabels::money( $payout, $currency, $decimals ) ) . '</td><td>';

			if ( ! $variable ) {
				self::render_markup_form( $creator, $product->id, $markup );
			}

			echo '</td></tr>';
		}
	}

	/**
	 * Example profit at a price, null without a usable price.
	 *
	 * @param int|null        $price    Price in units.
	 * @param EffectiveMarkup $markup   Share.
	 * @param int             $decimals Store price decimals.
	 */
	private static function example_profit( ?int $price, EffectiveMarkup $markup, int $decimals ): ?int {
		if ( null === $price || $decimals < 0 || $decimals > PricingMath::DECIMALS ) {
			return null;
		}

		return CreatorShare::profit( $price, $markup->markup->bps, $decimals );
	}

	/**
	 * Example payout of a profit.
	 *
	 * @param int  $profit         Profit in units.
	 * @param bool $vat_registered VAT status.
	 * @param int  $decimals       Store price decimals (already checked).
	 */
	private static function example_payout( int $profit, bool $vat_registered, int $decimals ): int {
		return CreatorShare::payout( $profit, $vat_registered, $decimals );
	}

	/**
	 * A parent product's own markup, or back to the creator markup.
	 *
	 * @param Creator         $creator    Creator.
	 * @param int             $product_id Parent product.
	 * @param EffectiveMarkup $markup     The share that applies now.
	 */
	private static function render_markup_form( Creator $creator, int $product_id, EffectiveMarkup $markup ): void {
		$own = MarkupSource::Product === $markup->source;

		echo '<details class="merchboard-pricing-markup-form"><summary>' . esc_html__( 'Edit markup', 'merchboard' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( CreatorPricingActions::PRODUCT_MARKUP_ACTION, $creator->id, $product_id );
		echo '<p><label>' . esc_html__( 'Product markup (%)', 'merchboard' ) . ' <input type="text" inputmode="decimal" name="product_markup" value="' . esc_attr( $own ? $markup->markup->percent() : '' ) . '" class="small-text" required pattern="\d{1,3}([.,]\d{1,2})?"></label></p>';
		echo '<p class="description">' . esc_html__( 'Replaces the creator markup for this product and all its variations. It does not change the WooCommerce price.', 'merchboard' ) . '</p>';
		echo '<button type="submit" class="button">' . esc_html__( 'Save product markup', 'merchboard' ) . '</button>';
		echo '</form>';

		if ( $own || null !== $markup->ignored ) {
			if ( ! $own ) {
				echo '<p class="description">' . esc_html__( 'A product markup set for a previous creator is ignored.', 'merchboard' ) . '</p>';
			}

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::hidden( CreatorPricingActions::REMOVE_PRODUCT_MARKUP_ACTION, $creator->id, $product_id );
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Use creator markup', 'merchboard' ) . '</button>';
			echo '</form>';
		}

		echo '</details>';
	}

	/**
	 * The share that applies and its source. Escaped HTML.
	 *
	 * @param EffectiveMarkup $markup Share.
	 */
	private static function markup_cell( EffectiveMarkup $markup ): string {
		return esc_html( PricingLabels::markup( $markup->markup->bps ) ) . '<br><span class="description">' . esc_html( PricingLabels::source( $markup->source ) ) . '</span>';
	}

	/**
	 * Regular, sale and active price (read only). Escaped HTML.
	 *
	 * @param PricingUnitRecord $unit     Unit.
	 * @param string            $currency Store currency.
	 * @param int               $decimals Store price decimals.
	 */
	private static function woocommerce_prices( PricingUnitRecord $unit, string $currency, int $decimals ): string {
		return esc_html(
			/* translators: %s: price. */
			sprintf( __( 'Regular: %s', 'merchboard' ), PricingLabels::money( $unit->regular_units(), $currency, $decimals ) )
		) . '<br>' . esc_html(
			/* translators: %s: price. */
			sprintf( __( 'Sale: %s', 'merchboard' ), PricingLabels::money( $unit->sale_units(), $currency, $decimals ) )
		) . '<br>' . esc_html(
			/* translators: %s: price. */
			sprintf( __( 'Active: %s', 'merchboard' ), PricingLabels::money( $unit->active_units(), $currency, $decimals ) )
		);
	}

	/**
	 * The hidden fields of a pricing form, with its nonce.
	 *
	 * @param string $action     Action.
	 * @param int    $creator_id Creator.
	 * @param int    $product_id Product (0 for none).
	 */
	private static function hidden( string $action, int $creator_id, int $product_id = 0 ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator_id ) . '">';

		if ( $product_id > 0 ) {
			echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $product_id ) . '">';
		}

		wp_nonce_field( CreatorPricingActions::nonce_action( $action, $creator_id ) );
	}

	/**
	 * Product name with id.
	 *
	 * @param ProductRecord $product Product.
	 */
	private static function product_name( ProductRecord $product ): string {
		$name = '' === $product->name ? __( '(no name)', 'merchboard' ) : $product->name;

		/* translators: 1: product name, 2: product id. */
		return sprintf( __( '%1$s (#%2$d)', 'merchboard' ), $name, $product->id );
	}

	/**
	 * Label of a product type.
	 *
	 * @param string $type WooCommerce product type.
	 */
	private static function type_label( string $type ): string {
		return match ( $type ) {
			'simple'    => __( 'Simple product', 'merchboard' ),
			'variation' => __( 'Variation', 'merchboard' ),
			'variable'  => __( 'Variable product without variations', 'merchboard' ),
			/* translators: %s: WooCommerce product type. */
			default     => sprintf( __( 'Product type: %s', 'merchboard' ), $type ),
		};
	}
}
