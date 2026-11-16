/**
 * Removes a plain background from an image in the browser: the colour along
 * the edges is the background; it is flooded from the edges with a
 * tolerance and the edge of the product is softened. A background that is
 * not plain is left alone. Used for the silhouettes made from a template's
 * photo on white and for PSD scenes imported with their background merged
 * (ADR-114); first written for the merch ad (ADR-113 pkt. 4).
 */

export interface Pixels {
	readonly data: Uint8ClampedArray;
	readonly width: number;
	readonly height: number;
}

/** How far a colour may be from the background (0–441). */
const TOLERANCE = 40;

/** How much of the edge must be the background for it to count as plain. */
const PLAIN_SHARE = 0.6;

/** A studio backdrop is light: its darkest channel at least this. */
const STUDIO_LIGHT = 200;

/** How much of a studio photo's edge is the backdrop. */
const STUDIO_SHARE = 0.9;

/** The least of the picture a cut-out photo keeps. */
const MIN_KEPT = 0.04;

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
 * Whether a photo is a studio photo: the product on a light, plain backdrop
 * that runs along nearly all of its edge (ADR-114, the owner's test
 * 2026-11-16). A lifestyle photo (a person in a room) or a dark one is not:
 * its "background" is the picture, and removing it destroys the product.
 * ImageProbe::studio() on the server uses the same rule.
 *
 * @param image The pixels.
 */
export function isStudioPhoto( image: Pixels ): boolean {
	const { data, width, height } = image;

	if ( width < 3 || height < 3 ) {
		return false;
	}

	const colour = backgroundOf( image );

	if ( Math.min( ...colour ) < STUDIO_LIGHT ) {
		return false;
	}

	const border = edge( width, height );
	const plain = border.filter(
		( index ) =>
			( data[ index + 3 ] ?? 0 ) > 0 &&
			distance( data, index, colour ) <= TOLERANCE
	);

	return plain.length >= border.length * STUDIO_SHARE;
}

/**
 * Whether a cut-out kept enough of the picture: a fill that ran into the
 * product leaves almost nothing.
 *
 * @param image The pixels after removeBackground().
 */
export function keptEnough( image: Pixels ): boolean {
	let kept = 0;

	for ( let index = 3; index < image.data.length; index += 4 ) {
		kept += ( image.data[ index ] ?? 0 ) > 16 ? 1 : 0;
	}

	return kept >= image.width * image.height * MIN_KEPT;
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
