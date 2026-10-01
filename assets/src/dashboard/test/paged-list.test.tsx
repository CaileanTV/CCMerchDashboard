/**
 * The list foundation as a section would use it: usePagedApi + requestList +
 * Table + Pagination + the existing state components, against the mocked
 * API. No real section (Products/Orders come in later steps).
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';

import { requestList } from '../api/client';
import type { Validator } from '../api/client';
import { isRecord, readString } from '../api/validate';
import { ConfigContext } from '../app/config-context';
import { ApiErrorState } from '../components/ApiErrorState';
import { EmptyState } from '../components/EmptyState';
import { Pagination } from '../components/Pagination';
import { Table } from '../components/Table';
import { usePagedApi } from '../hooks/usePagedApi';
import type { PageLoader } from '../hooks/usePagedApi';
import { listResponse, mockApi, response } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

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

const loadThings: PageLoader< Thing > = ( page, perPage, signal ) =>
	requestList(
		{ path: '/merchboard/v1/things', page, perPage, signal },
		validateThing
	);

function ThingsSection() {
	const { state, page, setPage, retry } = usePagedApi( loadThings, 2 );

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return (
		<>
			<Table
				caption="Things"
				columns={ [
					{
						key: 'name',
						header: 'Name',
						render: ( thing: Thing ) => thing.name,
					},
				] }
				rows={ state.status === 'success' ? state.data.items : [] }
				rowKey={ ( thing ) => thing.name }
				loading={ state.status === 'loading' }
				empty={ <EmptyState title="No things yet" /> }
			/>
			<Pagination
				page={ page }
				totalPages={
					state.status === 'success' ? state.data.totalPages : 0
				}
				onChange={ setPage }
				disabled={ state.status === 'loading' }
			/>
		</>
	);
}

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

function show(): HTMLElement {
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<ThingsSection />
		</ConfigContext.Provider>
	);

	return rendered.container;
}

function names( container: HTMLElement ): string[] {
	return Array.from( container.querySelectorAll( 'tbody td' ) ).map(
		( td ) => td.textContent ?? ''
	);
}

describe( 'A paged list section', () => {
	it( 'loads the first page, then the next one on "Next"', async () => {
		const requests = mockApi( {
			'GET /merchboard/v1/things?page=1&per_page=2': () =>
				listResponse( [ { name: 'A' }, { name: 'B' } ], 3, 2 ),
			'GET /merchboard/v1/things?page=2&per_page=2': () =>
				listResponse( [ { name: 'C' } ], 3, 2 ),
		} );
		const container = show();

		expect( container.querySelector( '[role="status"]' ) ).not.toBeNull();
		await flush();

		expect( names( container ) ).toEqual( [ 'A', 'B' ] );
		expect(
			container.querySelector( '.merchboard-pagination__status' )
				?.textContent
		).toBe( 'Page 1 of 2' );

		click( container.querySelectorAll( 'nav button' )[ 1 ] ?? null );
		await flush();

		expect( names( container ) ).toEqual( [ 'C' ] );
		expect( requests.map( ( r ) => r.path ) ).toEqual( [
			'/merchboard/v1/things?page=1&per_page=2',
			'/merchboard/v1/things?page=2&per_page=2',
		] );
	} );

	it( 'shows the empty state and no pagination for an empty list', async () => {
		mockApi( {
			'GET /merchboard/v1/things?page=1&per_page=2': () =>
				listResponse( [], 0, 0 ),
		} );
		const container = show();
		await flush();

		expect( container.textContent ).toBe( 'No things yet' );
		expect( container.querySelector( 'nav' ) ).toBeNull();
	} );

	it( 'shows the API error state and retries', async () => {
		let calls = 0;
		mockApi( {
			'GET /merchboard/v1/things?page=1&per_page=2': () => {
				calls++;

				return calls === 1
					? response( 500, { code: 'merchboard_internal_error' } )
					: listResponse( [ { name: 'A' } ], 1, 1 );
			},
		} );
		const container = show();
		await flush();

		expect( container.textContent ).toContain( 'Something went wrong.' );

		click( container.querySelector( 'button' ) );
		await flush();

		expect( names( container ) ).toEqual( [ 'A' ] );
	} );

	it( 'treats a response without the pagination headers as an error', async () => {
		mockApi( {
			'GET /merchboard/v1/things?page=1&per_page=2': () =>
				response( 200, [ { name: 'A' } ] ),
		} );
		const container = show();
		await flush();

		expect( container.textContent ).toContain( 'Something went wrong.' );
		expect( container.querySelector( 'table' ) ).toBeNull();
	} );
} );
