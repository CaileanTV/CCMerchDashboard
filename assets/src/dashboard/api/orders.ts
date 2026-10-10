/**
 * `GET /me/orders`: the creator's order lines, a page at a time. The creator
 * is the one the server resolves for the logged-in user; nothing here takes or
 * sends a creator id.
 */
import type { PageLoader } from '../hooks/usePagedApi';
import type { OrderLine } from '../types/orders';
import { requestList } from './client';
import { parseProfit } from './profit';
import { isRecord } from './validate';

const ISO_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const STATUS = /^[a-z0-9_-]{1,50}$/;

/**
 * Validates one order line of `GET /me/orders`. Exactly the nine documented
 * fields are read; anything else in the item is ignored.
 *
 * @param body Item of the response array.
 */
export function parseOrderLine( body: unknown ): OrderLine | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const {
		line_id: lineId,
		order_number: orderNumber,
		date,
		status,
		status_label: statusLabel,
		product_name: productName,
		quantity,
		refunded,
		counted,
		order_lines: orderLines,
	} = body;
	const profit = body.profit === null ? null : parseProfit( body.profit );
	const orderProfit =
		body.order_profit === null ? null : parseProfit( body.order_profit );

	if (
		typeof lineId !== 'number' ||
		! Number.isInteger( lineId ) ||
		lineId < 1 ||
		typeof orderNumber !== 'string' ||
		orderNumber === '' ||
		typeof date !== 'string' ||
		! ISO_UTC.test( date ) ||
		Number.isNaN( Date.parse( date ) ) ||
		typeof status !== 'string' ||
		! STATUS.test( status ) ||
		typeof statusLabel !== 'string' ||
		statusLabel === '' ||
		typeof productName !== 'string' ||
		typeof quantity !== 'number' ||
		! Number.isInteger( quantity ) ||
		typeof refunded !== 'boolean' ||
		typeof counted !== 'boolean' ||
		( body.profit !== null && profit === null ) ||
		( body.order_profit !== null && orderProfit === null ) ||
		typeof orderLines !== 'number' ||
		! Number.isInteger( orderLines ) ||
		orderLines < 0
	) {
		return null;
	}

	return {
		lineId,
		orderNumber,
		date,
		status,
		statusLabel,
		productName,
		quantity,
		refunded,
		counted,
		profit,
		orderProfit,
		orderLines,
	};
}

/**
 * One page of the creator's order lines.
 *
 * @param page    Page, from 1.
 * @param perPage Page size.
 * @param signal  Abort signal.
 */
export const getOrders: PageLoader< OrderLine > = ( page, perPage, signal ) =>
	requestList(
		{ path: '/merchboard/v1/me/orders', page, perPage, signal },
		parseOrderLine
	);
