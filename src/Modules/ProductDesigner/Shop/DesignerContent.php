<?php
/**
 * What a designer product shows in the shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

defined( 'ABSPATH' ) || exit;

/**
 * The content of an approved design version for WooCommerceDesignerWriter
 * (ADR-080 pkt. 6): title, description, categories, product images (the
 * mockups in the media library), the colours and sizes (one variation each
 * combination) and the customer's price incl. VAT.
 */
final readonly class DesignerContent {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $category_ids
	 * @phpstan-param list<array{key: string, name: string}> $colors
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<int> $images
	 * @phpstan-param array<string, int> $color_images
	 *
	 * @param string                                  $name         Title.
	 * @param string                                  $description  Description (plain text).
	 * @param int[]                                   $category_ids Product categories.
	 * @param array<array{key: string, name: string}> $colors     Colours, in order.
	 * @param string[]                                $sizes        Sizes, in order.
	 * @param int[]                                   $images       Attachments: the first is the product image, the rest the gallery.
	 * @param array<string, int>                      $color_images A variation's image by colour key.
	 * @param int                                     $price_units  Customer's price incl. VAT in units (SalesAmount).
	 * @param int                                     $decimals     Store price decimals.
	 * @param bool                                    $publish      Whether to publish the product (its first approval).
	 */
	public function __construct(
		public string $name,
		public string $description,
		public array $category_ids,
		public array $colors,
		public array $sizes,
		public array $images,
		public array $color_images,
		public int $price_units,
		public int $decimals,
		public bool $publish
	) {
	}
}
