/**
 * "Images without background" on Proposals (ADR-114): the administrator's
 * browser draws the product images of designs approved before as PNG
 * without background, as the designer draws them for new designs, from the
 * stored print files and the template's layers. Each image is sent on its
 * own; the page then shows them for approval.
 */
import { __, sprintf } from '@wordpress/i18n';

import { parseView } from '../dashboard/api/designer';
import type { LayerKind } from '../dashboard/api/designer';
import { composeMockup } from '../dashboard/sections/designer/mockup';

export interface CutoutSlot {
	readonly key: string;
	/** The colour (`#rrggbb`). */
	readonly color: string;
	/** The side as the designer reads it. */
	readonly view: unknown;
	/** The print file's URL. */
	readonly print: string;
	/** The layers in this colour: URL by kind. */
	readonly layers: Readonly< Record< string, string > >;
}

/**
 * The slots of a job, or null when it is not one.
 *
 * @param body The JSON answer.
 */
export function parseJob( body: unknown ): CutoutSlot[] | null {
	if (
		typeof body !== 'object' ||
		body === null ||
		( body as { success?: unknown } ).success !== true
	) {
		return null;
	}

	const data = ( body as { data?: unknown } ).data;
	const slots =
		typeof data === 'object' && data !== null
			? ( data as { slots?: unknown } ).slots
			: undefined;

	if ( ! Array.isArray( slots ) ) {
		return null;
	}

	const parsed: CutoutSlot[] = [];

	for ( const slot of slots ) {
		if ( typeof slot !== 'object' || slot === null ) {
			return null;
		}

		const { key, color, view, print, layers } = slot as Record<
			string,
			unknown
		>;

		if (
			typeof key !== 'string' ||
			typeof color !== 'string' ||
			! /^#[0-9a-f]{6}$/.test( color ) ||
			typeof print !== 'string' ||
			typeof layers !== 'object' ||
			layers === null ||
			Array.isArray( layers )
		) {
			return null;
		}

		parsed.push( {
			key,
			color,
			view,
			print,
			layers: Object.fromEntries(
				Object.entries( layers ).filter(
					( entry ): entry is [ string, string ] =>
						typeof entry[ 1 ] === 'string'
				)
			),
		} );
	}

	return parsed;
}

/**
 * The print file on a canvas, or null.
 *
 * @param url The print file's URL.
 */
async function printCanvas( url: string ): Promise< HTMLCanvasElement | null > {
	const image = new Image();
	image.src = url;

	try {
		await image.decode();
	} catch {
		return null;
	}

	const canvas = document.createElement( 'canvas' );
	canvas.width = image.naturalWidth;
	canvas.height = image.naturalHeight;
	canvas.getContext( '2d' )?.drawImage( image, 0, 0 );

	return canvas;
}

/**
 * Draws one image without background, or null when it cannot be drawn.
 *
 * @param slot The side and colour.
 */
export async function drawSlot( slot: CutoutSlot ): Promise< Blob | null > {
	const view = parseView( slot.view );
	const print = view === null ? null : await printCanvas( slot.print );

	if ( view === null || print === null ) {
		return null;
	}

	return composeMockup(
		view,
		slot.layers as Partial< Record< LayerKind, string > >,
		slot.color,
		print,
		null,
		true
	);
}

/**
 * Makes a product's images: reads its job, draws and sends each one.
 *
 * @param button The product's button (`data-job`, `data-upload`).
 * @param say    Shows the progress.
 * @return Whether every image was made.
 */
async function makeProduct(
	button: HTMLButtonElement,
	say: ( text: string ) => void
): Promise< boolean > {
	const job = await fetch( button.dataset.job ?? '', {
		credentials: 'same-origin',
	} )
		.then( ( response ) => response.json() )
		.then( parseJob )
		.catch( () => null );

	if ( job === null ) {
		say( __( 'The product could not be read.', 'merchboard' ) );
		return false;
	}

	let done = 0;
	let failed = 0;

	for ( const slot of job ) {
		say(
			sprintf(
				/* translators: 1: images done, 2: all images. */
				__( 'Image %1$d of %2$d…', 'merchboard' ),
				done + failed + 1,
				job.length
			)
		);

		const png = await drawSlot( slot );
		const body = new FormData();
		body.append( 'key', slot.key );

		if ( png !== null ) {
			body.append(
				'file',
				new File( [ png ], 'cutout.png', { type: 'image/png' } )
			);
		}

		const sent =
			png !== null &&
			( await fetch( button.dataset.upload ?? '', {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} )
				.then( ( response ) => response.ok )
				.catch( () => false ) );

		if ( sent ) {
			done++;
		} else {
			failed++;
		}
	}

	say(
		failed === 0
			? sprintf(
					/* translators: %d: number of images. */
					__( '%d images made.', 'merchboard' ),
					done
				)
			: sprintf(
					/* translators: 1: images made, 2: images that failed. */
					__( '%1$d images made, %2$d failed.', 'merchboard' ),
					done,
					failed
				)
	);

	return failed === 0;
}

/**
 * The status next to a button.
 *
 * @param element The button.
 */
function statusOf( element: Element ): ( text: string ) => void {
	const status = element.parentElement?.querySelector( '[aria-live]' );

	return ( text: string ) => {
		if ( status instanceof HTMLElement ) {
			status.textContent = text;
		}
	};
}

/**
 * Wires the buttons.
 */
function start(): void {
	const buttons = Array.from(
		document.querySelectorAll< HTMLButtonElement >(
			'button.merchboard-cutouts-make'
		)
	);
	const all = document.querySelector< HTMLButtonElement >(
		'button.merchboard-cutouts-all'
	);
	const lock = ( busy: boolean ) => {
		buttons.forEach( ( button ) => ( button.disabled = busy ) );

		if ( all !== null ) {
			all.disabled = busy;
		}
	};

	buttons.forEach( ( button ) =>
		button.addEventListener( 'click', () => {
			lock( true );
			void makeProduct( button, statusOf( button ) ).then( () =>
				window.location.reload()
			);
		} )
	);

	all?.addEventListener( 'click', () => {
		const say = statusOf( all );
		const todo = buttons.filter(
			( button ) => button.dataset.state === 'todo'
		);

		lock( true );

		void ( async () => {
			for ( const [ index, button ] of todo.entries() ) {
				say(
					sprintf(
						/* translators: 1: product number, 2: all products. */
						__( 'Product %1$d of %2$d…', 'merchboard' ),
						index + 1,
						todo.length
					)
				);
				await makeProduct( button, statusOf( button ) );
			}

			window.location.reload();
		} )();
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
