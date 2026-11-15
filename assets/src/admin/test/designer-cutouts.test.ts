/**
 * Tests for the images without background on Proposals (ADR-114).
 */
import { describe, expect, it } from '@jest/globals';

import { parseJob } from '../designer-cutouts-admin';
import { toSilhouette } from '../silhouette';

const slot = {
	key: 'front:black',
	color: '#111111',
	view: { key: 'front' },
	print: '/wp-admin/admin-post.php?action=print',
	layers: { color: '/wp-admin/admin-post.php?asset=7', shading: 8 },
};

describe( 'parseJob', () => {
	it( 'reads the sides and colours to draw', () => {
		expect(
			parseJob( { success: true, data: { slots: [ slot ] } } )
		).toEqual( [
			{
				...slot,
				// Only URLs are layers.
				layers: { color: '/wp-admin/admin-post.php?asset=7' },
			},
		] );
	} );

	it( 'refuses an error or a malformed answer', () => {
		expect(
			parseJob( { success: false, data: { reason: 'x' } } )
		).toBeNull();
		expect( parseJob( { success: true, data: {} } ) ).toBeNull();
		expect(
			parseJob( {
				success: true,
				data: { slots: [ { ...slot, color: 'red' } ] },
			} )
		).toBeNull();
		expect( parseJob( null ) ).toBeNull();
	} );
} );

describe( 'toSilhouette', () => {
	it( 'makes every pixel black and keeps its transparency', () => {
		const data = new Uint8ClampedArray( [ 200, 100, 50, 255, 9, 9, 9, 0 ] );

		toSilhouette( data );

		expect( Array.from( data ) ).toEqual( [ 0, 0, 0, 255, 0, 0, 0, 0 ] );
	} );
} );
