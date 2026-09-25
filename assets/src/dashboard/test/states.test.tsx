import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { useCallback } from '@wordpress/element';
import { act } from 'react';

import type { ApiError, ApiResult } from '../api/client';
import { ConfigContext } from '../app/config-context';
import { Alert } from '../components/Alert';
import { ApiErrorState } from '../components/ApiErrorState';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { EmptyState } from '../components/EmptyState';
import { Field } from '../components/Field';
import { Skeleton } from '../components/Skeleton';
import { useApi } from '../hooks/useApi';
import type { ApiState } from '../hooks/useApi';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

function show( ui: Parameters< typeof render >[ 0 ] ): HTMLElement {
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>{ ui }</ConfigContext.Provider>
	);

	return rendered.container;
}

function error( kind: ApiError[ 'kind' ], status: number ): ApiError {
	return { kind, status, code: 'rest_forbidden' };
}

describe( 'ApiErrorState', () => {
	it( '401: session expired with a server-provided login link, no automatic redirect', () => {
		const before = window.location.href;
		const container = show(
			<ApiErrorState error={ error( 'unauthenticated', 401 ) } />
		);

		expect(
			container.querySelector( '[role="alert"]' )?.textContent
		).toContain( 'Your session has expired.' );
		expect( container.querySelector( 'a' )?.getAttribute( 'href' ) ).toBe(
			CONFIG.loginUrl
		);
		expect( window.location.href ).toBe( before );
	} );

	it( '403: no access with a logout link', () => {
		const container = show(
			<ApiErrorState error={ error( 'forbidden', 403 ) } />
		);

		expect( container.textContent ).toContain(
			'You do not have access to this.'
		);
		expect( container.querySelector( 'a' )?.getAttribute( 'href' ) ).toBe(
			CONFIG.logoutUrl
		);
	} );

	it.each( [
		[ 'server', 500 ],
		[ 'network', 0 ],
		[ 'invalid_response', 200 ],
	] as const )(
		'%s: generic message and a working retry button',
		( kind, status ) => {
			const onRetry = jest.fn();
			const container = show(
				<ApiErrorState
					error={ error( kind, status ) }
					onRetry={ onRetry }
				/>
			);

			expect( container.textContent ).toContain(
				'Something went wrong.'
			);
			expect( container.textContent ).not.toContain( 'rest_forbidden' );
			click( container.querySelector( 'button' ) );
			expect( onRetry ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'never shows server details', () => {
		const container = show(
			<ApiErrorState
				error={ {
					kind: 'server',
					status: 500,
					code: '<b>db_error</b>',
				} }
			/>
		);

		expect( container.innerHTML ).not.toContain( 'db_error' );
	} );
} );

describe( 'useApi', () => {
	function describeState( state: ApiState< string > ): string {
		if ( state.status === 'success' ) {
			return `success:${ state.data }`;
		}

		return state.status === 'error'
			? `error:${ state.error.kind }`
			: 'loading';
	}

	function Probe( {
		load,
	}: {
		readonly load: (
			signal: AbortSignal
		) => Promise< ApiResult< string > >;
	} ) {
		const { state, retry } = useApi( load );

		return (
			<div>
				<span data-testid="state">{ describeState( state ) }</span>
				<button type="button" onClick={ retry }>
					retry
				</button>
			</div>
		);
	}

	function StableProbe( {
		load,
	}: {
		readonly load: (
			signal: AbortSignal
		) => Promise< ApiResult< string > >;
	} ) {
		const stable = useCallback(
			( signal: AbortSignal ) => load( signal ),
			[ load ]
		);

		return <Probe load={ stable } />;
	}

	const text = ( container: HTMLElement ) =>
		container.querySelector( '[data-testid="state"]' )?.textContent;

	it( 'goes from loading to success', async () => {
		const container = show(
			<StableProbe
				load={ () =>
					Promise.resolve( { ok: true, data: 'hi' } as const )
				}
			/>
		);

		expect( text( container ) ).toBe( 'loading' );
		await flush();
		expect( text( container ) ).toBe( 'success:hi' );
	} );

	it( 'shows errors and retries', async () => {
		const load = jest
			.fn< ( signal: AbortSignal ) => Promise< ApiResult< string > > >()
			.mockResolvedValueOnce( {
				ok: false,
				error: error( 'server', 500 ),
			} )
			.mockResolvedValueOnce( { ok: true, data: 'second' } );
		const container = show( <StableProbe load={ load } /> );

		await flush();
		expect( text( container ) ).toBe( 'error:server' );

		click( container.querySelector( 'button' ) );
		expect( text( container ) ).toBe( 'loading' );
		await flush();
		expect( text( container ) ).toBe( 'success:second' );
		expect( load ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'turns a thrown endpoint error into a generic error', async () => {
		const container = show(
			<StableProbe load={ () => Promise.reject( new Error( 'bug' ) ) } />
		);

		await flush();
		expect( text( container ) ).toBe( 'error:server' );
	} );

	it( 'aborts the request on unmount and ignores its late result', async () => {
		let signal: AbortSignal | null = null;
		let resolve: ( value: ApiResult< string > ) => void = () => undefined;
		const container = show(
			<StableProbe
				load={ ( s ) => {
					signal = s;
					return new Promise( ( r ) => {
						resolve = r;
					} );
				} }
			/>
		);

		rendered?.unmount();
		rendered = null;
		expect( ( signal as AbortSignal | null )?.aborted ).toBe( true );

		await act( async () => resolve( { ok: true, data: 'late' } ) );
		expect( container.textContent ).toBe( '' );
	} );
} );

describe( 'components', () => {
	it( 'Button: native button, type button by default, busy state', () => {
		const container = show(
			<>
				<Button>Save</Button>
				<Button isBusy variant="secondary" size="sm">
					Busy
				</Button>
			</>
		);
		const [ save, busy ] = Array.from(
			container.querySelectorAll( 'button' )
		);

		expect( save?.getAttribute( 'type' ) ).toBe( 'button' );
		expect( save?.className ).toBe(
			'merchboard-button merchboard-button--primary merchboard-button--md'
		);
		expect( busy?.disabled ).toBe( true );
		expect( busy?.getAttribute( 'aria-busy' ) ).toBe( 'true' );
	} );

	it( 'Alert: tone as text and the right live-region role', () => {
		const container = show(
			<>
				<Alert tone="danger">Broken</Alert>
				<Alert tone="success">Saved</Alert>
			</>
		);
		const [ danger, success ] = Array.from(
			container.querySelectorAll( '.merchboard-alert' )
		);

		expect( danger?.getAttribute( 'role' ) ).toBe( 'alert' );
		expect( danger?.textContent ).toBe( 'Error: Broken' );
		expect( success?.getAttribute( 'role' ) ).toBe( 'status' );
		expect( success?.textContent ).toBe( 'Success: Saved' );
	} );

	it( 'Card: labelled section with a heading', () => {
		const container = show( <Card title="Profile">Body</Card> );
		const section = container.querySelector( 'section' );
		const heading = container.querySelector( 'h2' );

		expect( section?.getAttribute( 'aria-labelledby' ) ).toBe(
			heading?.id
		);
		expect( heading?.textContent ).toBe( 'Profile' );
	} );

	it( 'Skeleton: status for screen readers, bars hidden', () => {
		const container = show(
			<Skeleton label="Loading overview…" lines={ 2 } />
		);

		expect(
			container.querySelector( '[role="status"]' )?.textContent
		).toBe( 'Loading overview…' );
		expect(
			container.querySelectorAll( '[aria-hidden="true"]' )
		).toHaveLength( 2 );
	} );

	it( 'EmptyState: title, description and action', () => {
		const container = show(
			<EmptyState
				title="Nothing yet"
				description="Later"
				action={ <Button>Go</Button> }
			/>
		);

		expect( container.textContent ).toBe( 'Nothing yetLaterGo' );
	} );

	it( 'Field: label, help and error are connected to the control', () => {
		const container = show(
			<Field label="Name" help="Shown publicly" error="Required">
				{ ( control ) => <input { ...control } /> }
			</Field>
		);
		const input = container.querySelector( 'input' );
		const label = container.querySelector( 'label' );
		const describedBy =
			input?.getAttribute( 'aria-describedby' )?.split( ' ' ) ?? [];

		expect( label?.getAttribute( 'for' ) ).toBe( input?.id );
		expect( input?.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect(
			describedBy.map(
				( id ) =>
					container.querySelector( `[id="${ id }"]` )?.textContent
			)
		).toEqual( [ 'Shown publicly', 'Required' ] );
	} );
} );
