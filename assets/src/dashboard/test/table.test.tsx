import { afterEach, describe, expect, it } from '@jest/globals';

import { EmptyState } from '../components/EmptyState';
import { Table } from '../components/Table';
import type { TableColumn } from '../components/Table';
import { render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

interface Row {
	readonly id: number;
	readonly name: string;
	readonly quantity: number;
}

const COLUMNS: readonly TableColumn< Row >[] = [
	{ key: 'name', header: 'Name', render: ( row ) => row.name },
	{
		key: 'quantity',
		header: 'Quantity',
		render: ( row ) => row.quantity,
		align: 'end',
	},
];

const ROWS: readonly Row[] = [
	{ id: 1, name: 'Tour tee', quantity: 2 },
	{ id: 2, name: '<b>Not bold</b>', quantity: 0 },
];

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

function show( ui: Parameters< typeof render >[ 0 ] ): HTMLElement {
	rendered = render( ui );

	return rendered.container;
}

function table( rows: readonly Row[], extra: { loading?: boolean } = {} ) {
	return (
		<Table
			caption="Things"
			columns={ COLUMNS }
			rows={ rows }
			rowKey={ ( row ) => row.id }
			empty={ <EmptyState title="Nothing yet" /> }
			{ ...extra }
		/>
	);
}

describe( 'Table', () => {
	it( 'renders a captioned table with column headers', () => {
		const container = show( table( ROWS ) );
		const headers = Array.from( container.querySelectorAll( 'thead th' ) );

		expect( container.querySelector( 'caption' )?.textContent ).toBe(
			'Things'
		);
		expect( headers.map( ( th ) => th.textContent ) ).toEqual( [
			'Name',
			'Quantity',
		] );
		expect( headers.map( ( th ) => th.getAttribute( 'scope' ) ) ).toEqual( [
			'col',
			'col',
		] );
	} );

	it( 'renders one row per item with cells as text, never HTML', () => {
		const container = show( table( ROWS ) );
		const rows = Array.from( container.querySelectorAll( 'tbody tr' ) );

		expect(
			rows.map( ( tr ) =>
				Array.from( tr.querySelectorAll( 'td' ) ).map(
					( td ) => td.textContent
				)
			)
		).toEqual( [
			[ 'Tour tee', '2' ],
			[ '<b>Not bold</b>', '0' ],
		] );
		expect( container.querySelector( 'tbody b' ) ).toBeNull();
		expect( rows[ 0 ]?.querySelectorAll( 'td' )[ 1 ]?.className ).toContain(
			'merchboard-table__cell--end'
		);
	} );

	it( 'is a focusable, labelled region that scrolls on small screens', () => {
		const container = show( table( ROWS ) );
		const region = container.querySelector( '.merchboard-table' );
		const caption = container.querySelector( 'caption' );

		expect( region?.getAttribute( 'role' ) ).toBe( 'region' );
		expect( region?.getAttribute( 'tabindex' ) ).toBe( '0' );
		expect( region?.getAttribute( 'aria-labelledby' ) ).toBe( caption?.id );
		expect( caption?.id ).not.toBe( '' );
	} );

	it( 'shows the empty state instead of the table without rows', () => {
		const container = show( table( [] ) );

		expect( container.querySelector( 'table' ) ).toBeNull();
		expect( container.textContent ).toBe( 'Nothing yet' );
	} );

	it( 'shows a loading placeholder instead of the table', () => {
		const container = show( table( ROWS, { loading: true } ) );

		expect( container.querySelector( 'table' ) ).toBeNull();
		expect(
			container.querySelector( '[role="status"]' )?.textContent
		).toBe( 'Things' );
	} );

	it( 'can hide the caption visually but keep it for screen readers', () => {
		const container = show(
			<Table
				caption="Things"
				captionHidden
				columns={ COLUMNS }
				rows={ ROWS }
				rowKey={ ( row ) => row.id }
				empty={ null }
			/>
		);

		expect( container.querySelector( 'caption' )?.className ).toBe(
			'merchboard-visually-hidden'
		);
	} );
} );
