import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';
import { act } from 'react';

import { parseSalesReport, salesPath } from '../api/sales';
import { App } from '../app/App';
import { ConfigContext } from '../app/config-context';
import { SalesSection } from '../sections/sales/SalesSection';
import { ME, mockApi, response } from '../testing/api';
import type { FakeResponse, RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render, setUrl } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const DEFAULT = '/merchboard/v1/me/sales';

/** Days of September 2026 from the 1st. */
function day( n: number ): string {
	return `2026-09-${ String( n ).padStart( 2, '0' ) }`;
}

/**
 * One currency of `GET /me/sales` with a daily series from September 1.
 *
 * @param code    Currency.
 * @param amounts Net sales per day.
 * @param totals  Totals (defaults: zeros).
 */
function currency(
	code: string,
	amounts: readonly string[],
	totals: Record< string, unknown > = {}
): Record< string, unknown > {
	const merged: Record< string, unknown > = {
		net_sales: '0.0000',
		sales: '0.0000',
		adjustments: '0.0000',
		units: 0,
		orders: 0,
		...totals,
	};

	return {
		currency: code,
		totals: {
			net_sales_incl_vat: merged.net_sales,
			sales_incl_vat: merged.sales,
			adjustments_incl_vat: merged.adjustments,
			...merged,
		},
		vat: { status: 'complete', missing_rows: 0 },
		series: amounts.map( ( amount, index ) => ( {
			start: day( index + 1 ),
			end: day( index + 1 ),
			net_sales: amount,
			net_sales_incl_vat: amount,
			units: index,
			orders: index === 0 ? 0 : 1,
		} ) ),
		products: [],
		truncated: false,
	};
}

/**
 * A daily report from September 1 to the n-th.
 *
 * @param days       Number of days.
 * @param currencies Currencies.
 */
function report(
	days: number,
	currencies: ReadonlyArray< Record< string, unknown > >
): Record< string, unknown > {
	return {
		from: day( 1 ),
		to: day( days ),
		interval: 'day',
		timezone: 'Europe/Copenhagen',
		currencies,
	};
}

const MIXED = report( 3, [
	{
		...currency( 'DKK', [ '1234.5000', '-266.0000', '0.0000' ], {
			net_sales: '968.5000',
			net_sales_incl_vat: '968.5000',
			sales: '1234.5000',
			adjustments: '-266.0000',
			units: 3,
			orders: 2,
		} ),
		profit: { currency: 'DKK', status: 'complete', amount: '161.4200' },
	},
] );

/** Text with Intl's non-breaking spaces as plain spaces. */
function plain( text: string | null | undefined ): string {
	return ( text ?? '' ).replace( /\s/gu, ' ' );
}

let rendered: Rendered | null = null;
let requests: RecordedRequest[] = [];

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

async function show(
	routes: Readonly< Record< string, Route > >
): Promise< HTMLElement > {
	requests = mockApi( routes );
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<SalesSection />
		</ConfigContext.Provider>
	);
	await flush();

	return rendered.container;
}

function paths(): string[] {
	return requests.map( ( request ) => request.path );
}

function rows( root: Element | null | undefined ): string[][] {
	return Array.from( root?.querySelectorAll( 'tbody tr' ) ?? [] ).map(
		( tr ) =>
			Array.from( tr.querySelectorAll( 'th, td' ) ).map( ( cell ) =>
				plain( cell.textContent )
			)
	);
}

function kpis( root: Element | null | undefined ): string[][] {
	return Array.from( root?.querySelectorAll( '.merchboard-kpi' ) ?? [] ).map(
		( kpi ) => [
			plain( kpi.querySelector( '.merchboard-kpi__label' )?.textContent ),
			plain( kpi.querySelector( '.merchboard-kpi__value' )?.textContent ),
		]
	);
}

/**
 * Sets a form control's value like a user would (React reads `input`/`change`).
 *
 * @param element Input or select.
 * @param value   New value.
 */
function setValue( element: Element | null, value: string ): void {
	if ( ! (
		element instanceof HTMLInputElement ||
		element instanceof HTMLSelectElement
	) ) {
		throw new Error( 'Control not found.' );
	}

	const setter = Object.getOwnPropertyDescriptor(
		Object.getPrototypeOf( element ),
		'value'
	)?.set;

	act( () => {
		setter?.call( element, value );
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		element.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
}

async function submit( container: HTMLElement ): Promise< void > {
	act( () => {
		container
			.querySelector( 'form' )
			?.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
	} );
	await flush();
}

describe( 'salesPath', () => {
	it( 'sends only the given parameters', () => {
		expect( salesPath( {} ) ).toBe( DEFAULT );
		expect( salesPath( { interval: 'week' } ) ).toBe(
			`${ DEFAULT }?interval=week`
		);
		expect(
			salesPath( {
				from: '2026-01-01',
				to: '2026-03-31',
				interval: 'month',
			} )
		).toBe( `${ DEFAULT }?from=2026-01-01&to=2026-03-31&interval=month` );
		expect( salesPath( { from: '', to: '' } ) ).toBe( DEFAULT );
	} );
} );

describe( 'parseSalesReport', () => {
	it( 'accepts the documented report, keeps amounts as strings and ignores products', () => {
		const parsed = parseSalesReport( {
			...MIXED,
			currencies: [
				{
					...currency( 'DKK', [ '1.0000' ] ),
					products: [ { product_id: 1, name: 'Secret' } ],
				},
			],
			to: day( 1 ),
		} );

		expect( parsed?.currencies[ 0 ]?.series ).toEqual( [
			{
				start: day( 1 ),
				end: day( 1 ),
				netSales: '1.0000',
				netSalesInclVat: '1.0000',
				units: 0,
				orders: 0,
			},
		] );
		expect( typeof parsed?.currencies[ 0 ]?.series[ 0 ]?.netSales ).toBe(
			'string'
		);
		expect( JSON.stringify( parsed ) ).not.toContain( 'Secret' );
	} );

	it( 'accepts a week series with partial first and last weeks', () => {
		const weeks = {
			from: '2026-07-01',
			to: '2026-07-16',
			interval: 'week',
			timezone: 'Europe/Copenhagen',
			currencies: [
				{
					...currency( 'DKK', [] ),
					series: [
						[ '2026-07-01', '2026-07-05' ],
						[ '2026-07-06', '2026-07-12' ],
						[ '2026-07-13', '2026-07-16' ],
					].map( ( [ start, end ] ) => ( {
						start,
						end,
						net_sales: '0.0000',
						net_sales_incl_vat: '0.0000',
						units: 0,
						orders: 0,
					} ) ),
				},
			],
		};

		expect(
			parseSalesReport( weeks )?.currencies[ 0 ]?.series
		).toHaveLength( 3 );
	} );

	const point = ( overrides: Record< string, unknown > ) => ( {
		...MIXED,
		currencies: [
			{
				...currency( 'DKK', [ '1.0000', '2.0000', '3.0000' ] ),
				series: [
					{
						start: day( 1 ),
						end: day( 1 ),
						net_sales: '1.0000',
						net_sales_incl_vat: '1.0000',
						units: 1,
						orders: 1,
						...overrides,
					},
					...(
						currency( 'DKK', [ '1.0000', '2.0000', '3.0000' ] )
							.series as unknown[]
					 ).slice( 1 ),
				],
			},
		],
	} );

	it.each( [
		[ 'a float amount', point( { net_sales: 1.5 } ) ],
		[ 'an amount without four decimals', point( { net_sales: '1.5' } ) ],
		[ 'a float unit count', point( { units: 0.5 } ) ],
		[ 'negative orders', point( { orders: -1 } ) ],
		[
			'a series that starts late',
			point( { start: day( 2 ), end: day( 2 ) } ),
		],
		[
			'a period that ends before it starts',
			point( { end: '2026-08-31' } ),
		],
		[
			'a missing series',
			{
				...MIXED,
				currencies: [ { ...currency( 'DKK', [] ), series: undefined } ],
			},
		],
		[ 'an empty series', report( 3, [ currency( 'DKK', [] ) ] ) ],
		[
			'a gap in the series',
			report( 4, [
				currency( 'DKK', [ '1.0000', '2.0000', '3.0000' ] ),
			] ),
		],
		[
			'series of different lengths',
			report( 2, [
				currency( 'DKK', [ '1.0000', '2.0000' ] ),
				{ ...currency( 'EUR', [ '1.0000' ] ), series: [] },
			] ),
		],
	] )( 'rejects %s', ( _label, body ) => {
		expect( parseSalesReport( body ) ).toBeNull();
	} );
} );

describe( 'sales section', () => {
	beforeEach( () => {
		setUrl( '/creator-dashboard/sales/' );
	} );

	it( 'loads the default period with GET /me/sales without parameters and never /me/overview', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
		} );

		expect( paths() ).toEqual( [ DEFAULT ] );
		expect( paths().some( ( path ) => path.includes( 'overview' ) ) ).toBe(
			false
		);
		expect(
			(
				container.querySelector(
					'input[name="from"]'
				) as HTMLInputElement
			 ).value
		).toBe( day( 1 ) );
		expect(
			(
				container.querySelector(
					'input[name="to"]'
				) as HTMLInputElement
			 ).value
		).toBe( day( 3 ) );
		expect(
			(
				container.querySelector(
					'select[name="interval"]'
				) as HTMLSelectElement
			 ).value
		).toBe( 'day' );
		expect( plain( container.textContent ) ).toContain(
			'Period: 1. sep. 2026 – 3. sep. 2026 · Interval: Day'
		);
	} );

	it( 'shows net sales, units and orders exactly as sent, with the explanation', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
		} );

		expect( kpis( container ) ).toEqual( [
			[ 'Sales (incl. VAT)', '968,50 kr.' ],
			[ 'Units', '3' ],
			[ 'Orders', '2' ],
			[ 'Profit', '161,42 kr.' ],
		] );
		expect( container.querySelector( 'h2' )?.textContent ).toBe(
			'Choose period'
		);
		expect(
			container.querySelector( '.merchboard-card__title' )?.textContent
		).toBe( 'Your sales' );
		expect( container.textContent ).toContain(
			"The shop's sales of your products after discounts, including VAT, excluding shipping and fees. It is not your payout."
		);
	} );

	it( 'renders the series in a semantic table with the same figures as the chart, including negative periods', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
		} );
		const table = container.querySelector( 'table' );

		expect( table?.querySelector( 'caption' )?.textContent ).toBe(
			'Sales, units and orders per period (DKK)'
		);
		expect(
			Array.from( table?.querySelectorAll( 'thead th' ) ?? [] ).map(
				( th ) => [ th.textContent, th.getAttribute( 'scope' ) ]
			)
		).toEqual( [
			[ 'Period', 'col' ],
			[ 'Sales (incl. VAT)', 'col' ],
			[ 'Units', 'col' ],
			[ 'Orders', 'col' ],
		] );
		expect( rows( table ) ).toEqual( [
			[ '1. sep. 2026', '1.234,50 kr.', '0', '0' ],
			[ '2. sep. 2026', '-266,00 kr.', '1', '1' ],
			[ '3. sep. 2026', '0,00 kr.', '2', '1' ],
		] );

		const titles = Array.from(
			container.querySelectorAll( 'svg rect title' )
		).map( ( title ) => plain( title.textContent ) );
		expect( titles ).toEqual( [
			'1. sep. 2026: 1.234,50 kr.',
			'2. sep. 2026: -266,00 kr.',
		] );
	} );

	it( 'draws a decorative, responsive SVG deterministically, with negative columns below the baseline', async () => {
		const first = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
		} );
		const svg = first.querySelector( 'svg' );
		const markup = svg?.outerHTML;

		expect( svg?.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( svg?.getAttribute( 'focusable' ) ).toBe( 'false' );
		expect( svg?.getAttribute( 'viewBox' ) ).toBe( '0 0 30 100' );
		expect( svg?.getAttribute( 'preserveAspectRatio' ) ).toBe( 'none' );
		expect( svg?.hasAttribute( 'width' ) ).toBe( false );
		expect(
			Array.from( svg?.querySelectorAll( 'rect' ) ?? [] ).map(
				( rect ) => [
					rect.getAttribute( 'x' ),
					rect.getAttribute( 'y' ),
					rect.getAttribute( 'height' ),
					rect.getAttribute( 'class' ),
				]
			)
		).toEqual( [
			[ '1.5', '0', '82.27', 'merchboard-sales-chart__column' ],
			[
				'11.5',
				'82.27',
				'17.72',
				'merchboard-sales-chart__column merchboard-sales-chart__column--negative',
			],
		] );
		expect( svg?.querySelector( 'line' )?.getAttribute( 'y1' ) ).toBe(
			'82.27'
		);
		expect(
			plain( first.querySelector( '[data-tick="max"]' )?.textContent )
		).toBe( '1.234,50 kr.' );
		expect(
			plain( first.querySelector( '[data-tick="min"]' )?.textContent )
		).toBe( '-266,00 kr.' );
		expect(
			first.querySelector( 'figure figcaption' )?.textContent
		).toContain( 'Sales per day (incl. VAT)' );

		rendered?.unmount();
		const second = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
		} );
		expect( second.querySelector( 'svg' )?.outerHTML ).toBe( markup );
	} );

	it( 'handles a series of zeros: a baseline, no columns, no NaN', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () =>
				response(
					200,
					report( 3, [
						currency( 'DKK', [ '0.0000', '0.0000', '0.0000' ], {
							units: 0,
						} ),
					] )
				),
		} );
		const svg = container.querySelector( 'svg' );

		expect( svg?.querySelectorAll( 'rect' ) ).toHaveLength( 0 );
		expect( svg?.querySelector( 'line' )?.getAttribute( 'y1' ) ).toBe(
			'100'
		);
		expect( svg?.outerHTML ).not.toContain( 'NaN' );
		expect( rows( container.querySelector( 'table' ) ) ).toHaveLength( 3 );
		expect(
			plain(
				container.querySelector( '[data-tick="zero"]' )?.textContent
			)
		).toBe( '0,00 kr.' );
	} );

	it( 'handles a single period', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () =>
				response(
					200,
					report( 1, [ currency( 'DKK', [ '42.0000' ] ) ] )
				),
		} );

		expect(
			container.querySelector( 'svg' )?.getAttribute( 'viewBox' )
		).toBe( '0 0 10 100' );
		expect( container.querySelectorAll( 'svg rect' ) ).toHaveLength( 1 );
		expect(
			container.querySelectorAll( '.merchboard-sales-chart__dates span' )
		).toHaveLength( 1 );
		expect( rows( container.querySelector( 'table' ) ) ).toEqual( [
			[ '1. sep. 2026', '42,00 kr.', '0', '0' ],
		] );
	} );

	it( 'handles amounts beyond Number precision without rounding or layout errors', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () =>
				response(
					200,
					report( 2, [
						currency(
							'DKK',
							[ '123456789012345.6789', '-123456789012345.6789' ],
							{ net_sales: '0.0000' }
						),
					] )
				),
		} );

		expect( rows( container.querySelector( 'table' ) ) ).toEqual( [
			[ '1. sep. 2026', '123.456.789.012.345,68 kr.', '0', '0' ],
			[ '2. sep. 2026', '-123.456.789.012.345,68 kr.', '1', '1' ],
		] );
		expect(
			Array.from( container.querySelectorAll( 'svg rect' ) ).map(
				( r ) => [ r.getAttribute( 'y' ), r.getAttribute( 'height' ) ]
			)
		).toEqual( [
			[ '0', '50' ],
			[ '50', '50' ],
		] );
	} );

	it( 'shows one block per currency with the code as heading, never a sum', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () =>
				response(
					200,
					report( 2, [
						currency( 'DKK', [ '100.0000', '0.0000' ], {
							net_sales: '100.0000',
							net_sales_incl_vat: '100.0000',
							units: 1,
							orders: 1,
						} ),
						currency( 'EUR', [ '0.0000', '30.2500' ], {
							net_sales: '30.2500',
							net_sales_incl_vat: '30.2500',
							units: 2,
							orders: 1,
						} ),
					] )
				),
		} );
		const cards = Array.from(
			container.querySelectorAll( 'section.merchboard-card' )
		);

		expect(
			cards.map(
				( card ) =>
					card.querySelector( '.merchboard-card__title' )?.textContent
			)
		).toEqual( [ 'DKK', 'EUR' ] );
		expect( cards.map( ( card ) => kpis( card )[ 0 ] ) ).toEqual( [
			[ 'Sales (incl. VAT)', '100,00 kr.' ],
			[ 'Sales (incl. VAT)', '30,25 €' ],
		] );
		expect(
			cards.map( ( card ) => card.querySelectorAll( 'svg' ).length )
		).toEqual( [ 1, 1 ] );
		expect(
			cards.map(
				( card ) => card.querySelector( 'caption' )?.textContent
			)
		).toEqual( [
			'Sales, units and orders per period (DKK)',
			'Sales, units and orders per period (EUR)',
		] );
		expect( container.textContent ).toContain(
			'The figures are shown per currency and never added together.'
		);
		expect( plain( container.textContent ) ).not.toContain( '130' );
	} );

	it( 'shows an empty state instead of an empty chart when the period has no sales', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, report( 3, [] ) ),
		} );

		expect( container.textContent ).toContain( 'No sales in this period.' );
		expect( container.querySelector( 'svg' ) ).toBeNull();
		expect( container.querySelector( 'table' ) ).toBeNull();
		expect( container.querySelector( '.merchboard-kpi' ) ).toBeNull();
	} );

	it( 'sends the chosen dates and interval, keeps the dates when the interval changes, and shows the new series', async () => {
		const weekly = `${ DEFAULT }?from=2026-09-01&to=2026-09-03&interval=week`;
		const ranged = `${ DEFAULT }?from=2026-08-01&to=2026-09-03&interval=month`;
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
			[ `GET ${ weekly }` ]: () =>
				response( 200, {
					...MIXED,
					interval: 'week',
					currencies: [
						{
							...currency( 'DKK', [] ),
							series: [
								{
									start: day( 1 ),
									end: day( 3 ),
									net_sales: '968.5000',
									net_sales_incl_vat: '968.5000',
									units: 3,
									orders: 2,
								},
							],
						},
					],
				} ),
			[ `GET ${ ranged }` ]: () =>
				response( 200, {
					...MIXED,
					from: '2026-08-01',
					interval: 'month',
					currencies: [
						{
							...currency( 'DKK', [] ),
							series: [
								{
									start: '2026-08-01',
									end: '2026-08-31',
									net_sales: '0.0000',
									net_sales_incl_vat: '0.0000',
									units: 0,
									orders: 0,
								},
								{
									start: day( 1 ),
									end: day( 3 ),
									net_sales: '968.5000',
									net_sales_incl_vat: '968.5000',
									units: 3,
									orders: 2,
								},
							],
						},
					],
				} ),
		} );

		setValue(
			container.querySelector( 'select[name="interval"]' ),
			'week'
		);
		await submit( container );

		expect( paths() ).toEqual( [ DEFAULT, weekly ] );
		expect( rows( container.querySelector( 'table' ) ) ).toEqual( [
			[ '1. sep. 2026 – 3. sep. 2026', '968,50 kr.', '3', '2' ],
		] );
		expect(
			container.querySelector( 'figure figcaption' )?.textContent
		).toContain( 'Sales per week (incl. VAT)' );

		setValue(
			container.querySelector( 'input[name="from"]' ),
			'2026-08-01'
		);
		setValue(
			container.querySelector( 'select[name="interval"]' ),
			'month'
		);
		await submit( container );

		expect( paths() ).toEqual( [ DEFAULT, weekly, ranged ] );
		expect( rows( container.querySelector( 'table' ) ) ).toEqual( [
			[ '1. aug. 2026 – 31. aug. 2026', '0,00 kr.', '0', '0' ],
			[ '1. sep. 2026 – 3. sep. 2026', '968,50 kr.', '3', '2' ],
		] );
	} );

	it( 'does not send a period that ends before it starts', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
		} );

		setValue(
			container.querySelector( 'input[name="from"]' ),
			'2026-09-10'
		);
		await submit( container );

		expect( paths() ).toEqual( [ DEFAULT ] );
		const to = container.querySelector( 'input[name="to"]' );
		expect( to?.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( container.textContent ).toContain(
			'The end date must be on or after the start date.'
		);
	} );

	it( 'explains a period the server rejects (400) without the server message', async () => {
		const rejected = `${ DEFAULT }?from=2020-01-01&to=2026-09-03&interval=day`;
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
			[ `GET ${ rejected }` ]: () =>
				response( 400, {
					code: 'merchboard_invalid_period',
					message: 'Internal <b>detail</b>',
				} ),
		} );

		setValue(
			container.querySelector( 'input[name="from"]' ),
			'2020-01-01'
		);
		await submit( container );

		expect( paths() ).toEqual( [ DEFAULT, rejected ] );
		expect( container.textContent ).toContain(
			'This period cannot be shown. Choose at most 366 days, weeks or months and at most five years.'
		);
		expect( container.textContent ).not.toContain( 'Internal' );
		expect(
			(
				container.querySelector(
					'input[name="from"]'
				) as HTMLInputElement
			 ).value
		).toBe( '2020-01-01' );
	} );

	it( 'shows a skeleton while loading, then an error state whose retry loads again', async () => {
		let resolve: ( value: FakeResponse ) => void = () => undefined;
		let calls = 0;
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => {
				calls++;

				return calls === 1
					? new Promise< FakeResponse >( ( r ) => {
							resolve = r;
						} )
					: response( 200, MIXED );
			},
		} );

		expect(
			container.querySelector( '[role="status"]' )?.textContent
		).toContain( 'Loading sales…' );
		expect(
			container
				.querySelector( 'button[type="submit"]' )
				?.hasAttribute( 'disabled' )
		).toBe( true );

		await act( async () =>
			resolve(
				response( 500, {
					code: 'merchboard_internal_error',
					message: 'SQL <b>detail</b>',
				} )
			)
		);
		await flush();

		expect(
			container.querySelector( '[role="alert"]' )?.textContent
		).toContain( 'Something went wrong.' );
		expect( container.textContent ).not.toContain( 'SQL' );

		click( container.querySelector( '[role="alert"] button' ) );
		await flush();

		expect( calls ).toBe( 2 );
		expect( kpis( container )[ 0 ]?.[ 1 ] ).toBe( '968,50 kr.' );
	} );

	it( 'rejects a malformed response with the error state', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () =>
				response( 200, report( 1, [ currency( 'DKK', [ '1.5' ] ) ] ) ),
		} );

		expect( container.textContent ).toContain( 'Something went wrong.' );
		expect( container.querySelector( 'svg' ) ).toBeNull();
	} );

	it( 'says the figures are not available without the sales route (no WooCommerce)', async () => {
		const container = await show( {} );

		expect( container.textContent ).toContain(
			'Sales figures are not available.'
		);
		expect( container.querySelector( '[role="alert"]' ) ).toBeNull();
	} );

	it( 'keeps every figure available outside the SVG: labelled controls and a focusable table region', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
		} );

		for ( const name of [ 'from', 'to', 'interval' ] ) {
			const control = container.querySelector( `[name="${ name }"]` );
			const label = container.querySelector(
				`label[for="${ control?.id ?? '' }"]`
			);
			expect( label?.textContent ).toBe(
				{ from: 'From', to: 'To', interval: 'Interval' }[ name ]
			);
		}

		expect(
			Array.from(
				container.querySelectorAll( 'select[name="interval"] option' )
			).map( ( option ) => option.textContent )
		).toEqual( [ 'Day', 'Week', 'Month' ] );

		const region = container.querySelector( '[role="region"]' );
		expect( region?.getAttribute( 'tabindex' ) ).toBe( '0' );
		expect( region?.querySelector( 'table' ) ).not.toBeNull();
		expect(
			container.querySelector( 'svg' )?.closest( '[aria-hidden="true"]' )
		).not.toBeNull();
		expect(
			container
				.querySelector( '.merchboard-sales-chart__axis' )
				?.getAttribute( 'aria-hidden' )
		).toBe( 'true' );
		expect(
			container.querySelector( 'figcaption' )?.textContent
		).toContain( '(the table below has the same figures)' );
	} );

	it( 'is a page of the app at /sales when the server sends the section', async () => {
		requests = mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
			[ `GET ${ DEFAULT }` ]: () => response( 200, MIXED ),
		} );
		rendered = render(
			<App
				config={ {
					...CONFIG,
					sections: [
						...CONFIG.sections,
						{ id: 'sales', label: 'Sales', path: 'sales' },
					],
				} }
			/>
		);
		await flush();
		await flush();

		const container = rendered.container;
		expect( container.querySelector( 'h1' )?.textContent ).toBe( 'Sales' );
		expect(
			container.querySelector(
				'.merchboard-sidebar [aria-current="page"]'
			)?.textContent
		).toBe( 'Sales' );
		expect( paths() ).toContain( DEFAULT );
		expect( container.querySelector( 'table' ) ).not.toBeNull();
	} );
} );

/** The parts of a report's currency the VAT tests change. */
interface VatCurrency {
	totals: Record< string, unknown >;
	vat?: { status: string; missing_rows: number };
	series: Array< Record< string, unknown > >;
}

describe( 'sales including VAT (ADR-053)', () => {
	/**
	 * DKK with 1000.00 excl. VAT and 1250.00 incl. VAT over two days, or
	 * without VAT (null amounts) when a row lacks its stored VAT.
	 *
	 * @param complete Whether every row has its stored VAT.
	 */
	function vatReport( complete: boolean ): Record< string, unknown > {
		return report( 2, [
			{
				currency: 'DKK',
				totals: {
					net_sales: '1000.0000',
					sales: '1200.0000',
					adjustments: '-200.0000',
					net_sales_incl_vat: complete ? '1250.0000' : null,
					sales_incl_vat: complete ? '1500.0000' : null,
					adjustments_incl_vat: complete ? '-250.0000' : null,
					units: 4,
					orders: 2,
				},
				vat: complete
					? { status: 'complete', missing_rows: 0 }
					: { status: 'incomplete', missing_rows: 3 },
				profit: null,
				series: [
					{
						start: day( 1 ),
						end: day( 1 ),
						net_sales: '1200.0000',
						net_sales_incl_vat: complete ? '1500.0000' : null,
						units: 5,
						orders: 2,
					},
					{
						start: day( 2 ),
						end: day( 2 ),
						net_sales: '-200.0000',
						net_sales_incl_vat: complete ? '-250.0000' : null,
						units: -1,
						orders: 0,
					},
				],
				products: [],
				truncated: false,
			},
		] );
	}

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
		jest.restoreAllMocks();
	} );

	it( 'shows the totals, the chart and the table including VAT when every row has its VAT', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, vatReport( true ) ),
		} );

		expect( kpis( container )[ 0 ] ).toEqual( [
			'Sales (incl. VAT)',
			'1.250,00 kr.',
		] );
		expect( rows( container.querySelector( 'table' ) ) ).toEqual( [
			[ '1. sep. 2026', '1.500,00 kr.', '5', '2' ],
			[ '2. sep. 2026', '-250,00 kr.', '-1', '0' ],
		] );
		expect(
			container.querySelector( 'figure figcaption' )?.textContent
		).toContain( 'Sales per day (incl. VAT)' );
		expect(
			Array.from( container.querySelectorAll( 'rect title' ) ).map(
				( title ) => plain( title.textContent )
			)
		).toEqual( [
			'1. sep. 2026: 1.500,00 kr.',
			'2. sep. 2026: -250,00 kr.',
		] );
		expect( container.textContent ).not.toContain( '1.000,00' );
		expect( container.textContent ).not.toContain(
			'VAT is missing for some older sales'
		);
	} );

	it( 'falls back to the amounts excluding VAT, labelled and explained, when a row lacks its VAT', async () => {
		const container = await show( {
			[ `GET ${ DEFAULT }` ]: () => response( 200, vatReport( false ) ),
		} );

		expect( kpis( container )[ 0 ] ).toEqual( [
			'Net sales (excl. VAT)',
			'1.000,00 kr.',
		] );
		expect( rows( container.querySelector( 'table' ) ) ).toEqual( [
			[ '1. sep. 2026', '1.200,00 kr.', '5', '2' ],
			[ '2. sep. 2026', '-200,00 kr.', '-1', '0' ],
		] );
		expect(
			container.querySelector( 'figure figcaption' )?.textContent
		).toContain( 'Net sales per day' );
		expect( container.textContent ).toContain(
			'VAT is missing for some older sales in this period, so the amounts are shown excluding VAT.'
		);
		expect( container.textContent ).not.toContain( '1.250,00' );
	} );

	it.each( [
		[
			'complete VAT without the amounts',
			( entry: VatCurrency ) => {
				entry.totals.net_sales_incl_vat = null;
			},
		],
		[
			'incomplete VAT with amounts',
			( entry: VatCurrency ) => {
				entry.vat = { status: 'incomplete', missing_rows: 1 };
			},
		],
		[
			'a missing VAT state',
			( entry: VatCurrency ) => {
				delete entry.vat;
			},
		],
		[
			'complete VAT with missing rows',
			( entry: VatCurrency ) => {
				entry.vat = { status: 'complete', missing_rows: 2 };
			},
		],
		[
			'a float amount incl. VAT',
			( entry: VatCurrency ) => {
				entry.totals.net_sales_incl_vat = 1250;
			},
		],
		[
			'a period without its amount incl. VAT',
			( entry: VatCurrency ) => {
				const period = entry.series[ 1 ];

				if ( period !== undefined ) {
					period.net_sales_incl_vat = null;
				}
			},
		],
	] )( 'rejects %s', ( _label, change ) => {
		const body = JSON.parse( JSON.stringify( vatReport( true ) ) ) as {
			currencies: VatCurrency[];
		};
		const entry = body.currencies[ 0 ];

		expect( entry ).toBeDefined();

		if ( entry !== undefined ) {
			change( entry );
		}

		expect( parseSalesReport( body ) ).toBeNull();
	} );

	it( 'parses the VAT state and keeps every amount as a string', () => {
		const parsed = parseSalesReport( vatReport( false ) );

		expect( parsed?.currencies[ 0 ] ).toMatchObject( {
			vatIncluded: false,
			missingVatRows: 3,
			totals: { netSales: '1000.0000', netSalesInclVat: null },
		} );
		expect( parsed?.currencies[ 0 ]?.series[ 0 ]?.netSalesInclVat ).toBe(
			null
		);
		expect(
			parseSalesReport( vatReport( true ) )?.currencies[ 0 ]?.totals
				.netSalesInclVat
		).toBe( '1250.0000' );
	} );
} );
