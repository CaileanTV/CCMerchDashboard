/**
 * A mockup template from a PSD (phase 30, step 30.7, ADR-080 pkt. 2): the
 * administrator's browser reads the PSD with ag-psd (MIT); the PSD itself is
 * never uploaded. The layers named by the convention become full-size RGBA
 * images of the document, which the base products form uploads as PNG
 * layers, and `mb_print_area` gives the print area.
 *
 * - `mb_background`, `mb_color` (the colour mask), `mb_shading` and
 *   `mb_highlight` are found anywhere in the layer tree, by name (trimmed,
 *   any case), hidden or not; each may appear only once.
 * - `mb_print_area` is a rectangle drawn on its own layer; the print area is
 *   the box of its visible pixels.
 * - Only 8 bits per channel and at most 8000 × 8000 px, as the server
 *   accepts. A layer's opacity is applied; blend modes and effects are not
 *   (the designer draws shading multiplied and highlights screened).
 */
import { readPsd } from 'ag-psd';
import type { Layer, Psd } from 'ag-psd';

/** The template layers, in the order the designer draws them. */
export const LAYERS = [
	'background',
	'color',
	'shading',
	'highlight',
] as const;

export type LayerKind = ( typeof LAYERS )[ number ];

/** Largest width and height, as the server accepts. */
export const MAX_PIXELS = 8000;

/** The layer name of the print area. */
export const PRINT_AREA_LAYER = 'mb_print_area';

export interface Rgba {
	readonly width: number;
	readonly height: number;
	readonly data: Uint8ClampedArray;
}

export interface PrintArea {
	readonly x: number;
	readonly y: number;
	readonly width: number;
	readonly height: number;
}

export interface PsdTemplate {
	readonly width: number;
	readonly height: number;
	readonly layers: Partial< Record< LayerKind, Rgba > >;
	readonly area: PrintArea | null;
}

export type TemplateErrorCode =
	'unreadable' | 'depth' | 'size' | 'no_layers' | 'duplicate' | 'empty_area';

/**
 * A PSD that cannot become a template, with a code for the message.
 */
export class TemplateError extends Error {
	public readonly code: TemplateErrorCode;

	public readonly detail: string;

	/**
	 * Constructor.
	 *
	 * @param code   Why.
	 * @param detail A layer name, when one is the reason.
	 */
	public constructor( code: TemplateErrorCode, detail = '' ) {
		super( `PSD template refused: ${ code }` );
		this.code = code;
		this.detail = detail;
	}
}

/**
 * The template of a PSD file's bytes.
 *
 * @param buffer The PSD.
 */
export function readTemplate( buffer: ArrayBuffer ): PsdTemplate {
	let psd: Psd;

	try {
		psd = readPsd( buffer, {
			useImageData: true,
			skipCompositeImageData: true,
			skipThumbnail: true,
			skipLinkedFilesData: true,
		} );
	} catch {
		throw new TemplateError( 'unreadable' );
	}

	return templateFromPsd( psd );
}

/**
 * The template of a read PSD.
 *
 * @param psd The PSD as ag-psd reads it.
 */
export function templateFromPsd( psd: Psd ): PsdTemplate {
	if ( undefined !== psd.bitsPerChannel && 8 !== psd.bitsPerChannel ) {
		throw new TemplateError( 'depth' );
	}

	const { width, height } = psd;

	if (
		! Number.isInteger( width ) ||
		! Number.isInteger( height ) ||
		width < 1 ||
		height < 1 ||
		width > MAX_PIXELS ||
		height > MAX_PIXELS
	) {
		throw new TemplateError( 'size' );
	}

	const named = conventionLayers( psd.children ?? [] );
	const layers: Partial< Record< LayerKind, Rgba > > = {};

	for ( const kind of LAYERS ) {
		const layer = named.get( `mb_${ kind }` );

		if ( undefined !== layer ) {
			layers[ kind ] = place( layer, width, height );
		}
	}

	if ( undefined === layers.background && undefined === layers.color ) {
		throw new TemplateError( 'no_layers' );
	}

	const areaLayer = named.get( PRINT_AREA_LAYER );

	return {
		width,
		height,
		layers,
		area:
			undefined === areaLayer
				? null
				: printArea( place( areaLayer, width, height ) ),
	};
}

/**
 * The layers of the convention by lower-case name, from the whole tree.
 *
 * @param children Layers and groups.
 */
function conventionLayers( children: Layer[] ): Map< string, Layer > {
	const names = new Set< string >( [
		...LAYERS.map( ( kind ) => `mb_${ kind }` ),
		PRINT_AREA_LAYER,
	] );
	const found = new Map< string, Layer >();
	const visit = ( layers: Layer[] ): void => {
		for ( const layer of layers ) {
			const name = ( layer.name ?? '' ).trim().toLowerCase();

			if ( names.has( name ) ) {
				if ( found.has( name ) ) {
					throw new TemplateError( 'duplicate', name );
				}

				found.set( name, layer );
			}

			if ( undefined !== layer.children ) {
				visit( layer.children );
			}
		}
	};

	visit( children );

	return found;
}

/**
 * A layer as a full-size RGBA image of the document, with its opacity.
 *
 * @param layer  The layer.
 * @param width  Document width.
 * @param height Document height.
 */
function place( layer: Layer, width: number, height: number ): Rgba {
	const data = new Uint8ClampedArray( width * height * 4 );
	const source = layer.imageData;

	if ( undefined === source || 0 === source.width || 0 === source.height ) {
		return { width, height, data };
	}

	if ( ! (
		source.data instanceof Uint8ClampedArray ||
		source.data instanceof Uint8Array
	) ) {
		throw new TemplateError( 'depth' );
	}

	const left = layer.left ?? 0;
	const top = layer.top ?? 0;
	const opacity = Math.min( 1, Math.max( 0, layer.opacity ?? 1 ) );
	const from = Math.max( 0, -left );
	const to = Math.min( source.width, width - left );

	for ( let y = 0; y < source.height; y++ ) {
		const row = top + y;

		if ( row < 0 || row >= height || from >= to ) {
			continue;
		}

		for ( let x = from; x < to; x++ ) {
			const read = ( y * source.width + x ) * 4;
			const write = ( row * width + left + x ) * 4;

			data.set( source.data.subarray( read, read + 3 ), write );
			data[ write + 3 ] = Math.round(
				( source.data[ read + 3 ] ?? 0 ) * opacity
			);
		}
	}

	return { width, height, data };
}

/**
 * The box of an image's visible pixels.
 *
 * @param image Full-size image.
 */
function printArea( image: Rgba ): PrintArea {
	let minX = image.width;
	let minY = image.height;
	let maxX = -1;
	let maxY = -1;

	for ( let y = 0; y < image.height; y++ ) {
		for ( let x = 0; x < image.width; x++ ) {
			if ( ( image.data[ ( y * image.width + x ) * 4 + 3 ] ?? 0 ) > 0 ) {
				minX = Math.min( minX, x );
				minY = Math.min( minY, y );
				maxX = Math.max( maxX, x );
				maxY = Math.max( maxY, y );
			}
		}
	}

	if ( maxX < 0 ) {
		throw new TemplateError( 'empty_area' );
	}

	return {
		x: minX,
		y: minY,
		width: maxX - minX + 1,
		height: maxY - minY + 1,
	};
}
