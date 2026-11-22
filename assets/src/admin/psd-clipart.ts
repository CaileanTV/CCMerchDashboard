/**
 * A clipart with coloured parts from a PSD (phase 56, step 56.2, ADR-121
 * pkt. 8): the administrator's browser reads the file with ag-psd and never
 * uploads it. Every layer whose name begins "farvet lag" (any case) is a
 * part; the other visible layers are fixed.
 *
 * Only what can be drawn as Photoshop does is taken: normal layers and, on a
 * part, one colour overlay, one linear gradient overlay and one outer stroke
 * of one colour. Anything else is refused with the layer's name, never drawn
 * differently. ag-psd leaves out effects it does not know without saying so,
 * so the bake is also compared with the PSD's own composite before it is
 * saved.
 */
import { readPsd } from 'ag-psd';
import type {
	Color,
	Layer,
	LayerEffectGradientOverlay,
	LayerEffectsInfo,
	PixelData,
	Psd,
} from 'ag-psd';

import type {
	ClipartPaint,
	ClipartRect,
	GradientPaint,
} from '../dashboard/sections/designer/clipart-model';
import { MAX_PIXELS } from './psd-template';
import type { Rgba } from './psd-template';

/** How a part's layer name begins. */
const PART_NAME = /^farvet lag/i;

/** The most parts of a clipart. */
export const MAX_PARTS = 8;

/** The largest area of a clipart. */
export const MAX_AREA = 16_000_000;

/** The widest stroke that is drawn. */
export const MAX_STROKE = 100;

/** Photoshop's image resource with the colour profile. */
const ICC_PROFILE = 1039;

/** An artboard without a background. */
const TRANSPARENT_ARTBOARD = 3;

export type ClipartErrorCode =
	| 'unreadable'
	| 'mode'
	| 'depth'
	| 'size'
	| 'profile'
	| 'no_parts'
	| 'too_many'
	| 'artboard'
	| 'group'
	| 'blend'
	| 'mask'
	| 'clipping'
	| 'adjustment'
	| 'blend_if'
	| 'opacity'
	| 'pixels'
	| 'effect'
	| 'overlay'
	| 'gradient'
	| 'stroke'
	| 'color'
	| 'too_complex'
	| 'atlas';

/**
 * A PSD that cannot become a clipart, with a code for the message.
 */
export class ClipartError extends Error {
	public readonly code: ClipartErrorCode;

	public readonly detail: string;

	/**
	 * Constructor.
	 *
	 * @param code   Why.
	 * @param detail The layer's name, when one is the reason.
	 */
	public constructor( code: ClipartErrorCode, detail = '' ) {
		super( `PSD clipart refused: ${ code }` );
		this.code = code;
		this.detail = detail;
	}
}

/** A layer's pixels on the document; they may go past its edge. */
export interface SourceImage extends Rgba {
	readonly left: number;
	readonly top: number;
}

/** A fixed layer. */
export interface FixedSource {
	readonly type: 'image';
	readonly name: string;
	readonly image: SourceImage;
	readonly opacity: number;
}

/** A layer the creator colours. */
export interface PartSource {
	readonly type: 'part';
	readonly name: string;
	readonly label: string;
	readonly image: SourceImage;
	readonly box: ClipartRect;
	readonly stroke: { readonly color: string; readonly size: number } | null;
	readonly scale: number;
	readonly paint: ClipartPaint;
	readonly gradient: GradientPaint;
}

/** A PSD as the bake takes it. */
export interface ClipartSource {
	readonly width: number;
	readonly height: number;
	/** Bottom first. */
	readonly layers: ReadonlyArray< FixedSource | PartSource >;
	/** The PSD's own composite, to compare the bake with. */
	readonly composite: Rgba | null;
}

/**
 * The clipart of a PSD file's bytes.
 *
 * @param buffer The PSD.
 */
export function readClipart( buffer: ArrayBuffer ): ClipartSource {
	let psd: Psd;
	let profile: Uint8Array | null;

	try {
		profile = colorProfile( new Uint8Array( buffer ) );
		psd = readPsd( buffer, {
			useImageData: true,
			skipThumbnail: true,
			skipLinkedFilesData: true,
		} );
	} catch {
		throw new ClipartError( 'unreadable' );
	}

	// ag-psd does not convert profiles: other numbers would be other colours.
	if ( profile !== null && ! namesSrgb( profile ) ) {
		throw new ClipartError( 'profile' );
	}

	return clipartFromPsd( psd );
}

/**
 * The clipart of a read PSD.
 *
 * @param psd The PSD as ag-psd reads it.
 */
export function clipartFromPsd( psd: Psd ): ClipartSource {
	// 3: RGB.
	if ( psd.colorMode !== undefined && Number( psd.colorMode ) !== 3 ) {
		throw new ClipartError( 'mode' );
	}

	if ( psd.bitsPerChannel !== undefined && psd.bitsPerChannel !== 8 ) {
		throw new ClipartError( 'depth' );
	}

	const { width, height } = psd;

	if (
		! Number.isInteger( width ) ||
		! Number.isInteger( height ) ||
		width < 1 ||
		height < 1 ||
		width > MAX_PIXELS ||
		height > MAX_PIXELS ||
		width * height > MAX_AREA
	) {
		throw new ClipartError( 'size' );
	}

	const layers: Array< FixedSource | PartSource > = [];
	const visit = ( children: readonly Layer[] ): void => {
		for ( const layer of children ) {
			if ( layer.hidden === true ) {
				continue;
			}

			if ( layer.children !== undefined ) {
				checkGroup( layer, width, height );
				visit( layer.children );
				continue;
			}

			const taken = takeLayer( layer );

			if ( taken !== null ) {
				layers.push( taken );
			}
		}
	};

	visit( psd.children ?? [] );

	const parts = layers.filter( ( layer ) => layer.type === 'part' ).length;

	if ( parts === 0 ) {
		throw new ClipartError( 'no_parts' );
	}

	if ( parts > MAX_PARTS ) {
		throw new ClipartError( 'too_many' );
	}

	const composite = pixelsOf( psd.imageData, '' );

	return {
		width,
		height,
		layers,
		composite:
			composite !== null &&
			composite.width === width &&
			composite.height === height
				? composite
				: null,
	};
}

/**
 * A part's label from its layer's name; null for a fixed layer.
 *
 * @param name The layer's name.
 */
export function partLabel( name: string ): string | null {
	const trimmed = name.trim();

	return PART_NAME.test( trimmed )
		? trimmed
				.replace( PART_NAME, '' )
				.replace( /^[\s:_–-]+/, '' )
				.trim()
		: null;
}

/**
 * A colour of ag-psd as `#rrggbb`.
 *
 * @param color The colour.
 * @param layer The layer's name, for the message.
 */
export function hexOf( color: Color | undefined, layer: string ): string {
	let rgb: number[];

	if ( color === undefined ) {
		throw new ClipartError( 'color', layer );
	} else if ( 'r' in color ) {
		rgb = [ color.r, color.g, color.b ];
	} else if ( 'fr' in color ) {
		rgb = [ color.fr * 255, color.fg * 255, color.fb * 255 ];
	} else if ( 'h' in color ) {
		// The hue as a share of the circle; saturation and brightness in percent.
		const saturation = color.s / 100;
		const value = ( color.b / 100 ) * 255;
		const channel = ( offset: number ): number => {
			const k = ( offset + color.h * 6 ) % 6;

			return (
				value -
				value * saturation * Math.max( 0, Math.min( k, 4 - k, 1 ) )
			);
		};

		rgb = [ channel( 5 ), channel( 3 ), channel( 1 ) ];
	} else {
		throw new ClipartError( 'color', layer );
	}

	if ( ! rgb.every( ( part ) => Number.isFinite( part ) ) ) {
		throw new ClipartError( 'color', layer );
	}

	return `#${ rgb
		.map( ( part ) =>
			Math.min( 255, Math.max( 0, Math.round( part ) ) )
				.toString( 16 )
				.padStart( 2, '0' )
		)
		.join( '' ) }`;
}

/**
 * The embedded colour profile, which ag-psd skips.
 *
 * @param bytes The PSD.
 */
export function colorProfile( bytes: Uint8Array ): Uint8Array | null {
	const view = new DataView(
		bytes.buffer,
		bytes.byteOffset,
		bytes.byteLength
	);
	let at = 26;

	at += 4 + view.getUint32( at );

	const end = at + 4 + view.getUint32( at );

	at += 4;

	while ( at + 12 <= end ) {
		const id = view.getUint16( at + 4 );
		// The name, padded to an even length with its length byte.
		const named = view.getUint8( at + 6 ) + 1;

		at += 6 + named + ( named % 2 );

		const size = view.getUint32( at );

		at += 4;

		if ( id === ICC_PROFILE ) {
			return bytes.subarray( at, at + size );
		}

		at += size + ( size % 2 );
	}

	return null;
}

/**
 * Whether a profile calls itself sRGB (in ASCII or UTF-16).
 *
 * @param profile The profile's bytes.
 */
function namesSrgb( profile: Uint8Array ): boolean {
	let text = '';

	for ( const byte of profile ) {
		if ( byte !== 0 ) {
			text += String.fromCharCode( byte );
		}
	}

	return text.includes( 'sRGB' );
}

/**
 * Refuses a group that cannot be flattened.
 *
 * @param group  The group.
 * @param width  Document width.
 * @param height Document height.
 */
function checkGroup( group: Layer, width: number, height: number ): void {
	const name = group.name ?? '';
	const board = group.artboard;

	if (
		board !== undefined &&
		( board.backgroundType !== TRANSPARENT_ARTBOARD ||
			board.rect.left !== 0 ||
			board.rect.top !== 0 ||
			board.rect.right !== width ||
			board.rect.bottom !== height )
	) {
		throw new ClipartError( 'artboard', name );
	}

	// A part is a layer: a group so named has no pixels of its own to colour.
	if ( partLabel( name ) !== null ) {
		throw new ClipartError( 'group', name );
	}

	if (
		group.blendMode !== undefined &&
		group.blendMode !== 'pass through' &&
		group.blendMode !== 'normal'
	) {
		throw new ClipartError( 'blend', name );
	}

	checkPlain( group );

	if ( ( group.opacity ?? 1 ) < 1 ) {
		throw new ClipartError( 'opacity', name );
	}

	if ( activeEffects( group.effects ).length > 0 ) {
		throw new ClipartError( 'effect', name );
	}
}

/**
 * Refuses what is drawn on neither a group, a layer nor a part.
 *
 * @param layer The layer or group.
 */
function checkPlain( layer: Layer ): void {
	const name = layer.name ?? '';

	if ( layer.clipping === true ) {
		throw new ClipartError( 'clipping', name );
	}

	if (
		( layer.mask !== undefined && layer.mask.disabled !== true ) ||
		( layer.realMask !== undefined && layer.realMask.disabled !== true ) ||
		// A shape's own path is in its pixels.
		( layer.vectorMask !== undefined &&
			layer.vectorMask.disable !== true &&
			layer.vectorFill === undefined )
	) {
		throw new ClipartError( 'mask', name );
	}

	if ( layer.adjustment !== undefined ) {
		throw new ClipartError( 'adjustment', name );
	}

	if (
		layer.knockout === true ||
		( layer.channelBlendingRestrictions ?? [] ).length > 0 ||
		blendsIf( layer )
	) {
		throw new ClipartError( 'blend_if', name );
	}

	if ( ( layer.fillOpacity ?? 1 ) < 1 ) {
		throw new ClipartError( 'opacity', name );
	}
}

/**
 * Whether "Blend If" is set.
 *
 * @param layer The layer.
 */
function blendsIf( layer: Layer ): boolean {
	const ranges = layer.blendingRanges;

	if ( ranges === undefined ) {
		return false;
	}

	const open = ( range: readonly number[] ): boolean =>
		range[ 0 ] === 0 &&
		range[ 1 ] === 0 &&
		range[ 2 ] === 255 &&
		range[ 3 ] === 255;

	return ! [
		ranges.compositeGrayBlendSource,
		ranges.compositeGraphBlendDestinationRange,
		...ranges.ranges.flatMap( ( range ) => [
			range.sourceRange,
			range.destRange,
		] ),
	].every( open );
}

/**
 * The names of a layer's effects that are switched on.
 *
 * @param effects The layer's effects.
 */
function activeEffects( effects: LayerEffectsInfo | undefined ): string[] {
	if ( effects === undefined || effects.disabled === true ) {
		return [];
	}

	const on: string[] = [];

	for ( const [ kind, value ] of Object.entries( effects ) ) {
		for ( const effect of ( Array.isArray( value )
			? value
			: [ value ] ) as unknown[] ) {
			if (
				typeof effect === 'object' &&
				effect !== null &&
				( effect as { enabled?: boolean } ).enabled === true
			) {
				on.push( kind );
			}
		}
	}

	return on;
}

/**
 * A visible layer as the bake takes it; null for an empty one.
 *
 * @param layer The layer.
 */
function takeLayer( layer: Layer ): FixedSource | PartSource | null {
	const name = layer.name ?? '';
	const label = partLabel( name );

	if ( layer.blendMode !== undefined && layer.blendMode !== 'normal' ) {
		throw new ClipartError( 'blend', name );
	}

	checkPlain( layer );

	const opacity = layer.opacity ?? 1;

	if ( label !== null && opacity < 1 ) {
		throw new ClipartError( 'opacity', name );
	}

	const pixels = pixelsOf( layer.imageData, name );
	const box = pixels === null ? null : visibleBox( pixels );
	const on = activeEffects( layer.effects );

	if ( pixels === null || box === null ) {
		// Text, shapes and smart objects without pixels would disappear.
		if (
			label !== null ||
			on.length > 0 ||
			( pixels === null &&
				( layer.text !== undefined ||
					layer.vectorFill !== undefined ||
					layer.placedLayer !== undefined ) )
		) {
			throw new ClipartError( 'pixels', name );
		}

		return null;
	}

	const image = { ...pixels, left: layer.left ?? 0, top: layer.top ?? 0 };

	if ( label === null ) {
		if ( on.length > 0 ) {
			throw new ClipartError( 'effect', name );
		}

		return { type: 'image', name, image, opacity };
	}

	return {
		type: 'part',
		name,
		label,
		image,
		box: { ...box, x: box.x + image.left, y: box.y + image.top },
		...effectsOfPart( layer, pixels, on ),
	};
}

/**
 * A part's stroke, paint and gradient.
 *
 * @param layer  The layer.
 * @param pixels Its pixels.
 * @param on     Its effects that are switched on.
 */
function effectsOfPart(
	layer: Layer,
	pixels: Rgba,
	on: readonly string[]
): Pick< PartSource, 'stroke' | 'scale' | 'paint' | 'gradient' > {
	const name = layer.name ?? '';
	const allowed = [ 'solidFill', 'gradientOverlay', 'stroke' ];

	if (
		! on.every( ( kind ) => allowed.includes( kind ) ) ||
		new Set( on ).size !== on.length
	) {
		throw new ClipartError( 'effect', name );
	}

	const { effects } = layer;
	const overlay = on.includes( 'solidFill' )
		? effects?.solidFill?.find( ( effect ) => effect.enabled === true )
		: undefined;
	const outline = on.includes( 'stroke' )
		? effects?.stroke?.find( ( effect ) => effect.enabled === true )
		: undefined;
	const active = on.includes( 'gradientOverlay' )
		? effects?.gradientOverlay?.find(
				( effect ) => effect.enabled === true
			)
		: undefined;

	if (
		overlay !== undefined &&
		( ( overlay.blendMode ?? 'normal' ) !== 'normal' ||
			( overlay.opacity ?? 1 ) < 1 )
	) {
		throw new ClipartError( 'overlay', name );
	}

	if ( active !== undefined && ! drawableGradient( active ) ) {
		throw new ClipartError( 'gradient', name );
	}

	let stroke: PartSource[ 'stroke' ] = null;

	if ( outline !== undefined ) {
		const size = outline.size?.value ?? 0;

		if (
			outline.position !== 'outside' ||
			( outline.fillType ?? 'color' ) !== 'color' ||
			( outline.blendMode ?? 'normal' ) !== 'normal' ||
			( outline.opacity ?? 1 ) < 1 ||
			outline.size?.units !== 'Pixels' ||
			! ( size > 0 && size <= MAX_STROKE )
		) {
			throw new ClipartError( 'stroke', name );
		}

		stroke = { color: hexOf( outline.color, name ), size };
	}

	const color =
		overlay === undefined
			? commonColor( pixels )
			: hexOf( overlay.color, name );
	// A gradient switched off is still what a creator's gradient starts as.
	const kept = active ?? effects?.gradientOverlay?.[ 0 ];
	const gradient = gradientStart( kept, color, name );

	return {
		stroke,
		scale: Math.min( 1.5, Math.max( 0.1, kept?.scale ?? 1 ) ),
		// Photoshop draws a colour overlay over a gradient overlay.
		paint:
			overlay === undefined && active !== undefined
				? gradient
				: { type: 'solid', color },
		gradient,
	};
}

/**
 * Whether a gradient that is switched on can be drawn.
 *
 * @param effect The gradient overlay.
 */
function drawableGradient( effect: LayerEffectGradientOverlay ): boolean {
	const { gradient } = effect;

	return (
		( effect.type ?? 'linear' ) === 'linear' &&
		( effect.blendMode ?? 'normal' ) === 'normal' &&
		( effect.opacity ?? 1 ) === 1 &&
		effect.align !== false &&
		( effect.offset?.x ?? 0 ) === 0 &&
		( effect.offset?.y ?? 0 ) === 0 &&
		gradient?.type === 'solid' &&
		gradient.opacityStops.every( ( stop ) => stop.opacity === 1 )
	);
}

/**
 * What a creator's gradient starts as: the file's first and last colour.
 *
 * @param effect The file's gradient overlay.
 * @param color  The part's colour.
 * @param name   The layer's name, for the message.
 */
function gradientStart(
	effect: LayerEffectGradientOverlay | undefined,
	color: string,
	name: string
): GradientPaint {
	const gradient = effect?.gradient;
	const angle = ( ( Math.round( effect?.angle ?? 90 ) % 360 ) + 360 ) % 360;

	if ( gradient?.type === 'solid' ) {
		const stops = [ ...gradient.colorStops ].sort(
			( one, other ) => one.location - other.location
		);
		const first = stops[ 0 ];
		const last = stops[ stops.length - 1 ];

		if ( first !== undefined && last !== undefined ) {
			const from = hexOf( first.color, name );
			const to = hexOf( last.color, name );

			return effect?.reverse === true
				? { type: 'gradient', from: to, to: from, angle }
				: { type: 'gradient', from, to, angle };
		}
	}

	return {
		type: 'gradient',
		from: color,
		to: color === '#000000' ? '#ffffff' : '#000000',
		angle,
	};
}

/**
 * 8-bit pixels, or null without any.
 *
 * @param source ag-psd's pixels.
 * @param name   The layer's name, for the message.
 */
function pixelsOf( source: PixelData | undefined, name: string ): Rgba | null {
	if ( source === undefined || source.width === 0 || source.height === 0 ) {
		return null;
	}

	const { data } = source;

	if ( data instanceof Uint8ClampedArray ) {
		return { width: source.width, height: source.height, data };
	}

	if ( data instanceof Uint8Array ) {
		return {
			width: source.width,
			height: source.height,
			data: new Uint8ClampedArray(
				data.buffer,
				data.byteOffset,
				data.byteLength
			),
		};
	}

	throw new ClipartError( 'depth', name );
}

/**
 * The box of the visible pixels, or null without any.
 *
 * @param image The image.
 */
export function visibleBox( image: Rgba ): ClipartRect | null {
	let left = image.width;
	let top = image.height;
	let right = -1;
	let bottom = -1;

	for ( let y = 0; y < image.height; y++ ) {
		for ( let x = 0; x < image.width; x++ ) {
			if ( ( image.data[ ( y * image.width + x ) * 4 + 3 ] ?? 0 ) > 0 ) {
				left = Math.min( left, x );
				right = Math.max( right, x );
				top = Math.min( top, y );
				bottom = y;
			}
		}
	}

	return right < 0
		? null
		: {
				x: left,
				y: top,
				width: right - left + 1,
				height: bottom - top + 1,
			};
}

/**
 * The colour most of a layer has: its colour without an overlay.
 *
 * @param image The layer's pixels.
 */
function commonColor( image: Rgba ): string {
	const counts = new Map< number, number >();
	const { data } = image;
	let best = 0;
	let most = 0;

	for ( let at = 0; at < data.length; at += 4 ) {
		if ( ( data[ at + 3 ] ?? 0 ) < 128 ) {
			continue;
		}

		const color =
			( data[ at ] as number ) * 65536 +
			( data[ at + 1 ] as number ) * 256 +
			( data[ at + 2 ] as number );
		const count = ( counts.get( color ) ?? 0 ) + 1;

		counts.set( color, count );

		if ( count > most ) {
			most = count;
			best = color;
		}
	}

	return `#${ best.toString( 16 ).padStart( 6, '0' ) }`;
}
