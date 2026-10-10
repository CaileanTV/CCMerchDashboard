/**
 * `GET /me/sales` (ADR-040 pkt. 11-12): the creator's sales statistics. The
 * Sales section sends `from`, `to` and `interval` when chosen; the Overview
 * calls it without parameters, i.e. the default period (the last 30 days
 * including today, by day, in the site's time zone); there is no
 * `/me/overview`. The creator is the one the server resolves for the
 * logged-in user; nothing here takes or sends a creator id. Nothing here
 * computes sales: every figure comes from the response.
 */
import type {
	SalesCurrencyReport,
	SalesCurrencyTotals,
	SalesInterval,
	SalesPoint,
	SalesQuery,
	SalesReport,
	SalesSummary,
	SalesTotals,
} from '../types/sales';
import { request } from './client';
import type { ApiResult } from './client';
import { parseProfit } from './profit';
import { isRecord } from './validate';

const DATE = /^\d{4}-\d{2}-\d{2}$/;
const AMOUNT = /^-?\d{1,15}\.\d{4}$/;
const CURRENCY = /^[A-Z]{3}$/;
export const SALES_INTERVALS: readonly SalesInterval[] = [
	'day',
	'week',
	'month',
];

function isInteger( value: unknown ): value is number {
	return typeof value === 'number' && Number.isSafeInteger( value );
}

function isAmountOrNull( value: unknown ): value is string | null {
	return (
		value === null || ( typeof value === 'string' && AMOUNT.test( value ) )
	);
}

function parseTotals( value: unknown ): SalesTotals | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const {
		net_sales: netSales,
		sales,
		adjustments,
		units,
		orders,
		net_sales_incl_vat: netSalesInclVat,
		sales_incl_vat: salesInclVat,
		adjustments_incl_vat: adjustmentsInclVat,
	} = value;

	if (
		! isAmountOrNull( netSalesInclVat ) ||
		! isAmountOrNull( salesInclVat ) ||
		! isAmountOrNull( adjustmentsInclVat ) ||
		typeof netSales !== 'string' ||
		! AMOUNT.test( netSales ) ||
		typeof sales !== 'string' ||
		! AMOUNT.test( sales ) ||
		typeof adjustments !== 'string' ||
		! AMOUNT.test( adjustments ) ||
		! isInteger( units ) ||
		! isInteger( orders ) ||
		orders < 0
	) {
		return null;
	}

	return {
		netSales,
		sales,
		adjustments,
		units,
		orders,
		netSalesInclVat,
		salesInclVat,
		adjustmentsInclVat,
	};
}

/**
 * The VAT state of a currency: complete exactly when the totals include VAT.
 *
 * @param value  Raw `vat`.
 * @param totals Parsed totals.
 */
function parseVat(
	value: unknown,
	totals: SalesTotals
): { vatIncluded: boolean; missingVatRows: number } | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const { status, missing_rows: missingVatRows } = value;
	const vatIncluded = status === 'complete';
	const amounts = [
		totals.netSalesInclVat,
		totals.salesInclVat,
		totals.adjustmentsInclVat,
	];

	if (
		( status !== 'complete' && status !== 'incomplete' ) ||
		! isInteger( missingVatRows ) ||
		missingVatRows < 0 ||
		vatIncluded !== ( missingVatRows === 0 ) ||
		! amounts.every( ( amount ) => ( amount !== null ) === vatIncluded )
	) {
		return null;
	}

	return { vatIncluded, missingVatRows };
}

function parseCurrency( value: unknown ): SalesCurrencyTotals | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const { currency } = value;
	const totals = parseTotals( value.totals );
	const vat = totals === null ? null : parseVat( value.vat, totals );
	const profit =
		value.profit === undefined || value.profit === null
			? null
			: parseProfit( value.profit );

	if (
		typeof currency !== 'string' ||
		! CURRENCY.test( currency ) ||
		totals === null ||
		vat === null ||
		( value.profit !== undefined &&
			value.profit !== null &&
			( profit === null || profit.currency !== currency ) )
	) {
		return null;
	}

	return { currency, totals, ...vat, profit };
}

/**
 * Validates the parts of `GET /me/sales` the key figures use: the period and
 * every currency's totals. `series` and `products` are not read here.
 *
 * @param body Response body.
 */
export function parseSalesSummary( body: unknown ): SalesSummary | null {
	if ( ! isRecord( body ) || ! Array.isArray( body.currencies ) ) {
		return null;
	}

	const { from, to, interval, timezone } = body;
	const currencies: SalesCurrencyTotals[] = [];

	for ( const item of body.currencies ) {
		const parsed = parseCurrency( item );

		if (
			parsed === null ||
			currencies.some( ( seen ) => seen.currency === parsed.currency )
		) {
			return null;
		}

		currencies.push( parsed );
	}

	if (
		typeof from !== 'string' ||
		! DATE.test( from ) ||
		typeof to !== 'string' ||
		! DATE.test( to ) ||
		from > to ||
		! SALES_INTERVALS.includes( interval as SalesInterval ) ||
		typeof timezone !== 'string' ||
		timezone === ''
	) {
		return null;
	}

	return {
		from,
		to,
		interval: interval as SalesInterval,
		timezone,
		currencies,
	};
}

function parsePoint( value: unknown, vatIncluded: boolean ): SalesPoint | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const {
		start,
		end,
		net_sales: netSales,
		net_sales_incl_vat: netSalesInclVat,
		units,
		orders,
	} = value;

	if (
		! isAmountOrNull( netSalesInclVat ) ||
		( netSalesInclVat !== null ) !== vatIncluded ||
		typeof start !== 'string' ||
		! DATE.test( start ) ||
		typeof end !== 'string' ||
		! DATE.test( end ) ||
		start > end ||
		typeof netSales !== 'string' ||
		! AMOUNT.test( netSales ) ||
		! isInteger( units ) ||
		! isInteger( orders ) ||
		orders < 0
	) {
		return null;
	}

	return { start, end, netSales, netSalesInclVat, units, orders };
}

/**
 * The calendar day after a `YYYY-MM-DD` date (calendar arithmetic in UTC).
 *
 * @param date Date.
 */
function nextDay( date: string ): string {
	const day = new Date( `${ date }T00:00:00Z` );
	day.setUTCDate( day.getUTCDate() + 1 );

	return day.toISOString().slice( 0, 10 );
}

/**
 * A dense series: every period of [from, to] in order, each starting the day
 * after the previous one ends, without gaps or overlaps.
 *
 * @param value       Raw series.
 * @param from        First day of the query.
 * @param to          Last day of the query.
 * @param vatIncluded Whether the currency's amounts incl. VAT are known.
 */
function parseSeries(
	value: unknown,
	from: string,
	to: string,
	vatIncluded: boolean
): SalesPoint[] | null {
	if ( ! Array.isArray( value ) || value.length === 0 ) {
		return null;
	}

	const series: SalesPoint[] = [];

	for ( const item of value ) {
		const point = parsePoint( item, vatIncluded );
		const previous = series[ series.length - 1 ];
		const expected =
			previous === undefined ? from : nextDay( previous.end );

		if ( point === null || point.start !== expected ) {
			return null;
		}

		series.push( point );
	}

	return series[ series.length - 1 ]?.end === to ? series : null;
}

/**
 * Validates `GET /me/sales` for the Sales section: the summary plus every
 * currency's dense series (the same number of periods for every currency).
 * `products` is not read.
 *
 * @param body Response body.
 */
export function parseSalesReport( body: unknown ): SalesReport | null {
	const summary = parseSalesSummary( body );

	if ( summary === null || ! isRecord( body ) ) {
		return null;
	}

	const raw = body.currencies as readonly unknown[];
	const currencies: SalesCurrencyReport[] = [];

	for ( const [ index, entry ] of summary.currencies.entries() ) {
		const item = raw[ index ];
		const series = isRecord( item )
			? parseSeries(
					item.series,
					summary.from,
					summary.to,
					entry.vatIncluded
				)
			: null;

		if (
			series === null ||
			( currencies[ 0 ] !== undefined &&
				currencies[ 0 ].series.length !== series.length )
		) {
			return null;
		}

		currencies.push( { ...entry, series } );
	}

	return { ...summary, currencies };
}

/**
 * The path of `GET /me/sales` with only the given parameters (none: the
 * server's default period, the same request as the Overview).
 *
 * @param query Parameters.
 */
export function salesPath( query: SalesQuery ): string {
	const params = new URLSearchParams();

	for ( const key of [ 'from', 'to', 'interval' ] as const ) {
		const value = query[ key ];

		if ( value !== undefined && value !== '' ) {
			params.set( key, value );
		}
	}

	const search = params.toString();

	return `/merchboard/v1/me/sales${ search === '' ? '' : `?${ search }` }`;
}

/**
 * The default period's statistics (`GET /me/sales` without parameters).
 *
 * @param signal Abort signal.
 */
export function getDefaultSales(
	signal?: AbortSignal
): Promise< ApiResult< SalesSummary > > {
	return request(
		{
			path: salesPath( {} ),
			...( signal === undefined ? {} : { signal } ),
		},
		parseSalesSummary
	);
}

/**
 * The statistics of a period, with the series (`GET /me/sales`).
 *
 * @param query  Parameters; omitted ones take the server's defaults.
 * @param signal Abort signal.
 */
export function getSales(
	query: SalesQuery,
	signal?: AbortSignal
): Promise< ApiResult< SalesReport > > {
	return request(
		{
			path: salesPath( query ),
			...( signal === undefined ? {} : { signal } ),
		},
		parseSalesReport
	);
}
