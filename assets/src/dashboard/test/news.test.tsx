import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseNews } from '../api/news';
import { ConfigContext } from '../app/config-context';
import { Dialog } from '../components/Dialog';
import { NewsContent } from '../sections/news/NewsContent';
import { NewsSection } from '../sections/news/NewsSection';
import { WhatsNew } from '../sections/news/WhatsNew';
import { mockApi, response } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/news';

const item = ( id: number, title: string, closed: string | null = null ) => ( {
	id,
	title,
	body: `<p>${ title } <strong>body</strong></p>`,
	published_at: '2026-10-23T10:00:00+00:00',
	seen_at: null,
	closed_at: closed,
} );

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
	document.body.innerHTML = '';
} );

describe( 'news', () => {
	it( 'validates the answer', () => {
		expect( parseNews( { items: [ item( 2, 'New' ) ] } )?.[ 0 ] ).toEqual( {
			id: 2,
			title: 'New',
			body: '<p>New <strong>body</strong></p>',
			publishedAt: '2026-10-23T10:00:00+00:00',
			seenAt: null,
			closedAt: null,
		} );
		expect(
			parseNews( { items: [ { ...item( 2, 'x' ), id: '2' } ] } )
		).toBeNull();
		expect(
			parseNews( { items: [ { ...item( 2, 'x' ), seen_at: 'nope' } ] } )
		).toBeNull();
		expect( parseNews( {} ) ).toBeNull();
	} );

	it( 'rebuilds only the allowed elements, never scripts or unsafe links', () => {
		rendered = render(
			<NewsContent html='<p>Hi <strong>there</strong> <em>you</em></p><ul><li>One</li></ul><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="https://example.com" onclick="x()">safe</a> <a href="javascript:alert(1)">unsafe</a><iframe src="https://example.com"></iframe>' />
		);
		const html = rendered.container.innerHTML;

		expect( html ).toContain( '<strong>there</strong>' );
		expect( html ).toContain( '<ul><li>One</li></ul>' );
		expect( html ).not.toMatch(
			/<script|<img|<iframe|onerror|onclick|javascript:/
		);
		expect(
			rendered.container.querySelector( 'a' )?.getAttribute( 'rel' )
		).toBe( 'noopener noreferrer' );
		expect( rendered.container.querySelectorAll( 'a' ) ).toHaveLength( 1 );
		expect( rendered.container.textContent ).toContain( 'unsafe' );
	} );

	it( 'is a modal dialog that takes focus, closes on Escape and returns focus', () => {
		const outside = document.createElement( 'button' );
		document.body.appendChild( outside );
		outside.focus();
		const onClose = jest.fn();

		rendered = render(
			<Dialog title="What's new" onClose={ onClose }>
				<p>Text</p>
			</Dialog>
		);
		const dialog = document.querySelector( '[role="dialog"]' );

		expect( dialog?.getAttribute( 'aria-modal' ) ).toBe( 'true' );
		expect(
			document.getElementById(
				dialog?.getAttribute( 'aria-labelledby' ) ?? ''
			)?.textContent
		).toBe( "What's new" );
		expect( outside.ownerDocument.activeElement ).toBe( dialog );
		expect( outside.hasAttribute( 'inert' ) ).toBe( true );

		act( () => {
			dialog?.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
			);
		} );
		expect( onClose ).toHaveBeenCalledTimes( 1 );

		rendered.unmount();
		rendered = null;
		expect( outside.hasAttribute( 'inert' ) ).toBe( false );
		expect( outside.ownerDocument.activeElement ).toBe( outside );
	} );

	it( 'opens the newest message the creator has not closed, once, and notes seen and closed', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					items: [ item( 3, 'Newest' ), item( 2, 'Older' ) ],
				} ),
			[ `POST ${ PATH }/3/seen` ]: () => response( 200, { items: [] } ),
			[ `POST ${ PATH }/3/closed` ]: () => response( 200, { items: [] } ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<WhatsNew />
			</ConfigContext.Provider>
		);
		await flush();

		expect(
			document.querySelector( '[role="dialog"] h2' )?.textContent
		).toBe( 'Newest' );
		expect(
			requests.map( ( r ) => `${ r.method } ${ r.path }` )
		).toContain( `POST ${ PATH }/3/seen` );

		click(
			Array.from( document.querySelectorAll( 'button' ) ).find(
				( button ) => button.textContent === 'Got it'
			) ?? null
		);
		await flush();

		expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
		expect( requests.at( -1 )?.path ).toBe( `${ PATH }/3/closed` );
	} );

	it( 'shows nothing when the newest is closed or the news cannot be read', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					items: [
						item( 3, 'Newest', '2026-10-23T11:00:00+00:00' ),
						item( 2, 'Older' ),
					],
				} ),
		} );
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<WhatsNew />
			</ConfigContext.Provider>
		);
		await flush();
		expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();

		rendered.unmount();
		mockApi( {} );
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<WhatsNew />
			</ConfigContext.Provider>
		);
		await flush();
		expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	} );

	it( 'lists every message on the page, the newest open', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					items: [ item( 3, 'Newest' ), item( 2, 'Older' ) ],
				} ),
			[ `POST ${ PATH }/2/seen` ]: () => response( 200, { items: [] } ),
		} );
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<NewsSection />
			</ConfigContext.Provider>
		);
		await flush();

		const details = Array.from(
			rendered.container.querySelectorAll( 'details' )
		);
		expect(
			details.map(
				( element ) =>
					element.querySelector( '.merchboard-news__title' )
						?.textContent
			)
		).toEqual( [ 'Newest', 'Older' ] );
		expect( details.map( ( element ) => element.open ) ).toEqual( [
			true,
			false,
		] );
	} );
} );
