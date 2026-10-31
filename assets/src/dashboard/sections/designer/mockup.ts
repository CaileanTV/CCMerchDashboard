/**
 * A product image of the submission (phase 30, step 30.9, ADR-080 pkt. 5):
 * the side's mockup template in one product colour with the print file on
 * its print area, as the editor shows it. The background and the colour mask
 * (tinted in the colour) lie under the design; the shading is multiplied and
 * the highlight screened over it. A plain 2D canvas: no Fabric. An embroidered
 * side shows thread instead of the print file (ADR-085), and it has a close-up
 * too: the same image around the design, in more pixels.
 */
import type { DesignerView, LayerKind } from '../../api/designer';
import { stitched, stitchMargin } from './embroidery';
import { renderScene } from './scene';
import type { Pixels, Scene, SceneRect } from './scene';

/** JPEG quality of a mockup. */
const QUALITY = 0.92;

/** JPEG quality of a flat side's print file. */
const FLAT_QUALITY = 0.95;

/** The side of a close-up, as the server expects it. */
export const CLOSE_SIZE = 1200;

/** The room around a design on its close-up. */
const CLOSE_ROOM = 1.3;

/** Pixels per millimetre a close-up never passes: it would show the photo's. */
const CLOSE_PX_PER_MM = 12;

/** The tile of stitched surface, once loaded. */
let tile: Pixels | null = null;

/**
 * An image of a `data:` URL, or null.
 *
 * @param url `data:` URL.
 */
function load( url: string ): Promise< HTMLImageElement | null > {
	return new Promise( ( resolve ) => {
		const image = new Image();
		image.onload = () => resolve( image );
		image.onerror = () => resolve( null );
		image.src = url;
	} );
}

/**
 * A canvas of a size with its 2D context, or null.
 *
 * @param width  Width.
 * @param height Height.
 */
function surface(
	width: number,
	height: number
): { canvas: HTMLCanvasElement; context: CanvasRenderingContext2D } | null {
	const canvas = document.createElement( 'canvas' );
	canvas.width = width;
	canvas.height = height;
	const context = canvas.getContext( '2d' );

	return context === null ? null : { canvas, context };
}

/**
 * The tile of stitched surface, or null. Its large file loads only when an
 * embroidered side is drawn.
 */
async function stitchTile(): Promise< Pixels | null > {
	if ( tile !== null ) {
		return tile;
	}

	const { STITCH_SURFACE } = await import( './stitch-surface' );
	const image = await load( STITCH_SURFACE );
	const target =
		image === null
			? null
			: surface( image.naturalWidth, image.naturalHeight );

	if ( image === null || target === null ) {
		return null;
	}

	target.context.drawImage( image, 0, 0 );
	tile = target.context.getImageData(
		0,
		0,
		target.canvas.width,
		target.canvas.height
	);

	return tile;
}

/**
 * The thread of a side as a canvas with a margin around the print.
 *
 * @param view  The side.
 * @param print The print file.
 * @param zoom  Pixels of the result per pixel of the template.
 */
async function threadLayer(
	view: DesignerView,
	print: HTMLCanvasElement,
	zoom: number
): Promise< { canvas: HTMLCanvasElement; margin: number } | null > {
	const { STITCH_PX_PER_MM } = await import( './stitch-surface' );
	const surfaceTile = await stitchTile();
	const scale = ( view.area.width / view.printWidth ) * zoom;
	const pxPerMm = ( scale * view.dpi ) / 25.4;
	const margin = stitchMargin( pxPerMm );
	const width = view.printWidth * scale;
	const height = view.printHeight * scale;
	const target = surface(
		Math.ceil( width ) + margin * 2,
		Math.ceil( height ) + margin * 2
	);

	if ( surfaceTile === null || target === null ) {
		return null;
	}

	const { canvas, context } = target;

	context.imageSmoothingQuality = 'high';
	context.drawImage( print, margin, margin, width, height );

	const thread = stitched(
		context.getImageData( 0, 0, canvas.width, canvas.height ),
		surfaceTile,
		STITCH_PX_PER_MM,
		pxPerMm
	);
	const result = context.createImageData( canvas.width, canvas.height );

	result.data.set( thread.data );
	context.putImageData( result, 0, 0 );

	return { canvas, margin };
}

/**
 * The part of a side's template a close-up shows: a square around the design,
 * or null when nothing shows on the print.
 *
 * @param view  The side.
 * @param print The print file.
 */
export function closeBox(
	view: DesignerView,
	print: HTMLCanvasElement
): SceneRect | null {
	const context = print.getContext( '2d', { willReadFrequently: true } );

	if ( context === null ) {
		return null;
	}

	const { data } = context.getImageData( 0, 0, print.width, print.height );
	let left = print.width;
	let right = -1;
	let top = print.height;
	let bottom = -1;

	for ( let y = 0; y < print.height; y++ ) {
		for ( let x = 0; x < print.width; x++ ) {
			if ( ( data[ ( y * print.width + x ) * 4 + 3 ] as number ) > 127 ) {
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

	const scale = view.area.width / view.printWidth;
	const perMm = ( scale * view.dpi ) / 25.4;
	const side = Math.min(
		view.width,
		view.height,
		Math.max(
			Math.max( right - left, bottom - top ) * scale * CLOSE_ROOM,
			( CLOSE_SIZE / CLOSE_PX_PER_MM ) * perMm
		)
	);
	const x = view.area.x + ( ( left + right ) / 2 ) * scale - side / 2;
	const y = view.area.y + ( ( top + bottom ) / 2 ) * scale - side / 2;

	return {
		x: Math.max( 0, Math.min( view.width - side, x ) ),
		y: Math.max( 0, Math.min( view.height - side, y ) ),
		width: side,
		height: side,
	};
}

/**
 * A side's mockup in one colour as a JPEG, or null when it cannot be drawn.
 *
 * @param view   The side (template size, layers and print area).
 * @param layers `data:` URL of each template layer the side has.
 * @param color  The product colour (`#rrggbb`).
 * @param print  The side's print file.
 * @param box    The part a close-up shows; null for the whole template.
 */
export async function composeMockup(
	view: DesignerView,
	layers: Partial< Record< LayerKind, string > >,
	color: string,
	print: HTMLCanvasElement,
	box: SceneRect | null = null
): Promise< Blob | null > {
	const images: Partial< Record< LayerKind, HTMLImageElement > > = {};

	for ( const [ kind, url ] of Object.entries( layers ) as [
		LayerKind,
		string,
	][] ) {
		const image = await load( url );

		if ( image === null ) {
			return null;
		}

		images[ kind ] = image;
	}

	const target =
		box === null
			? surface( view.width, view.height )
			: surface( CLOSE_SIZE, CLOSE_SIZE );

	if ( target === null ) {
		return null;
	}

	const { canvas, context } = target;
	const zoom = box === null ? 1 : CLOSE_SIZE / box.width;
	const left = box?.x ?? 0;
	const top = box?.y ?? 0;

	// A shop image has no transparency (JPEG): white where the template has none.
	context.fillStyle = '#ffffff';
	context.fillRect( 0, 0, canvas.width, canvas.height );
	context.imageSmoothingQuality = 'high';
	context.setTransform( zoom, 0, 0, zoom, -left * zoom, -top * zoom );

	if ( images.background !== undefined ) {
		context.drawImage( images.background, 0, 0, view.width, view.height );
	}

	if ( images.color !== undefined ) {
		const mask = surface( view.width, view.height );

		if ( mask === null ) {
			return null;
		}

		mask.context.drawImage( images.color, 0, 0, view.width, view.height );
		mask.context.globalCompositeOperation = 'source-in';
		mask.context.fillStyle = color;
		mask.context.fillRect( 0, 0, view.width, view.height );
		context.drawImage( mask.canvas, 0, 0 );
	}

	// The print file on the print area at the editor's scale (by width).
	const scale = view.area.width / view.printWidth;

	if ( view.embroidery?.stitched === true ) {
		const thread = await threadLayer( view, print, zoom );

		if ( thread === null ) {
			return null;
		}

		context.setTransform( 1, 0, 0, 1, 0, 0 );
		context.drawImage(
			thread.canvas,
			( view.area.x - left ) * zoom - thread.margin,
			( view.area.y - top ) * zoom - thread.margin
		);
		context.setTransform( zoom, 0, 0, zoom, -left * zoom, -top * zoom );
	} else {
		context.drawImage(
			print,
			view.area.x,
			view.area.y,
			view.printWidth * scale,
			view.printHeight * scale
		);
	}

	if ( images.shading !== undefined ) {
		// A flat side's sewing pattern lies on top as it is.
		context.globalCompositeOperation = view.flat
			? 'source-over'
			: 'multiply';
		context.drawImage( images.shading, 0, 0, view.width, view.height );
	}

	if ( images.highlight !== undefined ) {
		context.globalCompositeOperation = 'screen';
		context.drawImage( images.highlight, 0, 0, view.width, view.height );
	}

	context.globalCompositeOperation = 'source-over';
	context.setTransform( 1, 0, 0, 1, 0, 0 );

	return new Promise( ( resolve ) =>
		canvas.toBlob( resolve, 'image/jpeg', QUALITY )
	);
}

/**
 * The mockup of a PSD scene as a JPEG, or null when it cannot be drawn.
 *
 * @param scene The scene.
 * @param url   `data:` URL of its image.
 * @param print The print file.
 */
export async function composeScene(
	scene: Scene,
	url: string,
	print: HTMLCanvasElement
): Promise< Blob | null > {
	const image = await load( url );
	const atlas =
		image === null
			? null
			: surface( image.naturalWidth, image.naturalHeight );
	const printContext = print.getContext( '2d', { willReadFrequently: true } );
	const target = surface( scene.width, scene.height );

	if (
		image === null ||
		atlas === null ||
		printContext === null ||
		target === null
	) {
		return null;
	}

	atlas.context.drawImage( image, 0, 0 );

	const drawn = renderScene(
		scene,
		atlas.context.getImageData(
			0,
			0,
			atlas.canvas.width,
			atlas.canvas.height
		),
		printContext.getImageData( 0, 0, print.width, print.height )
	);
	const result = target.context.createImageData( scene.width, scene.height );

	result.data.set( drawn.data );
	target.context.putImageData( result, 0, 0 );

	return new Promise( ( resolve ) =>
		target.canvas.toBlob( resolve, 'image/jpeg', QUALITY )
	);
}

/**
 * A canvas as a PNG, or null.
 *
 * @param canvas Canvas.
 */
export function pngOf( canvas: HTMLCanvasElement ): Promise< Blob | null > {
	return new Promise( ( resolve ) => canvas.toBlob( resolve, 'image/png' ) );
}

/**
 * A flat side's print file: a JPEG on white. An all-over print has no
 * transparency, and a PNG of a photo this large passes the file limit.
 *
 * @param canvas The print file.
 */
export function flatJpegOf(
	canvas: HTMLCanvasElement
): Promise< Blob | null > {
	const target = surface( canvas.width, canvas.height );

	if ( target === null ) {
		return Promise.resolve( null );
	}

	target.context.fillStyle = '#ffffff';
	target.context.fillRect( 0, 0, canvas.width, canvas.height );
	target.context.drawImage( canvas, 0, 0 );

	return new Promise( ( resolve ) =>
		target.canvas.toBlob( resolve, 'image/jpeg', FLAT_QUALITY )
	);
}
