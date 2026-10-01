import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';

import { parseProduct } from '../api/products';
import { App } from '../app/App';
import { ConfigContext } from '../app/config-context';
import { ProductsSection } from '../sections/products/ProductsSection';
import { ME, PROFILE, listResponse, mockApi, response } from '../testing/api';
import type { Route } from '../testing/api';
import {
	CONFIG,
	ORIGIN,
	click,
	flush,
	render,
	setUrl,
} from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const FIRST = '/merchboard/v1/me/products?page=1&per_page=20';
const SECOND = '/merchboard/v1/me/products?page=2&per_page=20';

const TEE = {
	id: 12,
	name: 'Tour tee',
	status: 'publish',
	price: {
		min: '149.00',
		max: '149.00',
		currency: 'DKK',
		tax: 'store_setting',
	},
	stock_status: 'instock',
	image: {
		url: `${ ORIGIN }/wp-content/uploads/tee-150x150.jpg`,
		alt: 'Tour tee',
	},
};

const HOODIE = {
	id: 13,
	name: 'Hoodie',
	status: 'private',
	price: {
		min: '199.50',
		max: '300',
		currency: 'DKK',
		tax: 'store_setting',
	},
	stock_status: 'onbackorder',
	image: null,
};

const CAP = {
	id: 14,
	name: 'Cap',
	status: 'publish',
	price: null,
	stock_status: 'outofstock',
	image: null,
};

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
			<ProductsSection />
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

describe( 'parseProduct', () => {
	it( 'accepts the documented product and maps it to the internal type', () => {
		expect( parseProduct( TEE ) ).toEqual( {
			id: 12,
			name: 'Tour tee',
			status: 'publish',
			price: {
				min: '149.00',
				max: '149.00',
				currency: 'DKK',
				tax: 'store_setting',
			},
			stockStatus: 'instock',
			image: {
				url: `${ ORIGIN }/wp-content/uploads/tee-150x150.jpg`,
				alt: 'Tour tee',
			},
		} );
		expect( parseProduct( CAP )?.price ).toBeNull();
		expect(
			parseProduct( { ...CAP, stock_status: null } )?.stockStatus
		).toBeNull();
	} );

	it( 'drops an image that is not on this origin', () => {
		expect(
			parseProduct( {
				...TEE,
				image: { url: 'https://cdn.example/tee.jpg', alt: 'Tour tee' },
			} )?.image
		).toBeNull();
	} );

	it.each( [
		[ 'not an object', 'Tour tee' ],
		[ 'missing id', { ...TEE, id: undefined } ],
		[ 'id 0', { ...TEE, id: 0 } ],
		[ 'id as string', { ...TEE, id: '12' } ],
		[ 'empty name', { ...TEE, name: '' } ],
		[ 'unknown status', { ...TEE, status: 'draft' } ],
		[ 'unknown stock status', { ...TEE, stock_status: 'preorder' } ],
		[ 'missing stock status', { ...TEE, stock_status: undefined } ],
		[ 'price as a number', { ...TEE, price: 149 } ],
		[ 'float amounts', { ...TEE, price: { ...TEE.price, min: 149.5 } } ],
		[
			'HTML amount',
			{ ...TEE, price: { ...TEE.price, min: '<b>149</b>' } },
		],
		[ 'other tax basis', { ...TEE, price: { ...TEE.price, tax: 'incl' } } ],
		[ 'bad currency', { ...TEE, price: { ...TEE.price, currency: 'kr' } } ],
		[ 'image without alt', { ...TEE, image: { url: TEE.image.url } } ],
	] )( 'rejects a malformed product (%s)', ( _case, body ) => {
		expect( parseProduct( body ) ).toBeNull();
	} );
} );

describe( 'Products section', () => {
	it( 'renders the first page: name, image, status, price as sent and stock', async () => {
		const { container, paths } = await show( {
			[ `GET ${ FIRST }` ]: () =>
				listResponse( [ TEE, HOODIE, CAP ], 3, 1 ),
		} );

		expect( paths() ).toEqual( [ FIRST ] );
		expect( container.querySelector( 'h2' )?.textContent ).toBe(
			'Your products'
		);
		expect( container.querySelector( 'caption' )?.textContent ).toBe(
			'Your products'
		);
		expect(
			Array.from( container.querySelectorAll( 'thead th' ) ).map(
				( th ) => th.textContent
			)
		).toEqual( [ 'Product', 'Status', 'Price', 'Stock' ] );
		expect( rows( container ) ).toEqual( [
			[ 'Tour tee', 'Published', '149.00 DKK', 'In stock' ],
			[ 'Hoodie', 'Private', '199.50–300 DKK', 'On backorder' ],
			[ 'Cap', 'Published', 'No price', 'Out of stock' ],
		] );
	} );

	it( 'shows the image with its alt text, and an accessible fallback without one', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () => listResponse( [ TEE, HOODIE ], 2, 1 ),
		} );
		const image = container.querySelector( 'img' );
		const fallback = container.querySelector(
			'.merchboard-product__image--none'
		);

		expect( image?.getAttribute( 'src' ) ).toBe( TEE.image.url );
		expect( image?.getAttribute( 'alt' ) ).toBe( 'Tour tee' );
		expect( container.querySelectorAll( 'img' ) ).toHaveLength( 1 );
		expect( fallback?.getAttribute( 'role' ) ).toBe( 'img' );
		expect( fallback?.getAttribute( 'aria-label' ) ).toBe( 'No image' );
	} );

	it( 'shows no ids, no tax label and no HTML', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () =>
				listResponse( [ { ...TEE, name: '<b>Tee</b>' } ], 1, 1 ),
		} );
		const text = container.textContent ?? '';

		expect( container.querySelector( 'tbody b' ) ).toBeNull();
		expect( text ).toContain( '<b>Tee</b>' );
		expect( text ).not.toContain( '12' );
		expect( text.toLowerCase() ).not.toContain( 'tax' );
		expect( text.toLowerCase() ).not.toContain( 'moms' );
	} );

	it( 'loads the next page through the shared pagination', async () => {
		const { container, paths } = await show( {
			[ `GET ${ FIRST }` ]: () => listResponse( [ TEE ], 21, 2 ),
			[ `GET ${ SECOND }` ]: () => listResponse( [ CAP ], 21, 2 ),
		} );

		expect(
			container.querySelector( 'nav' )?.getAttribute( 'aria-label' )
		).toBe( 'Product pages' );
		expect(
			container.querySelector( '.merchboard-pagination__status' )
				?.textContent
		).toBe( 'Page 1 of 2' );

		click( container.querySelectorAll( 'nav button' )[ 1 ] ?? null );

		expect(
			Array.from( container.querySelectorAll( 'nav button' ) ).map(
				( button ) => ( button as HTMLButtonElement ).disabled
			)
		).toEqual( [ true, true ] );
		await flush();

		expect( paths() ).toEqual( [ FIRST, SECOND ] );
		expect( rows( container ) ).toEqual( [
			[ 'Cap', 'Published', 'No price', 'Out of stock' ],
		] );
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
				<ProductsSection />
			</ConfigContext.Provider>
		);

		expect(
			rendered.container.querySelector( '[role="status"]' )?.textContent
		).toBe( 'Loading products…' );
		expect( rendered.container.textContent ).not.toContain(
			'You have no products yet.'
		);
	} );

	it( 'shows the empty state without products', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () => listResponse( [], 0, 0 ),
		} );

		expect( container.textContent ).toContain(
			'You have no products yet.'
		);
		expect( container.querySelector( 'table' ) ).toBeNull();
		expect( container.querySelector( 'nav' ) ).toBeNull();
	} );

	it( 'shows the API error without the server text and retries', async () => {
		let calls = 0;
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () => {
				calls++;

				return calls === 1
					? response( 500, {
							code: 'merchboard_internal_error',
							message: 'SQL syntax error near products',
						} )
					: listResponse( [ TEE ], 1, 1 );
			},
		} );

		expect( container.textContent ).toContain( 'Something went wrong.' );
		expect( container.textContent ).not.toContain( 'SQL' );

		click( container.querySelector( 'button' ) );
		await flush();

		expect( rows( container )[ 0 ]?.[ 0 ] ).toBe( 'Tour tee' );
	} );

	it( 'treats a malformed product as an invalid response', async () => {
		const { container } = await show( {
			[ `GET ${ FIRST }` ]: () =>
				listResponse( [ TEE, { ...TEE, id: 'x' } ], 2, 1 ),
		} );

		expect( container.textContent ).toContain( 'Something went wrong.' );
		expect( container.querySelector( 'table' ) ).toBeNull();
	} );
} );

describe( 'Products in the dashboard', () => {
	beforeEach( () => {
		setUrl( '/creator-dashboard/products/' );
	} );

	it( 'mounts through the section registry when the server sends the section', async () => {
		mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
			'GET /merchboard/v1/me/profile': () => response( 200, PROFILE ),
			[ `GET ${ FIRST }` ]: () => listResponse( [ TEE ], 1, 1 ),
		} );
		rendered = render(
			<App
				config={ {
					...CONFIG,
					sections: [
						...CONFIG.sections,
						{ id: 'products', label: 'Products', path: 'products' },
					],
				} }
			/>
		);
		await flush();

		expect( rendered.container.querySelector( 'h1' )?.textContent ).toBe(
			'Products'
		);
		expect(
			rendered.container.querySelector( 'tbody td' )?.textContent
		).toBe( 'Tour tee' );
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
