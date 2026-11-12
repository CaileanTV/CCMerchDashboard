import { describe, expect, it } from '@jest/globals';

import { jpegWithDpi, pngWithDpi } from '../sections/designer/dpi';

/** A 1×1 PNG as a canvas writes it: IHDR, IDAT and IEND, no pHYs. */
const PNG = Uint8Array.from(
	atob(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
	),
	( c ) => c.charCodeAt( 0 )
);

/**
 * The chunk types of a PNG in order.
 *
 * @param bytes PNG.
 */
function chunks( bytes: Uint8Array ): string[] {
	const types: string[] = [];
	const view = new DataView( bytes.buffer, bytes.byteOffset );

	for ( let at = 8; at + 12 <= bytes.length; ) {
		const length = view.getUint32( at );
		types.push(
			String.fromCharCode(
				...Array.from( bytes.subarray( at + 4, at + 8 ) )
			)
		);
		at += 12 + length;
	}

	return types;
}

describe( 'print file dpi', () => {
	it( 'writes 300 dpi into a PNG after IHDR, once', () => {
		const once = pngWithDpi( PNG, 300 );
		const twice = pngWithDpi( once, 300 );

		expect( chunks( once ) ).toEqual( [ 'IHDR', 'pHYs', 'IDAT', 'IEND' ] );
		expect( twice ).toEqual( once );

		const at = once.indexOf( 0x70, 8 ); // "p" of pHYs.
		const view = new DataView( once.buffer, once.byteOffset );
		// 300 dpi = 11811 pixels per metre, unit 1 (metre).
		expect( [
			view.getUint32( at + 4 ),
			view.getUint32( at + 8 ),
			once[ at + 12 ],
		] ).toEqual( [ 11811, 11811, 1 ] );

		// 3484 px at 11811 px/m is 295 mm (not 1229 mm at 72 dpi).
		expect( Math.round( ( 3484 / 11811 ) * 1000 ) ).toBe( 295 );
	} );

	it( 'sets the JFIF density of a JPEG and adds a header when there is none', () => {
		const jfif = Uint8Array.from( [
			0xff, 0xd8, 0xff, 0xe0, 0x00, 0x10, 0x4a, 0x46, 0x49, 0x46, 0x00,
			0x01, 0x01, 0x00, 0x00, 0x01, 0x00, 0x01, 0x00, 0x00, 0xff, 0xd9,
		] );
		const set = jpegWithDpi( jfif, 300 );

		expect( Array.from( set.subarray( 13, 18 ) ) ).toEqual( [
			1, 1, 44, 1, 44,
		] );
		expect( set.length ).toBe( jfif.length );

		const bare = Uint8Array.from( [
			0xff, 0xd8, 0xff, 0xdb, 0x00, 0x02, 0xff, 0xd9,
		] );
		const added = jpegWithDpi( bare, 300 );

		expect( Array.from( added.subarray( 2, 4 ) ) ).toEqual( [
			0xff, 0xe0,
		] );
		expect( Array.from( added.subarray( 13, 18 ) ) ).toEqual( [
			1, 1, 44, 1, 44,
		] );
		expect( Array.from( added.subarray( 20 ) ) ).toEqual(
			Array.from( bare.subarray( 2 ) )
		);
	} );

	it( 'leaves anything else as it is', () => {
		const text = Uint8Array.from( [ 1, 2, 3, 4, 5 ] );

		expect( pngWithDpi( text, 300 ) ).toBe( text );
		expect( jpegWithDpi( text, 300 ) ).toBe( text );
	} );
} );
