/**
 * Sends a saved draft for approval (phase 30, step 30.9, ADR-080 pkt. 5):
 * the browser renders one transparent PNG print file per used side (a JPEG
 * on white on a flat side) in the side's print size and one JPEG mockup per
 * used side and chosen colour in
 * the template's size, sends them one by one (each request stays small) and
 * then submits. The server checks the exact sizes and that the files belong
 * to the saved draft. An embroidered side sends a close-up per colour too
 * (ADR-085).
 */
import { submitDesign, uploadSubmissionFile } from '../../api/designer';
import type {
	DesignerBase,
	DesignerPreview,
	DesignerProduct,
	DesignerView,
	LayerKind,
} from '../../api/designer';
import { coversSizes, objectsOf, usedSides } from './design-model';
import type { DesignDocument } from './design-model';
import {
	closeBox,
	composeMockup,
	composeScene,
	flatJpegOf,
	pngOf,
} from './mockup';
import { threadCanvas, threadSide } from './threads';

export interface SubmissionInput {
	readonly product: number;
	readonly base: DesignerBase;
	/** The saved design and colours (the draft the files belong to). */
	readonly design: DesignDocument;
	readonly colors: readonly string[];
	/** The saved sizes. */
	readonly sizes: readonly string[];
	/** A file as a `data:` URL (template layers and images), or null. */
	readonly image: ( id: number ) => Promise< string | null >;
	/** Resolves when the design's fonts are ready to draw. */
	readonly fonts: () => Promise< void >;
}

export type SubmissionResult =
	| { readonly ok: true; readonly product: DesignerProduct }
	| { readonly ok: false; readonly reason: string };

export type UploadResult =
	{ readonly ok: true } | { readonly ok: false; readonly reason: string };

/**
 * The number of files a design sends.
 *
 * @param design The design.
 * @param colors The chosen colours.
 * @param views  The sides of the base product.
 */
export function submissionSize(
	design: DesignDocument,
	colors: readonly string[],
	views: readonly DesignerView[]
): number {
	const sides = usedSides( design );
	const closeUps = views.filter(
		( view ) => sides.includes( view.key ) && hasStitches( view )
	).length;

	return sides.length * ( 1 + colors.length ) + closeUps * colors.length;
}

/**
 * Whether a side's images show stitches. A PSD scene draws its own product.
 *
 * @param view The side.
 */
export function hasStitches( view: DesignerView ): boolean {
	return view.embroidery?.stitched === true && view.scene === null;
}

/**
 * The key a side's close-ups are stored under, among the mockups.
 *
 * @param side The side.
 */
function closeKey( side: string ): string {
	return `_close-${ side }`;
}

/**
 * The template layers of a side, or null when one cannot be read.
 *
 * @param input The draft.
 * @param view  The side.
 */
async function layersOf(
	input: SubmissionInput,
	view: DesignerView
): Promise< Partial< Record< LayerKind, string > > | null > {
	const layers: Partial< Record< LayerKind, string > > = {};

	for ( const [ kind, id ] of Object.entries( view.layers ) as [
		LayerKind,
		number,
	][] ) {
		const url = await input.image( id );

		if ( url === null ) {
			return null;
		}

		layers[ kind ] = url;
	}

	return layers;
}

/**
 * A side's layers in a colour: its own background replaces the background
 * and the tinted mask. Null when that background cannot be read.
 *
 * @param input    The draft.
 * @param view     The side.
 * @param layers   The side's layers.
 * @param colorKey The colour.
 */
async function colorLayersOf(
	input: SubmissionInput,
	view: DesignerView,
	layers: Partial< Record< LayerKind, string > >,
	colorKey: string
): Promise< Partial< Record< LayerKind, string > > | null > {
	const own = view.backgrounds[ colorKey ];
	const ownUrl = own === undefined ? null : await input.image( own );

	if ( own !== undefined && ownUrl === null ) {
		return null;
	}

	if ( ownUrl === null ) {
		return layers;
	}

	const colorLayers = { ...layers, background: ownUrl };

	delete colorLayers.color;

	return colorLayers;
}

/**
 * Renders and sends the files, then submits.
 *
 * @param input    The draft.
 * @param progress Called after each file with the files sent and their total.
 */
export async function submitWithFiles(
	input: SubmissionInput,
	progress: ( done: number, total: number ) => void
): Promise< SubmissionResult > {
	const uploaded = await uploadFiles( input, progress );

	if ( ! uploaded.ok ) {
		return uploaded;
	}

	const submitted = await submitDesign( input.product );

	return submitted.ok
		? { ok: true, product: submitted.data }
		: { ok: false, reason: submitted.error.reason ?? 'submit' };
}

/**
 * A side's print file, or null when an image cannot be read.
 *
 * @param input The draft.
 * @param view  The side.
 */
async function printOf(
	input: SubmissionInput,
	view: DesignerView
): Promise< HTMLCanvasElement | null > {
	const { renderPrintFile } = await import( './fabric-stage' );
	const objects = objectsOf( input.design, view.key );
	const images = new Map< number, string >();

	for ( const object of objects ) {
		if ( object.type === 'image' && ! images.has( object.asset ) ) {
			const url = await input.image( object.asset );

			if ( url === null ) {
				return null;
			}

			images.set( object.asset, url );
		}
	}

	const threaded =
		view.embroidery === null
			? null
			: await threadSide(
					objects,
					images,
					input.design.threads?.[ view.key ] ?? {},
					view.embroidery
				);
	const print = await renderPrintFile(
		threaded?.objects ?? objects,
		threaded?.images ?? images,
		view.printWidth,
		view.printHeight
	);

	if ( threaded !== null ) {
		threadCanvas( print, threaded.plan );
	}

	return print;
}

/**
 * A file as a `data:` URL: the dashboard's CSP takes no other.
 *
 * @param file The file.
 */
function dataUrl( file: Blob ): Promise< string | null > {
	return new Promise( ( resolve ) => {
		const reader = new FileReader();

		reader.onload = () =>
			resolve( typeof reader.result === 'string' ? reader.result : null );
		reader.onerror = () => resolve( null );
		reader.readAsDataURL( file );
	} );
}

/**
 * The images of a stitched side in a colour, as `data:` URLs: the product,
 * and the design close up. Null when one cannot be drawn.
 *
 * @param input    The design as it stands.
 * @param view     The side.
 * @param print    Its print file.
 * @param colorKey The colour.
 */
async function stitchedImages(
	input: SubmissionInput,
	view: DesignerView,
	print: HTMLCanvasElement,
	colorKey: string
): Promise< DesignerPreview[ 'images' ] | null > {
	const color = input.base.colors.find(
		( candidate ) => candidate.key === colorKey
	);
	const layers = await layersOf( input, view );
	const own =
		layers === null
			? null
			: await colorLayersOf( input, view, layers, colorKey );

	if ( color === undefined || own === null ) {
		return null;
	}

	const whole = await composeMockup( view, own, color.hex, print );
	const box = closeBox( view, print );
	const close =
		box === null
			? null
			: await composeMockup( view, own, color.hex, print, box );
	const url = whole === null ? null : await dataUrl( whole );
	const closeUrl = close === null ? null : await dataUrl( close );

	if ( url === null ) {
		return null;
	}

	return [
		{ side: view.key, color: colorKey, url },
		...( closeUrl === null
			? []
			: [
					{
						side: closeKey( view.key ),
						color: colorKey,
						url: closeUrl,
					},
				] ),
	];
}

/**
 * The mockups a creator can see while designing: drawn here from the side's
 * PSD scene or its stitches, so nothing is sent. Null when one cannot be drawn.
 *
 * @param input The design as it stands.
 */
export async function sceneImages(
	input: SubmissionInput
): Promise< DesignerPreview[ 'images' ] | null > {
	const sides = usedSides( input.design );
	const shown: { side: string; color: string; url: string }[] = [];

	await input.fonts();

	for ( const view of input.base.views ) {
		const first = input.colors[ 0 ];

		if ( sides.includes( view.key ) && hasStitches( view ) ) {
			const print = await printOf( input, view );
			const images =
				print === null || first === undefined
					? null
					: await stitchedImages( input, view, print, first );

			if ( images === null ) {
				return null;
			}

			shown.push( ...images );
			continue;
		}

		if ( view.scene?.preview !== true || ! sides.includes( view.key ) ) {
			continue;
		}

		const atlas = await input.image( view.scene.asset );
		const print = await printOf( input, view );
		const mockup =
			atlas === null || print === null
				? null
				: await composeScene( view.scene, atlas, print );
		const url = mockup === null ? null : await dataUrl( mockup );

		if ( url === null ) {
			return null;
		}

		shown.push( { side: view.key, color: input.colors[ 0 ] ?? '', url } );
	}

	return shown;
}

/**
 * Renders and sends the files of the draft, for a submission or a preview.
 *
 * @param input    The draft.
 * @param progress Called after each file with the files sent and their total.
 */
export async function uploadFiles(
	input: SubmissionInput,
	progress: ( done: number, total: number ) => void
): Promise< UploadResult > {
	const sides = usedSides( input.design );

	if ( sides.length === 0 ) {
		return { ok: false, reason: 'design_empty' };
	}

	if ( ! coversSizes( input.base.views, input.design, input.sizes ) ) {
		return { ok: false, reason: 'design_sizes' };
	}

	let done = 0;

	await input.fonts();

	for ( const key of sides ) {
		const view = input.base.views.find(
			( candidate ) => candidate.key === key
		);

		if ( view === undefined ) {
			return { ok: false, reason: 'design_stale' };
		}

		const print = await printOf( input, view );

		if ( print === null ) {
			return { ok: false, reason: 'render' };
		}

		const layers = await layersOf( input, view );

		if ( layers === null ) {
			return { ok: false, reason: 'render' };
		}

		const total = submissionSize(
			input.design,
			input.colors,
			input.base.views
		);
		const file = await ( view.flat ? flatJpegOf( print ) : pngOf( print ) );

		if ( file === null ) {
			return { ok: false, reason: 'render' };
		}

		const sent = await uploadSubmissionFile(
			input.product,
			`print:${ key }`,
			file,
			`print-${ key }.${ view.flat ? 'jpg' : 'png' }`
		);

		if ( ! sent.ok ) {
			return { ok: false, reason: sent.error.reason ?? 'upload' };
		}

		progress( ++done, total );

		// A PSD scene draws the product as it is: the same mockup for each colour.
		const sceneUrl =
			view.scene === null ? null : await input.image( view.scene.asset );
		const sceneMockup =
			view.scene === null || sceneUrl === null
				? null
				: await composeScene( view.scene, sceneUrl, print );

		if ( view.scene !== null && sceneMockup === null ) {
			return { ok: false, reason: 'render' };
		}

		for ( const colorKey of input.colors ) {
			const color = input.base.colors.find(
				( candidate ) => candidate.key === colorKey
			);
			const colorLayers = await colorLayersOf(
				input,
				view,
				layers,
				colorKey
			);
			const mockup =
				sceneMockup ??
				( color === undefined || colorLayers === null
					? null
					: await composeMockup(
							view,
							colorLayers,
							color.hex,
							print
						) );

			if ( mockup === null ) {
				return { ok: false, reason: 'render' };
			}

			const result = await uploadSubmissionFile(
				input.product,
				`mockup:${ key }:${ colorKey }`,
				mockup,
				`mockup-${ key }.jpg`
			);

			if ( ! result.ok ) {
				return { ok: false, reason: result.error.reason ?? 'upload' };
			}

			progress( ++done, total );

			if ( ! hasStitches( view ) ) {
				continue;
			}

			// Nothing on the print shows no close-up; the design is still sent.
			const box = closeBox( view, print );
			const close =
				box === null || color === undefined || colorLayers === null
					? null
					: await composeMockup(
							view,
							colorLayers,
							color.hex,
							print,
							box
						);

			if ( close !== null ) {
				const closeResult = await uploadSubmissionFile(
					input.product,
					`mockup:${ closeKey( key ) }:${ colorKey }`,
					close,
					`close-${ key }.jpg`
				);

				if ( ! closeResult.ok ) {
					return {
						ok: false,
						reason: closeResult.error.reason ?? 'upload',
					};
				}
			}

			progress( ++done, total );
		}
	}

	return { ok: true };
}
