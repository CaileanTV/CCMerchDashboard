/**
 * A product image of the submission (phase 30, step 30.9, ADR-080 pkt. 5):
 * the side's mockup template in one product colour with the print file on
 * its print area, as the editor shows it. The background and the colour mask
 * (tinted in the colour) lie under the design; the shading is multiplied and
 * the highlight screened over it. A plain 2D canvas: no Fabric.
 */
import type { DesignerView, LayerKind } from '../../api/designer';

/** JPEG quality of a mockup. */
const QUALITY = 0.92;

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
 * A side's mockup in one colour as a JPEG, or null when it cannot be drawn.
 *
 * @param view   The side (template size, layers and print area).
 * @param layers `data:` URL of each template layer the side has.
 * @param color  The product colour (`#rrggbb`).
 * @param print  The side's print file.
 */
export async function composeMockup(
	view: DesignerView,
	layers: Partial< Record< LayerKind, string > >,
	color: string,
	print: HTMLCanvasElement
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

	const target = surface( view.width, view.height );

	if ( target === null ) {
		return null;
	}

	const { canvas, context } = target;
	// A shop image has no transparency (JPEG): white where the template has none.
	context.fillStyle = '#ffffff';
	context.fillRect( 0, 0, view.width, view.height );

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
	context.drawImage(
		print,
		view.area.x,
		view.area.y,
		view.printWidth * scale,
		view.printHeight * scale
	);

	if ( images.shading !== undefined ) {
		context.globalCompositeOperation = 'multiply';
		context.drawImage( images.shading, 0, 0, view.width, view.height );
	}

	if ( images.highlight !== undefined ) {
		context.globalCompositeOperation = 'screen';
		context.drawImage( images.highlight, 0, 0, view.width, view.height );
	}

	context.globalCompositeOperation = 'source-over';

	return new Promise( ( resolve ) =>
		canvas.toBlob( resolve, 'image/jpeg', QUALITY )
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
