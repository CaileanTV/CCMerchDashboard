/**
 * Bakes a clipart (phase 56, step 56.2, ADR-121 pkt. 8): fixed layers next to
 * each other become one image, a part becomes a mask of its fill and one of
 * the fill grown by its stroke, and all of them are packed in one atlas. The
 * stroke is made here once, so it is the same wherever the clipart is drawn.
 */
import type {
	AtlasPlace,
	ClipartArt,
	ClipartPart,
	ClipartRect,
	ClipartStep,
} from '../dashboard/sections/designer/clipart-model';
import { ClipartError, visibleBox } from './psd-clipart';
import type {
	ClipartSource,
	FixedSource,
	PartSource,
	SourceImage,
} from './psd-clipart';
import { MAX_PIXELS } from './psd-template';
import type { Rgba } from './psd-template';

/** The most pixel visits for one stroke: more would hang the browser. */
const MAX_WORK = 4e9;

/** Empty pixels between the atlas' images. */
const GAP = 1;

/** A baked clipart. */
export interface BakedClipart {
	readonly art: ClipartArt;
	readonly atlas: Rgba;
}

/**
 * The baked clipart.
 *
 * @param source The PSD's layers.
 */
export function bakeClipart( source: ClipartSource ): BakedClipart {
	const { width, height } = source;
	const images: Rgba[] = [];
	const keep = ( image: Rgba ): number => images.push( image ) - 1;
	const parts: ClipartPart[] = [];
	const drafts: Array<
		| { type: 'image'; rect: ClipartRect; image: number }
		| {
				type: 'part';
				part: number;
				rect: ClipartRect;
				fill: number;
				grown: number | null;
		  }
	> = [];
	let run: FixedSource[] = [];
	const flush = (): void => {
		const flat = flatten( run, width, height );

		run = [];

		if ( flat !== null ) {
			drafts.push( {
				type: 'image',
				rect: flat.rect,
				image: keep( flat.image ),
			} );
		}
	};

	for ( const layer of source.layers ) {
		if ( layer.type === 'image' ) {
			run.push( layer );
			continue;
		}

		flush();

		const masks = masksOf( layer, width, height );

		if ( masks === null ) {
			throw new ClipartError( 'pixels', layer.name );
		}

		drafts.push( {
			type: 'part',
			part: parts.length,
			rect: masks.rect,
			fill: keep( maskImage( masks.fill, masks.rect ) ),
			grown:
				masks.grown === null
					? null
					: keep( maskImage( masks.grown, masks.rect ) ),
		} );
		parts.push( {
			label: layer.label,
			box: layer.box,
			stroke: layer.stroke?.color ?? null,
			scale: layer.scale,
			paint: layer.paint,
			gradient: layer.gradient,
		} );
	}

	flush();

	const { atlas, places } = packed( images );
	const placeOf = ( index: number ): AtlasPlace =>
		places[ index ] as AtlasPlace;
	const steps: ClipartStep[] = drafts.map( ( draft ) =>
		draft.type === 'image'
			? { ...draft, image: placeOf( draft.image ) }
			: {
					...draft,
					fill: placeOf( draft.fill ),
					grown: draft.grown === null ? null : placeOf( draft.grown ),
				}
	);

	return { art: { width, height, parts, steps }, atlas };
}

/**
 * How much of each pixel a shape with an outer stroke covers, as measured
 * against Photoshop: a pixel covered `a` reaches `size + a - 0.5` px.
 *
 * @param image The shape, which may go past the rectangle.
 * @param size  The stroke in pixels; 0 for none.
 * @param rect  Where the answer is wanted, in the document.
 * @param layer The layer's name, for the message.
 * @return Coverage 0–1 per pixel of the rectangle.
 */
export function grownCoverage(
	image: SourceImage,
	size: number,
	rect: ClipartRect,
	layer = ''
): Float32Array {
	const out = new Float32Array( rect.width * rect.height );
	const { width, height, data } = image;
	const alphaAt = ( x: number, y: number ): number =>
		x < 0 || y < 0 || x >= width || y >= height
			? 0
			: ( data[ ( y * width + x ) * 4 + 3 ] as number );
	const reach = Math.ceil( size + 0.5 );
	const offsets: Array< readonly [ number, number, number ] > = [];

	if ( size > 0 ) {
		for ( let y = -reach; y <= reach; y++ ) {
			for ( let x = -reach; x <= reach; x++ ) {
				const distance = Math.hypot( x, y );

				if ( distance < size + 0.5 ) {
					offsets.push( [ x, y, distance ] );
				}
			}
		}
	}

	const edges: number[] = [];

	for ( let y = 0; y < height; y++ ) {
		for ( let x = 0; x < width; x++ ) {
			const alpha = alphaAt( x, y );

			if ( alpha === 0 ) {
				continue;
			}

			const column = x + image.left - rect.x;
			const row = y + image.top - rect.y;

			if (
				column >= 0 &&
				row >= 0 &&
				column < rect.width &&
				row < rect.height
			) {
				const at = row * rect.width + column;

				out[ at ] = Math.max( out[ at ] as number, alpha / 255 );
			}

			// A covered pixel among covered ones is never the nearest.
			if (
				size > 0 &&
				( alpha < 255 ||
					alphaAt( x - 1, y ) < 255 ||
					alphaAt( x + 1, y ) < 255 ||
					alphaAt( x, y - 1 ) < 255 ||
					alphaAt( x, y + 1 ) < 255 )
			) {
				edges.push( y * width + x );
			}
		}
	}

	if ( edges.length * offsets.length > MAX_WORK ) {
		throw new ClipartError( 'too_complex', layer );
	}

	for ( const edge of edges ) {
		const x = edge % width;
		const y = ( edge - x ) / width;
		const most = size + alphaAt( x, y ) / 255 - 0.5;

		for ( const [ dx, dy, distance ] of offsets ) {
			const column = x + dx + image.left - rect.x;
			const row = y + dy + image.top - rect.y;

			if (
				distance >= most ||
				column < 0 ||
				row < 0 ||
				column >= rect.width ||
				row >= rect.height
			) {
				continue;
			}

			const at = row * rect.width + column;
			const covered = Math.min( 1, most - distance );

			if ( covered > ( out[ at ] as number ) ) {
				out[ at ] = covered;
			}
		}
	}

	return out;
}

/**
 * The overlap of a box and the document, or null.
 *
 * @param box    The box.
 * @param width  Document width.
 * @param height Document height.
 */
function onDocument(
	box: ClipartRect,
	width: number,
	height: number
): ClipartRect | null {
	const x = Math.max( 0, box.x );
	const y = Math.max( 0, box.y );
	const right = Math.min( width, box.x + box.width );
	const bottom = Math.min( height, box.y + box.height );

	return right > x && bottom > y
		? { x, y, width: right - x, height: bottom - y }
		: null;
}

/**
 * A part's two masks, or null when it is outside the document. The stroke of
 * a shape past the edge still reaches into the document.
 *
 * @param layer  The part.
 * @param width  Document width.
 * @param height Document height.
 */
function masksOf(
	layer: PartSource,
	width: number,
	height: number
): {
	rect: ClipartRect;
	fill: Float32Array;
	grown: Float32Array | null;
} | null {
	const size = layer.stroke?.size ?? 0;
	const margin = Math.ceil( size + 0.5 ) * ( size > 0 ? 1 : 0 );
	const rect = onDocument(
		{
			x: layer.box.x - margin,
			y: layer.box.y - margin,
			width: layer.box.width + 2 * margin,
			height: layer.box.height + 2 * margin,
		},
		width,
		height
	);

	return rect === null
		? null
		: {
				rect,
				fill: grownCoverage( layer.image, 0, rect ),
				grown:
					size > 0
						? grownCoverage( layer.image, size, rect, layer.name )
						: null,
			};
}

/**
 * A mask as white pixels with the coverage in their alpha, which a canvas
 * keeps exactly.
 *
 * @param mask Coverage.
 * @param rect Its rectangle.
 */
function maskImage( mask: Float32Array, rect: ClipartRect ): Rgba {
	const data = new Uint8ClampedArray( mask.length * 4 ).fill( 255 );

	for ( let at = 0; at < mask.length; at++ ) {
		data[ at * 4 + 3 ] = Math.round( ( mask[ at ] as number ) * 255 );
	}

	return { width: rect.width, height: rect.height, data };
}

/**
 * Fixed layers as one image, cropped to what shows; null when nothing does.
 *
 * @param layers Bottom first.
 * @param width  Document width.
 * @param height Document height.
 */
function flatten(
	layers: readonly FixedSource[],
	width: number,
	height: number
): { rect: ClipartRect; image: Rgba } | null {
	let left = width;
	let top = height;
	let right = 0;
	let bottom = 0;

	for ( const { image } of layers ) {
		const shown = onDocument(
			{
				x: image.left,
				y: image.top,
				width: image.width,
				height: image.height,
			},
			width,
			height
		);

		if ( shown !== null ) {
			left = Math.min( left, shown.x );
			top = Math.min( top, shown.y );
			right = Math.max( right, shown.x + shown.width );
			bottom = Math.max( bottom, shown.y + shown.height );
		}
	}

	if ( right <= left || bottom <= top ) {
		return null;
	}

	const columns = right - left;
	const rows = bottom - top;
	// Premultiplied, unrounded until the last layer.
	const mixed = new Float32Array( columns * rows * 4 );

	for ( const { image, opacity } of layers ) {
		for ( let y = 0; y < image.height; y++ ) {
			const row = y + image.top - top;

			if ( row < 0 || row >= rows ) {
				continue;
			}

			for ( let x = 0; x < image.width; x++ ) {
				const column = x + image.left - left;

				if ( column < 0 || column >= columns ) {
					continue;
				}

				const read = ( y * image.width + x ) * 4;
				const write = ( row * columns + column ) * 4;
				const alpha =
					( ( image.data[ read + 3 ] as number ) / 255 ) * opacity;

				if ( alpha === 0 ) {
					continue;
				}

				for ( let channel = 0; channel < 3; channel++ ) {
					mixed[ write + channel ] =
						( image.data[ read + channel ] as number ) * alpha +
						( mixed[ write + channel ] as number ) * ( 1 - alpha );
				}

				mixed[ write + 3 ] =
					alpha + ( mixed[ write + 3 ] as number ) * ( 1 - alpha );
			}
		}
	}

	const data = new Uint8ClampedArray( mixed.length );

	for ( let at = 0; at < mixed.length; at += 4 ) {
		const alpha = mixed[ at + 3 ] as number;

		if ( alpha > 0 ) {
			for ( let channel = 0; channel < 3; channel++ ) {
				data[ at + channel ] = Math.round(
					( mixed[ at + channel ] as number ) / alpha
				);
			}

			data[ at + 3 ] = Math.round( alpha * 255 );
		}
	}

	const box = visibleBox( { width: columns, height: rows, data } );

	if ( box === null ) {
		return null;
	}

	const cropped = new Uint8ClampedArray( box.width * box.height * 4 );

	for ( let row = 0; row < box.height; row++ ) {
		const from = ( ( box.y + row ) * columns + box.x ) * 4;

		cropped.set(
			data.subarray( from, from + box.width * 4 ),
			row * box.width * 4
		);
	}

	return {
		rect: { ...box, x: box.x + left, y: box.y + top },
		image: { width: box.width, height: box.height, data: cropped },
	};
}

/**
 * The images in one atlas no larger than a designer file may be, the tallest
 * first, row by row.
 *
 * @param images The images.
 */
function packed( images: readonly Rgba[] ): {
	atlas: Rgba;
	places: AtlasPlace[];
} {
	const order = images
		.map( ( image, index ) => ( { image, index } ) )
		.sort( ( one, other ) => other.image.height - one.image.height );
	const places: AtlasPlace[] = [];
	let x = 0;
	let y = 0;
	let row = 0;
	let width = 0;
	let height = 0;

	for ( const { image, index } of order ) {
		if ( x > 0 && x + image.width > MAX_PIXELS ) {
			x = 0;
			y += row + GAP;
			row = 0;
		}

		places[ index ] = { x, y };
		width = Math.max( width, x + image.width );
		height = Math.max( height, y + image.height );
		row = Math.max( row, image.height );
		x += image.width + GAP;
	}

	if ( width > MAX_PIXELS || height > MAX_PIXELS ) {
		throw new ClipartError( 'atlas' );
	}

	const data = new Uint8ClampedArray( width * height * 4 );

	for ( const { image, index } of order ) {
		const place = places[ index ] as AtlasPlace;

		for ( let line = 0; line < image.height; line++ ) {
			data.set(
				image.data.subarray(
					line * image.width * 4,
					( line + 1 ) * image.width * 4
				),
				( ( place.y + line ) * width + place.x ) * 4
			);
		}
	}

	return { atlas: { width, height, data }, places };
}
