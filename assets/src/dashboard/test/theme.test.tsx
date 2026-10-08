import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';
import { resetLocaleData, setLocaleData } from '@wordpress/i18n';
import { act } from 'react';
import type { ReactNode } from 'react';

import { parsePreferences, updateTheme } from '../api/preferences';
import { ConfigContext } from '../app/config-context';
import { ThemeSettings } from '../sections/profile/ThemeSettings';
import {
	THEMES,
	applyTheme,
	isTheme,
	readTheme,
	themeLabel,
} from '../theme/themes';
import { mockApi, response } from '../testing/api';
import type { FakeResponse, RecordedRequest } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const ROUTE = 'PATCH /merchboard/v1/me/preferences';
const root = document.documentElement;

/** Echoes the requested theme, like the server. */
const echo = ( request: RecordedRequest ): FakeResponse =>
	response( 200, { theme: ( request.data as { theme: unknown } ).theme } );

function renderSettings(): Rendered {
	const ui: ReactNode = (
		<ConfigContext.Provider value={ CONFIG }>
			<ThemeSettings />
		</ConfigContext.Provider>
	);

	return render( ui );
}

function selectOf( view: Rendered ): HTMLSelectElement {
	const element = view.container.querySelector( 'select' );

	if ( element === null ) {
		throw new Error( 'No select.' );
	}

	return element;
}

/** Changes the select like a user (keyboard or mouse) and lets React see it. */
function choose( view: Rendered, value: string ): void {
	const element = selectOf( view );

	act( () => {
		element.value = value;
		element.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
}

/** A response the test resolves later. */
function deferred(): {
	promise: Promise< FakeResponse >;
	resolve: ( value: FakeResponse ) => void;
} {
	let resolve: ( value: FakeResponse ) => void = () => undefined;
	const promise = new Promise< FakeResponse >( ( done ) => {
		resolve = done;
	} );

	return { promise, resolve };
}

let view: Rendered | null = null;

beforeEach( () => {
	root.removeAttribute( 'data-theme' );
} );

afterEach( () => {
	view?.unmount();
	view = null;
	root.removeAttribute( 'data-theme' );
	resetLocaleData( undefined, 'merchboard' );
} );

describe( 'themes', () => {
	it( 'has exactly the six themes of ADR-043, without aliases', () => {
		expect( THEMES ).toEqual( [
			'light',
			'dark-black',
			'dark-green',
			'dark-blue',
			'dark-red',
			'dark-pink',
		] );
		expect( isTheme( 'dark-blue' ) ).toBe( true );

		for ( const value of [ 'dark', 'Light', 'system', '', null, 1 ] ) {
			expect( isTheme( value ) ).toBe( false );
		}
	} );

	it( 'reads only a valid data-theme and applies or removes it', () => {
		expect( readTheme() ).toBeNull();

		root.setAttribute( 'data-theme', 'dark-purple' );
		expect( readTheme() ).toBeNull();

		applyTheme( 'dark-red' );
		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-red' );
		expect( readTheme() ).toBe( 'dark-red' );

		applyTheme( null );
		expect( root.hasAttribute( 'data-theme' ) ).toBe( false );
	} );

	it( 'validates the response and sends only the theme', async () => {
		expect( parsePreferences( { theme: 'dark-green' } ) ).toEqual( {
			theme: 'dark-green',
		} );
		expect( parsePreferences( { theme: null } ) ).toEqual( {
			theme: null,
		} );

		for ( const body of [
			null,
			{},
			{ theme: 'dark' },
			{ theme: 3 },
			[ 'light' ],
		] ) {
			expect( parsePreferences( body ) ).toBeNull();
		}

		const requests = mockApi( { [ ROUTE ]: echo } );
		const result = await updateTheme( 'dark-pink' );

		expect( result ).toEqual( { ok: true, data: { theme: 'dark-pink' } } );
		expect( requests ).toEqual( [
			{
				path: '/merchboard/v1/me/preferences',
				method: 'PATCH',
				data: { theme: 'dark-pink' },
			},
		] );
	} );
} );

describe( 'ThemeSettings', () => {
	it( 'is a labelled native select with system default and the six themes', () => {
		mockApi( {} );
		view = renderSettings();
		const element = selectOf( view );
		const label = view.container.querySelector( 'label' );

		expect( label?.textContent ).toBe( 'Theme' );
		expect( label?.getAttribute( 'for' ) ).toBe( element.id );
		expect( element.getAttribute( 'aria-describedby' ) ).not.toBeNull();
		expect( view.container.querySelector( 'h2' )?.textContent ).toBe(
			'Appearance'
		);
		expect(
			Array.from( element.options ).map( ( option ) => [
				option.value,
				option.textContent,
			] )
		).toEqual( [
			[ '', 'System default' ],
			[ 'light', 'Light' ],
			[ 'dark-black', 'Dark black' ],
			[ 'dark-green', 'Dark green' ],
			[ 'dark-blue', 'Dark blue' ],
			[ 'dark-red', 'Dark red' ],
			[ 'dark-pink', 'Dark pink' ],
		] );
		expect( element.value ).toBe( '' );
	} );

	it( 'shows the Danish names through i18n', () => {
		setLocaleData(
			{
				Light: [ 'Lys' ],
				'Dark black': [ 'Mørk sort' ],
				'Dark green': [ 'Mørk grøn' ],
				'Dark blue': [ 'Mørk blå' ],
				'Dark red': [ 'Mørk rød' ],
				'Dark pink': [ 'Mørk pink' ],
				'System default': [ 'Systemstandard' ],
				Theme: [ 'Tema' ],
			},
			'merchboard'
		);
		mockApi( {} );
		view = renderSettings();

		expect(
			Array.from( selectOf( view ).options ).map(
				( option ) => option.textContent
			)
		).toEqual( [
			'Systemstandard',
			'Lys',
			'Mørk sort',
			'Mørk grøn',
			'Mørk blå',
			'Mørk rød',
			'Mørk pink',
		] );
		expect( view.container.querySelector( 'label' )?.textContent ).toBe(
			'Tema'
		);
		expect( themeLabel( 'dark-blue' ) ).toBe( 'Mørk blå' );
	} );

	it( 'selects the server-rendered theme', () => {
		root.setAttribute( 'data-theme', 'dark-green' );
		mockApi( {} );
		view = renderSettings();

		expect( selectOf( view ).value ).toBe( 'dark-green' );
		expect( selectOf( view ).selectedOptions[ 0 ]?.textContent ).toBe(
			'Dark green'
		);
	} );

	it( 'applies a choice at once and saves it', async () => {
		const pending = deferred();
		const requests = mockApi( { [ ROUTE ]: () => pending.promise } );
		view = renderSettings();

		choose( view, 'dark-blue' );

		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-blue' );
		expect( requests.map( ( request ) => request.data ) ).toEqual( [
			{ theme: 'dark-blue' },
		] );

		pending.resolve( response( 200, { theme: 'dark-blue' } ) );
		await flush();

		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-blue' );
		expect( selectOf( view ).value ).toBe( 'dark-blue' );
		expect(
			view.container.querySelector( '.merchboard-toast' )?.textContent
		).toContain( 'Theme saved.' );
		expect( view.container.querySelector( '[role="alert"]' ) ).toBeNull();
	} );

	it( 'resets to the system default by removing data-theme', async () => {
		root.setAttribute( 'data-theme', 'dark-red' );
		const requests = mockApi( { [ ROUTE ]: echo } );
		view = renderSettings();

		choose( view, '' );
		expect( root.hasAttribute( 'data-theme' ) ).toBe( false );
		await flush();

		expect( requests.map( ( request ) => request.data ) ).toEqual( [
			{ theme: null },
		] );
		expect( root.hasAttribute( 'data-theme' ) ).toBe( false );
		expect( selectOf( view ).value ).toBe( '' );
	} );

	it( 'restores the saved theme when saving fails and can retry', async () => {
		root.setAttribute( 'data-theme', 'dark-green' );
		let fail = true;
		const requests = mockApi( {
			[ ROUTE ]: ( request ) =>
				fail
					? response( 500, { code: 'merchboard_internal_error' } )
					: echo( request ),
		} );
		view = renderSettings();

		choose( view, 'dark-pink' );
		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-pink' );
		await flush();

		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-green' );
		expect( selectOf( view ).value ).toBe( 'dark-green' );
		expect(
			view.container.querySelector( '[role="alert"]' )?.textContent
		).toContain( 'Something went wrong.' );
		expect(
			view.container.querySelector( '.merchboard-toast' )
		).toBeNull();

		fail = false;
		click(
			Array.from( view.container.querySelectorAll( 'button' ) ).find(
				( button ) => button.textContent === 'Try again'
			) ?? null
		);
		await flush();

		expect( requests.map( ( request ) => request.data ) ).toEqual( [
			{ theme: 'dark-pink' },
			{ theme: 'dark-pink' },
		] );
		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-pink' );
		expect( selectOf( view ).value ).toBe( 'dark-pink' );
		expect( view.container.querySelector( '[role="alert"]' ) ).toBeNull();
	} );

	it( 'never lets a late response overwrite a newer choice', async () => {
		const first = deferred();
		const second = deferred();
		const queue = [ first, second ];
		const requests = mockApi( {
			[ ROUTE ]: () => {
				const next = queue.shift();

				if ( next === undefined ) {
					throw new Error( 'Unexpected request.' );
				}

				return next.promise;
			},
		} );
		view = renderSettings();

		choose( view, 'dark-blue' );
		choose( view, 'dark-red' );
		choose( view, 'dark-pink' );

		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-pink' );
		expect( requests ).toHaveLength( 1 );

		// The old request returns after the newer choices.
		first.resolve( response( 200, { theme: 'dark-blue' } ) );
		await flush();

		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-pink' );
		expect( selectOf( view ).value ).toBe( 'dark-pink' );
		expect( requests.map( ( request ) => request.data ) ).toEqual( [
			{ theme: 'dark-blue' },
			{ theme: 'dark-pink' },
		] );
		expect(
			view.container.querySelector( '.merchboard-toast' )
		).toBeNull();

		second.resolve( response( 200, { theme: 'dark-pink' } ) );
		await flush();

		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-pink' );
		expect( selectOf( view ).value ).toBe( 'dark-pink' );
		expect(
			view.container.querySelector( '.merchboard-toast' )?.textContent
		).toContain( 'Theme saved.' );
	} );

	it( 'a failed old request does not undo a newer choice', async () => {
		root.setAttribute( 'data-theme', 'light' );
		const first = deferred();
		const requests = mockApi( {
			[ ROUTE ]: ( request ) =>
				requests.length === 1 ? first.promise : echo( request ),
		} );
		view = renderSettings();

		choose( view, 'dark-blue' );
		choose( view, 'dark-green' );

		first.resolve( response( 500, { code: 'merchboard_internal_error' } ) );
		await flush();
		await flush();

		expect( requests.map( ( request ) => request.data ) ).toEqual( [
			{ theme: 'dark-blue' },
			{ theme: 'dark-green' },
		] );
		expect( root.getAttribute( 'data-theme' ) ).toBe( 'dark-green' );
		expect( selectOf( view ).value ).toBe( 'dark-green' );
		expect( view.container.querySelector( '[role="alert"]' ) ).toBeNull();
	} );

	it( 'stores nothing in the browser', async () => {
		const storage = jest.spyOn( Storage.prototype, 'setItem' );
		mockApi( { [ ROUTE ]: echo } );
		view = renderSettings();

		choose( view, 'dark-black' );
		await flush();

		// Cookies are forbidden by ESLint in the dashboard code.
		expect( storage ).not.toHaveBeenCalled();
		storage.mockRestore();
	} );
} );
