import { afterEach, describe, expect, it } from '@jest/globals';

import { parseOrderLine } from '../api/orders';
import { parseProduct } from '../api/products';
import { parseProfit, parseProfitList } from '../api/profit';
import { parseSalesSummary } from '../api/sales';
import { ConfigContext } from '../app/config-context';
import { ProfitValue } from '../components/ProfitValue';
import { SalesFigures } from '../sections/sales/SalesFigures';
import { CONFIG, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';
import type { Profit } from '../types/profit';

/** Text with Intl's non-breaking spaces as plain spaces. */
function plain( text: string | null | undefined ): string {
	return ( text ?? '' ).replace( /\s/gu, ' ' );
}

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

function shown( profit: Profit | null, emptyText?: string ): string {
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<ProfitValue
				profit={ profit }
				{ ...( emptyText === undefined ? {} : { emptyText } ) }
			/>
		</ConfigContext.Provider>
	);

	return plain( rendered.container.textContent );
}

const complete = ( amount: string ): Profit => ( {
	currency: 'DKK',
	status: 'complete',
	amount,
} );

describe( 'parseProfit', () => {
	it( 'accepts the three statuses with a coherent amount', () => {
		expect( parseProfit( complete( '20.0000' ) ) ).toEqual(
			complete( '20.0000' )
		);
		expect(
			parseProfit( {
				currency: 'DKK',
				status: 'incomplete',
				amount: null,
			} )
		).toEqual( { currency: 'DKK', status: 'incomplete', amount: null } );
		expect(
			parseProfit( { currency: 'EUR', status: 'unknown', amount: null } )
		).toEqual( { currency: 'EUR', status: 'unknown', amount: null } );
	} );

	it.each( [
		[
			'a complete profit without amount',
			{ currency: 'DKK', status: 'complete', amount: null },
		],
		[
			'an unknown profit with an amount',
			{ currency: 'DKK', status: 'unknown', amount: '0.0000' },
		],
		[
			'an incomplete profit with an amount',
			{ currency: 'DKK', status: 'incomplete', amount: '12.0000' },
		],
		[
			'a number instead of a string',
			{ currency: 'DKK', status: 'complete', amount: 20 },
		],
		[
			'an amount without four decimals',
			{ currency: 'DKK', status: 'complete', amount: '20.5' },
		],
		[
			'an unknown status',
			{ currency: 'DKK', status: 'partial', amount: null },
		],
		[
			'a bad currency',
			{ currency: 'dkk', status: 'unknown', amount: null },
		],
	] )( 'rejects %s', ( _label: string, value: unknown ) => {
		expect( parseProfit( value ) ).toBeNull();
	} );

	it( 'reads a list per currency, null for no sales, and rejects malformed lists', () => {
		expect( parseProfitList( null ) ).toBeNull();
		expect( parseProfitList( [ complete( '1.0000' ) ] ) ).toEqual( [
			complete( '1.0000' ),
		] );
		expect( parseProfitList( [] ) ).toBeUndefined();
		expect( parseProfitList( undefined ) ).toBeUndefined();
		expect(
			parseProfitList( [ complete( '1.0000' ), { status: 'x' } ] )
		).toBeUndefined();
	} );

	it( 'is required by products and order lines, so a missing profit never looks like no sales', () => {
		const product = {
			id: 1,
			name: 'Tee',
			status: 'publish',
			price: null,
			stock_status: null,
			image: null,
		};

		expect( parseProduct( product ) ).toBeNull();
		expect(
			parseProduct( { ...product, profit: null } )?.profit
		).toBeNull();

		const line = {
			line_id: 1,
			order_number: '1',
			date: '2026-08-15T08:30:00Z',
			status: 'processing',
			status_label: 'Processing',
			product_name: 'Tee',
			quantity: 1,
			refunded: false,
			counted: true,
		};

		expect( parseOrderLine( line ) ).toBeNull();
		expect(
			parseOrderLine( {
				...line,
				profit: { currency: 'DKK', status: 'complete', amount: null },
				order_profit: null,
				order_lines: 1,
			} )
		).toBeNull();
	} );

	it( 'rejects a sales profit in another currency than its block', () => {
		const block = {
			currency: 'DKK',
			totals: {
				net_sales: '0.0000',
				sales: '0.0000',
				adjustments: '0.0000',
				net_sales_incl_vat: '0.0000',
				sales_incl_vat: '0.0000',
				adjustments_incl_vat: '0.0000',
				units: 0,
				orders: 0,
			},
			vat: { status: 'complete', missing_rows: 0 },
		};
		const summary = ( profit: unknown ) =>
			parseSalesSummary( {
				from: '2026-09-01',
				to: '2026-09-30',
				interval: 'day',
				timezone: 'Europe/Copenhagen',
				currencies: [ { ...block, profit } ],
			} );

		expect(
			summary( complete( '5.0000' ) )?.currencies[ 0 ]?.profit
		).toEqual( complete( '5.0000' ) );
		expect(
			summary( { currency: 'EUR', status: 'complete', amount: '5.0000' } )
		).toBeNull();
	} );
} );

describe( 'ProfitValue', () => {
	it( 'shows a complete profit as money, also 0 and negative', () => {
		expect( shown( complete( '29.8000' ) ) ).toBe( '29,80 kr.' );
		expect( shown( complete( '0.0000' ) ) ).toBe( '0,00 kr.' );
		expect( shown( complete( '-16.0000' ) ) ).toBe( '-16,00 kr.' );
	} );

	it( 'never shows an incomplete or unknown profit as an amount', () => {
		for ( const status of [ 'incomplete', 'unknown' ] as const ) {
			const text = shown( { currency: 'DKK', status, amount: null } );

			expect( text ).toBe(
				status === 'incomplete' ? 'Incomplete' : 'Unknown'
			);
			expect( text ).not.toMatch( /\d|kr/ );
			rendered?.unmount();
			rendered = null;
		}
	} );

	it( 'shows a missing profit as its explanation, never as 0', () => {
		expect( shown( null, 'No sales' ) ).toBe( 'No sales' );
		rendered?.unmount();
		rendered = null;
		expect( shown( null ) ).toBe( 'Not available' );
	} );
} );

describe( 'SalesFigures', () => {
	const entry = {
		currency: 'DKK',
		totals: {
			netSales: '968.5000',
			sales: '1234.5000',
			adjustments: '-266.0000',
			units: 3,
			orders: 2,
			netSalesInclVat: '1210.6250',
			salesInclVat: '1543.1250',
			adjustmentsInclVat: '-332.5000',
		},
		vatIncluded: true,
		missingVatRows: 0,
	};

	function figures( profit: Profit | null, withProfit: boolean ): string[] {
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<SalesFigures
					entry={ { ...entry, profit } }
					locale="da_DK"
					labelLevel="h3"
					withProfit={ withProfit }
				/>
			</ConfigContext.Provider>
		);

		return Array.from(
			rendered.container.querySelectorAll( '.merchboard-kpi' )
		).map( ( li ) => plain( li.textContent ) );
	}

	it( 'shows profit as its own figure next to the unchanged sales figures', () => {
		expect( figures( complete( '161.4200' ), true ) ).toEqual( [
			'Sales (incl. VAT)1.210,63 kr.',
			'Units3',
			'Orders2',
			'Profit161,42 kr.',
		] );
	} );

	it( 'shows an incomplete period profit without a total', () => {
		expect(
			figures(
				{ currency: 'DKK', status: 'incomplete', amount: null },
				true
			)[ 3 ]
		).toBe( 'ProfitIncomplete' );
	} );

	it( 'leaves the overview figures unchanged', () => {
		expect( figures( complete( '1.0000' ), false ) ).toHaveLength( 3 );
	} );
} );
