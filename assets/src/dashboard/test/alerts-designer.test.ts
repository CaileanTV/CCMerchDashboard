import { describe, expect, it } from '@jest/globals';

import {
	DEFAULT_LAYOUT,
	background,
	moveBox,
	resizeBox,
	sampleText,
	snap,
} from '../sections/alerts/designer';

const SNAP = {
	...DEFAULT_LAYOUT,
	canvas: { snap_grid: true, grid_size: 10 },
};

describe( 'designer helpers', () => {
	it( 'snaps to the grid only when snapping is on', () => {
		expect( snap( 43.4, DEFAULT_LAYOUT ) ).toBe( 43 );
		expect( snap( 43.4, SNAP ) ).toBe( 40 );
		expect( snap( 46, SNAP ) ).toBe( 50 );
	} );

	it( 'moves and resizes within the server bounds', () => {
		const box = DEFAULT_LAYOUT.elements.main_text;

		expect( moveBox( box, 9999, -9999, DEFAULT_LAYOUT ) ).toMatchObject( {
			x: 3840,
			y: -2160,
		} );
		expect( resizeBox( box, 3, 5000, DEFAULT_LAYOUT ) ).toMatchObject( {
			w: 10,
			h: 2160,
		} );
	} );

	it( 'keeps the aspect ratio of a locked box', () => {
		const image = DEFAULT_LAYOUT.elements.product_image;

		expect( resizeBox( image, 240, 10, DEFAULT_LAYOUT ) ).toMatchObject( {
			w: 240,
			h: 240,
		} );
	} );

	it( 'fills the sample values in one pass', () => {
		expect(
			sampleText( '$UserName bought $Product ($Variation) $Unknown' )
		).toBe( 'TestFan bought Hoodie (Size: L) $Unknown' );
		expect( sampleText( 'Hi $FirstName' ) ).toBe( 'Hi TestFan' );
	} );

	it( 'builds the box background', () => {
		expect( background( '#102030', 45 ) ).toBe( 'rgba(16,32,48,0.45)' );
	} );
} );
