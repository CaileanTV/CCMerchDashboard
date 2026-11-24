/**
 * Draws a baked clipart with the creator's paints and places a locked one
 * (phase 56, step 56.3, ADR-121 pkt. 6 and 12). Pure functions over pixels:
 * the canvas, the print file and the mockups get the same picture.
 *
 * The gradient is Photoshop's as measured: around the middle of the part's
 * box, as long as the line through it at the angle times the file's scale,
 * mixed in CIELAB and eased.
 */
import type {
	AtlasPlace,
	ClipartArt,
	ClipartPaint,
	ClipartPart,
	ClipartRect,
	GradientPaint,
} from './clipart-model';

/** How much of the smooth curve is in Photoshop's mix, as measured. */
const EASE = 0.55;

/** The colours computed along a gradient. */
const STEPS = 4096;

/** D65, which sRGB is in. */
const WHITE_POINT = [ 0.95047, 1, 1.08883 ] as const;

/** RGBA pixels. */
export interface ClipartPixels {
	readonly width: number;
	readonly height: number;
	readonly data: Uint8ClampedArray;
}

type Triple = readonly [ number, number, number ];

/** A colour at a pixel of the clipart. */
type ColorAt = ( x: number, y: number ) => Triple;

/**
 * A `#rrggbb` colour's channels.
 *
 * @param hex The colour.
 */
function rgbOf( hex: string ): Triple {
	return [
		Number.parseInt( hex.slice( 1, 3 ), 16 ),
		Number.parseInt( hex.slice( 3, 5 ), 16 ),
		Number.parseInt( hex.slice( 5, 7 ), 16 ),
	];
}

/**
 * A colour in CIELAB.
 *
 * @param color sRGB, 0–255.
 */
function labOf( color: Triple ): Triple {
	const [ r, g, b ] = color.map( ( channel ) => {
		const value = channel / 255;

		return value <= 0.04045
			? value / 12.92
			: ( ( value + 0.055 ) / 1.055 ) ** 2.4;
	} ) as unknown as Triple;
	const [ x, y, z ] = [
		( 0.4124564 * r + 0.3575761 * g + 0.1804375 * b ) / WHITE_POINT[ 0 ],
		( 0.2126729 * r + 0.7151522 * g + 0.072175 * b ) / WHITE_POINT[ 1 ],
		( 0.0193339 * r + 0.119192 * g + 0.9503041 * b ) / WHITE_POINT[ 2 ],
	].map( ( value ) =>
		value > 216 / 24389
			? Math.cbrt( value )
			: ( ( 24389 / 27 ) * value + 16 ) / 116
	) as unknown as Triple;

	return [ 116 * y - 16, 500 * ( x - y ), 200 * ( y - z ) ];
}

/**
 * A CIELAB colour in sRGB, 0–255.
 *
 * @param lab The colour.
 */
function rgbOfLab( lab: Triple ): Triple {
	const [ lightness, a, b ] = lab;
	const middle = ( lightness + 16 ) / 116;
	const [ x, y, z ] = [ middle + a / 500, middle, middle - b / 200 ].map(
		( value, index ) =>
			( value ** 3 > 216 / 24389
				? value ** 3
				: ( 116 * value - 16 ) / ( 24389 / 27 ) ) *
			( WHITE_POINT[ index ] as number )
	) as unknown as Triple;

	return [
		3.2404542 * x - 1.5371385 * y - 0.4985314 * z,
		-0.969266 * x + 1.8760108 * y + 0.041556 * z,
		0.0556434 * x - 0.2040259 * y + 1.0572252 * z,
	].map( ( channel ) => {
		const value = Math.min( 1, Math.max( 0, channel ) );

		return (
			255 *
			( value <= 0.0031308
				? 12.92 * value
				: 1.055 * value ** ( 1 / 2.4 ) - 0.055 )
		);
	} ) as unknown as Triple;
}

/**
 * How far along the colours a place on the gradient is.
 *
 * @param place 0 at the first colour, 1 at the last.
 */
export function eased( place: number ): number {
	return place + ( place * place * ( 3 - 2 * place ) - place ) * EASE;
}

/**
 * The length of a part's gradient in pixels of the clipart.
 *
 * @param box   The part's box.
 * @param angle Degrees.
 * @param scale The file's scale.
 */
export function gradientLength(
	box: ClipartRect,
	angle: number,
	scale: number
): number {
	const radians = ( angle * Math.PI ) / 180;

	return Math.max(
		1,
		Math.min(
			box.width / Math.abs( Math.cos( radians ) ),
			box.height / Math.abs( Math.sin( radians ) )
		) * scale
	);
}

/**
 * A gradient's colour at each pixel.
 *
 * @param paint The gradient.
 * @param part  The part it is in.
 */
function gradientAt( paint: GradientPaint, part: ClipartPart ): ColorAt {
	const from = labOf( rgbOf( paint.from ) );
	const to = labOf( rgbOf( paint.to ) );
	const colors: Triple[] = [];

	for ( let step = 0; step <= STEPS; step++ ) {
		const share = eased( step / STEPS );

		colors.push(
			rgbOfLab( [
				from[ 0 ] + ( to[ 0 ] - from[ 0 ] ) * share,
				from[ 1 ] + ( to[ 1 ] - from[ 1 ] ) * share,
				from[ 2 ] + ( to[ 2 ] - from[ 2 ] ) * share,
			] )
		);
	}

	const { box } = part;
	const radians = ( paint.angle * Math.PI ) / 180;
	const length = gradientLength( box, paint.angle, part.scale );
	// Counter-clockwise from the right, with y downwards.
	const along = Math.cos( radians ) / length;
	const down = -Math.sin( radians ) / length;
	const middleX = box.x + box.width / 2;
	const middleY = box.y + box.height / 2;

	return ( x, y ) => {
		const place =
			0.5 + ( x + 0.5 - middleX ) * along + ( y + 0.5 - middleY ) * down;

		return colors[
			Math.round( Math.min( 1, Math.max( 0, place ) ) * STEPS )
		] as Triple;
	};
}

/**
 * A part's colour at each pixel.
 *
 * @param paint The paint.
 * @param part  The part.
 */
function colorAt( paint: ClipartPaint, part: ClipartPart ): ColorAt {
	if ( paint.type === 'gradient' ) {
		return gradientAt( paint, part );
	}

	const color = rgbOf( paint.color );

	return () => color;
}

/**
 * The clipart's picture with a paint per part.
 *
 * @param art    The baked clipart.
 * @param atlas  Its atlas' pixels.
 * @param paints A paint per part, in the parts' order.
 * @param hard   Embroidery: every pixel is wholly one colour or empty.
 * @param scale  Below 1: a smaller picture while a colour is dragged (step
 *               57.20), each pixel the full picture's nearest one.
 * @return The picture, or null when a paint or the atlas does not fit: it is
 *         never drawn with something left out.
 */
export function drawClipart(
	art: ClipartArt,
	atlas: ClipartPixels,
	paints: readonly ClipartPaint[],
	hard = false,
	scale = 1
): ClipartPixels | null {
	const { width, height, parts, steps } = art;
	const fits = ( rect: ClipartRect, place: AtlasPlace ): boolean =>
		rect.x >= 0 &&
		rect.y >= 0 &&
		rect.x + rect.width <= width &&
		rect.y + rect.height <= height &&
		place.x >= 0 &&
		place.y >= 0 &&
		place.x + rect.width <= atlas.width &&
		place.y + rect.height <= atlas.height;
	const whole = steps.every( ( step ) =>
		step.type === 'image'
			? fits( step.rect, step.image )
			: fits( step.rect, step.fill ) &&
				( step.grown === null || fits( step.rect, step.grown ) ) &&
				parts[ step.part ] !== undefined
	);

	if ( ! whole || paints.length !== parts.length ) {
		return null;
	}

	const shown = sampled( width, height, scale );
	const data = new Uint8ClampedArray( shown.width * shown.height * 4 );
	const from = atlas.data;
	const covered = ( at: number ): number => {
		const alpha = ( from[ at + 3 ] as number ) / 255;

		return hard ? Number( alpha >= 0.5 ) : alpha;
	};
	const over = (
		write: number,
		red: number,
		green: number,
		blue: number,
		alpha: number
	): void => {
		const below = ( ( data[ write + 3 ] as number ) / 255 ) * ( 1 - alpha );
		const total = alpha + below;

		data[ write ] = Math.round(
			( red * alpha + ( data[ write ] as number ) * below ) / total
		);
		data[ write + 1 ] = Math.round(
			( green * alpha + ( data[ write + 1 ] as number ) * below ) / total
		);
		data[ write + 2 ] = Math.round(
			( blue * alpha + ( data[ write + 2 ] as number ) * below ) / total
		);
		data[ write + 3 ] = Math.round( total * 255 );
	};

	for ( const step of steps ) {
		const { rect } = step;
		const part = step.type === 'part' ? parts[ step.part ] : undefined;
		const paint = step.type === 'part' ? paints[ step.part ] : undefined;
		const fill =
			part === undefined || paint === undefined
				? null
				: colorAt( paint, part );
		const stroke =
			part === undefined || part.stroke === null
				? null
				: rgbOf( part.stroke );
		const place = step.type === 'image' ? step.image : step.fill;
		const grownAt = step.type === 'part' ? step.grown : null;

		const columns = within( shown.xs, rect.x, rect.width );
		const rows = within( shown.ys, rect.y, rect.height );

		for ( let oy = rows.from; oy < rows.to; oy++ ) {
			const y = shown.full ? oy : ( shown.ys[ oy ] as number );
			const row = y - rect.y;
			const readRow = ( place.y + row ) * atlas.width + place.x - rect.x;
			const writeRow = oy * shown.width;

			for ( let ox = columns.from; ox < columns.to; ox++ ) {
				// The full size reads its own pixel: no look-up (step 57.20).
				const x = shown.full ? ox : ( shown.xs[ ox ] as number );
				const column = x - rect.x;
				const read = ( readRow + x ) * 4;
				const write = ( writeRow + ox ) * 4;
				const body = covered( read );

				if ( fill === null ) {
					if ( body > 0 ) {
						over(
							write,
							from[ read ] as number,
							from[ read + 1 ] as number,
							from[ read + 2 ] as number,
							body
						);
					}

					continue;
				}

				const total =
					grownAt === null || stroke === null
						? body
						: Math.max(
								body,
								covered(
									( ( grownAt.y + row ) * atlas.width +
										grownAt.x +
										column ) *
										4
								)
							);

				if ( total === 0 ) {
					continue;
				}

				// Only outside the fill is the stroke seen.
				const share = body / total;
				const color = body === 0 ? ( stroke as Triple ) : fill( x, y );
				const edge = share < 1 ? ( stroke as Triple ) : color;

				over(
					write,
					color[ 0 ] * share + edge[ 0 ] * ( 1 - share ),
					color[ 1 ] * share + edge[ 1 ] * ( 1 - share ),
					color[ 2 ] * share + edge[ 2 ] * ( 1 - share ),
					total
				);
			}
		}
	}

	return { width: shown.width, height: shown.height, data };
}

/**
 * The picture's size at a scale and, per pixel of it, the full picture's
 * column and row it shows (the nearest: each pixel's middle).
 *
 * @param width  Full width.
 * @param height Full height.
 * @param scale  Scale; 1 or more is the full size.
 */
function sampled(
	width: number,
	height: number,
	scale: number
): {
	readonly width: number;
	readonly height: number;
	readonly full: boolean;
	readonly xs: Int32Array;
	readonly ys: Int32Array;
} {
	const small = Number.isFinite( scale ) && scale > 0 && scale < 1;
	const shownWidth = small
		? Math.max( 1, Math.round( width * scale ) )
		: width;
	const shownHeight = small
		? Math.max( 1, Math.round( height * scale ) )
		: height;
	const along = ( full: number, shownSize: number ): Int32Array =>
		Int32Array.from( { length: shownSize }, ( _, at ) =>
			Math.min(
				full - 1,
				Math.floor( ( ( at + 0.5 ) * full ) / shownSize )
			)
		);

	return {
		width: shownWidth,
		height: shownHeight,
		full: ! small,
		xs: along( width, shownWidth ),
		ys: along( height, shownHeight ),
	};
}

/**
 * The shown pixels whose full column (or row) lies in a rectangle's span:
 * the map only grows, so they are one run.
 *
 * @param map   Full column (or row) per shown pixel.
 * @param start The span's start.
 * @param size  Its size.
 */
function within(
	map: Int32Array,
	start: number,
	size: number
): { readonly from: number; readonly to: number } {
	let from = 0;

	while ( from < map.length && ( map[ from ] as number ) < start ) {
		from++;
	}

	let to = from;

	while ( to < map.length && ( map[ to ] as number ) < start + size ) {
		to++;
	}

	return { from, to };
}

/** Where a clipart with locked placement is. */
export interface LockedPlacement {
	readonly x: number;
	readonly y: number;
	readonly scaleX: number;
	readonly scaleY: number;
	readonly angle: 0;
	readonly flipX: false;
	readonly flipY: false;
}

/**
 * A locked clipart's placement: from top to bottom, or the width when it
 * would be wider than the print area; the creator only moves it sideways and
 * it stays whole. The scale is rounded inwards as "fit" is.
 *
 * @param width       The clipart's width.
 * @param height      The clipart's height.
 * @param printWidth  Print file width.
 * @param printHeight Print file height.
 * @param x           Its middle as asked for; the middle of the area without.
 */
export function lockedPlacement(
	width: number,
	height: number,
	printWidth: number,
	printHeight: number,
	x?: number
): LockedPlacement {
	const fit = Math.min( printWidth / width, printHeight / height );
	const scale = Math.min(
		100,
		Math.max( 0.01, Math.floor( fit * 10000 ) / 10000 )
	);
	const centre = printWidth / 2;
	const room = Math.max( 0, ( printWidth - width * scale ) / 2 );
	const wanted = x !== undefined && Number.isFinite( x ) ? x : centre;

	return {
		x: Math.min( centre + room, Math.max( centre - room, wanted ) ),
		y: printHeight / 2,
		scaleX: scale,
		scaleY: scale,
		angle: 0,
		flipX: false,
		flipY: false,
	};
}
