/**
 * A mockup PSD with smart objects: the administrator's browser reads it (it
 * is never uploaded) and keeps what a creator's browser needs to draw the
 * mockup: the layers around each design, in one image (the atlas), and each
 * smart object's warp. Smart objects with "design" in their name take the
 * print file; "højre"/"right" shows its right end, "venstre"/"left" its left
 * end, anything else its middle.
 *
 * Hidden layers and groups are left out. Layers in a normal blend are merged.
 * Effects, layer masks and adjustment layers cannot be redrawn: they are named
 * in `warnings`, and the administrator can merge them in Photoshop.
 */
import { readBoundedPsd } from './psd-budget';
import type { Layer, Psd } from 'ag-psd';

import { FLAT_MESH } from '../dashboard/sections/designer/scene';
import type {
	Pixels,
	Scene,
	SceneBlend,
	SceneDesign,
	SceneRect,
	SceneStep,
} from '../dashboard/sections/designer/scene';
import { MAX_PIXELS, TemplateError } from './psd-template';

/** The longest side of a mockup: a shop image needs no more. */
export const MAX_SCENE_SIDE = 2000;

/** The width the atlas is filled to before a new one row starts. */
const ATLAS_WIDTH = 4096;

export interface PsdScene {
	readonly scene: Scene;
	readonly atlas: Pixels;
	/** The design layers found. */
	readonly designs: readonly string[];
	/** What could not be redrawn. */
	readonly warnings: readonly string[];
}

const BLENDS: Readonly< Record< string, SceneBlend > > = {
	normal: 'normal',
	multiply: 'multiply',
	screen: 'screen',
	overlay: 'overlay',
	darken: 'darken',
	lighten: 'lighten',
	'color dodge': 'color-dodge',
	'color burn': 'color-burn',
	'hard light': 'hard-light',
	'darker color': 'darker-color',
};

/** A part of the document at the scene's scale. */
interface Piece {
	readonly left: number;
	readonly top: number;
	readonly width: number;
	readonly height: number;
	readonly data: Uint8ClampedArray;
}

/**
 * The visible layers, bottom first, with the opacity of their groups.
 *
 * @param children Layers and groups.
 * @param opacity  The groups' opacity.
 * @param warnings Receives what cannot be redrawn.
 */
function visible(
	children: readonly Layer[],
	opacity: number,
	warnings: Set< string >
): { readonly layer: Layer; readonly opacity: number }[] {
	return children.flatMap( ( layer ) => {
		if ( layer.hidden === true ) {
			return [];
		}

		if ( layer.children !== undefined ) {
			if (
				layer.blendMode !== undefined &&
				layer.blendMode !== 'pass through' &&
				layer.blendMode !== 'normal'
			) {
				warnings.add( layer.name ?? '' );
			}

			return visible(
				layer.children,
				opacity * ( layer.opacity ?? 1 ),
				warnings
			);
		}

		return [ { layer, opacity } ];
	} );
}

/**
 * Whether a layer has an effect, a mask or an adjustment that is left out.
 *
 * @param layer The layer.
 */
function hasLoss( layer: Layer ): boolean {
	const effects = Object.entries( layer.effects ?? {} ).some(
		( [ key, value ] ) =>
			key !== 'scale' &&
			( Array.isArray( value )
				? value.some( ( effect ) => effect.enabled === true )
				: typeof value === 'object' &&
					value !== null &&
					( value as { enabled?: boolean } ).enabled === true )
	);

	return (
		effects ||
		layer.adjustment !== undefined ||
		( layer.mask !== undefined && layer.mask.disabled !== true )
	);
}

/**
 * A layer's pixels at the scene's scale.
 *
 * @param layer The layer.
 * @param scale The scale, at most 1.
 */
function piece( layer: Layer, scale: number ): Piece | null {
	const source = layer.imageData;

	if ( source === undefined || source.width === 0 || source.height === 0 ) {
		return null;
	}

	if ( ! (
		source.data instanceof Uint8ClampedArray ||
		source.data instanceof Uint8Array
	) ) {
		throw new TemplateError( 'depth' );
	}

	const left = Math.floor( ( layer.left ?? 0 ) * scale );
	const top = Math.floor( ( layer.top ?? 0 ) * scale );
	const width =
		Math.ceil( ( ( layer.left ?? 0 ) + source.width ) * scale ) - left;
	const height =
		Math.ceil( ( ( layer.top ?? 0 ) + source.height ) * scale ) - top;
	const sums = new Float64Array( width * height * 5 );

	for ( let y = 0; y < source.height; y++ ) {
		const row = Math.floor( ( y + ( layer.top ?? 0 ) ) * scale ) - top;

		for ( let x = 0; x < source.width; x++ ) {
			const at = ( y * source.width + x ) * 4;
			const alpha = source.data[ at + 3 ] as number;
			const to =
				( row * width +
					Math.floor( ( x + ( layer.left ?? 0 ) ) * scale ) -
					left ) *
				5;

			sums[ to ] =
				( sums[ to ] as number ) +
				( source.data[ at ] as number ) * alpha;
			sums[ to + 1 ] =
				( sums[ to + 1 ] as number ) +
				( source.data[ at + 1 ] as number ) * alpha;
			sums[ to + 2 ] =
				( sums[ to + 2 ] as number ) +
				( source.data[ at + 2 ] as number ) * alpha;
			sums[ to + 3 ] = ( sums[ to + 3 ] as number ) + alpha;
			sums[ to + 4 ] = ( sums[ to + 4 ] as number ) + 1;
		}
	}

	const data = new Uint8ClampedArray( width * height * 4 );

	for ( let index = 0; index < width * height; index++ ) {
		const weight = sums[ index * 5 + 3 ] as number;

		if ( weight > 0 ) {
			data[ index * 4 ] = ( sums[ index * 5 ] as number ) / weight;
			data[ index * 4 + 1 ] =
				( sums[ index * 5 + 1 ] as number ) / weight;
			data[ index * 4 + 2 ] =
				( sums[ index * 5 + 2 ] as number ) / weight;
			data[ index * 4 + 3 ] =
				weight / ( sums[ index * 5 + 4 ] as number );
		}
	}

	return { left, top, width, height, data };
}

/**
 * Lays a piece on a document-size layer in a normal blend.
 *
 * @param target      The layer.
 * @param size        The document.
 * @param size.width  Width.
 * @param size.height Height.
 * @param image       The piece.
 * @param opacity     Its opacity.
 * @param clip        The alpha it is clipped to, or null.
 */
function merge(
	target: Uint8ClampedArray,
	size: { readonly width: number; readonly height: number },
	image: Piece,
	opacity: number,
	clip: Uint8ClampedArray | null
): void {
	for ( let row = 0; row < image.height; row++ ) {
		const y = row + image.top;

		for ( let column = 0; column < image.width; column++ ) {
			const x = column + image.left;

			if ( x < 0 || y < 0 || x >= size.width || y >= size.height ) {
				continue;
			}

			const place = y * size.width + x;
			const from = ( row * image.width + column ) * 4;
			const to = place * 4;
			const top =
				( ( image.data[ from + 3 ] as number ) / 255 ) *
				opacity *
				( clip === null ? 1 : ( clip[ place ] as number ) / 255 );

			if ( top === 0 ) {
				continue;
			}

			const below = ( target[ to + 3 ] as number ) / 255;
			const alpha = top + below * ( 1 - top );

			for ( let channel = 0; channel < 3; channel++ ) {
				target[ to + channel ] =
					( ( image.data[ from + channel ] as number ) * top +
						( target[ to + channel ] as number ) *
							below *
							( 1 - top ) ) /
					alpha;
			}

			target[ to + 3 ] = alpha * 255;
		}
	}
}

/**
 * The visible box of a document-size layer, as a piece.
 *
 * @param data   The layer.
 * @param width  Document width.
 * @param height Document height.
 */
function cropped(
	data: Uint8ClampedArray,
	width: number,
	height: number
): Piece | null {
	let minX = width;
	let minY = height;
	let maxX = -1;
	let maxY = -1;

	for ( let y = 0; y < height; y++ ) {
		for ( let x = 0; x < width; x++ ) {
			if ( ( data[ ( y * width + x ) * 4 + 3 ] as number ) > 0 ) {
				minX = Math.min( minX, x );
				minY = Math.min( minY, y );
				maxX = Math.max( maxX, x );
				maxY = Math.max( maxY, y );
			}
		}
	}

	if ( maxX < 0 ) {
		return null;
	}

	const boxWidth = maxX - minX + 1;
	const boxHeight = maxY - minY + 1;
	const box = new Uint8ClampedArray( boxWidth * boxHeight * 4 );

	for ( let row = 0; row < boxHeight; row++ ) {
		box.set(
			data.subarray(
				( ( row + minY ) * width + minX ) * 4,
				( ( row + minY ) * width + maxX + 1 ) * 4
			),
			row * boxWidth * 4
		);
	}

	return {
		left: minX,
		top: minY,
		width: boxWidth,
		height: boxHeight,
		data: box,
	};
}

/**
 * A smart object as a step. The warp's points lie in a box of their own, which
 * the transform puts on the document.
 *
 * @param layer The layer.
 * @param scale The scene's scale.
 * @param mask  Where it may show.
 */
function design(
	layer: Layer,
	scale: number,
	mask: SceneDesign[ 'mask' ]
): SceneDesign {
	const placedLayer = layer.placedLayer;

	if ( placedLayer === undefined ) {
		throw new TemplateError( 'unreadable' );
	}

	const warp = placedLayer.warp;
	const points = warp?.customEnvelopeWarp?.meshPoints;

	if (
		warp !== undefined &&
		warp.style !== 'none' &&
		( points === undefined || points.length !== 16 )
	) {
		throw new TemplateError( 'warp', layer.name ?? '' );
	}

	let mesh = FLAT_MESH;

	if ( points !== undefined && points.length === 16 ) {
		const across = points.map( ( point ) => point.x );
		const down = points.map( ( point ) => point.y );
		const minX = Math.min( ...across );
		const minY = Math.min( ...down );
		const spanX = Math.max( ...across ) - minX || 1;
		const spanY = Math.max( ...down ) - minY || 1;

		mesh = points.flatMap( ( point ) => [
			Number( ( ( point.x - minX ) / spanX ).toFixed( 5 ) ),
			Number( ( ( point.y - minY ) / spanY ).toFixed( 5 ) ),
		] );
	}

	const name = ( layer.name ?? '' ).toLowerCase();
	let end: SceneDesign[ 'end' ] = 'middle';

	if ( /højre|hojre|right/.test( name ) ) {
		end = 'end';
	} else if ( /venstre|left/.test( name ) ) {
		end = 'start';
	}

	return {
		type: 'design',
		end,
		width: Math.max( 1, Math.round( placedLayer.width ?? 1 ) ),
		height: Math.max( 1, Math.round( placedLayer.height ?? 1 ) ),
		quad: placedLayer.transform.map( ( value ) =>
			Number( ( value * scale ).toFixed( 2 ) )
		),
		mesh,
		mask,
	};
}

/**
 * Packs the pieces into one image, row by row.
 *
 * @param pieces The pieces.
 * @return The image and each piece's place in it.
 */
function pack( pieces: readonly Piece[] ): {
	readonly atlas: Pixels;
	readonly places: readonly SceneRect[];
} {
	const order = pieces
		.map( ( _, index ) => index )
		.sort(
			( a, b ) =>
				( pieces[ b ] as Piece ).height -
				( pieces[ a ] as Piece ).height
		);
	const places: SceneRect[] = pieces.map( () => ( {
		x: 0,
		y: 0,
		width: 0,
		height: 0,
	} ) );
	let x = 0;
	let y = 0;
	let rowHeight = 0;
	let width = 1;

	for ( const index of order ) {
		const item = pieces[ index ] as Piece;

		if ( x > 0 && x + item.width > ATLAS_WIDTH ) {
			x = 0;
			y += rowHeight + 1;
			rowHeight = 0;
		}

		places[ index ] = { x, y, width: item.width, height: item.height };
		x += item.width + 1;
		width = Math.max( width, x );
		rowHeight = Math.max( rowHeight, item.height );
	}

	const height = Math.max( 1, y + rowHeight );

	if ( width > MAX_PIXELS || height > MAX_PIXELS ) {
		throw new TemplateError( 'size' );
	}

	const data = new Uint8ClampedArray( width * height * 4 );

	pieces.forEach( ( item, index ) => {
		const place = places[ index ] as SceneRect;

		for ( let row = 0; row < item.height; row++ ) {
			data.set(
				item.data.subarray(
					row * item.width * 4,
					( row + 1 ) * item.width * 4
				),
				( ( row + place.y ) * width + place.x ) * 4
			);
		}
	} );

	return { atlas: { width, height, data }, places };
}

/**
 * The scene of a PSD file's bytes.
 *
 * @param buffer The PSD.
 */
export function readScene( buffer: ArrayBuffer ): PsdScene {
	let psd: Psd;

	try {
		psd = readBoundedPsd( buffer );
	} catch {
		throw new TemplateError( 'unreadable' );
	}

	return sceneFromPsd( psd );
}

/**
 * The scene of a read PSD.
 *
 * @param psd The PSD as ag-psd reads it.
 */
export function sceneFromPsd( psd: Psd ): PsdScene {
	if ( psd.bitsPerChannel !== undefined && psd.bitsPerChannel !== 8 ) {
		throw new TemplateError( 'depth' );
	}

	const scale = Math.min(
		1,
		MAX_SCENE_SIDE / Math.max( psd.width, psd.height )
	);
	const width = Math.round( psd.width * scale );
	const height = Math.round( psd.height * scale );
	const size = { width, height };
	const lost = new Set< string >();
	const pieces: Piece[] = [];
	// A step with the index of its piece until the atlas is packed.
	const drafts: (
		| {
				readonly piece: number;
				readonly blend: SceneBlend;
				readonly opacity: number;
		  }
		| { readonly design: Layer; readonly mask: number | null }
	)[] = [];
	const names: string[] = [];
	let plate = new Uint8ClampedArray( width * height * 4 );
	let clip: Uint8ClampedArray | null = null;

	const flush = (): void => {
		const image = cropped( plate, width, height );

		if ( image !== null ) {
			pieces.push( image );
			drafts.push( {
				piece: pieces.length - 1,
				blend: 'normal',
				opacity: 1,
			} );
			plate = new Uint8ClampedArray( width * height * 4 );
		}
	};

	for ( const { layer, opacity } of visible( psd.children ?? [], 1, lost ) ) {
		if ( hasLoss( layer ) ) {
			lost.add( layer.name ?? '' );
		}

		const clipped = layer.clipping === true ? clip : null;

		if (
			layer.placedLayer !== undefined &&
			/design/i.test( layer.name ?? '' )
		) {
			flush();

			if (
				( layer.placedLayer.filter?.list.length ?? 0 ) > 0 &&
				layer.placedLayer.filter?.enabled === true
			) {
				lost.add( layer.name ?? '' );
			}

			let mask: number | null = null;

			if ( clipped !== null ) {
				const alpha = new Uint8ClampedArray( width * height * 4 );

				clipped.forEach( ( value, index ) => {
					alpha[ index * 4 + 3 ] = value;
				} );

				const image = cropped( alpha, width, height );

				if ( image !== null ) {
					pieces.push( image );
					mask = pieces.length - 1;
				}
			}

			drafts.push( { design: layer, mask } );
			names.push( layer.name ?? '' );
			continue;
		}

		const strength =
			opacity * ( layer.opacity ?? 1 ) * ( layer.fillOpacity ?? 1 );
		let image = piece( layer, scale );

		// A colour fill without pixels, clipped: it fills what it is clipped to.
		if (
			image === null &&
			clipped !== null &&
			layer.vectorFill?.type === 'color'
		) {
			const fill = layer.vectorFill.color as {
				r?: number;
				g?: number;
				b?: number;
			};

			image = {
				left: 0,
				top: 0,
				width,
				height,
				data: new Uint8ClampedArray( width * height * 4 ).map(
					( _, index ) =>
						[ fill.r ?? 0, fill.g ?? 0, fill.b ?? 0, 255 ][
							index % 4
						] as number
				),
			};
		}

		if ( image === null ) {
			continue;
		}

		const blend = BLENDS[ layer.blendMode ?? 'normal' ];

		if ( blend === undefined ) {
			lost.add( layer.name ?? '' );
		}

		if ( blend === undefined || blend === 'normal' ) {
			merge( plate, size, image, strength, clipped );
		} else {
			flush();

			const own = new Uint8ClampedArray( width * height * 4 );

			merge( own, size, image, 1, clipped );

			const box = cropped( own, width, height );

			if ( box !== null ) {
				pieces.push( box );
				drafts.push( {
					piece: pieces.length - 1,
					blend,
					opacity: Number( strength.toFixed( 4 ) ),
				} );
			}
		}

		if ( layer.clipping !== true ) {
			const alpha = new Uint8ClampedArray( width * height );

			for ( let row = 0; row < image.height; row++ ) {
				for ( let column = 0; column < image.width; column++ ) {
					const x = column + image.left;
					const y = row + image.top;

					if ( x >= 0 && y >= 0 && x < width && y < height ) {
						alpha[ y * width + x ] = image.data[
							( row * image.width + column ) * 4 + 3
						] as number;
					}
				}
			}

			clip = alpha;
		}
	}

	flush();

	if ( names.length === 0 ) {
		throw new TemplateError( 'no_design' );
	}

	const { atlas, places } = pack( pieces );
	const steps: SceneStep[] = drafts.map( ( draft ) => {
		if ( 'design' in draft ) {
			const rect =
				draft.mask === null
					? null
					: ( places[ draft.mask ] as SceneRect );
			const box =
				draft.mask === null ? null : ( pieces[ draft.mask ] as Piece );

			return design(
				draft.design,
				scale,
				rect === null || box === null
					? null
					: { ...rect, left: box.left, top: box.top }
			);
		}

		const box = pieces[ draft.piece ] as Piece;

		return {
			type: 'image',
			rect: places[ draft.piece ] as SceneRect,
			left: box.left,
			top: box.top,
			blend: draft.blend,
			opacity: draft.opacity,
		};
	} );

	return {
		scene: { width, height, steps },
		atlas,
		designs: names,
		warnings: [ ...lost ].filter( ( name ) => name !== '' ),
	};
}
