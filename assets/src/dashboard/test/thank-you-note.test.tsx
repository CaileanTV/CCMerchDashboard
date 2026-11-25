import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseThankYouNote } from '../api/thankYouNote';
import { ConfigContext } from '../app/config-context';
import { RouterProvider } from '../app/router';
import { ThankYouNoteCard } from '../sections/profile/ThankYouNoteCard';
import { mockApi, response } from '../testing/api';
import type { RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/thank-you-note';

const NOTE = {
	enabled: false,
	text: '',
	limits: { max_text: 300, max_lines: 6 },
};

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

async function show(
	routes: Readonly< Record< string, Route > >
): Promise< { container: HTMLElement; requests: RecordedRequest[] } > {
	const requests = mockApi( routes );
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<RouterProvider>
				<ThankYouNoteCard />
			</RouterProvider>
		</ConfigContext.Provider>
	);
	await flush();
	await flush();
	await flush();

	return { container: rendered.container, requests };
}

function write( container: HTMLElement, value: string ): void {
	const textarea = container.querySelector( 'textarea' );

	act( () => {
		Object.getOwnPropertyDescriptor(
			HTMLTextAreaElement.prototype,
			'value'
		)?.set?.call( textarea, value );
		textarea?.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

async function submit( container: HTMLElement ): Promise< void > {
	act( () => {
		container
			.querySelector( '.merchboard-thank-you-note' )
			?.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
	} );

	for ( let i = 0; i < 6; i++ ) {
		await flush();
	}
}

describe( 'the thank-you note on the packing slip (phase 58)', () => {
	it( 'parses only valid answers', () => {
		expect( parseThankYouNote( NOTE ) ).toEqual( {
			enabled: false,
			text: '',
			maxText: 300,
			maxLines: 6,
		} );
		expect( parseThankYouNote( { ...NOTE, text: 3 } ) ).toBeNull();
		expect( parseThankYouNote( { ...NOTE, limits: null } ) ).toBeNull();
		expect( parseThankYouNote( null ) ).toBeNull();
	} );

	it( 'saves the note with its line breaks and counts the characters', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, NOTE ),
			[ `POST ${ PATH }` ]: () =>
				response( 200, {
					...NOTE,
					enabled: true,
					text: 'Tak!\nKh. Nova',
				} ),
		} );

		expect( container.textContent ).toContain( '0 of 300 characters' );
		click(
			container.querySelector( '#merchboard-thank-you-note-enabled' )
		);
		write( container, 'Tak!\nKh. Nova' );
		expect( container.textContent ).toContain( '13 of 300 characters' );
		await submit( container );

		expect(
			requests.find( ( request ) => request.method === 'POST' )?.data
		).toEqual( { enabled: true, text: 'Tak!\nKh. Nova' } );
		expect( container.textContent ).toContain(
			'The thank-you note was saved.'
		);
	} );

	it( 'shows why a note is refused', async () => {
		const { container } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, NOTE ),
			[ `POST ${ PATH }` ]: () =>
				response( 400, {
					code: 'merchboard_invalid_thank_you_note',
					message: 'Invalid.',
					data: { status: 400, fields: { text: 'has_link' } },
				} ),
		} );

		write( container, 'www.example.dk' );
		await submit( container );

		expect( container.textContent ).toContain(
			'Leave out links: the note is printed on paper.'
		);
	} );

	it( 'is hidden without the shop', async () => {
		const { container } = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 404, {
					code: 'rest_no_route',
					message: 'No route.',
					data: { status: 404 },
				} ),
		} );

		expect( container.textContent ).toBe( '' );
	} );
} );
