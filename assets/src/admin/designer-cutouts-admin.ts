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
import { silhouetteFromUrl } from './silhouette';
import {
	composeMockup,
	composeScene,
} from '../dashboard/sections/designer/mockup';

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

	// A PSD scene: its own picture with the design, without the white document.
	const scene = slot.layers.scene;
	const image =
		view.scene !== null && scene !== undefined
			? await composeScene( view.scene, scene, print, true )
			: await composeMockup(
					view,
					slot.layers as Partial< Record< LayerKind, string > >,
					slot.color,
					print,
					null,
					true
				);

	// A side the browser finds no outline on stays as it is.
	return image?.type === 'image/png' ? image : null;
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
 * Gives the sides of a product's base product without an outline a
 * silhouette made from the template's photo on white (`data-outline-job`,
 * `data-outline`). True when there was nothing to do or every side got one.
 *
 * @param button The product's button.
 * @param say    Shows the progress.
 */
async function makeOutline(
	button: HTMLButtonElement,
	say: ( text: string ) => void
): Promise< boolean > {
	const jobUrl = button.dataset.outlineJob;

	if ( jobUrl === undefined ) {
		return true;
	}

	say( __( 'Removing the white background…', 'merchboard' ) );

	const answer: unknown = await fetch( jobUrl, {
		credentials: 'same-origin',
	} )
		.then( ( response ) => response.json() )
		.catch( () => null );
	const sides =
		typeof answer === 'object' &&
		answer !== null &&
		( answer as { success?: unknown } ).success === true
			? ( answer as { data?: { sides?: unknown } } ).data?.sides
			: null;

	if ( ! Array.isArray( sides ) ) {
		say( __( 'The product could not be read.', 'merchboard' ) );
		return false;
	}

	for ( const side of sides as { key?: unknown; photo?: unknown }[] ) {
		const png =
			typeof side.photo === 'string'
				? await silhouetteFromUrl( side.photo )
				: null;

		if ( png === null || typeof side.key !== 'string' ) {
			say(
				__(
					'The background is not plain. Upload a PNG without background as the silhouette on the base product.',
					'merchboard'
				)
			);
			return false;
		}

		const body = new FormData();
		body.append( 'side', side.key );
		body.append(
			'file',
			new File( [ png ], 'silhouette.png', { type: 'image/png' } )
		);

		const sent = await fetch( button.dataset.outline ?? '', {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} )
			.then( ( response ) => response.ok )
			.catch( () => false );

		if ( ! sent ) {
			say( __( 'The silhouette could not be saved.', 'merchboard' ) );
			return false;
		}
	}

	return true;
}

/**
 * Puts a product's waiting images in the shop.
 *
 * @param button The product's button (`data-apply`).
 * @return Whether images were put in the shop.
 */
async function applyProduct( button: HTMLButtonElement ): Promise< boolean > {
	const answer = await fetch( button.dataset.apply ?? '', {
		method: 'POST',
		credentials: 'same-origin',
	} )
		.then( ( response ) => response.json() )
		.catch( () => null );

	return (
		typeof answer === 'object' &&
		answer !== null &&
		( answer as { success?: unknown } ).success === true
	);
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
			const say = statusOf( button );

			lock( true );
			void makeOutline( button, say ).then( async ( outlined ) => {
				if ( ! outlined ) {
					lock( false );
					return;
				}

				await makeProduct( button, say );
				window.location.reload();
			} );
		} )
	);

	// The automatic run: make each product's images and put them in the shop at once.
	all?.addEventListener( 'click', () => {
		const say = statusOf( all );
		let applied = 0;

		lock( true );

		void ( async () => {
			for ( const [ index, button ] of buttons.entries() ) {
				say(
					sprintf(
						/* translators: 1: product number, 2: all products. */
						__( 'Product %1$d of %2$d…', 'merchboard' ),
						index + 1,
						buttons.length
					)
				);

				// The old images on white: an outline from the template's photo first.
				if ( ! ( await makeOutline( button, statusOf( button ) ) ) ) {
					continue;
				}

				if ( button.dataset.state !== 'pending' ) {
					await makeProduct( button, statusOf( button ) );
				}

				if ( await applyProduct( button ) ) {
					applied++;
				}
			}

			say(
				sprintf(
					/* translators: 1: products changed, 2: all products. */
					__(
						'%1$d of %2$d products use images without background.',
						'merchboard'
					),
					applied,
					buttons.length
				)
			);
			window.location.reload();
		} )();
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
