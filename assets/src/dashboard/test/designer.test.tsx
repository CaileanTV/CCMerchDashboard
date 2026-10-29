/**
 * Tests for the product designer's model and API (phase 30, step 30.8).
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
	withObjects,
} from '../sections/designer/design-model';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

// jsdom has no canvas: the editor gets a stand-in for the Fabric stage.
jest.mock( '../sections/designer/fabric-stage', () => ( {
	FabricStage: class {
		setView(): void {}
		render(): Promise< void > {
			return Promise.resolve();
		}
		select(): void {}
		dispose(): void {}
	},
} ) );

const OVERVIEW = {
	bases: [
		{
			id: 3,
			name: 'Organic tee',
			description: '',
			base_price: '149.9500',
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

		design = withObjects( design, 'front', [] );
		expect( design.sides ).toEqual( {} );
		expect( objectsOf( design, 'back' ) ).toEqual( [] );
	} );

	it( 'quotes the customer price like the server', () => {
		expect( quotePrice( '149.9500', 'percent', '20', 2 ) ).toBe( 179.94 );
		expect( quotePrice( '150.0000', 'fixed', '49,995', 2 ) ).toBe( 200 );
		expect( quotePrice( '149.1000', 'fixed', '50', 0 ) ).toBe( 200 );
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
			'input[maxlength="120"]'
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
} );
