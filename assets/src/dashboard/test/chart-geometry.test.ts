import { describe, expect, it } from '@jest/globals';

import {
	chartGeometry,
	COLUMN,
	HEIGHT,
	MIN_COLUMN,
	SLOT,
	toUnits,
} from '../sections/sales/chart-geometry';

describe( 'toUnits', () => {
	it( 'parses four-decimal strings to exact BigInt units of 1/10000', () => {
		expect( toUnits( '0.0000' ) ).toBe( 0n );
		expect( toUnits( '1234.5000' ) ).toBe( 12345000n );
		expect( toUnits( '-0.0001' ) ).toBe( -1n );
		expect( toUnits( '999999999999999.9999' ) ).toBe(
			9999999999999999999n
		);
	} );

	it.each( [ '12.5', '1e3', '', '12.50000', ' 1.0000', '--1.0000' ] )(
		'rejects %p',
		( value ) => {
			expect( () => toUnits( value ) ).toThrow();
		}
	);
} );

describe( 'chartGeometry', () => {
	it( 'places positive columns above and negative columns below the zero baseline', () => {
		const geometry = chartGeometry( [ '100.0000', '-50.0000', '0.0000' ] );

		expect( geometry ).toEqual( {
			width: 3 * SLOT,
			height: HEIGHT,
			baseline: 66.66,
			columns: [
				{
					index: 0,
					x: 1.5,
					y: 0,
					width: COLUMN,
					height: 66.66,
					negative: false,
				},
				{
					index: 1,
					x: 11.5,
					y: 66.66,
					width: COLUMN,
					height: 33.33,
					negative: true,
				},
			],
			maxIndex: 0,
			minIndex: 1,
		} );
	} );

	it( 'draws no columns and no division by zero for a series of zeros', () => {
		const geometry = chartGeometry( [ '0.0000', '0.0000', '0.0000' ] );

		expect( geometry.baseline ).toBe( HEIGHT );
		expect( geometry.columns ).toEqual( [] );
		expect( geometry.maxIndex ).toBeNull();
		expect( geometry.minIndex ).toBeNull();
		expect( geometry.width ).toBe( 3 * SLOT );
	} );

	it( 'draws a single period as one full-height column', () => {
		const geometry = chartGeometry( [ '5.0000' ] );

		expect( geometry.width ).toBe( SLOT );
		expect( geometry.columns ).toEqual( [
			{
				index: 0,
				x: 1.5,
				y: 0,
				width: COLUMN,
				height: HEIGHT,
				negative: false,
			},
		] );
	} );

	it( 'puts the baseline at the top when every amount is negative', () => {
		const geometry = chartGeometry( [ '-10.0000', '-40.0000' ] );

		expect( geometry.baseline ).toBe( 0 );
		expect( geometry.columns.map( ( c ) => [ c.y, c.height ] ) ).toEqual( [
			[ 0, 25 ],
			[ 0, 100 ],
		] );
		expect( geometry.maxIndex ).toBeNull();
		expect( geometry.minIndex ).toBe( 1 );
	} );

	it( 'scales amounts beyond Number precision exactly and keeps every coordinate in the viewBox', () => {
		const geometry = chartGeometry( [
			'999999999999999.9999',
			'-999999999999999.9999',
			'999999999999999.9998',
		] );

		expect( geometry.baseline ).toBe( 50 );
		expect( geometry.columns.map( ( c ) => [ c.y, c.height ] ) ).toEqual( [
			[ 0, 50 ],
			[ 50, 50 ],
			[ 0.01, 49.99 ],
		] );

		for ( const column of geometry.columns ) {
			expect( Number.isFinite( column.y + column.height ) ).toBe( true );
			expect( column.y ).toBeGreaterThanOrEqual( 0 );
			expect( column.y + column.height ).toBeLessThanOrEqual( HEIGHT );
		}
	} );

	it( 'keeps a tiny non-zero amount visible', () => {
		const geometry = chartGeometry( [ '100000.0000', '0.0001' ] );

		expect( geometry.columns[ 1 ]?.height ).toBe( MIN_COLUMN );
		expect( geometry.columns[ 1 ]?.y ).toBe( HEIGHT - MIN_COLUMN );
	} );

	it( 'is deterministic', () => {
		const amounts = [ '12.3400', '-5.0000', '0.0000', '7.7700' ];

		expect( chartGeometry( amounts ) ).toEqual( chartGeometry( amounts ) );
	} );

	it( 'scales 366 periods without layout errors', () => {
		const amounts = Array.from(
			{ length: 366 },
			( _, index ) => `${ index % 7 === 0 ? '-' : '' }${ index }.0000`
		);
		const geometry = chartGeometry( amounts );

		expect( geometry.width ).toBe( 366 * SLOT );
		expect(
			geometry.columns.every(
				( c ) =>
					c.x >= 0 &&
					c.x + c.width <= geometry.width &&
					c.y >= 0 &&
					c.y + c.height <= HEIGHT + MIN_COLUMN
			)
		).toBe( true );
	} );
} );
