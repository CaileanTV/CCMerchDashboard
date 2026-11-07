/**
 * Tests for an element's size in centimetres (ADR-096 pkt. 8).
 */
import { describe, expect, it, jest } from '@jest/globals';

import { objectSizeInCm, sizeInCm } from '../sections/designer/design-model';
import type { DesignObject } from '../sections/designer/design-model';
import { cmText } from '../sections/designer/DesignerEditor';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

describe( 'the size of an element on the print', () => {
	it( 'turns print file pixels into centimetres at the side’s dpi', () => {
		// A 300 mm wide print area at 150 dpi is 1772 px.
		expect( sizeInCm( 1772, 886, 150 ) ).toEqual( {
			width: 30,
			height: 15,
		} );
		expect( sizeInCm( 300, 300, 300 ) ).toEqual( {
			width: 2.5,
			height: 2.5,
		} );
		expect( sizeInCm( -600, 0, 300 ) ).toEqual( { width: 5.1, height: 0 } );
		expect( sizeInCm( 100, 100, 0 ) ).toEqual( { width: 0, height: 0 } );
	} );

	it( 'counts the scale of an element', () => {
		const object = {
			type: 'rect',
			id: 'a',
			x: 0,
			y: 0,
			width: 600,
			height: 300,
			angle: 30,
			scaleX: 0.5,
			scaleY: 2,
			flipX: false,
			flipY: false,
			opacity: 1,
			locked: false,
			hidden: false,
			fill: '#000000',
			stroke: null,
			strokeWidth: 0,
			radius: 0,
		} as DesignObject;

		expect( objectSizeInCm( object, 300 ) ).toEqual( {
			width: 2.5,
			height: 5.1,
		} );
	} );

	it( 'shows the size with one decimal in the viewer’s locale', () => {
		expect( cmText( { width: 12.5, height: 8 }, 'da-DK' ) ).toBe(
			'12,5 × 8,0 cm'
		);
		expect( cmText( { width: 3, height: 10.2 }, 'en-US' ) ).toBe(
			'3.0 × 10.2 cm'
		);
	} );
} );
