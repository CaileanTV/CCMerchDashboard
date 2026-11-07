<?php
/**
 * The print files of a sold designer product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerVersionFiles;
use MerchBoard\Modules\ProductDesigner\Admin\DesignsPage;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\ProductDesigner\Designs\ColorDesigns;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulCatalog;
use Throwable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-080 pkt. 5–6 (step 30.10): the print files reach production through
 * the administrator only.
 *
 * - An order line of a designer product stores the version that was live
 *   when it was ordered (hidden item meta `_merchboard_designer_version`),
 *   so the print file of exactly the sold design can be fetched later, even
 *   after a change. Never breaks the checkout.
 * - Below such a line in the order, the administrator
 *   (`merchboard_manage_designer`) gets a download link per print file.
 * - The product editor of a designer product shows a box with the live
 *   version, its print files and the way to Designs.
 */
class DesignerOrderFiles {

	public const ITEM_META = '_merchboard_designer_version';

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products Designer products.
	 * @param DesignerProductGuard      $guard    The legacy guard.
	 * @param Logger                    $logger   Technical log.
	 * @param BaseProductRepository     $bases    Base products (the sides of a size).
	 */
	public function __construct( private DesignerProductRepository $products, private DesignerProductGuard $guard, private Logger $logger, private BaseProductRepository $bases ) {
	}

	/**
	 * Hooks into the checkout, the order screen and the product editor.
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'remember' ), 10, 1 );
		// An order line made outside the checkout (an order the administrator creates, the REST API; review 30.15).
		add_action( 'woocommerce_new_order_item', array( $this, 'remember_saved' ), 10, 2 );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_item' ), 10, 2 );
		add_action( 'add_meta_boxes_product', array( $this, 'add_box' ), 10, 1 );
	}

	/**
	 * Stores the live version on an order line saved without the checkout
	 * (the checkout's lines have it already); never breaks the save.
	 *
	 * @param mixed $item_id Item id.
	 * @param mixed $item    The order line.
	 */
	public function remember_saved( mixed $item_id, mixed $item ): void {
		if ( ! is_numeric( $item_id ) || ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) || ! method_exists( $item, 'get_meta' ) || '' !== (string) $item->get_meta( self::ITEM_META, true ) ) {
			return;
		}

		try {
			$product_id = (int) $item->get_product_id();
			$version    = $product_id > 0 && null !== $this->guard->guard( $product_id ) ? $this->products->live_for_shop_product( $product_id ) : null;

			if ( null !== $version ) {
				wc_add_order_item_meta( (int) $item_id, self::ITEM_META, (string) $version, true );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The design of an order line could not be stored.',
				array(
					'product_id' => $product_id ?? 0,
					'exception'  => $e::class,
				)
			);
		}
	}

	/**
	 * Stores the live version on a new order line of a designer product.
	 *
	 * @param mixed $item The order line (WC_Order_Item_Product).
	 */
	public function remember( mixed $item ): void {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		try {
			$product_id = (int) $item->get_product_id();
			$version    = $product_id > 0 && null !== $this->guard->guard( $product_id ) ? $this->products->live_for_shop_product( $product_id ) : null;
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The design of an order line could not be stored.',
				array(
					'product_id' => $product_id ?? 0,
					'exception'  => $e::class,
				)
			);

			return;
		}

		if ( null !== $version ) {
			$item->add_meta_data( self::ITEM_META, (string) $version, true );
		}
	}

	/**
	 * The print file links below an order line of a designer product.
	 *
	 * @param mixed $item_id Item id.
	 * @param mixed $item    The order line.
	 */
	public function render_item( mixed $item_id, mixed $item ): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) || ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return;
		}

		$version = (int) $item->get_meta( self::ITEM_META, true );

		if ( $version < 1 ) {
			return;
		}

		$variation = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
		$size      = $variation > 0 ? get_post_meta( $variation, DesignerPrices::SIZE_META, true ) : '';
		$color     = $variation > 0 ? get_post_meta( $variation, DesignerPrices::COLOR_META, true ) : '';

		echo '<p class="merchboard-designer-files"><strong>' . esc_html__( 'Print files:', 'merchboard' ) . '</strong> ';
		$this->print_links( $version, is_string( $size ) && '' !== $size ? $size : null, is_string( $color ) && '' !== $color ? $color : null );
		echo '</p>';
	}

	/**
	 * Adds the box to a designer product's editor.
	 *
	 * @param mixed $post The product post.
	 */
	public function add_box( mixed $post ): void {
		if ( ! $post instanceof WP_Post || ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			return;
		}

		try {
			$proof = $this->guard->guard( (int) $post->ID );
		} catch ( DatabaseException ) {
			return;
		}

		if ( null !== $proof ) {
			add_meta_box( 'merchboard-designer', __( 'Product designer', 'merchboard' ), array( $this, 'render_box' ), 'product', 'side', 'default', array( 'design_product_id' => $proof->design_product_id ) );
		}
	}

	/**
	 * The box: the live version and its print files.
	 *
	 * @param mixed                $post Post.
	 * @param array<string, mixed> $box  Box with `args`.
	 */
	public function render_box( mixed $post, array $box ): void {
		$product = (int) ( $box['args']['design_product_id'] ?? 0 );

		try {
			$live = $this->products->live( $product );
		} catch ( DatabaseException ) {
			$live = null;
		}

		echo '<p>' . esc_html__( 'This product is made with the product designer. Its prices, variations and images are written when a design is approved; changes made here are overwritten then.', 'merchboard' ) . '</p>';

		if ( null !== $live ) {
			/* translators: %d: version number. */
			echo '<p>' . esc_html( sprintf( __( 'In the shop: version %d.', 'merchboard' ), (int) $live['number'] ) ) . '<br>';
			$this->print_links( (int) $live['id'] );
			echo '</p>';
		}

		echo '<p><a href="' . esc_url( DesignsPage::url() ) . '">' . esc_html__( 'Open Designs', 'merchboard' ) . '</a></p>';
	}

	/**
	 * The print files a size is made from. With sides per size, a cup of one
	 * size must never be printed with the file of another. All of them when
	 * the size or its sides cannot be told.
	 *
	 * @param array<string, string>     $slots Slot by side.
	 * @param array<string, mixed>|null $row   Version.
	 * @param string|null               $size  Size of the order line.
	 * @return array<string, string>
	 */
	private function of_size( array $slots, ?array $row, ?string $size ): array {
		try {
			$base = null === $size || null === $row ? null : $this->bases->find( (int) $row['base_id'] );
		} catch ( DatabaseException ) {
			$base = null;
		}

		if ( null === $base || null === $size || ! $base->sized() ) {
			return $slots;
		}

		// A colour design's key is "side:id" (ADR-096).
		$sides = $base->sides_of( $size );
		$own   = array_filter( $slots, static fn( int|string $key ): bool => in_array( explode( ':', (string) $key, 2 )[0], $sides, true ), ARRAY_FILTER_USE_KEY );

		return array() === $own ? $slots : $own;
	}

	/**
	 * The print files of the design a colour uses (ADR-096): a shirt in
	 * orange must never be printed with the black ones' design. All of them
	 * when the colour cannot be told; we never guess.
	 *
	 * @param array<string, string>     $slots Slot by key ("side", or "side:id" for a colour design).
	 * @param array<string, mixed>|null $row   Version.
	 * @param string|null               $color Colour key of the order line.
	 * @return array<string, string>
	 */
	private static function of_color( array $slots, ?array $row, ?string $color ): array {
		$design = null === $row ? null : json_decode( (string) ( $row['design'] ?? '' ), true );

		if ( null === $color || ! is_array( $design ) || ! ColorDesigns::has_any( $design ) ) {
			return $slots;
		}

		$wanted = ColorDesigns::part_of( $design, $color );

		return array_filter( $slots, static fn( int|string $key ): bool => ( explode( ':', (string) $key, 2 )[1] ?? ColorDesigns::SHARED ) === $wanted, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * A download link per print file of a version.
	 *
	 * @param int         $version Version.
	 * @param string|null $size    Only the files of this size; null for all.
	 * @param string|null $color   Only the files of this colour's design (ADR-096); null for all.
	 */
	private function print_links( int $version, ?string $size = null, ?string $color = null ): void {
		try {
			$row = $this->products->version( $version );
		} catch ( DatabaseException ) {
			$row = null;
		}

		$slots = $this->of_size( self::of_color( DesignerVersionFiles::slots( is_string( $row['files'] ?? null ) ? $row['files'] : null )['print'], $row, $color ), $row, $size );

		if ( array() === $slots ) {
			echo esc_html__( 'none stored', 'merchboard' );

			return;
		}

		$links = array();

		try {
			$base = null === $row ? null : $this->bases->find( (int) $row['base_id'] );
		} catch ( DatabaseException ) {
			$base = null;
		}

		$names = null === $base ? array() : array_column( $base->colors, 'name', 'key' );

		foreach ( $slots as $side => $slot ) {
			$links[] = '<a href="' . esc_url( DesignerVersionFiles::url( $version, $slot ) ) . '">' . esc_html( DesignerVersionFiles::print_label( (string) $side, is_string( $row['design'] ?? null ) ? $row['design'] : null, $names ) ) . '</a>';
		}

		echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		if ( '' !== SubmissionFiles::stitch( $row ?? array() ) ) {
			/* translators: %s: stitch colour. */
			echo '<br>' . esc_html( sprintf( __( 'Stitching: %s', 'merchboard' ), PrintfulCatalog::stitch_name( SubmissionFiles::stitch( $row ?? array() ) ) ) );
		}
	}
}
