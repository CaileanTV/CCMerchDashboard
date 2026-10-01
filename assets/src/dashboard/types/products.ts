/**
 * Products from `GET /merchboard/v1/me/products` (docs/API.md, ADR-039 P7,
 * P8 and the P7 clarification). The types mirror the backend contract.
 */

export type ProductStatus = 'publish' | 'private';

export type StockStatus = 'instock' | 'outofstock' | 'onbackorder';

/**
 * The active WooCommerce price as the store stores it: decimal strings, the
 * store currency and `tax: "store_setting"` (whether it includes tax depends
 * on the store; the dashboard never calculates or labels tax).
 */
export interface ProductPrice {
	readonly min: string;
	readonly max: string;
	readonly currency: string;
	readonly tax: 'store_setting';
}

/** A same-origin thumbnail with its alt text (the product name). */
export interface ProductImage {
	readonly url: string;
	readonly alt: string;
}

export interface Product {
	/** Product id; the row key only, never shown. */
	readonly id: number;
	readonly name: string;
	readonly status: ProductStatus;
	readonly price: ProductPrice | null;
	readonly stockStatus: StockStatus | null;
	readonly image: ProductImage | null;
}
