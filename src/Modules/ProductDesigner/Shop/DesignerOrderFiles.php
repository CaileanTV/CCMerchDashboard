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
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
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
	 */
	public function __construct( private DesignerProductRepository $products, private DesignerProductGuard $guard, private Logger $logger ) {
	}

	/**
	 * Hooks into the checkout, the order screen and the product editor.
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'remember' ), 10, 1 );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_item' ), 10, 2 );
		add_action( 'add_meta_boxes_product', array( $this, 'add_box' ), 10, 1 );
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

		echo '<p class="merchboard-designer-files"><strong>' . esc_html__( 'Print files:', 'merchboard' ) . '</strong> ';
		$this->print_links( $version );
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
	 * A download link per print file of a version.
	 *
	 * @param int $version Version.
	 */
	private function print_links( int $version ): void {
		try {
			$row = $this->products->version( $version );
		} catch ( DatabaseException ) {
			$row = null;
		}

		$slots = DesignerVersionFiles::slots( is_string( $row['files'] ?? null ) ? $row['files'] : null )['print'];

		if ( array() === $slots ) {
			echo esc_html__( 'none stored', 'merchboard' );

			return;
		}

		$links = array();

		foreach ( $slots as $side => $slot ) {
			$links[] = '<a href="' . esc_url( DesignerVersionFiles::url( $version, $slot ) ) . '">' . esc_html( $side ) . '</a>';
		}

		echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}
}
