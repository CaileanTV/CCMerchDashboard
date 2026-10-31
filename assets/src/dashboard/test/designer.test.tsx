/**
 * Tests for the product designer's model and API (phase 30, steps 30.8–30.9).
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseOverview, parseProduct } from '../api/designer';
import { ConfigContext } from '../app/config-context';
import { DesignerSection } from '../sections/designer/DesignerSection';
import { mockApi, response } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';
import {
	EMPTY_DESIGN,
	moveObject,
	newImage,
	newShape,
	newText,
	objectsOf,
	parseDesign,
	quotePrice,
	updateObject,
	usedSides,
	withObjects,
} from '../sections/designer/design-model';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

// jsdom has no canvas: the editor gets a stand-in for the Fabric stage and
// the rendering of the submission's files.
jest.mock( '../sections/designer/fabric-stage', () => ( {
	FabricStage: class {
		setView(): void {}
		render(): Promise< void > {
			return Promise.resolve();
		}
		select(): void {}
		dispose(): void {}
	},
	renderPrintFile: () => Promise.resolve( mockCanvas() ),
} ) );

jest.mock( '../sections/designer/mockup', () => ( {
	composeMockup: () => Promise.resolve( mockBlob( 'image/jpeg' ) ),
	pngOf: () => Promise.resolve( mockBlob( 'image/png' ) ),
} ) );

/**
 * A rendered image (the mocks run after the module loaded).
 *
 * @param type MIME type.
 */
function mockBlob( type: string ): Blob {
	return new Blob( [ 'x' ], { type } );
}

/**
 * A print file canvas.
 */
function mockCanvas(): HTMLCanvasElement {
	return document.createElement( 'canvas' );
}

const OVERVIEW = {
	bases: [
		{
			id: 3,
			name: 'Organic tee',
			description: '',
			base_price: '149.9500',
			extra_side_price: '0.0000',
			currency: 'DKK',
			colors: [ { key: 'black', name: 'Black', hex: '#111111' } ],
			sizes: [ 'S', 'M' ],
			version: 2,
			views: [
				{
					key: 'front',
					name: 'Front',
					width: 1000,
					height: 1000,
					layers: { color: 7 },
					area: { x: 250, y: 200, width: 500, height: 600 },
					print_width: 3543,
					print_height: 4724,
					dpi: 300,
				},
			],
		},
	],
	cliparts: [
		{ id: 9, name: 'Star', width: 32, height: 32, mime: 'image/png' },
	],
	uploads: [],
	fonts: [
		{ family: 'Roboto', category: 'sans-serif', weights: [ 400, 700 ] },
	],
	products: [
		{
			id: 5,
			base_id: 3,
			title: 'Tee',
			status: 'draft',
			in_shop: false,
			updated_at: '2026-10-29T10:00:00+00:00',
		},
	],
	limits: {
		max_upload_bytes: 26214400,
		max_uploads: 200,
		max_objects: 100,
		price_decimals: 2,
	},
};

describe( 'the designer API', () => {
	it( 'accepts the overview and refuses anything off', () => {
		const overview = parseOverview( OVERVIEW );

		expect( overview?.bases[ 0 ]?.views[ 0 ]?.layers ).toEqual( {
			color: 7,
		} );
		expect( overview?.bases[ 0 ]?.views[ 0 ]?.printWidth ).toBe( 3543 );
		expect( overview?.bases[ 0 ]?.views[ 0 ]?.backgrounds ).toEqual( {} );
		expect(
			parseOverview( {
				...OVERVIEW,
				bases: [
					{
						...OVERVIEW.bases[ 0 ],
						views: [
							{
								...OVERVIEW.bases[ 0 ]?.views[ 0 ],
								layers: { color: 7, 'background:black': 8 },
							},
						],
					},
				],
			} )?.bases[ 0 ]?.views[ 0 ]
		).toMatchObject( { layers: { color: 7 }, backgrounds: { black: 8 } } );
		expect( overview?.fonts ).toEqual( [
			{ family: 'Roboto', weights: [ 400, 700 ] },
		] );
		expect( overview?.priceDecimals ).toBe( 2 );
		expect(
			parseOverview( {
				...OVERVIEW,
				cliparts: [
					{
						id: 9,
						name: 'x',
						width: 1,
						height: 1,
						mime: 'text/html',
					},
				],
			} )
		).toBeNull();
		expect(
			parseOverview( {
				...OVERVIEW,
				bases: [
					{
						...OVERVIEW.bases[ 0 ],
						colors: [ { key: 'x', name: 'X', hex: 'red' } ],
					},
				],
			} )
		).toBeNull();
		expect(
			parseOverview( {
				...OVERVIEW,
				limits: { ...OVERVIEW.limits, price_decimals: 9 },
			} )
		).toBeNull();
	} );

	it( 'reads a draft, also one never saved', () => {
		const product = parseProduct( {
			id: 5,
			base_id: 3,
			in_shop: false,
			draft: {
				number: 1,
				title: 'Tee',
				description: '',
				colors: [ 'black' ],
				sizes: [ 'S' ],
				design: { version: 1, sides: [] },
				profit_kind: 'fixed',
				profit_value: '0.0000',
				price: '149.9500',
				base_version: 2,
				updated_at: '2026-10-29T10:00:00+00:00',
			},
		} );

		expect( product?.draft?.design ).toEqual( EMPTY_DESIGN );
		expect(
			parseProduct( {
				id: 5,
				base_id: 3,
				in_shop: false,
				draft: { title: 'x' },
			} )
		).toBeNull();
		expect(
			parseProduct( { id: 5, base_id: 3, in_shop: true, draft: null } )
				?.draft
		).toBeNull();
	} );

	it( 'reads the version waiting for approval', () => {
		const waiting = parseProduct( {
			id: 5,
			base_id: 3,
			in_shop: false,
			draft: null,
			pending: {
				number: 1,
				title: 'Tee',
				price: '179.9400',
				submitted_at: '2026-10-29T10:00:00+00:00',
			},
		} );

		expect( waiting?.pending ).toEqual( {
			number: 1,
			title: 'Tee',
			price: '179.9400',
			submittedAt: '2026-10-29T10:00:00+00:00',
		} );
		expect(
			parseProduct( {
				id: 5,
				base_id: 3,
				in_shop: false,
				draft: null,
				pending: { number: 0, title: 'Tee' },
			} )
		).toBeNull();
	} );
} );

describe( 'the design model', () => {
	it( 'creates objects in the middle of the print file and keeps them valid', () => {
		const text = newText( 3543, 4724, 'Roboto', 'Hello' );
		const shape = newShape( 'ellipse', 3543, 4724 );
		const image = newImage( 9, 4000, 2000, 3543, 4724 );

		expect( [ text.x, text.y ] ).toEqual( [ 1772, 2362 ] );
		expect( text.font ).toBe( 'Roboto' );
		expect( shape.fill ).toBe( '#000000' );
		expect( image.scaleX ).toBeCloseTo( 0.4429, 3 );
		expect( text.id ).not.toBe( shape.id );

		const design = withObjects( EMPTY_DESIGN, 'front', [
			text,
			shape,
			image,
		] );
		expect( parseDesign( JSON.parse( JSON.stringify( design ) ) ) ).toEqual(
			design
		);
		expect(
			parseDesign( {
				version: 1,
				sides: { front: [ { ...text, fill: 'red' } ] },
			} )
		).toBeNull();
		expect( parseDesign( { version: 2, sides: {} } ) ).toBeNull();
	} );

	it( 'orders, changes and removes objects per side', () => {
		const a = newShape( 'rect', 100, 100 );
		const b = newShape( 'triangle', 100, 100 );
		const c = newShape( 'ellipse', 100, 100 );
		let design = withObjects( EMPTY_DESIGN, 'front', [ a, b, c ] );

		expect(
			moveObject( objectsOf( design, 'front' ), a.id, 1 ).map(
				( item ) => item.id
			)
		).toEqual( [ b.id, a.id, c.id ] );
		expect( moveObject( objectsOf( design, 'front' ), c.id, 1 ) ).toBe(
			objectsOf( design, 'front' )
		);
		expect(
			updateObject( objectsOf( design, 'front' ), b.id, {
				hidden: true,
			} )[ 1 ]?.hidden
		).toBe( true );

		expect( usedSides( design ) ).toEqual( [ 'front' ] );
		expect(
			usedSides(
				withObjects( design, 'back', [ { ...a, hidden: true } ] )
			)
		).toEqual( [ 'front' ] );

		design = withObjects( design, 'front', [] );
		expect( design.sides ).toEqual( {} );
		expect( usedSides( design ) ).toEqual( [] );
		expect( objectsOf( design, 'back' ) ).toEqual( [] );
	} );

	it( 'quotes the customer price like the server', () => {
		expect( quotePrice( '149.9500', 'percent', '20', 2 ) ).toBe( 179.94 );
		expect( quotePrice( '150.0000', 'fixed', '49,995', 2 ) ).toBe( 200 );
		expect( quotePrice( '149.1000', 'fixed', '50', 0 ) ).toBe( 200 );
		expect(
			quotePrice( '150.0000', 'percent', '20', 2, '50.0000', 2 )
		).toBe( 240 );
		expect( quotePrice( '150.0000', 'fixed', '10', 2, '50.0000', 3 ) ).toBe(
			260
		);
		expect( quotePrice( '150.0000', 'fixed', '10', 2, '50.0000', 1 ) ).toBe(
			160
		);
		expect( quotePrice( '150.0000', 'fixed', '10', 2, '50.0000', 0 ) ).toBe(
			160
		);
		expect( quotePrice( '150.0000', 'fixed', '-1', 2 ) ).toBeNull();
		expect( quotePrice( '150.0000', 'fixed', '', 2 ) ).toBeNull();
	} );
} );

describe( 'DesignerSection', () => {
	let rendered: Rendered | null = null;

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
	} );

	it( 'lists the designs, starts one and saves its draft', async () => {
		const draft = {
			number: 1,
			title: 'Organic tee',
			description: '',
			colors: [ 'black' ],
			sizes: [ 'S', 'M' ],
			design: { version: 1, sides: [] },
			profit_kind: 'fixed',
			profit_value: '0.0000',
			price: '149.9500',
			base_version: 2,
			updated_at: '2026-10-29T10:00:00+00:00',
		};
		const requests = mockApi( {
			'GET /merchboard/v1/me/designer': () => response( 200, OVERVIEW ),
			'POST /merchboard/v1/me/designer/products': () =>
				response( 201, { id: 6, base_id: 3, in_shop: false, draft } ),
			'PATCH /merchboard/v1/me/designer/products/6': ( request ) =>
				response( 200, {
					id: 6,
					base_id: 3,
					in_shop: false,
					draft: {
						...draft,
						title: ( request.data as { title: string } ).title,
						profit_kind: 'percent',
						profit_value: '20.0000',
						price: '179.9400',
					},
				} ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<DesignerSection />
			</ConfigContext.Provider>
		);
		await flush();

		const text = rendered.container.textContent ?? '';
		expect( text ).toContain( 'Tee' );
		expect( text ).toContain( 'Organic tee' );

		click(
			Array.from( rendered.container.querySelectorAll( 'button' ) ).find(
				( element ) => element.textContent === 'Design'
			) ?? null
		);
		await flush();

		const title = rendered.container.querySelector< HTMLInputElement >(
			'input[maxlength="60"]'
		);
		expect( title?.value ).toBe( 'Organic tee' );
		expect( rendered.container.textContent ).toContain(
			'Customer price incl. VAT'
		);

		await act( async () => {
			const setter = Object.getOwnPropertyDescriptor(
				HTMLInputElement.prototype,
				'value'
			)?.set;
			setter?.call( title, 'Stream tee' );
			title?.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		// The profit and what happens after sending are always in view.
		expect( rendered.container.textContent ).toContain(
			'Price and profit'
		);
		expect( rendered.container.textContent ).toContain(
			'The finished mockups are made automatically before we can approve your design.'
		);
		expect(
			rendered.container.querySelector(
				'.merchboard-product-designer__summary'
			)?.textContent
		).toContain( 'Customer price incl. VAT' );

		click(
			Array.from( rendered.container.querySelectorAll( 'button' ) ).find(
				( element ) => element.textContent === 'Save draft'
			) ?? null
		);
		await flush();

		const saved = requests.find(
			( request ) => request.method === 'PATCH'
		);
		expect( saved?.data ).toMatchObject( {
			title: 'Stream tee',
			colors: [ 'black' ],
			sizes: [ 'S', 'M' ],
			profit_kind: 'fixed',
			design: { version: 1, sides: {} },
		} );
		expect( rendered.container.textContent ).toContain(
			'Your design is saved.'
		);
	} );

	it( 'sends a draft with its files for approval and withdraws it', async () => {
		const draft = {
			number: 1,
			title: 'Organic tee',
			description: '',
			colors: [ 'black' ],
			sizes: [ 'S', 'M' ],
			design: {
				version: 1,
				sides: {
					front: [
						{
							type: 'rect',
							id: 'r1',
							x: 100,
							y: 100,
							width: 50,
							height: 50,
							angle: 0,
							scaleX: 1,
							scaleY: 1,
							flipX: false,
							flipY: false,
							opacity: 1,
							locked: false,
							hidden: false,
							fill: '#000000',
							stroke: null,
							strokeWidth: 0,
							radius: 0,
						},
					],
				},
			},
			profit_kind: 'fixed',
			profit_value: '0.0000',
			price: '149.9500',
			base_version: 2,
			updated_at: '2026-10-29T10:00:00+00:00',
		};
		const product = { id: 5, base_id: 3, in_shop: false, draft };
		const waiting = {
			...product,
			draft: null,
			pending: {
				number: 1,
				title: 'Organic tee',
				price: '149.9500',
				submitted_at: '2026-10-29T10:00:00+00:00',
			},
		};
		let layer = true;
		const requests = mockApi( {
			'GET /merchboard/v1/me/designer': () => response( 200, OVERVIEW ),
			'GET /merchboard/v1/me/designer/products/5': () =>
				response( 200, product ),
			'PATCH /merchboard/v1/me/designer/products/5': () =>
				response( 200, product ),
			'GET /merchboard/v1/me/designer/assets/7': () =>
				layer
					? {
							...response(
								200,
								{},
								{ 'Content-Type': 'image/png' }
							),
							blob: () =>
								Promise.resolve(
									new Blob( [ 'png' ], { type: 'image/png' } )
								),
						}
					: response( 404, {} ),
			'POST /merchboard/v1/me/designer/products/5/files': () =>
				response( 200, product ),
			'POST /merchboard/v1/me/designer/products/5/submit': () =>
				response( 200, waiting ),
			'POST /merchboard/v1/me/designer/products/5/withdraw': () =>
				response( 200, { ...product, draft: { ...draft, number: 2 } } ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<DesignerSection />
			</ConfigContext.Provider>
		);
		await flush();
		click( button( rendered, 'Edit' ) );
		await flush();
		click( button( rendered, 'Send for approval' ) );
		expect( rendered.container.textContent ).toContain(
			'sent with 2 images'
		);
		click( button( rendered, 'Send' ) );
		await flush();
		await flush();

		const sent = requests.filter( ( request ) =>
			request.path.endsWith( '/files' )
		);
		expect(
			sent.map( ( request ) =>
				( request.body as FormData ).get( 'slot' )
			)
		).toEqual( [ 'print:front', 'mockup:front:black' ] );
		expect(
			requests.map( ( request ) => request.method + ' ' + request.path )
		).toContain( 'POST /merchboard/v1/me/designer/products/5/submit' );
		expect( rendered.container.textContent ).toContain(
			'waits for our approval'
		);

		click( button( rendered, 'Withdraw and edit' ) );
		await flush();
		expect( button( rendered, 'Send for approval' ) ).not.toBeNull();

		// A template layer that cannot be fetched: nothing is sent.
		layer = false;
		rendered.unmount();
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<DesignerSection />
			</ConfigContext.Provider>
		);
		await flush();
		click( button( rendered, 'Edit' ) );
		await flush();
		const before = requests.length;
		click( button( rendered, 'Send for approval' ) );
		click( button( rendered, 'Send' ) );
		await flush();
		expect( rendered.container.textContent ).toContain(
			'could not be drawn in this browser'
		);
		expect(
			requests
				.slice( before )
				.some( ( request ) => request.path.endsWith( '/files' ) )
		).toBe( false );
	} );

	it( 'shows a product in the shop, starts a change and shows a rejection note', async () => {
		const draft = {
			number: 3,
			title: 'Tee',
			description: '',
			colors: [ 'black' ],
			sizes: [ 'S' ],
			design: { version: 1, sides: [] },
			profit_kind: 'fixed',
			profit_value: '0.0000',
			price: '149.9500',
			base_version: 2,
			updated_at: '2026-10-29T10:00:00+00:00',
		};
		const live = { number: 1, title: 'Tee', price: '179.9400' };
		mockApi( {
			'GET /merchboard/v1/me/designer': () => response( 200, OVERVIEW ),
			'GET /merchboard/v1/me/designer/products/5': () =>
				response( 200, {
					id: 5,
					base_id: 3,
					in_shop: true,
					draft: null,
					live,
				} ),
			'POST /merchboard/v1/me/designer/products/5/change': () =>
				response( 200, {
					id: 5,
					base_id: 3,
					in_shop: true,
					draft,
					live,
					rejection: {
						number: 2,
						note: 'Use a sharper logo.',
						decided_at: '2026-10-29T11:00:00+00:00',
					},
				} ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<DesignerSection />
			</ConfigContext.Provider>
		);
		await flush();
		click( button( rendered, 'Edit' ) );
		await flush();

		expect( rendered.container.textContent ).toContain(
			'Tee is in the shop'
		);
		click( button( rendered, 'Make a change' ) );
		await flush();

		const text = rendered.container.textContent ?? '';
		expect( text ).toContain( 'You are changing a product in the shop' );
		expect( text ).toContain( 'Use a sharper logo.' );
		expect( button( rendered, 'Send for approval' ) ).not.toBeNull();
	} );

	it( 'never offers sending while the administrator views as the creator', async () => {
		mockApi( {
			'GET /merchboard/v1/me/designer': () => response( 200, OVERVIEW ),
			'GET /merchboard/v1/me/designer/products/5': () =>
				response( 200, {
					id: 5,
					base_id: 3,
					in_shop: false,
					draft: {
						number: 1,
						title: 'Tee',
						description: '',
						colors: [ 'black' ],
						sizes: [ 'S' ],
						design: { version: 1, sides: [] },
						profit_kind: 'fixed',
						profit_value: '0.0000',
						price: '149.9500',
						base_version: 2,
						updated_at: '2026-10-29T10:00:00+00:00',
					},
				} ),
		} );

		rendered = render(
			<ConfigContext.Provider
				value={ { ...CONFIG, impersonating: true } }
			>
				<DesignerSection />
			</ConfigContext.Provider>
		);
		await flush();
		click( button( rendered, 'Edit' ) );
		await flush();

		expect( button( rendered, 'Send for approval' ) ).toBeNull();
		expect( rendered.container.textContent ).toContain(
			'only the creator can send it'
		);
	} );
} );

/**
 * A button by its text.
 *
 * @param rendered The rendered tree.
 * @param text     Its text.
 */
function button( rendered: Rendered, text: string ): HTMLElement | null {
	return (
		Array.from( rendered.container.querySelectorAll( 'button' ) ).find(
			( element ) => element.textContent === text
		) ?? null
	);
}
