/**
 * Sends a saved draft for approval (phase 30, step 30.9, ADR-080 pkt. 5):
 * the browser renders one transparent PNG print file per used side in the
 * side's print size and one JPEG mockup per used side and chosen colour in
 * the template's size, sends them one by one (each request stays small) and
 * then submits. The server checks the exact sizes and that the files belong
 * to the saved draft.
 */
import { submitDesign, uploadSubmissionFile } from '../../api/designer';
import type {
	DesignerBase,
	DesignerProduct,
	LayerKind,
} from '../../api/designer';
import { objectsOf, usedSides } from './design-model';
import type { DesignDocument } from './design-model';
import { composeMockup, pngOf } from './mockup';

export interface SubmissionInput {
	readonly product: number;
	readonly base: DesignerBase;
	/** The saved design and colours (the draft the files belong to). */
	readonly design: DesignDocument;
	readonly colors: readonly string[];
	/** A file as a `data:` URL (template layers and images), or null. */
	readonly image: ( id: number ) => Promise< string | null >;
	/** Resolves when the design's fonts are ready to draw. */
	readonly fonts: () => Promise< void >;
}

export type SubmissionResult =
	| { readonly ok: true; readonly product: DesignerProduct }
	| { readonly ok: false; readonly reason: string };

/**
 * The number of files a design sends.
 *
 * @param design The design.
 * @param colors The chosen colours.
 */
export function submissionSize(
	design: DesignDocument,
	colors: readonly string[]
): number {
	return usedSides( design ).length * ( 1 + colors.length );
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
	const sides = usedSides( input.design );

	if ( sides.length === 0 ) {
		return { ok: false, reason: 'design_empty' };
	}

	let done = 0;

	await input.fonts();
	const { renderPrintFile } = await import( './fabric-stage' );

	for ( const key of sides ) {
		const view = input.base.views.find(
			( candidate ) => candidate.key === key
		);

		if ( view === undefined ) {
			return { ok: false, reason: 'design_stale' };
		}

		const objects = objectsOf( input.design, key );
		const images = new Map< number, string >();

		for ( const object of objects ) {
			if ( object.type === 'image' && ! images.has( object.asset ) ) {
				const url = await input.image( object.asset );

				if ( url === null ) {
					return { ok: false, reason: 'render' };
				}

				images.set( object.asset, url );
			}
		}

		const layers: Partial< Record< LayerKind, string > > = {};

		for ( const [ kind, id ] of Object.entries( view.layers ) as [
			LayerKind,
			number,
		][] ) {
			const url = await input.image( id );

			if ( url === null ) {
				return { ok: false, reason: 'render' };
			}

			layers[ kind ] = url;
		}

		const print = await renderPrintFile(
			objects,
			images,
			view.printWidth,
			view.printHeight
		);
		const png = await pngOf( print );

		if ( png === null ) {
			return { ok: false, reason: 'render' };
		}

		const sent = await uploadSubmissionFile(
			input.product,
			`print:${ key }`,
			png,
			`print-${ key }.png`
		);

		if ( ! sent.ok ) {
			return { ok: false, reason: sent.error.reason ?? 'upload' };
		}

		progress( ++done, submissionSize( input.design, input.colors ) );

		for ( const colorKey of input.colors ) {
			const color = input.base.colors.find(
				( candidate ) => candidate.key === colorKey
			);
			// The colour's own background replaces the background and the tinted mask.
			const own = view.backgrounds[ colorKey ];
			const ownUrl = own === undefined ? null : await input.image( own );
			const colorLayers: Partial< Record< LayerKind, string > > =
				ownUrl === null ? layers : { ...layers, background: ownUrl };

			if ( ownUrl !== null ) {
				delete colorLayers.color;
			}

			const mockup =
				color === undefined || ( own !== undefined && ownUrl === null )
					? null
					: await composeMockup(
							view,
							colorLayers,
							color.hex,
							print
						);

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

			progress( ++done, submissionSize( input.design, input.colors ) );
		}
	}

	const submitted = await submitDesign( input.product );

	return submitted.ok
		? { ok: true, product: submitted.data }
		: { ok: false, reason: submitted.error.reason ?? 'submit' };
}
