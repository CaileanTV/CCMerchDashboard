import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseAgreement } from '../api/agreement';
import { AgreementNotice } from '../components/AgreementNotice';
import { SafeHtml } from '../components/SafeHtml';
import { mockApi, response } from '../testing/api';
import { click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/agreement';

const WAITING = {
	required: true,
	must_accept: true,
	satisfied: false,
	accepted: 0,
	version: 1,
	published_at: '2026-11-06T12:00:00Z',
	text: '<h2>Aftale</h2><p>Læs <strong>dette</strong>.</p>',
};

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

describe( 'SafeHtml', () => {
	it( 'keeps the text and its plain formatting, never markup or scripts', () => {
		rendered = render(
			<SafeHtml html='<h2>Title</h2><p>A <strong>bold</strong> <a href="https://example.com">link</a> and <a href="javascript:alert(1)">no link</a>.</p><script>alert(1)</script><img src="x" onerror="alert(1)">' />
		);
		const { container } = rendered;

		expect( container.querySelector( 'h3' )?.textContent ).toBe( 'Title' );
		expect( container.querySelector( 'strong' )?.textContent ).toBe(
			'bold'
		);
		expect( container.querySelectorAll( 'a' ) ).toHaveLength( 1 );
		expect( container.querySelector( 'a' )?.getAttribute( 'href' ) ).toBe(
			'https://example.com'
		);
		expect( container.querySelector( 'script, img' ) ).toBeNull();
		expect( container.textContent ).toContain( 'no link' );
	} );
} );

describe( 'parseAgreement', () => {
	it( 'accepts the documented shape only', () => {
		expect( parseAgreement( WAITING )?.mustAccept ).toBe( true );
		expect( parseAgreement( { ...WAITING, version: '1' } ) ).toBeNull();
		expect(
			parseAgreement( { ...WAITING, text: null, published_at: null } )
				?.text
		).toBeNull();
	} );
} );

describe( 'AgreementNotice', () => {
	it( 'shows nothing when there is nothing to accept', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...WAITING,
					required: false,
					must_accept: false,
				} ),
		} );
		rendered = render( <AgreementNotice /> );
		await flush();

		expect( rendered.container.textContent ).toBe( '' );
	} );

	it( 'lets the creator read and accept it after confirming their age', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, WAITING ),
			[ `POST ${ PATH }` ]: () =>
				response( 200, {
					...WAITING,
					must_accept: false,
					satisfied: true,
					accepted: 1,
				} ),
		} );
		rendered = render( <AgreementNotice /> );
		await flush();

		expect( rendered.container.textContent ).toContain(
			'Please read and accept our agreement.'
		);

		click(
			Array.from( rendered.container.querySelectorAll( 'button' ) ).find(
				( button ) => button.textContent === 'Read the agreement'
			) ?? null
		);

		const dialog = document.querySelector( '[role="dialog"]' );
		expect(
			dialog?.querySelector( '.merchboard-agreement-text h3' )
				?.textContent
		).toBe( 'Aftale' );

		const accept = Array.from(
			dialog?.querySelectorAll( 'button' ) ?? []
		).find( ( button ) => button.textContent === 'Accept the agreement' );
		expect( accept?.disabled ).toBe( true );

		act( () => {
			(
				dialog?.querySelector(
					'input[type="checkbox"]'
				) as HTMLInputElement
			 ).click();
		} );
		expect( accept?.disabled ).toBe( false );

		click( accept ?? null );
		await flush();

		expect(
			requests.find( ( request ) => request.method === 'POST' )?.data
		).toEqual( { version: 1, adult: true } );
		expect( rendered.container.textContent ).toBe( '' );
		expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	} );
} );
