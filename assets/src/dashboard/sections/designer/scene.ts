/**
 * A mockup from a PSD with smart objects, without Photoshop: the import keeps
 * the layers around the design in one image (the atlas) and the warp of each
 * smart object; this draws a print file through them. Pure pixels, so it runs
 * the same in the browser and in tests.
 *
 * A warp is a cubic Bézier patch of 16 points. Its points are kept in the box
 * of the points, and the smart object's transform puts that box on the
 * document: checked against Photoshop's own warped layers (2026-10-31).
 */

export interface Pixels {
	readonly width: number;
	readonly height: number;
	readonly data: Uint8ClampedArray;
}

export interface SceneRect {
	readonly x: number;
	readonly y: number;
	readonly width: number;
	readonly height: number;
}

export const SCENE_BLENDS = [
	'normal',
	'multiply',
	'screen',
	'overlay',
	'darken',
	'lighten',
	'color-dodge',
	'color-burn',
	'hard-light',
	'darker-color',
] as const;

export type SceneBlend = ( typeof SCENE_BLENDS )[ number ];

/** A part of the atlas drawn on the document. */
export interface SceneImage {
	readonly type: 'image';
	readonly rect: SceneRect;
	readonly left: number;
	readonly top: number;
	readonly blend: SceneBlend;
	readonly opacity: number;
}

/** A smart object: which end of the print file it shows, and its warp. */
export interface SceneDesign {
	readonly type: 'design';
	readonly end: 'start' | 'middle' | 'end';
	/** The smart object's size: gives the shape of the part it shows. */
	readonly width: number;
	readonly height: number;
	/** Top left, top right, bottom right, bottom left: x and y of each. */
	readonly quad: readonly number[];
	/** 16 points, row by row, as x and y from 0 to 1 in their own box. */
	readonly mesh: readonly number[];
	/** Where the design may show: the alpha of a part of the atlas. */
	readonly mask:
		( SceneRect & { readonly left: number; readonly top: number } ) | null;
}

export type SceneStep = SceneImage | SceneDesign;

export interface Scene {
	/** Whether a creator can see the mockup while designing. */
	readonly preview?: boolean;
	readonly width: number;
	readonly height: number;
	readonly steps: readonly SceneStep[];
}

/** Squares per side of the grid a warp is drawn with. */
const GRID = 48;

/** A flat mesh: a smart object without a warp. */
export const FLAT_MESH: readonly number[] = Array.from(
	{ length: 16 },
	( _, index ) => [ ( index % 4 ) / 3, Math.floor( index / 4 ) / 3 ]
).flat();

/**
 * A place on a warp, from 0 to 1 in the mesh's box.
 *
 * @param mesh The mesh.
 * @param u    Across the smart object, from 0 to 1.
 * @param v    Down the smart object, from 0 to 1.
 */
function onMesh(
	mesh: readonly number[],
	u: number,
	v: number
): [ number, number ] {
	const across = [
		( 1 - u ) ** 3,
		3 * u * ( 1 - u ) ** 2,
		3 * u * u * ( 1 - u ),
		u ** 3,
	];
	const down = [
		( 1 - v ) ** 3,
		3 * v * ( 1 - v ) ** 2,
		3 * v * v * ( 1 - v ),
		v ** 3,
	];
	let x = 0;
	let y = 0;

	for ( let row = 0; row < 4; row++ ) {
		for ( let column = 0; column < 4; column++ ) {
			const weight =
				( down[ row ] as number ) * ( across[ column ] as number );
			const at = ( row * 4 + column ) * 2;

			x += ( mesh[ at ] as number ) * weight;
			y += ( mesh[ at + 1 ] as number ) * weight;
		}
	}

	return [ x, y ];
}

/**
 * A place of a smart object on the document.
 *
 * @param design The smart object.
 * @param u      Across it, from 0 to 1.
 * @param v      Down it, from 0 to 1.
 */
export function placed(
	design: SceneDesign,
	u: number,
	v: number
): [ number, number ] {
	const [ s, r ] = onMesh( design.mesh, u, v );
	const q = design.quad as readonly number[];
	const corner = ( at: number ): number => q[ at ] as number;

	return [
		( 1 - r ) * ( ( 1 - s ) * corner( 0 ) + s * corner( 2 ) ) +
			r * ( ( 1 - s ) * corner( 6 ) + s * corner( 4 ) ),
		( 1 - r ) * ( ( 1 - s ) * corner( 1 ) + s * corner( 3 ) ) +
			r * ( ( 1 - s ) * corner( 7 ) + s * corner( 5 ) ),
	];
}

/**
 * The part of the print file a smart object shows, in print pixels: as high
 * as the file, at the end it stands for.
 *
 * @param design The smart object.
 * @param width  Print width.
 * @param height Print height.
 */
export function shownPart(
	design: SceneDesign,
	width: number,
	height: number
): SceneRect {
	const shape = design.width / design.height;
	const shownWidth = Math.min( width, height * shape );
	const shownHeight = Math.min( height, shownWidth / shape );
	const free = width - shownWidth;

	return {
		x: { start: 0, middle: free / 2, end: free }[ design.end ],
		y: ( height - shownHeight ) / 2,
		width: shownWidth,
		height: shownHeight,
	};
}

/**
 * An image made smaller by a whole factor, so a big print file keeps its
 * detail on a small mockup.
 *
 * @param image  The image.
 * @param factor The factor.
 */
function shrunk( image: Pixels, factor: number ): Pixels {
	if ( factor < 2 ) {
		return image;
	}

	const width = Math.floor( image.width / factor );
	const height = Math.floor( image.height / factor );
	const data = new Uint8ClampedArray( width * height * 4 );

	for ( let y = 0; y < height; y++ ) {
		for ( let x = 0; x < width; x++ ) {
			let red = 0;
			let green = 0;
			let blue = 0;
			let alpha = 0;

			for ( let dy = 0; dy < factor; dy++ ) {
				for ( let dx = 0; dx < factor; dx++ ) {
					const from =
						( ( y * factor + dy ) * image.width +
							x * factor +
							dx ) *
						4;
					const a = image.data[ from + 3 ] as number;

					red += ( image.data[ from ] as number ) * a;
					green += ( image.data[ from + 1 ] as number ) * a;
					blue += ( image.data[ from + 2 ] as number ) * a;
					alpha += a;
				}
			}

			const to = ( y * width + x ) * 4;

			if ( alpha > 0 ) {
				data[ to ] = red / alpha;
				data[ to + 1 ] = green / alpha;
				data[ to + 2 ] = blue / alpha;
				data[ to + 3 ] = alpha / ( factor * factor );
			}
		}
	}

	return { width, height, data };
}

/**
 * Reads a point of an image between its pixels.
 *
 * @param image The image.
 * @param x     X.
 * @param y     Y.
 * @param out   Receives red, green, blue and alpha.
 */
function read( image: Pixels, x: number, y: number, out: Float64Array ): void {
	const fx = Math.max( 0, Math.min( image.width - 1, x ) );
	const fy = Math.max( 0, Math.min( image.height - 1, y ) );
	const left = Math.floor( fx );
	const top = Math.floor( fy );
	const right = Math.min( image.width - 1, left + 1 );
	const bottom = Math.min( image.height - 1, top + 1 );
	const dx = fx - left;
	const dy = fy - top;

	for ( let channel = 0; channel < 4; channel++ ) {
		const pick = ( column: number, row: number ): number =>
			image.data[
				( row * image.width + column ) * 4 + channel
			] as number;

		out[ channel ] =
			pick( left, top ) * ( 1 - dx ) * ( 1 - dy ) +
			pick( right, top ) * dx * ( 1 - dy ) +
			pick( left, bottom ) * ( 1 - dx ) * dy +
			pick( right, bottom ) * dx * dy;
	}
}

/**
 * The smart object drawn on a transparent layer of the document's size.
 *
 * @param design      The smart object.
 * @param print       The print file.
 * @param size        The document.
 * @param size.width  Its width.
 * @param size.height Its height.
 */
function warped(
	design: SceneDesign,
	print: Pixels,
	size: { readonly width: number; readonly height: number }
): Uint8ClampedArray {
	const target = new Uint8ClampedArray( size.width * size.height * 4 );
	const part = shownPart( design, print.width, print.height );
	const points: [ number, number ][] = [];

	for ( let row = 0; row <= GRID; row++ ) {
		for ( let column = 0; column <= GRID; column++ ) {
			points.push( placed( design, column / GRID, row / GRID ) );
		}
	}

	const across = points.map( ( point ) => point[ 0 ] );
	const shownWide = Math.max( ...across ) - Math.min( ...across );
	const factor = Math.floor( part.width / Math.max( 1, shownWide ) );
	const source = shrunk( print, factor );
	const scale = source.width / print.width;
	const value = new Float64Array( 4 );

	const triangle = (
		a: [ number, number ],
		b: [ number, number ],
		c: [ number, number ],
		au: number,
		av: number,
		bu: number,
		bv: number,
		cu: number,
		cv: number
	): void => {
		const area =
			( b[ 1 ] - c[ 1 ] ) * ( a[ 0 ] - c[ 0 ] ) +
			( c[ 0 ] - b[ 0 ] ) * ( a[ 1 ] - c[ 1 ] );

		if ( area === 0 ) {
			return;
		}

		const fromX = Math.max(
			0,
			Math.floor( Math.min( a[ 0 ], b[ 0 ], c[ 0 ] ) )
		);
		const toX = Math.min(
			size.width - 1,
			Math.ceil( Math.max( a[ 0 ], b[ 0 ], c[ 0 ] ) )
		);
		const fromY = Math.max(
			0,
			Math.floor( Math.min( a[ 1 ], b[ 1 ], c[ 1 ] ) )
		);
		const toY = Math.min(
			size.height - 1,
			Math.ceil( Math.max( a[ 1 ], b[ 1 ], c[ 1 ] ) )
		);

		for ( let y = fromY; y <= toY; y++ ) {
			for ( let x = fromX; x <= toX; x++ ) {
				const px = x + 0.5 - c[ 0 ];
				const py = y + 0.5 - c[ 1 ];
				const wa =
					( ( b[ 1 ] - c[ 1 ] ) * px + ( c[ 0 ] - b[ 0 ] ) * py ) /
					area;
				const wb =
					( ( c[ 1 ] - a[ 1 ] ) * px + ( a[ 0 ] - c[ 0 ] ) * py ) /
					area;
				const wc = 1 - wa - wb;

				if ( wa < -1e-6 || wb < -1e-6 || wc < -1e-6 ) {
					continue;
				}

				const u = wa * au + wb * bu + wc * cu;
				const v = wa * av + wb * bv + wc * cv;

				read(
					source,
					( part.x + u * part.width ) * scale,
					( part.y + v * part.height ) * scale,
					value
				);
				target.set( value, ( y * size.width + x ) * 4 );
			}
		}
	};

	for ( let row = 0; row < GRID; row++ ) {
		for ( let column = 0; column < GRID; column++ ) {
			const at = row * ( GRID + 1 ) + column;
			const nw = points[ at ] as [ number, number ];
			const ne = points[ at + 1 ] as [ number, number ];
			const sw = points[ at + GRID + 1 ] as [ number, number ];
			const se = points[ at + GRID + 2 ] as [ number, number ];
			const left = column / GRID;
			const right = ( column + 1 ) / GRID;
			const top = row / GRID;
			const bottom = ( row + 1 ) / GRID;

			triangle( nw, ne, sw, left, top, right, top, left, bottom );
			triangle( ne, se, sw, right, top, right, bottom, left, bottom );
		}
	}

	return target;
}

/**
 * One channel of a blend, from 0 to 255.
 *
 * @param blend       The blend.
 * @param source      The layer.
 * @param destination What lies below.
 */
function mixed(
	blend: SceneBlend,
	source: number,
	destination: number
): number {
	switch ( blend ) {
		case 'multiply':
			return ( source * destination ) / 255;
		case 'screen':
			return 255 - ( ( 255 - source ) * ( 255 - destination ) ) / 255;
		case 'overlay':
			return destination < 128
				? ( 2 * source * destination ) / 255
				: 255 - ( 2 * ( 255 - source ) * ( 255 - destination ) ) / 255;
		case 'hard-light':
			return source < 128
				? ( 2 * source * destination ) / 255
				: 255 - ( 2 * ( 255 - source ) * ( 255 - destination ) ) / 255;
		case 'darken':
			return Math.min( source, destination );
		case 'lighten':
			return Math.max( source, destination );
		case 'color-dodge':
			if ( destination === 0 ) {
				return 0;
			}

			return source >= 255
				? 255
				: Math.min( 255, ( destination * 255 ) / ( 255 - source ) );
		case 'color-burn':
			if ( destination >= 255 ) {
				return 255;
			}

			return source <= 0
				? 0
				: 255 -
						Math.min(
							255,
							( ( 255 - destination ) * 255 ) / source
						);
		default:
			return source;
	}
}

/**
 * Lays a pixel on the opaque document.
 *
 * @param canvas The document.
 * @param to     The pixel's place in it.
 * @param layer  The layer's pixels.
 * @param from   The pixel's place in them.
 * @param blend  The blend.
 * @param alpha  Its strength from 0 to 1.
 */
function lay(
	canvas: Uint8ClampedArray,
	to: number,
	layer: ArrayLike< number >,
	from: number,
	blend: SceneBlend,
	alpha: number
): void {
	const red = layer[ from ] as number;
	const green = layer[ from + 1 ] as number;
	const blue = layer[ from + 2 ] as number;
	const belowRed = canvas[ to ] as number;
	const belowGreen = canvas[ to + 1 ] as number;
	const belowBlue = canvas[ to + 2 ] as number;
	let outRed: number;
	let outGreen: number;
	let outBlue: number;

	if ( blend === 'darker-color' ) {
		const keep =
			0.3 * red + 0.59 * green + 0.11 * blue <
			0.3 * belowRed + 0.59 * belowGreen + 0.11 * belowBlue;

		outRed = keep ? red : belowRed;
		outGreen = keep ? green : belowGreen;
		outBlue = keep ? blue : belowBlue;
	} else {
		outRed = mixed( blend, red, belowRed );
		outGreen = mixed( blend, green, belowGreen );
		outBlue = mixed( blend, blue, belowBlue );
	}

	canvas[ to ] = belowRed + ( outRed - belowRed ) * alpha;
	canvas[ to + 1 ] = belowGreen + ( outGreen - belowGreen ) * alpha;
	canvas[ to + 2 ] = belowBlue + ( outBlue - belowBlue ) * alpha;
}

/**
 * A mockup: the steps on a white document.
 *
 * @param scene The scene.
 * @param atlas The scene's image.
 * @param print The print file.
 */
export function renderScene(
	scene: Scene,
	atlas: Pixels,
	print: Pixels
): Pixels {
	const { width, height } = scene;
	const data = new Uint8ClampedArray( width * height * 4 ).fill( 255 );

	for ( const step of scene.steps ) {
		if ( step.type === 'image' ) {
			for ( let row = 0; row < step.rect.height; row++ ) {
				const y = row + step.top;

				if ( y < 0 || y >= height ) {
					continue;
				}

				for ( let column = 0; column < step.rect.width; column++ ) {
					const x = column + step.left;

					if ( x < 0 || x >= width ) {
						continue;
					}

					const from =
						( ( row + step.rect.y ) * atlas.width +
							column +
							step.rect.x ) *
						4;
					const alpha = atlas.data[ from + 3 ] as number;

					if ( alpha > 0 ) {
						lay(
							data,
							( y * width + x ) * 4,
							atlas.data,
							from,
							step.blend,
							( alpha / 255 ) * step.opacity
						);
					}
				}
			}

			continue;
		}

		const layer = warped( step, print, scene );
		const mask = step.mask;

		for ( let y = 0; y < height; y++ ) {
			for ( let x = 0; x < width; x++ ) {
				const at = ( y * width + x ) * 4;
				let alpha = ( layer[ at + 3 ] as number ) / 255;

				if ( alpha === 0 ) {
					continue;
				}

				if ( mask !== null ) {
					const column = x - mask.left;
					const row = y - mask.top;

					alpha *=
						column < 0 ||
						row < 0 ||
						column >= mask.width ||
						row >= mask.height
							? 0
							: ( atlas.data[
									( ( row + mask.y ) * atlas.width +
										column +
										mask.x ) *
										4 +
										3
								] as number ) / 255;
				}

				if ( alpha > 0 ) {
					lay( data, at, layer, at, 'normal', alpha );
				}
			}
		}
	}

	return { width, height, data };
}
