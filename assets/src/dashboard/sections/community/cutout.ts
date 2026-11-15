/**
 * Removes a plain background from a product image in the browser (ADR-113
 * pkt. 4): the colour along the edges is the background; it is flooded from
 * the edges with a tolerance, the edge of the product is softened and the
 * image is cropped to the product. Nothing leaves the browser except the
 * finished PNG. A background that is not plain is left alone (the creator
 * can upload an own PNG instead).
 */

export interface Pixels {
	readonly data: Uint8ClampedArray;
	readonly width: number;
	readonly height: number;
}

export interface Box {
	readonly x: number;
	readonly y: number;
	readonly width: number;
	readonly height: number;
}

/** How far a colour may be from the background (0–441). */
const TOLERANCE = 40;

/** How much of the edge must be the background for it to count as plain. */
const PLAIN_SHARE = 0.6;

/**
 * The distance between two colours.
 *
 * @param data   Pixels.
 * @param index  A pixel's first byte.
 * @param colour The colour.
 */
function distance(
	data: Uint8ClampedArray,
	index: number,
	colour: readonly [ number, number, number ]
): number {
	const r = ( data[ index ] ?? 0 ) - colour[ 0 ];
	const g = ( data[ index + 1 ] ?? 0 ) - colour[ 1 ];
	const b = ( data[ index + 2 ] ?? 0 ) - colour[ 2 ];

	return Math.sqrt( r * r + g * g + b * b );
}

/**
 * The pixels along the edges, as indexes of their first byte.
 *
 * @param width  Width.
 * @param height Height.
 */
function edge( width: number, height: number ): number[] {
	const pixels: number[] = [];

	for ( let x = 0; x < width; x++ ) {
		pixels.push( x * 4, ( ( height - 1 ) * width + x ) * 4 );
	}

	for ( let y = 1; y < height - 1; y++ ) {
		pixels.push( y * width * 4, ( y * width + width - 1 ) * 4 );
	}

	return pixels;
}

/**
 * The background colour: the median of the edge, per channel.
 *
 * @param image The pixels.
 */
export function backgroundOf( image: Pixels ): [ number, number, number ] {
	const pixels = edge( image.width, image.height );
	const channel = ( offset: number ): number => {
		const values = pixels
			.map( ( index ) => image.data[ index + offset ] ?? 0 )
			.sort( ( a, b ) => a - b );

		return values[ Math.floor( values.length / 2 ) ] ?? 0;
	};

	return [ channel( 0 ), channel( 1 ), channel( 2 ) ];
}

/**
 * Makes the plain background transparent, in place.
 *
 * @param image The pixels (RGBA).
 * @return Whether the background was plain (else nothing is changed).
 */
export function removeBackground( image: Pixels ): boolean {
	const { data, width, height } = image;

	if ( width < 3 || height < 3 ) {
		return false;
	}

	const colour = backgroundOf( image );
	const border = edge( width, height );
	const plain = border.filter(
		( index ) => distance( data, index, colour ) <= TOLERANCE
	);

	if ( plain.length < border.length * PLAIN_SHARE ) {
		return false;
	}

	// Flood from every edge pixel that is the background.
	const seen = new Uint8Array( width * height );
	const stack = plain.map( ( index ) => index / 4 );

	for ( const pixel of stack ) {
		seen[ pixel ] = 1;
	}

	while ( stack.length > 0 ) {
		const pixel = stack.pop() as number;
		data[ pixel * 4 + 3 ] = 0;
		const x = pixel % width;
		const y = Math.floor( pixel / width );
		const near = [
			x > 0 ? pixel - 1 : -1,
			x < width - 1 ? pixel + 1 : -1,
			y > 0 ? pixel - width : -1,
			y < height - 1 ? pixel + width : -1,
		];

		for ( const next of near ) {
			if (
				next >= 0 &&
				seen[ next ] === 0 &&
				distance( data, next * 4, colour ) <= TOLERANCE
			) {
				seen[ next ] = 1;
				stack.push( next );
			}
		}
	}

	// Soften the product's edge: a visible pixel next to a removed one is half visible.
	for ( let pixel = 0; pixel < width * height; pixel++ ) {
		if ( seen[ pixel ] === 1 ) {
			continue;
		}

		const x = pixel % width;
		const y = Math.floor( pixel / width );
		const touches =
			( x > 0 && seen[ pixel - 1 ] === 1 ) ||
			( x < width - 1 && seen[ pixel + 1 ] === 1 ) ||
			( y > 0 && seen[ pixel - width ] === 1 ) ||
			( y < height - 1 && seen[ pixel + width ] === 1 );

		if ( touches ) {
			data[ pixel * 4 + 3 ] = Math.min(
				data[ pixel * 4 + 3 ] ?? 255,
				160
			);
		}
	}

	return true;
}

/**
 * The box around the visible pixels, with a little room; null when none
 * is visible.
 *
 * @param image The pixels.
 */
export function visibleBox( image: Pixels ): Box | null {
	const { data, width, height } = image;
	let left = width;
	let top = height;
	let right = -1;
	let bottom = -1;

	for ( let y = 0; y < height; y++ ) {
		for ( let x = 0; x < width; x++ ) {
			if ( ( data[ ( y * width + x ) * 4 + 3 ] ?? 0 ) > 16 ) {
				left = Math.min( left, x );
				right = Math.max( right, x );
				top = Math.min( top, y );
				bottom = Math.max( bottom, y );
			}
		}
	}

	if ( right < 0 ) {
		return null;
	}

	const room = Math.round( Math.max( right - left, bottom - top ) * 0.02 );
	const x = Math.max( 0, left - room );
	const y = Math.max( 0, top - room );

	return {
		x,
		y,
		width: Math.min( width, right + room + 1 ) - x,
		height: Math.min( height, bottom + room + 1 ) - y,
	};
}

/**
 * A product image without its plain background, as a PNG data URL; null
 * when the image cannot be read (another domain) or the background is not
 * plain.
 *
 * @param source  The shop image's URL.
 * @param maxSide The longest side of the result.
 */
export async function cutoutFrom(
	source: string,
	maxSide = 1200
): Promise< string | null > {
	const image = new Image();
	image.decoding = 'async';
	image.src = source;

	try {
		await image.decode();
	} catch {
		return null;
	}

	const scale = Math.min(
		1,
		maxSide / Math.max( image.naturalWidth, image.naturalHeight )
	);
	const width = Math.max( 1, Math.round( image.naturalWidth * scale ) );
	const height = Math.max( 1, Math.round( image.naturalHeight * scale ) );
	const canvas = document.createElement( 'canvas' );
	canvas.width = width;
	canvas.height = height;
	const context = canvas.getContext( '2d' );

	if ( context === null ) {
		return null;
	}

	context.drawImage( image, 0, 0, width, height );

	let pixels: ImageData;

	try {
		pixels = context.getImageData( 0, 0, width, height );
	} catch {
		// An image from another domain cannot be read.
		return null;
	}

	if ( ! removeBackground( pixels ) ) {
		return null;
	}

	const box = visibleBox( pixels );

	if ( box === null ) {
		return null;
	}

	context.putImageData( pixels, 0, 0 );
	const cropped = document.createElement( 'canvas' );
	cropped.width = box.width;
	cropped.height = box.height;
	cropped
		.getContext( '2d' )
		?.drawImage(
			canvas,
			box.x,
			box.y,
			box.width,
			box.height,
			0,
			0,
			box.width,
			box.height
		);

	return cropped.toDataURL( 'image/png' );
}
