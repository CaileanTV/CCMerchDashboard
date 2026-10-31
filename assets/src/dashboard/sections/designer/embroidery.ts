/**
 * An embroidered side as it looks on the product (ADR-085): the threads of the
 * print file are filled with a sample of real stitched surface, raised into a
 * soft dome and given a toothed edge. A flat image of the design does not look
 * like thread, so this replaces it on the mockups. Pure pixels, so it runs the
 * same in the browser and in tests.
 *
 * Measures are in millimetres of the product: a mockup and its close-up differ
 * only in pixels per millimetre.
 */
import type { Pixels } from './scene';

/** How far thread lies outside the drawing. */
const GROW_MM = 0.3;

/** Width of the rounded edge of a stroke. */
const DOME_MM = 1.2;

/** Height of that edge, in the pixels of a tile. */
const DOME_HEIGHT = 13;

/** Distance between the rows of stitches, whose ends make the toothed edge. */
const ROW_MM = 0.6;

/** How far a row ends out of line. */
const ROW_END_MM = 0.16;

/** The fine unevenness of the outline. */
const TOOTH_MM = 0.08;

/** The darkening of the fabric right beside the thread. */
const SHADOW = 0.1;

/** Reach of that darkening. */
const SHADOW_MM = 0.33;

/** The light, from the top left. */
const LIGHT = [ -0.38, -0.6, 0.7 ] as const;

/** Mean brightness of black thread in a tile: what counts as unlit. */
const TILE_MEAN = 26;

/**
 * The transparent margin a stitched image needs around its print.
 *
 * @param pxPerMm Pixels per millimetre.
 */
export function stitchMargin( pxPerMm: number ): number {
	return (
		Math.ceil( ( GROW_MM + ROW_END_MM + TOOTH_MM + SHADOW_MM ) * pxPerMm ) +
		2
	);
}

/**
 * A repeatable random number between 0 and 1.
 *
 * @param a First number.
 * @param b Second number.
 */
function hash( a: number, b: number ): number {
	/* eslint-disable no-bitwise */
	let h = ( Math.imul( a, 73856093 ) ^ Math.imul( b, 19349663 ) ) >>> 0;

	h = Math.imul( h ^ ( h >>> 13 ), 1274126177 ) >>> 0;

	return ( ( h ^ ( h >>> 16 ) ) >>> 0 ) / 4294967296;
	/* eslint-enable no-bitwise */
}

/**
 * The exact distance of each pixel to the nearest pixel that is not set
 * (Felzenszwalb and Huttenlocher).
 *
 * @param set    1 for pixels counted.
 * @param width  Width.
 * @param height Height.
 */
export function distanceField(
	set: Uint8Array,
	width: number,
	height: number
): Float32Array {
	const FAR = 1e12;
	const grid = new Float64Array( width * height );
	const size = Math.max( width, height );
	const from = new Float64Array( size );
	const to = new Float64Array( size );
	const hull = new Int32Array( size );
	const edges = new Float64Array( size + 1 );
	const line = ( n: number ) => {
		let k = 0;

		hull[ 0 ] = 0;
		edges[ 0 ] = -FAR;
		edges[ 1 ] = FAR;

		for ( let q = 1; q < n; q++ ) {
			const next = ( f: number ) => {
				const p = hull[ f ] as number;

				return (
					( ( from[ q ] as number ) +
						q * q -
						( from[ p ] as number ) -
						p * p ) /
					( 2 * q - 2 * p )
				);
			};
			let s = next( k );

			while ( s <= ( edges[ k ] as number ) && k > 0 ) {
				k--;
				s = next( k );
			}

			k++;
			hull[ k ] = q;
			edges[ k ] = s;
			edges[ k + 1 ] = FAR;
		}

		k = 0;

		for ( let q = 0; q < n; q++ ) {
			while ( ( edges[ k + 1 ] as number ) < q ) {
				k++;
			}

			const p = hull[ k ] as number;

			to[ q ] = ( q - p ) ** 2 + ( from[ p ] as number );
		}
	};

	for ( let i = 0; i < grid.length; i++ ) {
		grid[ i ] = set[ i ] === 1 ? FAR : 0;
	}

	for ( let x = 0; x < width; x++ ) {
		for ( let y = 0; y < height; y++ ) {
			from[ y ] = grid[ y * width + x ] as number;
		}

		line( height );

		for ( let y = 0; y < height; y++ ) {
			grid[ y * width + x ] = to[ y ] as number;
		}
	}

	for ( let y = 0; y < height; y++ ) {
		for ( let x = 0; x < width; x++ ) {
			from[ x ] = grid[ y * width + x ] as number;
		}

		line( width );

		for ( let x = 0; x < width; x++ ) {
			grid[ y * width + x ] = to[ x ] as number;
		}
	}

	return Float32Array.from( grid, Math.sqrt );
}

/**
 * A field after three passes of a box blur.
 *
 * @param field  The field.
 * @param width  Width.
 * @param height Height.
 * @param radius Radius of the box.
 */
function softened(
	field: Float32Array,
	width: number,
	height: number,
	radius: number
): Float32Array {
	const out = Float32Array.from( field );
	const temp = new Float32Array( field.length );
	const span = 2 * radius + 1;
	const at = ( values: Float32Array, index: number ) =>
		values[ index ] as number;

	for ( let pass = 0; pass < 3 && radius > 0; pass++ ) {
		for ( let y = 0; y < height; y++ ) {
			const row = y * width;
			let sum = 0;

			for ( let x = -radius; x <= radius; x++ ) {
				sum += at( out, row + Math.max( 0, Math.min( width - 1, x ) ) );
			}

			for ( let x = 0; x < width; x++ ) {
				temp[ row + x ] = sum / span;
				sum +=
					at( out, row + Math.min( width - 1, x + radius + 1 ) ) -
					at( out, row + Math.max( 0, x - radius ) );
			}
		}

		for ( let x = 0; x < width; x++ ) {
			let sum = 0;

			for ( let y = -radius; y <= radius; y++ ) {
				sum += at(
					temp,
					Math.max( 0, Math.min( height - 1, y ) ) * width + x
				);
			}

			for ( let y = 0; y < height; y++ ) {
				out[ y * width + x ] = sum / span;
				sum +=
					at(
						temp,
						Math.min( height - 1, y + radius + 1 ) * width + x
					) - at( temp, Math.max( 0, y - radius ) * width + x );
			}
		}
	}

	return out;
}

/**
 * The tile in the scale of the drawing, averaged so that fine stitches blend
 * as they do at a distance.
 *
 * @param surface The tile.
 * @param step    Pixels of the tile per pixel of the drawing.
 */
function scaledTile(
	surface: Pixels,
	step: number
): { width: number; height: number; values: Float32Array } {
	const width = Math.max( 1, Math.round( surface.width / step ) );
	const height = Math.max( 1, Math.round( surface.height / step ) );
	const values = new Float32Array( width * height );
	const reach = Math.max( 1, Math.ceil( step ) );

	for ( let y = 0; y < height; y++ ) {
		for ( let x = 0; x < width; x++ ) {
			const left = Math.floor( ( x * surface.width ) / width );
			const top = Math.floor( ( y * surface.height ) / height );
			let sum = 0;

			for ( let dy = 0; dy < reach; dy++ ) {
				for ( let dx = 0; dx < reach; dx++ ) {
					const from =
						( ( ( top + dy ) % surface.height ) * surface.width +
							( ( left + dx ) % surface.width ) ) *
						4;

					sum += surface.data[ from ] as number;
				}
			}

			values[ y * width + x ] = sum / ( reach * reach );
		}
	}

	return { width, height, values };
}

/**
 * The colour of each pixel near the thread: its own, or that of the nearest
 * thread on a line from it.
 *
 * @param print The drawing.
 * @param solid 1 where the drawing has thread.
 * @param reach How far to look.
 * @param x     Column.
 * @param y     Row.
 */
function threadAt(
	print: Pixels,
	solid: Uint8Array,
	reach: number,
	x: number,
	y: number
): number {
	const { width, height } = print;
	const here = y * width + x;

	if ( solid[ here ] === 1 ) {
		return here;
	}

	for ( let r = 1; r <= reach; r++ ) {
		for ( const [ dx, dy ] of [
			[ r, 0 ],
			[ -r, 0 ],
			[ 0, r ],
			[ 0, -r ],
			[ r, r ],
			[ -r, r ],
			[ r, -r ],
			[ -r, -r ],
		] as const ) {
			const nx = x + dx;
			const ny = y + dy;

			if (
				nx >= 0 &&
				ny >= 0 &&
				nx < width &&
				ny < height &&
				solid[ ny * width + nx ] === 1
			) {
				return ny * width + nx;
			}
		}
	}

	return -1;
}

/**
 * The thread as an image of the same size as the drawing: transparent where
 * there is no thread and faintly dark on the fabric beside it. The print file
 * has only thread colours, so its colours are kept. A lighter thread keeps the
 * tile's light and shade in its own colour.
 *
 * @param print     The drawing, with a margin of stitchMargin() around it.
 * @param surface   The tile (grey).
 * @param tileScale Pixels of the tile per millimetre.
 * @param pxPerMm   Pixels of the drawing per millimetre.
 */
export function stitched(
	print: Pixels,
	surface: Pixels,
	tileScale: number,
	pxPerMm: number
): Pixels {
	const { width, height } = print;
	const size = width * height;
	const solid = new Uint8Array( size );
	const alpha = new Float32Array( size );

	for ( let i = 0; i < size; i++ ) {
		alpha[ i ] = ( print.data[ i * 4 + 3 ] as number ) / 255;
	}

	// Digitizing rounds sharp corners a little.
	const rounded = softened(
		alpha,
		width,
		height,
		Math.max( 0, Math.round( 0.06 * pxPerMm ) )
	);

	for ( let i = 0; i < size; i++ ) {
		solid[ i ] = ( rounded[ i ] as number ) > 0.5 ? 1 : 0;
	}

	const depth = distanceField( solid, width, height );
	const away = distanceField(
		solid.map( ( value ) => 1 - value ),
		width,
		height
	);
	const dome = softened(
		depth.map(
			( value ) =>
				Math.sqrt(
					1 -
						( 1 - Math.min( 1, value / ( DOME_MM * pxPerMm ) ) ) **
							2
				) *
				DOME_HEIGHT *
				( pxPerMm / tileScale )
		),
		width,
		height,
		Math.max( 1, Math.round( 0.2 * pxPerMm ) )
	);
	const tile = scaledTile( surface, tileScale / pxPerMm );
	const data = new Uint8ClampedArray( size * 4 );
	const grow = GROW_MM * pxPerMm;
	const shadow = SHADOW_MM * pxPerMm;
	const reach = Math.ceil(
		( GROW_MM + ROW_END_MM + TOOTH_MM ) * pxPerMm + 2
	);
	const rowWidth = ROW_MM * pxPerMm;
	const toothSize = Math.max( 1, 0.3 * pxPerMm );

	for ( let y = 0; y < height; y++ ) {
		for ( let x = 0; x < width; x++ ) {
			const i = y * width + x;
			const inside = depth[ i ] as number;
			const outside = away[ i ] as number;

			if ( outside > grow + reach + shadow ) {
				continue;
			}

			// Rows of stitches end unevenly, a little more or less.
			const end = hash( Math.floor( ( x + y ) / rowWidth ), 9 ) - 0.5;
			const tooth =
				hash(
					Math.floor( x / toothSize ),
					Math.floor( y / toothSize )
				) - 0.5;
			const signed =
				inside -
				outside +
				grow +
				( end * ROW_END_MM * 2 + tooth * TOOTH_MM * 2 ) * pxPerMm;
			const cover = Math.max( 0, Math.min( 1, signed + 0.5 ) );
			const beside =
				SHADOW *
				Math.max( 0, Math.min( 1, ( signed + shadow ) / shadow ) );
			const to = i * 4;

			if ( cover === 0 ) {
				data[ to + 3 ] = Math.round( beside * 255 );
				continue;
			}

			const thread = threadAt( print, solid, reach, x, y );

			if ( thread < 0 ) {
				continue;
			}

			const nx =
				x > 0 && x < width - 1
					? ( ( dome[ i - 1 ] as number ) -
							( dome[ i + 1 ] as number ) ) /
						2
					: 0;
			const ny =
				y > 0 && y < height - 1
					? ( ( dome[ i - width ] as number ) -
							( dome[ i + width ] as number ) ) /
						2
					: 0;
			const length = Math.hypot( nx, ny, 1 );
			const light = Math.max(
				0,
				( nx * LIGHT[ 0 ] + ny * LIGHT[ 1 ] + LIGHT[ 2 ] ) / length
			);
			const grey = tile.values[
				( y % tile.height ) * tile.width + ( x % tile.width )
			] as number;
			const value = Math.max(
				0,
				grey * ( 0.42 + 1.1 * light * light ) + 26 * ( light - 0.72 )
			);
			const shade = Math.min( 1.25, value / TILE_MEAN );
			const shown = cover + ( 1 - cover ) * beside;

			for ( let c = 0; c < 3; c++ ) {
				const own = print.data[ thread * 4 + c ] as number;

				data[ to + c ] =
					( ( own * ( 0.64 + 0.36 * shade ) +
						value * ( 1 - own / 255 ) ) *
						cover ) /
					shown;
			}

			data[ to + 3 ] = Math.round( shown * 255 );
		}
	}

	return { width, height, data };
}
