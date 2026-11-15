import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';

import {
	fetchPanel,
	languageOf,
	parsePanel,
	renderPanel,
	start,
} from '../panel';
import type { Panel } from '../panel';

type FetchLike = (
	url: string,
	init: RequestInit
) => Promise< { ok: boolean; json: () => Promise< unknown > } >;

const PANEL: Panel = {
	enabled: true,
	creator: 'Nova',
	products: [
		{ id: 1, name: 'Hoodie <b>', image: 'https://shop.test/1.jpg' },
		{ id: 2, name: 'Mug', image: null },
	],
};

let root: HTMLElement;

beforeEach( () => {
	root = document.createElement( 'main' );
	document.body.append( root );
} );

afterEach( () => {
	root.remove();
	delete document.body.dataset.api;
	document.body.classList.remove( 'mb-dark' );
	jest.restoreAllMocks();
} );

describe( 'the Twitch Extension panel (ADR-112)', () => {
	it( 'reads the language from Twitch, English otherwise', () => {
		expect( languageOf( '?language=da&mode=viewer' ) ).toBe( 'da' );
		expect( languageOf( '?language=da-DK' ) ).toBe( 'da' );
		expect( languageOf( '?language=de' ) ).toBe( 'en' );
		expect( languageOf( '' ) ).toBe( 'en' );
	} );

	it( 'accepts only a panel with https images', () => {
		expect( parsePanel( PANEL ) ).toEqual( PANEL );
		expect(
			parsePanel( {
				...PANEL,
				products: [
					{ id: 1, name: 'x', image: 'http://shop.test/1.jpg' },
				],
			} )
		).toBeNull();
		expect(
			parsePanel( {
				...PANEL,
				products: [
					{ id: 1, name: 'x', image: 'javascript:alert(1)' },
				],
			} )
		).toBeNull();
		expect( parsePanel( { ...PANEL, creator: 7 } ) ).toBeNull();
		expect( parsePanel( null ) ).toBeNull();
	} );

	it( 'shows image and name as text, never a price, link or HTML', () => {
		renderPanel( root, PANEL, 'da' );

		expect( root.querySelector( 'h1' )?.textContent ).toBe(
			'Merch fra Nova'
		);
		expect(
			Array.from( root.querySelectorAll( '.mb-panel__name' ) ).map(
				( name ) => name.textContent
			)
		).toEqual( [ 'Hoodie <b>', 'Mug' ] );
		expect( root.querySelector( 'b' ) ).toBeNull();
		expect( root.querySelector( 'a' ) ).toBeNull();
		expect( root.querySelector( 'img' )?.getAttribute( 'src' ) ).toBe(
			'https://shop.test/1.jpg'
		);
		expect(
			root.querySelector( '.mb-panel__image--blank' )
		).not.toBeNull();
		expect( root.textContent ).not.toMatch( /kr\.|DKK|\$/ );
	} );

	it( 'says so when there is nothing to show', () => {
		renderPanel( root, { ...PANEL, enabled: false }, 'en' );
		expect( root.textContent ).toBe( 'No products to show yet.' );
		renderPanel( root, { ...PANEL, products: [] }, 'da' );
		expect( root.textContent ).toBe( 'Ingen produkter at vise endnu.' );
	} );

	it( 'sends the JWT and nothing else, and fails quietly', async () => {
		const fetchMock = jest.fn< FetchLike >( async () => ( {
			ok: true,
			json: async () => PANEL,
		} ) );
		global.fetch = fetchMock as unknown as typeof fetch;

		expect( await fetchPanel( 'https://shop.test/panel', 'jwt' ) ).toEqual(
			PANEL
		);
		expect( fetchMock ).toHaveBeenCalledWith( 'https://shop.test/panel', {
			headers: { Authorization: 'Bearer jwt' },
			credentials: 'omit',
		} );

		global.fetch = jest.fn( async () => {
			throw new Error( 'offline' );
		} ) as unknown as typeof fetch;
		expect(
			await fetchPanel( 'https://shop.test/panel', 'jwt' )
		).toBeNull();
	} );

	it( 'loads once per channel when Twitch authorizes, and follows the theme', async () => {
		document.body.dataset.api = 'https://shop.test/panel';
		const fetchMock = jest.fn< FetchLike >( async () => ( {
			ok: true,
			json: async () => PANEL,
		} ) );
		global.fetch = fetchMock as unknown as typeof fetch;
		let authorized: ( auth: {
			channelId: string;
			token: string;
		} ) => void = () => undefined;
		let context: ( value: { theme?: string } ) => void = () => undefined;

		start( root, {
			onAuthorized: ( callback ) => {
				authorized = callback;
			},
			onContext: ( callback ) => {
				context = callback;
			},
		} );
		expect( root.textContent ).toBe( 'Loading products …' );

		authorized( { channelId: '4711', token: 'a' } );
		authorized( { channelId: '4711', token: 'b' } );
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		expect( root.querySelector( 'h1' )?.textContent ).toBe(
			'Merch by Nova'
		);

		context( { theme: 'dark' } );
		expect( document.body.classList.contains( 'mb-dark' ) ).toBe( true );
	} );
} );
