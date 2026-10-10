import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';

import { parseOrderLine } from '../api/orders';
import { App } from '../app/App';
import { ConfigContext } from '../app/config-context';
import { OrdersSection } from '../sections/orders/OrdersSection';
import { ME, PROFILE, listResponse, mockApi, response } from '../testing/api';
import type { Route } from '../testing/api';
import { CONFIG, click, flush, render, setUrl } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const FIRST = '/merchboard/v1/me/orders?page=1&per_page=20';
const SECOND = '/merchboard/v1/me/orders?page=2&per_page=20';

const SALE = {
	line_id: 4101,
	order_number: 'A-1041',
	date: '2026-08-15T08:30:00Z',
	status: 'processing',
	status_label: 'Processing',
	product_name: 'Tour tee',
	quantity: 2,
	refunded: false,
	counted: true,
	profit: { currency: 'DKK', status: 'complete', amount: '20.0000' },
	order_profit: { currency: 'DKK', status: 'complete', amount: '40.0000' },
	order_lines: 2,
};

const REFUNDED = {
	...SALE,
	line_id: 4201,
	order_number: '1042',
	product_name: 'Hoodie',
	quantity: 1,
	refunded: true,
	profit: { currency: 'DKK', status: 'unknown', amount: null },
	order_profit: { currency: 'DKK', status: 'unknown', amount: null },
	order_lines: 1,
};

const NOT_COUNTED = {
	...SALE,
	line_id: 4301,
	order_number: '1043',
	status: 'cancelled',
	status_label: 'Cancelled',
	product_name: 'Cap',
	quantity: 0,
	counted: false,
	profit: { currency: 'DKK', status: 'complete', amount: '0.0000' },
	order_profit: { currency: 'DKK', status: 'complete', amount: '0.0000' },
	order_lines: 1,
};

const BOTH = {
	...SALE,
	line_id: 4401,
	order_number: '1044',
	status: 'deleted',
	status_label: 'Deleted',
	product_name: 'Mug',
	quantity: 0,
	refunded: true,
	counted: false,
	profit: { currency: 'DKK', status: 'incomplete', amount: null },
	order_profit: { currency: 'DKK', status: 'incomplete', amount: null },
	order_lines: 1,
};

/** An amount as the dashboard formats it (site locale da_DK, DKK). */
function money( amount: string ): string {
	return new Intl.NumberFormat( 'da-DK', {
		style: 'currency',
		currency: 'DKK',
	} ).format( Number( amount ) );
}

/** The date as the section shows it (site locale, viewer's time zone). */
function shown( iso: string ): string {
	return new Intl.DateTimeFormat( 'da-DK', { dateStyle: 'medium' } ).format(
		new Date( iso )
	);
}

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

async function show(
	routes: Readonly< Record< string, Route > >
): Promise< { container: HTMLElement; paths: () => string[] } > {
	const requests = mockApi( routes );
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<OrdersSection />
		</ConfigContext.Provider>
	);
	await flush();

	return {
		container: rendered.container,
		paths: () => requests.map( ( r ) => r.path ),
	};
}

function rows( container: HTMLElement ): string[][] {
	return Array.from( container.querySelectorAll( 'tbody tr' ) ).map( ( tr ) =>
		Array.from( tr.querySelectorAll( 'td' ) ).map(
			( td ) => td.textContent ?? ''
		)
	);
}

describe( 'parseOrderLine', () => {
	it( 'accepts the documented line and reads only its nine fields', () => {
		expect(
			parseOrderLine( {
				...SALE,
				customer_email: 'karen@example.org',
				total: '100.00',
			} )
		).toEqual( {
			lineId: 4101,
			orderNumber: 'A-1041',
			date: '2026-08-15T08:30:00Z',
			status: 'processing',
			statusLabel: 'Processing',
			productName: 'Tour tee',
			quantity: 2,
			refunded: false,
			counted: true,
			profit: { currency: 'DKK', status: 'complete', amount: '20.0000' },
			orderProfit: {
				currency: 'DKK',
				status: 'complete',
				amount: '40.0000',
			},
			orderLines: 2,
		} );
		expect(
			parseOrderLine( { ...SALE, product_name: '' } )?.productName
		).toBe( '' );
		expect( parseOrderLine( { ...SALE, quantity: 0 } )?.quantity ).toBe(
			0
		);
	} );

	it.each( [
		[ 'not an object', [ SALE ] ],
		[ 'missing line id', { ...SALE, line_id: undefined } ],
		[ 'line id 0', { ...SALE, line_id: 0 } ],
		[ 'empty order number', { ...SALE, order_number: '' } ],
		[ 'order number as number', { ...SALE, order_number: 1041 } ],
		[ 'local date', { ...SALE, date: '2026-08-15 08:30:00' } ],
		[ 'impossible date', { ...SALE, date: '2026-13-45T08:30:00Z' } ],
		[ 'status with spaces', { ...SALE, status: 'on hold' } ],
		[ 'empty status label', { ...SALE, status_label: '' } ],
		[ 'missing product name', { ...SALE, product_name: undefined } ],
		[ 'fractional quantity', { ...SALE, quantity: 1.5 } ],
		[ 'quantity as string', { ...SALE, quantity: '2' } ],
		[ 'refunded as number', { ...SALE, refunded: 1 } ],
		[ 'missing counted', { ...SALE, counted: undefined } ],
	] )( 'rejects a malformed line (%s)', ( _case, body ) => {
		expect( parseOrderLine( body ) ).toBeNull();
	} );
} );

describe( 'Orders section', () => {
	it( 'renders the first page with the approved fields', async () => {
		const { container, paths } = await show( {
			[ `GET ${ FIRST }` ]: () =>
				listResponse( [ SALE, REFUNDED, NOT_COUNTED ], 3, 1 ),
		} );

		expect( paths() ).toEqual( [ FIRST ] );
		expect( container.querySelector( 'h2' )?.textContent ).toBe(
			'Your order lines'
		);
		expect( container.querySelector( 'caption' )?.textContent ).toBe(
			'Your order lines'
		);
		expect(
			Array.from( container.querySelectorAll( 'thead th' ) ).map(
				( th ) => th.textContent
			)
		).toEqual( [
			'Date',
			'Order',
			'Product',
			'Quantity',
			'Status',
			'Notes',
			'Profit',
		] );
		expect( rows( container ) ).toEqual( [
			[
				shown( SALE.date ),
				'A-1041',
				'Tour tee',
				'2',
				'Processing',
				'–None',
				`${ money( '20' ) }Order total: ${ money( '40' ) }`,
			],
			[
				shown( SALE.date ),
				'1042',
				'Hoodie',
				'1',
				'Processing',
				'Refund recorded',
				'Unknown',
			],
			[
				shown( SALE.date ),
				'1043',
				'Cap',
				'0',
				'Cancelled',
				'Not counted',
				money( '0' ),
			],
		] );
	} );

	it( 'shows the API date in a time element and the quantity as sent', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () => listResponse( [ NOT_COUNTED ], 1, 1 ),
		} );
		const time = container.querySelector( 'time' );

		expect( time?.getAttribute( 'dateTime' ) ).toBe(
			'2026-08-15T08:30:00Z'
		);
		expect( time?.textContent ).toBe( shown( '2026-08-15T08:30:00Z' ) );
		expect(
			container.querySelector( 'tbody .merchboard-table__cell--end' )
				?.textContent
		).toBe( '0' );
	} );

	it( 'shows no line, order or product ids and no amounts', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () => listResponse( [ SALE ], 1, 1 ),
		} );
		const text = container.textContent ?? '';

		expect( text ).not.toContain( '4101' );
		expect( text ).not.toContain( 'DKK' );
		expect( container.querySelector( 'a' ) ).toBeNull();
	} );

	it( 'shows a deleted order with both markers as text', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () => listResponse( [ BOTH ], 1, 1 ),
		} );
		const badges = Array.from(
			container.querySelectorAll( 'tbody .merchboard-badge' )
		).map( ( badge ) => badge.textContent );

		expect( badges ).toEqual( [
			'Refund recorded',
			'Not counted',
			'Incomplete',
		] );
		expect( rows( container )[ 0 ]?.[ 4 ] ).toBe( 'Deleted' );
	} );

	it( 'explains the markers without claiming a full refund or a cause', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () => listResponse( [ SALE ], 1, 1 ),
		} );
		const legend = Array.from(
			container.querySelectorAll( '.merchboard-orders__legend li' )
		).map( ( li ) => li.textContent ?? '' );

		expect( legend ).toEqual( [
			'Refund recorded: at least one refund applies to this order line.',
			'Not counted: the order does not currently count as a sale.',
		] );
		expect( legend.join( ' ' ).toLowerCase() ).not.toMatch(
			/fully|cancel|delet/
		);
	} );

	it( 'shows a fallback for a line without a product name', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () =>
				listResponse( [ { ...SALE, product_name: '' } ], 1, 1 ),
		} );

		expect( rows( container )[ 0 ]?.[ 2 ] ).toBe( 'Unnamed product' );
	} );

	it( 'loads the next page through the shared pagination', async () => {
		const { container, paths } = await show( {
			[ `GET ${ FIRST }` ]: () => listResponse( [ SALE ], 21, 2 ),
			[ `GET ${ SECOND }` ]: () => listResponse( [ REFUNDED ], 21, 2 ),
		} );

		expect(
			container.querySelector( 'nav' )?.getAttribute( 'aria-label' )
		).toBe( 'Order pages' );

		click( container.querySelectorAll( 'nav button' )[ 1 ] ?? null );

		expect(
			container.querySelector( '[role="status"]' )?.textContent
		).toBe( 'Loading orders…' );
		expect(
			Array.from( container.querySelectorAll( 'nav button' ) ).map(
				( button ) => ( button as HTMLButtonElement ).disabled
			)
		).toEqual( [ true, true ] );
		await flush();

		expect( paths() ).toEqual( [ FIRST, SECOND ] );
		expect( rows( container )[ 0 ]?.[ 2 ] ).toBe( 'Hoodie' );
		expect(
			container.querySelector( '.merchboard-pagination__status' )
				?.textContent
		).toBe( 'Page 2 of 2' );
	} );

	it( 'shows a loading placeholder first, not the empty state', () => {
		mockApi( {
			[ `GET ${ FIRST }` ]: () => new Promise( () => undefined ),
		} );
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<OrdersSection />
			</ConfigContext.Provider>
		);

		expect(
			rendered.container.querySelector( '[role="status"]' )?.textContent
		).toBe( 'Loading orders…' );
		expect( rendered.container.textContent ).not.toContain(
			'You have no orders yet.'
		);
	} );

	it( 'shows the empty state without order lines', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () => listResponse( [], 0, 0 ),
		} );

		expect( container.textContent ).toContain( 'You have no orders yet.' );
		expect( container.querySelector( 'table' ) ).toBeNull();
		expect( container.querySelector( 'nav' ) ).toBeNull();
		expect(
			container.querySelector( '.merchboard-orders__legend' )
		).toBeNull();
	} );

	it( 'shows the API error without the server text and retries', async () => {
		let calls = 0;
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () => {
				calls++;

				return calls === 1
					? response( 500, {
							code: 'merchboard_internal_error',
							message: 'Table wp_merchboard_sales is gone',
						} )
					: listResponse( [ SALE ], 1, 1 );
			},
		} );

		expect( container.textContent ).toContain( 'Something went wrong.' );
		expect( container.textContent ).not.toContain( 'merchboard_sales' );

		click( container.querySelector( 'button' ) );
		await flush();

		expect( rows( container )[ 0 ]?.[ 2 ] ).toBe( 'Tour tee' );
	} );

	it( 'treats a malformed line as an invalid response', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () =>
				listResponse( [ SALE, { ...SALE, refunded: 'yes' } ], 2, 1 ),
		} );

		expect( container.textContent ).toContain( 'Something went wrong.' );
		expect( container.querySelector( 'table' ) ).toBeNull();
	} );
} );

describe( 'Orders in the dashboard', () => {
	beforeEach( () => {
		setUrl( '/creator-dashboard/orders/' );
	} );

	it( 'mounts through the section registry when the server sends the section', async () => {
		mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
			'GET /merchboard/v1/me/profile': () => response( 200, PROFILE ),
			[ `GET ${ FIRST }` ]: () => listResponse( [ SALE ], 1, 1 ),
		} );
		rendered = render(
			<App
				config={ {
					...CONFIG,
					sections: [
						...CONFIG.sections,
						{ id: 'products', label: 'Products', path: 'products' },
						{ id: 'orders', label: 'Orders', path: 'orders' },
					],
				} }
			/>
		);
		await flush();

		expect( rendered.container.querySelector( 'h1' )?.textContent ).toBe(
			'Orders'
		);
		expect(
			Array.from(
				rendered.container.querySelectorAll( '.merchboard-sidebar a' )
			).map( ( a ) => a.textContent )
		).toEqual( [ 'Overview', 'Products', 'Orders' ] );
		expect( rows( rendered.container )[ 0 ]?.[ 2 ] ).toBe( 'Tour tee' );
	} );

	it( 'is not shown when the server does not send the section', async () => {
		mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
		} );
		rendered = render( <App config={ CONFIG } /> );
		await flush();

		expect( rendered.container.querySelector( 'h1' )?.textContent ).toBe(
			'Page not found'
		);
		expect( rendered.container.querySelector( 'table' ) ).toBeNull();
	} );
} );
