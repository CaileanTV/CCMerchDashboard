import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseCollections } from '../api/collections';
import { ConfigContext } from '../app/config-context';
import { CollectionsCard } from '../sections/products/CollectionsCard';
import { mockApi, response } from '../testing/api';
import type { RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/collections';

const STATE = {
	available: true,
	products: [
		{ id: 21, name: 'Tee Red' },
		{ id: 22, name: 'Tee Blue' },
	],
	collections: [ { id: 10, name: 'Tee', published: true, designs: 3 } ],
	requests: [
		{
			id: 4,
			kind: 'new',
			name: 'Hoodie',
			status: 'declined',
			note: 'Different products.',
			designs: [ 'Hoodie A', 'Hoodie B' ],
			requested_at: '2026-11-22 12:00:00',
		},
	],
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
			<CollectionsCard />
		</ConfigContext.Provider>
	);
	await flush();

	return { container: rendered.container, requests };
}

function button( text: string ): HTMLButtonElement {
	const found = Array.from( document.querySelectorAll( 'button' ) ).find(
		( item ) => item.textContent?.trim() === text
	);

	if ( found === undefined ) {
		throw new Error( `No button: ${ text }` );
	}

	return found;
}

async function type( selector: string, value: string ): Promise< void > {
	const field = document.querySelector( selector ) as
		HTMLInputElement | HTMLSelectElement;
	const prototype =
		field instanceof HTMLSelectElement
			? HTMLSelectElement.prototype
			: HTMLInputElement.prototype;

	act( () => {
		Object.getOwnPropertyDescriptor( prototype, 'value' )?.set?.call(
			field,
			value
		);
		field.dispatchEvent(
			new Event(
				field instanceof HTMLSelectElement ? 'change' : 'input',
				{ bubbles: true }
			)
		);
	} );
	await flush();
}

async function tick( id: number ): Promise< void > {
	click(
		document.querySelector(
			`.merchboard-collection__designs input[value="${ id }"]`
		)
	);
	await flush();
}

describe( 'parseCollections', () => {
	it( 'reads the documented fields', () => {
		expect( parseCollections( STATE )?.requests[ 0 ] ).toEqual( {
			id: 4,
			kind: 'new',
			name: 'Hoodie',
			status: 'declined',
			note: 'Different products.',
			designs: [ 'Hoodie A', 'Hoodie B' ],
		} );
	} );

	it( 'refuses anything else', () => {
		expect( parseCollections( {} ) ).toBeNull();
		expect(
			parseCollections( {
				...STATE,
				requests: [ { ...STATE.requests[ 0 ], status: 'lost' } ],
			} )
		).toBeNull();
	} );
} );

describe( 'Collections (ADR-120)', () => {
	it( 'shows a request with our note', async () => {
		const { container } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
		} );

		expect( container.textContent ).toContain( 'Hoodie' );
		expect( container.textContent ).toContain(
			'Not approved: Different products.'
		);
	} );

	it( 'asks for a new collection of at least two designs', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
			[ `POST ${ PATH }` ]: () =>
				response( 201, {
					...STATE,
					products: [],
					requests: [
						{
							...STATE.requests[ 0 ],
							id: 5,
							name: 'Nova Tee',
							status: 'pending',
							note: null,
						},
					],
				} ),
		} );

		click( button( 'Request a collection' ) );
		await flush();
		await type( '.merchboard-collection__name', 'Nova Tee' );
		await tick( 21 );
		expect( button( 'Send request' ).disabled ).toBe( true );

		await tick( 22 );
		click( button( 'Send request' ) );
		await flush();

		expect(
			requests.find( ( item ) => item.method === 'POST' )?.data
		).toEqual( { name: 'Nova Tee', product_ids: [ 21, 22 ] } );
		expect( container.textContent ).toContain( 'Waiting for our approval' );
		expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	} );

	it( 'asks to add to a collection, which is never preselected', async () => {
		const { requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
			[ `POST ${ PATH }` ]: () =>
				response( 400, {
					code: 'merchboard_collection_refused',
					message: 'Refused.',
					data: { status: 400, reason: 'product_requested' },
				} ),
		} );

		click( button( 'Request a collection' ) );
		await flush();
		click(
			document.querySelectorAll( 'input[type="radio"]' )[ 1 ] ?? null
		);
		await flush();
		await tick( 21 );
		expect( button( 'Send request' ).disabled ).toBe( true );

		await type( '.merchboard-collection__target', '10' );
		click( button( 'Send request' ) );
		await flush();

		expect(
			requests.find( ( item ) => item.method === 'POST' )?.data
		).toEqual( { collection_id: 10, product_ids: [ 21 ] } );
		expect( document.body.textContent ).toContain(
			'One of the products is already waiting in another request.'
		);
	} );

	it( 'is not there without a request or a way to send one', async () => {
		const { container } = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { ...STATE, available: false, requests: [] } ),
		} );

		expect( container.textContent ).toBe( '' );
	} );
} );
