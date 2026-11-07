/* eslint-disable-next-line jsdoc/check-tag-names */
/** @jest-environment node */
/** Reject small PSDs claiming excessive decoded resources before allocation. */
import { describe, expect, it } from '@jest/globals';
import { writePsd } from 'ag-psd';
import { readBoundedPsd, validatePsdCompression } from '../psd-budget';

/** A tiny PSD, with raw layer bounds that can be changed without pixel data. */
function fixture(): ArrayBuffer {
	return writePsd( {
		width: 1,
		height: 1,
		children: [ { name: 'mb_background' } ],
	} );
}

describe( 'PSD resource budgets', () => {
	it( 'rejects oversized documents before decoding', () => {
		const buffer = fixture();
		const view = new DataView( buffer );
		view.setUint32( 14, 8000 );
		view.setUint32( 18, 8000 );
		expect( () => readBoundedPsd( buffer ) ).toThrow( /dimensions/ );
	} );

	it( 'rejects an oversized layer on a tiny document', () => {
		const buffer = fixture();
		const view = new DataView( buffer );
		// Skip color-mode data, resources, layer/mask and layer-info lengths.
		let offset = 26;
		offset += 4 + view.getUint32( offset );
		offset += 4 + view.getUint32( offset );
		offset += 10;
		view.setInt32( offset + 8, 8001 );
		view.setInt32( offset + 12, 8001 );
		expect( () => readBoundedPsd( buffer ) ).toThrow( /dimensions/ );
	} );

	it( 'rejects excessive layer counts, including empty layers', () => {
		const buffer = writePsd( {
			width: 1,
			height: 1,
			children: Array.from( { length: 257 }, () => ( {
				name: 'hidden',
				hidden: true,
			} ) ),
		} );
		expect( () => readBoundedPsd( buffer ) ).toThrow( /layer limit/ );
	} );
} );

/** A compressed channel whose actual output can exceed the declared rectangle. */
function compressedFixture(): ArrayBuffer {
	return writePsd(
		{
			width: 4,
			height: 4,
			children: [
				{
					name: 'mb_background',
					imageData: {
						width: 4,
						height: 4,
						data: new Uint8ClampedArray( 64 ),
					},
				},
			],
		},
		{ compress: true, noBackground: true }
	);
}

it( 'accepts ZIP channels with the declared output length', async () => {
	await expect(
		validatePsdCompression( compressedFixture() )
	).resolves.toBeUndefined();
} );

it( 'rejects ZIP output beyond the declared layer dimensions', async () => {
	const buffer = compressedFixture();
	const view = new DataView( buffer );
	let offset = 26;
	offset += 4 + view.getUint32( offset );
	offset += 4 + view.getUint32( offset );
	offset += 10;
	view.setInt32( offset + 8, 1 );
	view.setInt32( offset + 12, 1 );
	await expect( validatePsdCompression( buffer ) ).rejects.toThrow(
		/exceeds dimensions/
	);
} );
