<?php
/**
 * One product in the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * The fields of a product that `GET /me/products` may show (ADR-039 P3, P7,
 * P8 and the P7 clarification): id, plain-text name, status, the active
 * price, stock status and a same-origin thumbnail. Nothing else.
 */
final readonly class ProductSummary {

	/**
	 * Constructor.
	 *
	 * @phpstan-param array{min: string, max: string, currency: string, tax: string}|null $price
	 *
	 * @param int               $id           Product id.
	 * @param string            $name         Current name, plain text.
	 * @param string            $status       `publish` or `private`.
	 * @param array<mixed>|null $price        Active price (`min`, `max`, `currency`, `tax`), or null without one.
	 * @param string|null       $stock_status `instock`, `outofstock`, `onbackorder` or null.
	 * @param string|null       $image_url    Same-origin thumbnail URL, or null.
	 */
	public function __construct(
		public int $id,
		public string $name,
		public string $status,
		public ?array $price,
		public ?string $stock_status,
		public ?string $image_url
	) {
	}

	/**
	 * The REST representation; the image's alt text is the product name.
	 *
	 * @return array{id: int, name: string, status: string, price: array{min: string, max: string, currency: string, tax: string}|null, stock_status: string|null, image: array{url: string, alt: string}|null}
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'name'         => $this->name,
			'status'       => $this->status,
			'price'        => $this->price,
			'stock_status' => $this->stock_status,
			'image'        => null === $this->image_url ? null : array(
				'url' => $this->image_url,
				'alt' => $this->name,
			),
		);
	}
}
