<?php
/**
 * A designer writer that records instead of writing WooCommerce products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerContent;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\GuardedProduct;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;

/**
 * The integration database has no WooCommerce. The draft is a real
 * `product` post claimed through the real legacy guard (marker and token);
 * the content is recorded, and publishing sets the post's status. set_content() can be made to
 * fail a number of times, to test an interrupted apply.
 */
final class RecordingDesignerWriter extends WooCommerceDesignerWriter {

	/**
	 * Every written content, in order.
	 *
	 * @var list<array{0: int, 1: DesignerContent}>
	 */
	public array $contents = array();

	/**
	 * Written prices: [WooCommerce product, units].
	 *
	 * @var list<array{0: int, 1: int}>
	 */
	public array $prices = array();

	/**
	 * The prices by size of each set_prices().
	 *
	 * @var list<array<string, int>>
	 */
	public array $size_prices = array();

	/**
	 * Prices by colour and size of set_prices() (ADR-096).
	 *
	 * @var list<array<string, array<string, int>>>
	 */
	public array $color_prices = array();

	/**
	 * Published products.
	 *
	 * @var list<int>
	 */
	public array $published = array();

	/**
	 * The images of each set_images() (ADR-096).
	 *
	 * @var list<array{0: int, 1: list<int>}>
	 */
	public array $images = array();

	/**
	 * Product tags written (ADR-100).
	 *
	 * @var list<array{0: int, 1: array<int>}>
	 */
	public array $tags = array();

	/**
	 * Unpublished products.
	 *
	 * @var list<int>
	 */
	public array $unpublished = array();

	/**
	 * Names written: [WooCommerce product, name].
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	public array $names = array();

	/**
	 * Drafts created (not found again).
	 *
	 * @var list<int>
	 */
	public array $created = array();

	/**
	 * Calls of set_content() that fail before one succeeds.
	 *
	 * @var int
	 */
	public int $fail_content = 0;

	/**
	 * Constructor.
	 *
	 * @param DesignerProductGuard $proof The real guard.
	 * @param ProductPosts         $posts Test posts.
	 */
	public function __construct( private DesignerProductGuard $proof, private ProductPosts $posts ) {
		parent::__construct( $proof );
	}

	/**
	 * The row's product, or a new `product` draft claimed by the guard.
	 *
	 * @param int    $design_product_id Row.
	 * @param string $name              Name.
	 */
	public function create_draft( int $design_product_id, string $name ): GuardedProduct {
		$existing = $this->proof->for_row( $design_product_id );

		if ( null !== $existing ) {
			return $existing;
		}

		$draft = $this->posts->add( $name, 'draft', array() );
		$this->posts->meta( $draft, DesignerProductGuard::PENDING_KEY, $this->proof->token( $design_product_id ) );
		$this->created[] = $draft;

		return $this->proof->claim( $design_product_id, $draft );
	}

	/**
	 * Records the content, or fails while $fail_content is above 0.
	 *
	 * @param GuardedProduct  $product Proof.
	 * @param DesignerContent $content Content.
	 * @throws DatabaseException While failing.
	 */
	public function set_content( GuardedProduct $product, DesignerContent $content ): void {
		if ( $this->fail_content > 0 ) {
			--$this->fail_content;
			throw new DatabaseException( 'Interrupted.' );
		}

		$this->contents[] = array( $product->wc_product_id, $content );
	}

	/**
	 * Records a price.
	 *
	 * @param GuardedProduct                    $product     Proof.
	 * @param int                               $price_units Price in units.
	 * @param int                               $decimals    Decimals.
	 * @param array<string, int>                $size_prices Price by size.
	 * @param array<string, array<string, int>> $color_prices Price by colour and size.
	 */
	public function set_prices( GuardedProduct $product, int $price_units, int $decimals, array $size_prices = array(), array $color_prices = array() ): void {
		$this->prices[]       = array( $product->wc_product_id, $price_units );
		$this->size_prices[]  = $size_prices;
		$this->color_prices[] = $color_prices;
	}

	/**
	 * Records the name.
	 *
	 * @param GuardedProduct $product Proof.
	 * @param string         $name    Name.
	 */
	public function set_name( GuardedProduct $product, string $name ): void {
		$this->names[] = array( $product->wc_product_id, $name );
	}

	/**
	 * Records a new order of the images.
	 *
	 * @phpstan-param list<int> $images
	 *
	 * @param GuardedProduct $product Proof.
	 * @param int[]          $images  Attachments.
	 */
	public function set_images( GuardedProduct $product, array $images ): void {
		$this->images[] = array( $product->wc_product_id, $images );
	}

	/**
	 * Records the product tags.
	 *
	 * @param GuardedProduct $product Proof.
	 * @param int[]          $tags    Product tags.
	 */
	public function set_tags( GuardedProduct $product, array $tags ): bool {
		$this->tags[] = array( $product->wc_product_id, $tags );

		return true;
	}

	/**
	 * Records the product.
	 *
	 * @param GuardedProduct $product Proof.
	 */
	public function publish( GuardedProduct $product ): void {
		$this->published[] = $product->wc_product_id;
		wp_update_post(
			array(
				'ID'          => $product->wc_product_id,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Records the product.
	 *
	 * @param GuardedProduct $product Proof.
	 */
	public function unpublish( GuardedProduct $product ): void {
		$this->unpublished[] = $product->wc_product_id;
		wp_update_post(
			array(
				'ID'          => $product->wc_product_id,
				'post_status' => 'draft',
			)
		);
	}
}
