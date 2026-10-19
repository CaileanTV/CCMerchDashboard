import { describe, expect, it } from '@jest/globals';

import {
	DEFAULT_LAYOUT,
	alignBoxes,
	background,
	growsUp,
	moveBox,
	moveBoxes,
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

describe( 'several boxes', () => {
	const { product_image: image, main_text: main } = DEFAULT_LAYOUT.elements;

	it( 'grows up near the bottom of the canvas', () => {
		expect( growsUp( main ) ).toBe( true );
		expect( growsUp( { ...main, y: 40 } ) ).toBe( false );
	} );

	it( 'moves the selected boxes together, snapping the dragged one', () => {
		const moved = moveBoxes(
			SNAP,
			{ product_image: image, main_text: main },
			'main_text',
			-103,
			-205
		);

		// The dragged box snaps to 70/690; the image keeps its distance.
		expect( moved.elements.main_text ).toMatchObject( { x: 70, y: 690 } );
		expect( moved.elements.product_image ).toMatchObject( {
			x: image.x - 106,
			y: image.y - 205,
		} );
		expect( moved.elements.secondary_text ).toEqual(
			DEFAULT_LAYOUT.elements.secondary_text
		);
	} );

	it( 'aligns to the canvas', () => {
		const right = alignBoxes(
			DEFAULT_LAYOUT,
			[ 'main_text' ],
			'right',
			'canvas'
		);
		const middle = alignBoxes(
			DEFAULT_LAYOUT,
			[ 'main_text' ],
			'middle',
			'canvas'
		);

		expect( right.elements.main_text.x ).toBe( 1920 - main.w );
		expect( middle.elements.main_text.y ).toBe( ( 1080 - main.h ) / 2 );
	} );

	it( 'aligns to the outline of the selected boxes', () => {
		const top = alignBoxes(
			DEFAULT_LAYOUT,
			[ 'product_image', 'main_text' ],
			'top',
			'selection'
		);
		const left = alignBoxes(
			DEFAULT_LAYOUT,
			[ 'product_image', 'main_text' ],
			'left',
			'selection'
		);
		const center = alignBoxes(
			DEFAULT_LAYOUT,
			[ 'product_image', 'main_text' ],
			'center',
			'selection'
		);

		expect( top.elements.main_text.y ).toBe( image.y );
		expect( top.elements.product_image.y ).toBe( image.y );
		expect( left.elements.main_text.x ).toBe( image.x );
		// The outline runs from 40 to 876: its centre is 458.
		expect( center.elements.product_image.x ).toBe( 458 - image.w / 2 );
		expect( center.elements.main_text.x ).toBe( 458 - main.w / 2 );
	} );
} );
