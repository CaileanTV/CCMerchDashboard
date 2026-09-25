import { beforeEach, describe, expect, it, jest } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';

import { request } from '../api/client';
import type { Validator } from '../api/client';
import { isRecord, readString } from '../api/validate';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const mockedFetch = apiFetch as unknown as jest.Mock<
	( options: unknown ) => Promise< unknown >
>;

interface Thing {
	readonly name: string;
}

const validateThing: Validator< Thing > = ( body ) => {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const name = readString( body, 'name' );

	return name === null ? null : { name };
};

function response( status: number, body: unknown, jsonFails = false ) {
	return {
		status,
		ok: status >= 200 && status < 300,
		json: () =>
			jsonFails
				? Promise.reject( new SyntaxError( 'Unexpected token' ) )
				: Promise.resolve( body ),
	};
}

describe( 'API client', () => {
	beforeEach( () => {
		mockedFetch.mockReset();
	} );

	it( 'sends the request through apiFetch without parsing and validates the body', async () => {
		mockedFetch.mockResolvedValue(
			response( 200, { name: 'Nova', extra: '<b>ignored</b>' } )
		);

		const result = await request(
			{ path: '/merchboard/v1/thing' },
			validateThing
		);

		expect( result ).toEqual( { ok: true, data: { name: 'Nova' } } );
		expect( mockedFetch ).toHaveBeenCalledTimes( 1 );
		expect( mockedFetch.mock.calls[ 0 ]?.[ 0 ] ).toEqual( {
			path: '/merchboard/v1/thing',
			method: 'GET',
			parse: false,
		} );
	} );

	it( 'passes method, data and abort signal', async () => {
		mockedFetch.mockResolvedValue( response( 200, { name: 'x' } ) );
		const controller = new AbortController();

		await request(
			{
				path: '/merchboard/v1/thing',
				method: 'PATCH',
				data: { name: 'x' },
				signal: controller.signal,
			},
			validateThing
		);

		expect( mockedFetch.mock.calls[ 0 ]?.[ 0 ] ).toEqual( {
			path: '/merchboard/v1/thing',
			method: 'PATCH',
			data: { name: 'x' },
			signal: controller.signal,
			parse: false,
		} );
	} );

	it.each( [
		[ 401, 'unauthenticated' ],
		[ 403, 'forbidden' ],
		[ 404, 'not_found' ],
		[ 500, 'server' ],
		[ 502, 'server' ],
	] )(
		'maps HTTP %i to %s, keeping only the error code',
		async ( status, kind ) => {
			// With parse: false, apiFetch rejects with the Response for non-2xx statuses.
			mockedFetch.mockRejectedValue(
				response( status, {
					code: 'rest_forbidden',
					message: '<img src=x onerror=alert(1)> internal detail',
					data: { status },
				} )
			);

			const result = await request(
				{ path: '/merchboard/v1/thing' },
				validateThing
			);

			expect( result ).toEqual( {
				ok: false,
				error: { kind, status, code: 'rest_forbidden' },
			} );
			expect( JSON.stringify( result ) ).not.toContain(
				'internal detail'
			);
		}
	);

	it( 'handles error responses without a JSON body', async () => {
		mockedFetch.mockRejectedValue( response( 500, null, true ) );

		expect(
			await request( { path: '/merchboard/v1/thing' }, validateThing )
		).toEqual( {
			ok: false,
			error: { kind: 'server', status: 500, code: null },
		} );
	} );

	it.each( [
		[ 'invalid JSON', response( 200, null, true ) ],
		[ 'wrong shape', response( 200, { title: 'x' } ) ],
		[ 'array', response( 200, [ { name: 'x' } ] ) ],
		[ 'not a response', 'text' ],
	] )(
		'treats a malformed success response (%s) as invalid_response',
		async ( _label, value ) => {
			mockedFetch.mockResolvedValue( value );

			const result = await request(
				{ path: '/merchboard/v1/thing' },
				validateThing
			);

			expect( result.ok ).toBe( false );
			expect( result.ok ? null : result.error.kind ).toBe(
				'invalid_response'
			);
		}
	);

	it( 'reports network failures and aborts', async () => {
		mockedFetch.mockRejectedValue( {
			code: 'fetch_error',
			message: 'You are probably offline.',
		} );
		expect(
			await request( { path: '/merchboard/v1/thing' }, validateThing )
		).toEqual( {
			ok: false,
			error: { kind: 'network', status: 0, code: null },
		} );

		mockedFetch.mockRejectedValue( { name: 'AbortError' } );
		expect(
			await request( { path: '/merchboard/v1/thing' }, validateThing )
		).toEqual( {
			ok: false,
			error: { kind: 'aborted', status: 0, code: null },
		} );
	} );

	it.each( [
		'/wp/v2/users/me',
		'/wp/v2/users?creator=1',
		'https://evil.example/merchboard/v1/x',
		'/merchboard/v1/../../wp/v2/users',
		'merchboard/v1/x',
	] )(
		'refuses requests outside the plugin namespace: %s',
		async ( path ) => {
			await expect( request( { path }, validateThing ) ).rejects.toThrow(
				'merchboard/v1 namespace'
			);
			expect( mockedFetch ).not.toHaveBeenCalled();
		}
	);
} );
