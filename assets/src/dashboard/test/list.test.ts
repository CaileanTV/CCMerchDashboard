import { beforeEach, describe, expect, it, jest } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';

import { requestList } from '../api/client';
import type { Validator } from '../api/client';
import { isRecord, readString } from '../api/validate';
import { listResponse, response } from '../testing/api';

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

const PATH = '/merchboard/v1/things';

function list( page = 1, perPage = 2 ) {
	return requestList( { path: PATH, page, perPage }, validateThing );
}

describe( 'requestList', () => {
	beforeEach( () => {
		mockedFetch.mockReset();
	} );

	it( 'sends page and per_page in the query and returns the items with the totals', async () => {
		mockedFetch.mockResolvedValue(
			listResponse( [ { name: 'A' }, { name: 'B', extra: 1 } ], 5, 3 )
		);

		const result = await list( 2, 2 );

		expect( mockedFetch.mock.calls[ 0 ]?.[ 0 ] ).toEqual( {
			path: `${ PATH }?page=2&per_page=2`,
			method: 'GET',
			parse: false,
		} );
		expect( result ).toEqual( {
			ok: true,
			data: {
				items: [ { name: 'A' }, { name: 'B' } ],
				page: 2,
				perPage: 2,
				total: 5,
				totalPages: 3,
			},
		} );
	} );

	it( 'reads the headers case-insensitively', async () => {
		mockedFetch.mockResolvedValue(
			response( 200, [ { name: 'A' } ], {
				'x-wp-total': '1',
				'x-wp-totalpages': '1',
			} )
		);

		const result = await list();

		expect( result.ok && result.data.total ).toBe( 1 );
		expect( result.ok && result.data.totalPages ).toBe( 1 );
	} );

	it( 'treats an empty list with zero totals and a page after the last as valid', async () => {
		mockedFetch.mockResolvedValueOnce( listResponse( [], 0, 0 ) );
		mockedFetch.mockResolvedValueOnce( listResponse( [], 5, 3 ) );

		const empty = await list();
		const after = await list( 4 );

		expect( empty ).toEqual( {
			ok: true,
			data: {
				items: [],
				page: 1,
				perPage: 2,
				total: 0,
				totalPages: 0,
			},
		} );
		expect( after.ok && after.data.items ).toEqual( [] );
		expect( after.ok && after.data.totalPages ).toBe( 3 );
	} );

	it.each( [
		[ 'missing total', { 'X-WP-TotalPages': '1' } ],
		[ 'missing total pages', { 'X-WP-Total': '1' } ],
		[ 'not a number', { 'X-WP-Total': 'abc', 'X-WP-TotalPages': '1' } ],
		[ 'negative', { 'X-WP-Total': '-1', 'X-WP-TotalPages': '1' } ],
		[ 'fraction', { 'X-WP-Total': '1.5', 'X-WP-TotalPages': '1' } ],
		[ 'items but no pages', { 'X-WP-Total': '3', 'X-WP-TotalPages': '0' } ],
		[ 'pages but no items', { 'X-WP-Total': '0', 'X-WP-TotalPages': '1' } ],
	] )(
		'missing or malformed headers are an invalid response (%s)',
		async ( _case, headers ) => {
			mockedFetch.mockResolvedValue(
				response( 200, [ { name: 'A' } ], headers )
			);

			const result = await list();

			expect( result.ok ).toBe( false );
			expect( ! result.ok && result.error.kind ).toBe(
				'invalid_response'
			);
		}
	);

	it.each( [
		[ 'an object with items', { items: [ { name: 'A' } ] } ],
		[ 'an invalid item', [ { name: 'A' }, { nope: true } ] ],
		[
			'more items than the page size',
			[ { name: 'A' }, { name: 'B' }, { name: 'C' } ],
		],
		[ 'null', null ],
	] )(
		'a body that is not a valid page is an invalid response (%s)',
		async ( _case, body ) => {
			mockedFetch.mockResolvedValue(
				response( 200, body, {
					'X-WP-Total': '3',
					'X-WP-TotalPages': '2',
				} )
			);

			const result = await list();

			expect( ! result.ok && result.error.kind ).toBe(
				'invalid_response'
			);
		}
	);

	it.each( [
		[ 400, 'invalid_request', 'rest_invalid_param' ],
		[ 401, 'unauthenticated', 'merchboard_unauthenticated' ],
		[ 403, 'forbidden', 'merchboard_forbidden' ],
		[ 404, 'not_found', 'rest_no_route' ],
		[ 500, 'server', 'merchboard_internal_error' ],
	] )(
		'HTTP %i is reduced to %s without the server message',
		async ( status, kind, code ) => {
			mockedFetch.mockRejectedValue(
				response( status, { code, message: '<b>details</b>' } )
			);

			const result = await list();

			expect( result ).toEqual( {
				ok: false,
				error: { kind, status, code, fields: {} },
			} );
		}
	);

	it( 'network failures and aborts are reported as such', async () => {
		const controller = new AbortController();
		mockedFetch.mockRejectedValueOnce( new TypeError( 'Failed to fetch' ) );
		mockedFetch.mockRejectedValueOnce(
			Object.assign( new Error( 'aborted' ), { name: 'AbortError' } )
		);

		const network = await list();
		const aborted = await requestList(
			{ path: PATH, page: 1, perPage: 2, signal: controller.signal },
			validateThing
		);

		expect( ! network.ok && network.error.kind ).toBe( 'network' );
		expect( ! aborted.ok && aborted.error.kind ).toBe( 'aborted' );
	} );

	it.each( [
		[ 'page 0', { path: PATH, page: 0, perPage: 20 } ],
		[ 'fractional page', { path: PATH, page: 1.5, perPage: 20 } ],
		[ 'page size 0', { path: PATH, page: 1, perPage: 0 } ],
		[ 'page size 101', { path: PATH, page: 1, perPage: 101 } ],
		[
			'a query in the path',
			{ path: `${ PATH }?creator_id=2`, page: 1, perPage: 20 },
		],
		[ 'another namespace', { path: '/wp/v2/users', page: 1, perPage: 20 } ],
	] )(
		'a programming error is thrown before any request (%s)',
		async ( _case, options ) => {
			await expect(
				requestList( options, validateThing )
			).rejects.toThrow();
			expect( mockedFetch ).not.toHaveBeenCalled();
		}
	);
} );
