<?php
/**
 * The only writer of designer products' WooCommerce data and prices.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-080 pkt. 6–7, the exception to ADR-049 pkt. 1: Merch Dashboard writes
 * WooCommerce products and prices, but only here and only for designer
 * products. An architecture test fails when a price is written anywhere
 * else.
 *
 * - create_draft() creates the hidden draft of a new designer product with
 *   the row's token as pending meta, then DesignerProductGuard claims and
 *   marks it. Repeated after an interruption it finds the same draft, so no
 *   second product is created.
 * - Every write to an existing product takes a GuardedProduct, the guard's
 *   proof; a legacy product can never be passed.
 * - Prices are written only when the store enters prices including VAT
 *   (base price and profit are incl. VAT), as the regular price of every
 *   variation; a sale price, which only the company sets, is left alone.
 * - set_content() (step 30.10) writes an approved version: title,
 *   description, categories, images, the local attributes colour and size,
 *   a published variation per combination with its colour's image and the
 *   price, and makes variations that are no longer offered private (never
 *   deleted: orders keep pointing at them). The attribute names are chosen
 *   once per product and kept, so a variation keeps its attributes.
 * - publish() puts a designer product in the shop; the apply pipeline calls
 *   it at a first approval only after the price used for the settlement is
 *   stored (review 30.15), so no sale is made before it.
 * - unpublish() takes a designer product out of the shop (draft).
 *
 * WooCommerce CRUD only, no direct SQL; no transaction around WooCommerce
 * saves (HPOS commits outer transactions); every step can be repeated.
 */
class WooCommerceDesignerWriter {

	/**
	 * Product meta: the names of the colour and size attributes (JSON list).
	 */
	public const ATTRIBUTES_META = '_merchboard_designer_attributes';

	/**
	 * Constructor.
	 *
	 * @param DesignerProductGuard $guard The legacy guard.
	 */
	public function __construct( private DesignerProductGuard $guard ) {
	}

	/**
	 * The designer product's WooCommerce draft: the one created earlier for
	 * the row, or a new hidden draft of a variable product.
	 *
	 * @param int    $design_product_id Row in `merchboard_designer_products`.
	 * @param string $name              Product name.
	 * @throws DesignerException When the row cannot get a product (unknown, or it names a product that is not its own).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create_draft( int $design_product_id, string $name ): GuardedProduct {
		$existing = $this->guard->for_row( $design_product_id );

		if ( null !== $existing ) {
			return $existing;
		}

		$token = $this->guard->token( $design_product_id );

		return $this->guard->claim( $design_product_id, $this->pending( $token ) ?? self::new_draft( $token, $name ) );
	}

	/**
	 * Sets the price of every variation of a designer product.
	 *
	 * @param GuardedProduct $product     The guard's proof.
	 * @param int            $price_units Price per unit incl. VAT in 1/10 000 units (base + profit).
	 * @param int            $decimals    Store price decimals.
	 * @throws DesignerException When the store enters prices excluding VAT, or the product is not a variable product.
	 * @throws InvalidArgumentException When the price cannot be written with the store precision.
	 */
	public function set_prices( GuardedProduct $product, int $price_units, int $decimals ): void {
		if ( wc_tax_enabled() && ! wc_prices_include_tax() ) {
			self::refuse( DesignerException::PRICES_EXCL_TAX );
		}

		$price  = PricingMath::price_string( $price_units, $decimals );
		$parent = wc_get_product( $product->wc_product_id );

		if ( ! $parent instanceof WC_Product_Variable ) {
			self::refuse( DesignerException::NOT_DESIGNER_PRODUCT );
		}

		foreach ( $parent->get_children() as $child_id ) {
			$variation = wc_get_product( (int) $child_id );

			if ( $variation instanceof WC_Product_Variation && $variation->get_parent_id( 'edit' ) === $product->wc_product_id && $variation->get_regular_price( 'edit' ) !== $price ) {
				$variation->set_regular_price( $price );
				$variation->save();
			}
		}

		WC_Product_Variable::sync( $product->wc_product_id );
	}

	/**
	 * Writes an approved version to the designer product.
	 *
	 * @param GuardedProduct  $product The guard's proof.
	 * @param DesignerContent $content The version's content.
	 * @throws DesignerException When the store enters prices excluding VAT or the product is not variable.
	 * @throws DatabaseException When a product cannot be saved.
	 */
	public function set_content( GuardedProduct $product, DesignerContent $content ): void {
		if ( wc_tax_enabled() && ! wc_prices_include_tax() ) {
			self::refuse( DesignerException::PRICES_EXCL_TAX );
		}

		$parent = wc_get_product( $product->wc_product_id );

		if ( ! $parent instanceof WC_Product_Variable ) {
			self::refuse( DesignerException::NOT_DESIGNER_PRODUCT );
		}

		[ $color_name, $size_name ] = self::attribute_names( $parent );
		$color_slug                 = sanitize_title( $color_name );
		$size_slug                  = sanitize_title( $size_name );
		$color_names                = array_column( $content->colors, 'name', 'key' );

		$parent->set_name( $content->name );
		$parent->set_description( $content->description );
		$parent->set_category_ids( $content->category_ids );
		$parent->set_image_id( $content->images[0] ?? 0 );
		$parent->set_gallery_image_ids( array_slice( $content->images, 1 ) );
		$parent->set_attributes(
			array(
				self::attribute( $color_name, array_values( $color_names ), 0 ),
				self::attribute( $size_name, $content->sizes, 1 ),
			)
		);

		if ( $content->publish ) {
			$parent->set_status( 'publish' );
		}

		self::save( $parent );

		$price    = PricingMath::price_string( $content->price_units, $content->decimals );
		$existing = array();

		foreach ( $parent->get_children() as $child_id ) {
			$variation = wc_get_product( (int) $child_id );

			if ( $variation instanceof WC_Product_Variation && $variation->get_parent_id( 'edit' ) === $product->wc_product_id ) {
				$attributes = $variation->get_attributes( 'edit' );
				$existing[ (string) ( $attributes[ $color_slug ] ?? '' ) . "\0" . (string) ( $attributes[ $size_slug ] ?? '' ) ] = $variation;
			}
		}

		foreach ( $color_names as $key => $name ) {
			foreach ( $content->sizes as $size ) {
				$variation = $existing[ $name . "\0" . $size ] ?? new WC_Product_Variation();
				unset( $existing[ $name . "\0" . $size ] );

				if ( 0 === $variation->get_id() ) {
					$variation->set_parent_id( $product->wc_product_id );
					$variation->set_attributes(
						array(
							$color_slug => $name,
							$size_slug  => $size,
						)
					);
				}

				$variation->set_status( 'publish' );
				$variation->set_image_id( $content->color_images[ $key ] ?? 0 );

				if ( $variation->get_regular_price( 'edit' ) !== $price ) {
					$variation->set_regular_price( $price );
				}

				self::save( $variation );
			}
		}

		// No longer offered: hidden, never deleted (orders point at them).
		foreach ( $existing as $variation ) {
			if ( 'private' !== $variation->get_status( 'edit' ) ) {
				$variation->set_status( 'private' );
				self::save( $variation );
			}
		}

		WC_Product_Variable::sync( $product->wc_product_id );
	}

	/**
	 * Puts a designer product in the shop (published).
	 *
	 * @param GuardedProduct $product The guard's proof.
	 * @throws DatabaseException When it cannot be saved.
	 */
	public function publish( GuardedProduct $product ): void {
		$parent = wc_get_product( $product->wc_product_id );

		if ( $parent instanceof WC_Product_Variable && 'publish' !== $parent->get_status( 'edit' ) ) {
			$parent->set_status( 'publish' );
			self::save( $parent );
		}
	}

	/**
	 * Takes a designer product out of the shop (a draft again).
	 *
	 * @param GuardedProduct $product The guard's proof.
	 * @throws DatabaseException When it cannot be saved.
	 */
	public function unpublish( GuardedProduct $product ): void {
		$parent = wc_get_product( $product->wc_product_id );

		if ( $parent instanceof WC_Product_Variable && 'draft' !== $parent->get_status( 'edit' ) ) {
			$parent->set_status( 'draft' );
			self::save( $parent );
		}
	}

	/**
	 * The names of the colour and size attributes: chosen at the first
	 * write, in the site's language, and kept on the product after that.
	 *
	 * @param WC_Product_Variable $variable The product.
	 * @return array{0: string, 1: string}
	 */
	private static function attribute_names( WC_Product_Variable $variable ): array {
		$stored = json_decode( (string) $variable->get_meta( self::ATTRIBUTES_META, true, 'edit' ), true );

		if ( is_array( $stored ) && 2 === count( $stored ) && is_string( $stored[0] ?? null ) && is_string( $stored[1] ?? null ) && '' !== $stored[0] && '' !== $stored[1] ) {
			return array( $stored[0], $stored[1] );
		}

		$names = array( __( 'Colour', 'merchboard' ), __( 'Size', 'merchboard' ) );
		$variable->update_meta_data( self::ATTRIBUTES_META, (string) wp_json_encode( $names ) );

		return $names;
	}

	/**
	 * A local attribute used for variations.
	 *
	 * @phpstan-param list<string> $options
	 *
	 * @param string   $name     Name.
	 * @param string[] $options  Values.
	 * @param int      $position Position.
	 */
	private static function attribute( string $name, array $options, int $position ): WC_Product_Attribute {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( $name );
		$attribute->set_options( $options );
		$attribute->set_position( $position );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		return $attribute;
	}

	/**
	 * Saves a product or variation.
	 *
	 * @param WC_Product_Variable|WC_Product_Variation $product Product.
	 * @throws DatabaseException When it is not saved.
	 */
	private static function save( WC_Product_Variable|WC_Product_Variation $product ): void {
		if ( (int) $product->save() < 1 ) {
			throw new DatabaseException( 'Could not save the designer product.' );
		}
	}

	/**
	 * A draft created earlier for a token and not claimed yet.
	 *
	 * @param string $token Creation token.
	 */
	private function pending( string $token ): ?int {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'numberposts'      => 2,
				'suppress_filters' => true,
				'meta_key'         => DesignerProductGuard::PENDING_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Once per new designer product.
				'meta_value'       => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Once per new designer product.
			)
		);

		return 1 === count( $ids ) ? (int) $ids[0] : null;
	}

	/**
	 * A new hidden draft of a variable product carrying the pending token.
	 *
	 * @param string $token Creation token.
	 * @param string $name  Product name.
	 * @throws DatabaseException When WooCommerce cannot save it.
	 */
	private static function new_draft( string $token, string $name ): int {
		$product = new WC_Product_Variable();
		$product->set_name( $name );
		$product->set_status( 'draft' );
		$product->update_meta_data( DesignerProductGuard::PENDING_KEY, $token );
		$id = (int) $product->save();

		if ( $id < 1 ) {
			throw new DatabaseException( 'Could not create the designer product draft.' );
		}

		return $id;
	}

	/**
	 * Refuses a write.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
