/**
 * `GET /me/products`: the creator's products, a page at a time, and a request
 * to remove one. The creator is the one the server resolves for the logged-in
 * user; nothing here takes or sends a creator id.
 */
import type { PageLoader } from '../hooks/usePagedApi';
import type {
	Product,
	ProductImage,
	ProductPrice,
	ProductStatus,
	StockStatus,
} from '../types/products';
import { request, requestList } from './client';
import type { ApiResult } from './client';
import { parseProfitList } from './profit';
import { isRecord, readSameOriginUrl } from './validate';

const DECIMAL = /^-?\d{1,15}(?:\.\d{1,6})?$/;
const CURRENCY = /^[A-Z]{3}$/;
const STATUSES: readonly ProductStatus[] = [ 'publish', 'private' ];
const STOCK_STATUSES: readonly StockStatus[] = [
	'instock',
	'outofstock',
	'onbackorder',
];

/**
 * Valid `price`, `null` for "no price", or undefined when malformed.
 *
 * @param value Candidate.
 */
function parsePrice( value: unknown ): ProductPrice | null | undefined {
	if ( value === null ) {
		return null;
	}

	if ( ! isRecord( value ) ) {
		return undefined;
	}

	const { min, max, currency, tax } = value;

	if (
		typeof min !== 'string' ||
		typeof max !== 'string' ||
		! DECIMAL.test( min ) ||
		! DECIMAL.test( max ) ||
		typeof currency !== 'string' ||
		! CURRENCY.test( currency ) ||
		tax !== 'store_setting'
	) {
		return undefined;
	}

	return { min, max, currency, tax };
}

/**
 * Valid `image`, `null` for "no image" (also for a URL that is not on this
 * origin: the dashboard's CSP would block it anyway), or undefined when
 * malformed.
 *
 * @param value Candidate.
 */
function parseImage( value: unknown ): ProductImage | null | undefined {
	if ( value === null ) {
		return null;
	}

	if (
		! isRecord( value ) ||
		typeof value.url !== 'string' ||
		typeof value.alt !== 'string'
	) {
		return undefined;
	}

	const url = readSameOriginUrl( value.url, window.location.origin );

	return url === null ? null : { url, alt: value.alt };
}

/**
 * Validates one product of `GET /me/products`.
 *
 * @param body Item of the response array.
 */
export function parseProduct( body: unknown ): Product | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const { id, name, status, stock_status: stock } = body;
	const price = parsePrice( body.price );
	const image = parseImage( body.image );
	const profit = parseProfitList( body.profit );

	if (
		typeof id !== 'number' ||
		! Number.isInteger( id ) ||
		id < 1 ||
		typeof name !== 'string' ||
		name === '' ||
		! STATUSES.includes( status as ProductStatus ) ||
		( stock !== null &&
			! STOCK_STATUSES.includes( stock as StockStatus ) ) ||
		price === undefined ||
		image === undefined ||
		profit === undefined
	) {
		return null;
	}

	return {
		id,
		name,
		status: status as ProductStatus,
		price,
		stockStatus: stock as StockStatus | null,
		image,
		profit,
		designer: body.designer === true,
		removalPending: body.removal_pending === true,
	};
}

/**
 * Validates `{ removal_pending: true }`.
 *
 * @param body Response body.
 */
export function parseRemovalRequest(
	body: unknown
): { removalPending: true } | null {
	return isRecord( body ) && body.removal_pending === true
		? { removalPending: true }
		: null;
}

/**
 * `POST /me/products/{id}/removal-request` (ADR-083): asks us to remove a
 * legacy product. Nothing happens to the product until we have dealt with it.
 *
 * @param id Product id.
 */
export function requestProductRemoval(
	id: number
): Promise< ApiResult< { removalPending: true } > > {
	return request(
		{
			path: `/merchboard/v1/me/products/${ id }/removal-request`,
			method: 'POST',
		},
		parseRemovalRequest
	);
}

/**
 * One page of the creator's products.
 *
 * @param page    Page, from 1.
 * @param perPage Page size.
 * @param signal  Abort signal.
 */
export const getProducts: PageLoader< Product > = ( page, perPage, signal ) =>
	requestList(
		{ path: '/merchboard/v1/me/products', page, perPage, signal },
		parseProduct
	);
