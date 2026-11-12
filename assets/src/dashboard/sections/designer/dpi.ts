/* eslint-disable no-bitwise -- Binary file headers (PNG chunks, CRC-32, JFIF). */
/**
 * The print resolution written into a print file. A canvas encodes PNG
 * without a `pHYs` chunk and JPEG with a JFIF density of 1:1 without a
 * unit, so programs read both as 72 dpi: a print of 295 mm at 300 dpi
 * (3484 px) showed as 1229 mm. The pixels are right; only the file's dpi
 * is set here, so the print shows at its real size.
 */

const PNG_SIGNATURE = [ 137, 80, 78, 71, 13, 10, 26, 10 ];

/** CRC-32 table (PNG chunks). */
const CRC_TABLE = ( () => {
	const table = new Uint32Array( 256 );

	for ( let n = 0; n < 256; n++ ) {
		let c = n;

		for ( let k = 0; k < 8; k++ ) {
			c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
		}

		table[ n ] = c >>> 0;
	}

	return table;
} )();

/**
 * CRC-32 of bytes.
 *
 * @param bytes Bytes.
 */
function crc32( bytes: Uint8Array ): number {
	let c = 0xffffffff;

	for ( const byte of bytes ) {
		c = ( CRC_TABLE[ ( c ^ byte ) & 0xff ] as number ) ^ ( c >>> 8 );
	}

	return ( c ^ 0xffffffff ) >>> 0;
}

/**
 * A big-endian 32-bit number.
 *
 * @param bytes  Bytes.
 * @param offset Offset.
 */
function uint32( bytes: Uint8Array, offset: number ): number {
	return (
		( ( ( bytes[ offset ] as number ) << 24 ) |
			( ( bytes[ offset + 1 ] as number ) << 16 ) |
			( ( bytes[ offset + 2 ] as number ) << 8 ) |
			( bytes[ offset + 3 ] as number ) ) >>>
		0
	);
}

/**
 * A PNG with a `pHYs` chunk of the dpi (pixels per metre) after `IHDR`; an
 * existing `pHYs` is replaced. Anything that is not a PNG comes back as it is.
 *
 * @param bytes PNG bytes.
 * @param dpi   Dots per inch.
 */
export function pngWithDpi( bytes: Uint8Array, dpi: number ): Uint8Array {
	if (
		bytes.length < 33 ||
		PNG_SIGNATURE.some( ( byte, index ) => bytes[ index ] !== byte ) ||
		dpi <= 0
	) {
		return bytes;
	}

	const perMetre = Math.round( dpi / 0.0254 );
	const chunk = new Uint8Array( 21 );
	const view = new DataView( chunk.buffer );
	view.setUint32( 0, 9 );
	chunk.set( [ 112, 72, 89, 115 ], 4 ); // "pHYs"
	view.setUint32( 8, perMetre );
	view.setUint32( 12, perMetre );
	chunk[ 16 ] = 1; // Unit: metre.
	view.setUint32( 17, crc32( chunk.subarray( 4, 17 ) ) );

	const parts: Uint8Array[] = [ bytes.subarray( 0, 8 ) ];
	let offset = 8;
	let added = false;

	while ( offset + 12 <= bytes.length ) {
		const length = uint32( bytes, offset );
		const type = String.fromCharCode(
			...Array.from( bytes.subarray( offset + 4, offset + 8 ) )
		);
		const end = offset + 12 + length;

		if ( end > bytes.length ) {
			return bytes;
		}

		if ( 'pHYs' !== type ) {
			parts.push( bytes.subarray( offset, end ) );
		}

		if ( 'IHDR' === type && ! added ) {
			parts.push( chunk );
			added = true;
		}

		offset = end;
	}

	if ( ! added ) {
		return bytes;
	}

	const out = new Uint8Array(
		parts.reduce( ( sum, part ) => sum + part.length, 0 )
	);
	let at = 0;

	for ( const part of parts ) {
		out.set( part, at );
		at += part.length;
	}

	return out;
}

/**
 * A JPEG whose JFIF header says the dpi; a JPEG without a JFIF header gets
 * one. Anything that is not a JPEG comes back as it is.
 *
 * @param bytes JPEG bytes.
 * @param dpi   Dots per inch.
 */
export function jpegWithDpi( bytes: Uint8Array, dpi: number ): Uint8Array {
	if (
		bytes.length < 4 ||
		bytes[ 0 ] !== 0xff ||
		bytes[ 1 ] !== 0xd8 ||
		dpi <= 0
	) {
		return bytes;
	}

	const density = Math.min( 65535, Math.round( dpi ) );
	const jfif =
		bytes[ 2 ] === 0xff &&
		bytes[ 3 ] === 0xe0 &&
		bytes.length > 18 &&
		'JFIF' ===
			String.fromCharCode( ...Array.from( bytes.subarray( 6, 10 ) ) );

	if ( jfif ) {
		const out = bytes.slice();
		out[ 13 ] = 1; // Unit: dots per inch.
		out[ 14 ] = density >> 8;
		out[ 15 ] = density & 0xff;
		out[ 16 ] = density >> 8;
		out[ 17 ] = density & 0xff;

		return out;
	}

	const header = new Uint8Array( [
		0xff,
		0xe0,
		0x00,
		0x10,
		0x4a,
		0x46,
		0x49,
		0x46,
		0x00,
		0x01,
		0x01,
		0x01,
		density >> 8,
		density & 0xff,
		density >> 8,
		density & 0xff,
		0x00,
		0x00,
	] );
	const out = new Uint8Array( bytes.length + header.length );
	out.set( bytes.subarray( 0, 2 ), 0 );
	out.set( header, 2 );
	out.set( bytes.subarray( 2 ), 2 + header.length );

	return out;
}

/**
 * A blob's bytes (FileReader where `Blob.arrayBuffer` is missing).
 *
 * @param file Blob.
 */
function bytesOf( file: Blob ): Promise< ArrayBuffer > {
	if ( typeof file.arrayBuffer === 'function' ) {
		return file.arrayBuffer();
	}

	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () =>
			reader.result instanceof ArrayBuffer
				? resolve( reader.result )
				: reject( new Error( 'unreadable' ) );
		reader.onerror = () => reject( new Error( 'unreadable' ) );
		reader.readAsArrayBuffer( file );
	} );
}

/**
 * A print file blob with its dpi written in (PNG or JPEG by its type). The
 * file goes as it is when it cannot be read: the dpi never stops a submission.
 *
 * @param file The canvas' blob.
 * @param dpi  Dots per inch of the side.
 */
export async function withDpi( file: Blob, dpi: number ): Promise< Blob > {
	let bytes: Uint8Array;

	try {
		bytes = new Uint8Array( await bytesOf( file ) );
	} catch {
		return file;
	}

	const out =
		'image/png' === file.type
			? pngWithDpi( bytes, dpi )
			: jpegWithDpi( bytes, dpi );

	return out === bytes
		? file
		: new Blob( [ out.slice().buffer ], { type: file.type } );
}
