import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { useState } from '@wordpress/element';

import { Pagination } from '../components/Pagination';
import { click, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

function show( ui: Parameters< typeof render >[ 0 ] ): HTMLElement {
	rendered = render( ui );

	return rendered.container;
}

function buttons( container: HTMLElement ): HTMLButtonElement[] {
	return Array.from( container.querySelectorAll( 'button' ) );
}

function status( container: HTMLElement ): string {
	return (
		container.querySelector( '.merchboard-pagination__status' )
			?.textContent ?? ''
	);
}

function Controlled( {
	initial,
	totalPages,
}: {
	readonly initial: number;
	readonly totalPages: number;
} ) {
	const [ page, setPage ] = useState( initial );

	return (
		<Pagination
			page={ page }
			totalPages={ totalPages }
			onChange={ setPage }
		/>
	);
}

describe( 'Pagination', () => {
	it( 'on the first page: previous is disabled, next enabled, "Page 1 of 3"', () => {
		const container = show(
			<Pagination page={ 1 } totalPages={ 3 } onChange={ jest.fn() } />
		);
		const [ previous, next ] = buttons( container );

		expect( status( container ) ).toBe( 'Page 1 of 3' );
		expect( previous?.disabled ).toBe( true );
		expect( next?.disabled ).toBe( false );
	} );

	it( 'on a middle page both buttons work and report the target page', () => {
		const onChange = jest.fn();
		const container = show(
			<Pagination page={ 2 } totalPages={ 3 } onChange={ onChange } />
		);
		const [ previous, next ] = buttons( container );

		click( previous ?? null );
		click( next ?? null );

		expect( onChange.mock.calls ).toEqual( [ [ 1 ], [ 3 ] ] );
	} );

	it( 'on the last page: next is disabled', () => {
		const container = show(
			<Pagination page={ 3 } totalPages={ 3 } onChange={ jest.fn() } />
		);
		const [ previous, next ] = buttons( container );

		expect( status( container ) ).toBe( 'Page 3 of 3' );
		expect( previous?.disabled ).toBe( false );
		expect( next?.disabled ).toBe( true );
	} );

	it.each( [
		[ 'zero pages', 0 ],
		[ 'a single page', 1 ],
	] )( 'renders nothing with %s', ( _case, totalPages ) => {
		const container = show(
			<Pagination
				page={ 1 }
				totalPages={ totalPages }
				onChange={ jest.fn() }
			/>
		);

		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'after the last page, previous leads back to the last page', () => {
		const onChange = jest.fn();
		const container = show(
			<Pagination page={ 5 } totalPages={ 2 } onChange={ onChange } />
		);
		const [ previous, next ] = buttons( container );

		click( previous ?? null );

		expect( status( container ) ).toBe( 'Page 5 of 2' );
		expect( next?.disabled ).toBe( true );
		expect( onChange ).toHaveBeenCalledWith( 2 );
	} );

	it( 'a list that is now empty still offers the way back from a later page', () => {
		const onChange = jest.fn();
		const container = show(
			<Pagination page={ 3 } totalPages={ 0 } onChange={ onChange } />
		);

		click( buttons( container )[ 0 ] ?? null );

		expect( onChange ).toHaveBeenCalledWith( 1 );
	} );

	it( 'disabled turns both buttons off', () => {
		const container = show(
			<Pagination
				page={ 2 }
				totalPages={ 3 }
				onChange={ jest.fn() }
				disabled
			/>
		);

		expect( buttons( container ).map( ( b ) => b.disabled ) ).toEqual( [
			true,
			true,
		] );
	} );

	it( 'is a labelled navigation of native buttons with a live page status', () => {
		const container = show(
			<Pagination
				page={ 2 }
				totalPages={ 3 }
				onChange={ jest.fn() }
				label="Products pages"
			/>
		);
		const nav = container.querySelector( 'nav' );

		expect( nav?.getAttribute( 'aria-label' ) ).toBe( 'Products pages' );
		expect( buttons( container ).map( ( b ) => b.textContent ) ).toEqual( [
			'Previous',
			'Next',
		] );
		expect( buttons( container ).map( ( b ) => b.type ) ).toEqual( [
			'button',
			'button',
		] );
		expect(
			container
				.querySelector( '.merchboard-pagination__status' )
				?.getAttribute( 'aria-live' )
		).toBe( 'polite' );
	} );

	it( 'has a default accessible name', () => {
		const container = show(
			<Pagination page={ 1 } totalPages={ 2 } onChange={ jest.fn() } />
		);

		expect(
			container.querySelector( 'nav' )?.getAttribute( 'aria-label' )
		).toBe( 'Pagination' );
	} );

	it( 'keyboard: the buttons are focusable, and focus moves to the status when the focused button becomes disabled', () => {
		const container = show( <Controlled initial={ 1 } totalPages={ 2 } /> );
		const next = buttons( container )[ 1 ] ?? null;

		next?.focus();
		expect( container.ownerDocument.activeElement ).toBe( next );

		click( next );

		expect( status( container ) ).toBe( 'Page 2 of 2' );
		expect( next?.disabled ).toBe( true );
		expect( container.ownerDocument.activeElement ).toBe(
			container.querySelector( '.merchboard-pagination__status' )
		);
	} );
} );
