import { afterEach, describe, expect, it, jest } from '@jest/globals';

import {
	firstDelayMs,
	intervalMs,
	parseConfig,
	pillParts,
	stackOffset,
	stepMs,
	viewsOf,
} from '../ad';
import type { Ad, Config } from '../ad';
import { pill, renderView, start } from '../index';

const PRODUCT = (
	name: string,
	cutout = true
): { name: string; image: string; cutout: boolean } => ( {
	name,
	image: `https://shop.test/${ name }.png`,
	cutout,
} );

const AD: Ad = {
	enabled: true,
	design: 'spotlight',
	note: 'Skriv !merch i chatten',
	every: 10,
	seconds: 15,
	enter: 'slide',
	leave: 'fade',
	accent: '#9146ff',
	theme: 'dark',
	pillPosition: 'below',
	pillSize: 'medium',
	showNames: true,
	stackSpread: 50,
	products: [ PRODUCT( 'A' ), PRODUCT( 'B', false ), PRODUCT( 'C' ) ],
};

/** The ad as the page embeds it (snake case). */
const RAW = {
	...AD,
	pill_position: 'below',
	pill_size: 'medium',
	show_names: true,
	stack_spread: 50,
};

const CONFIG: Config = {
	status: 'https://shop.test/wp-json/merchboard/v1/merch-ad/x',
	version: 'v1',
	showAt: 0,
	preview: false,
	ad: AD,
};

afterEach( () => {
	jest.useRealTimers();
	document.body.innerHTML = '';
} );

describe( 'the merch ad overlay (ADR-113)', () => {
	it( 'accepts only a valid configuration', () => {
		expect( parseConfig( { ...CONFIG, ad: RAW } ) ).toEqual( CONFIG );
		expect(
			parseConfig( { ...CONFIG, ad: { ...RAW, pill_position: 'top' } } )
		).toBeNull();
		expect(
			parseConfig( { ...CONFIG, ad: { ...RAW, stack_spread: 101 } } )
		).toBeNull();
		expect(
			parseConfig( { ...CONFIG, ad: { ...RAW, design: 'banner' } } )
		).toBeNull();
		expect(
			parseConfig( { ...CONFIG, ad: { ...RAW, every: 0 } } )
		).toBeNull();
		expect(
			parseConfig( { ...CONFIG, ad: { ...RAW, accent: 'red' } } )
		).toBeNull();
		expect(
			parseConfig( {
				...CONFIG,
				ad: {
					...AD,
					products: [
						{
							name: 'x',
							image: 'javascript:alert(1)',
							cutout: true,
						},
					],
				},
			} )
		).toBeNull();
	} );

	it( 'puts commands in bold in the pill, as text', () => {
		expect( pillParts( 'Skriv !merch i chatten' ) ).toEqual( [
			{ text: 'Skriv ', command: false },
			{ text: '!merch', command: true },
			{ text: ' i chatten', command: false },
		] );

		const node = pill( 'Hi <b>x</b> !merch' );
		expect( node.querySelector( 'b' ) ).toBeNull();
		expect( node.querySelector( 'strong' )?.textContent ).toBe( '!merch' );
	} );

	it( 'groups the products per design', () => {
		expect(
			viewsOf( AD ).map( ( view ) => view.map( ( p ) => p.name ) )
		).toEqual( [ [ 'A' ], [ 'B' ], [ 'C' ] ] );
		expect(
			viewsOf( { ...AD, design: 'showcase' } ).map( ( view ) =>
				view.map( ( p ) => p.name )
			)
		).toEqual( [
			[ 'C', 'A', 'B' ],
			[ 'A', 'B', 'C' ],
			[ 'B', 'C', 'A' ],
		] );
		expect(
			viewsOf( { ...AD, design: 'stack' } ).map( ( view ) =>
				view.map( ( p ) => p.name )
			)
		).toEqual( [
			[ 'C', 'A', 'B' ],
			[ 'A', 'B', 'C' ],
			[ 'B', 'C', 'A' ],
		] );
		expect(
			renderView( { ...AD, design: 'stack' }, [
				PRODUCT( 'C' ),
				PRODUCT( 'A' ),
				PRODUCT( 'B' ),
			] ).querySelector(
				'.mb-ad__stack .mb-ad__item--middle .mb-ad__name'
			)?.textContent
		).toBe( 'A' );
		expect( viewsOf( { ...AD, design: 'shelf' } ) ).toHaveLength( 1 );
		expect( viewsOf( { ...AD, design: 'carousel' } )[ 0 ] ).toHaveLength(
			3
		);
		expect( viewsOf( { ...AD, products: [] } ) ).toEqual( [] );
		expect( stepMs( AD ) ).toBe( 5000 );
		expect( stepMs( { ...AD, seconds: 5 } ) ).toBe( 3000 );
		expect( firstDelayMs( CONFIG ) ).toBe( 15000 );
		expect( intervalMs( CONFIG ) ).toBe( 600000 );
		expect( intervalMs( { ...CONFIG, preview: true } ) ).toBe( 18000 );
	} );

	it( 'draws each design with images and names', () => {
		const spotlight = renderView( AD, [ PRODUCT( 'A' ) ] );
		expect(
			spotlight
				.querySelector( '.mb-ad__img--cutout img' )
				?.getAttribute( 'src' )
		).toBe( 'https://shop.test/A.png' );
		expect( spotlight.querySelector( '.mb-ad__pill' ) ).not.toBeNull();

		const tile = renderView( AD, [ PRODUCT( 'B', false ) ] );
		expect( tile.querySelector( '.mb-ad__img--tile' ) ).not.toBeNull();

		const three = renderView(
			{ ...AD, design: 'showcase' },
			viewsOf( { ...AD, design: 'showcase' } )[ 0 ] ?? []
		);
		expect(
			three.querySelector( '.mb-ad__item--left .mb-ad__name' )
				?.textContent
		).toBe( 'C' );
		expect(
			three.querySelector( '.mb-ad__item--middle .mb-ad__name' )
				?.textContent
		).toBe( 'A' );

		const strip = renderView( { ...AD, design: 'carousel' }, AD.products );
		expect( strip.querySelectorAll( '.mb-ad__item' ) ).toHaveLength( 24 );

		const corner = renderView( { ...AD, design: 'corner' }, [
			PRODUCT( 'A' ),
		] );
		expect(
			corner.querySelector( '.mb-ad__info .mb-ad__pill' )
		).not.toBeNull();
	} );

	it( 'puts the pill over the image, hides names and fills the carousel', () => {
		const over = renderView(
			{
				...AD,
				pillPosition: 'over',
				pillSize: 'large',
				showNames: false,
			},
			[ PRODUCT( 'A' ) ]
		);
		expect(
			over.querySelector(
				'.mb-ad__img .mb-ad__pill--over.mb-ad__pill--large'
			)
		).not.toBeNull();
		expect( over.querySelector( '.mb-ad__name' ) ).toBeNull();

		const stack = renderView(
			{ ...AD, design: 'stack', pillPosition: 'over' },
			[ PRODUCT( 'C' ), PRODUCT( 'A' ), PRODUCT( 'B' ) ]
		);
		expect(
			stack.querySelector(
				'.mb-ad__item--middle .mb-ad__img .mb-ad__pill'
			)
		).not.toBeNull();

		const strip = renderView( { ...AD, design: 'carousel' }, AD.products );
		// Three products fill a round of 12, twice.
		expect( strip.querySelectorAll( '.mb-ad__item' ) ).toHaveLength( 24 );
		expect(
			strip.querySelector( '.mb-ad__strip > .mb-ad__pill' )
		).toBeNull();
		expect( stackOffset( 0 ) ).toBe( '15%' );
		expect( stackOffset( 100 ) ).toBe( '70%' );
	} );

	it( 'shows a switched-off ad only in the preview', () => {
		jest.useFakeTimers();
		global.fetch = jest.fn(
			() => new Promise( () => undefined )
		) as unknown as typeof fetch;
		const live = document.createElement( 'div' );
		const preview = document.createElement( 'div' );
		document.body.append( live, preview );

		start( live, { ...CONFIG, ad: { ...AD, enabled: false } } );
		jest.advanceTimersByTime( 16000 );
		expect( live.children ).toHaveLength( 0 );

		start( preview, {
			...CONFIG,
			preview: true,
			ad: { ...AD, enabled: false },
		} );
		jest.advanceTimersByTime( 300 );
		expect( preview.querySelector( '.mb-ad__name' ) ).not.toBeNull();
		expect(
			document.documentElement.classList.contains( 'mb-ad-preview' )
		).toBe( true );
	} );

	it( 'shows the ad, turns the products and leaves after the seconds', () => {
		jest.useFakeTimers();
		const root = document.createElement( 'div' );
		document.body.append( root );
		global.fetch = jest.fn(
			() => new Promise( () => undefined )
		) as unknown as typeof fetch;

		start( root, { ...CONFIG, preview: true } );
		expect( root.className ).toBe( 'mb-ad mb-ad--spotlight mb-ad--dark' );
		expect( root.children ).toHaveLength( 0 );

		jest.advanceTimersByTime( 300 );
		expect(
			root.querySelector( '.mb-ad__stage--in-slide .mb-ad__name' )
				?.textContent
		).toBe( 'A' );

		jest.advanceTimersByTime( 5000 );
		expect( root.querySelector( '.mb-ad__name' )?.textContent ).toBe( 'B' );

		jest.advanceTimersByTime( 10000 );
		expect(
			root.querySelector( '.mb-ad__stage--out-fade' )
		).not.toBeNull();

		jest.advanceTimersByTime( 700 );
		expect( root.children ).toHaveLength( 0 );
	} );
} );
