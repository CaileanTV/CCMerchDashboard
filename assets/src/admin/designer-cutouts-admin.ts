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
 * Why a step failed: a code (shown to the administrator, e.g. for support)
 * and a readable text.
 */
export interface Failure {
	readonly code: string;
	readonly text: string;
}

/**
 * A readable text for a failure code from the server or the browser.
 *
 * @param code The code.
 */
export function failureText( code: string ): string {
	switch ( code ) {
		case 'view':
			return __( 'The side could not be read.', 'merchboard' );
		case 'print':
			return __( 'The print file could not be loaded.', 'merchboard' );
		case 'draw':
			return __(
				'The image could not be drawn (a layer could not be loaded, or the browser ran out of memory).',
				'merchboard'
			);
		case 'no_outline':
			return __(
				'The side has no outline: upload a PNG without background as the silhouette on the base product.',
				'merchboard'
			);
		case 'not_plain':
			return __(
				'The background is not plain. Upload a PNG without background as the silhouette on the base product.',
				'merchboard'
			);
		case 'file_too_large':
			return __(
				'The image is larger than the server accepts (upload limit). Raise the upload limit or upload a smaller template.',
				'merchboard'
			);
		case 'base_layer_size':
			return __(
				'The image does not have the size of the template.',
				'merchboard'
			);
		case 'file_type':
			return __(
				'The server refused the file (no PNG with transparent pixels).',
				'merchboard'
			);
		case 'design_busy':
			return __(
				'The product is being changed right now. Try again in a minute.',
				'merchboard'
			);
		case 'design_stale':
			return __(
				'There was nothing to put in the shop. Make the images again.',
				'merchboard'
			);
		case 'not_live':
			return __( 'The product is no longer in the shop.', 'merchboard' );
		case 'network':
			return __(
				'The server did not answer (network or timeout).',
				'merchboard'
			);
		default:
			return __( 'A technical error occurred.', 'merchboard' );
	}
}

/**
 * A failure from a code.
 *
 * @param code The code.
 */
function failure( code: string ): Failure {
	return { code, text: failureText( code ) };
}

/**
 * One line about failures: "text (code)", the same ones once.
 *
 * @param failures The failures.
 */
export function describe( failures: readonly Failure[] ): string {
	const seen = new Map< string, string >();

	for ( const item of failures ) {
		seen.set( item.code, `${ item.text } (${ item.code })` );
	}

	return Array.from( seen.values() ).join( ' ' );
}

/**
 * Sends a request and reads the plugin's JSON answer: null when it
 * succeeded, else the failure (the server's reason, the HTTP status, or
 * `network`).
 *
 * @param url  The admin URL.
 * @param init The request.
 */
async function request(
	url: string,
	init: RequestInit = {}
): Promise< { failure: Failure | null; data: unknown } > {
	let response: Response;

	try {
		response = await fetch( url, { credentials: 'same-origin', ...init } );
	} catch {
		return { failure: failure( 'network' ), data: null };
	}

	let body: unknown = null;

	try {
		body = await response.json();
	} catch {
		body = null;
	}

	const answer =
		typeof body === 'object' && body !== null
			? ( body as { success?: unknown; data?: unknown } )
			: {};

	if ( response.ok && answer.success === true ) {
		return { failure: null, data: answer.data };
	}

	const reason =
		typeof answer.data === 'object' &&
		answer.data !== null &&
		typeof ( answer.data as { reason?: unknown } ).reason === 'string'
			? ( answer.data as { reason: string } ).reason
			: `http_${ response.status }`;

	// PHP drops a request larger than post_max_size: nothing arrives but the URL.
	return {
		failure: failure( 413 === response.status ? 'file_too_large' : reason ),
		data: null,
	};
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
 * Draws one image without background, or why it cannot be drawn.
 *
 * @param slot The side and colour.
 */
export async function drawSlot( slot: CutoutSlot ): Promise< Blob | Failure > {
	const view = parseView( slot.view );

	if ( view === null ) {
		return failure( 'view' );
	}

	const print = await printCanvas( slot.print );

	if ( print === null ) {
		return failure( 'print' );
	}

	let image: Blob | null;

	try {
		// A PSD scene: its own picture with the design, without the white document.
		const scene = slot.layers.scene;
		image =
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
	} catch {
		image = null;
	}

	if ( image === null ) {
		return failure( 'draw' );
	}

	// A side the browser finds no outline on stays as it is.
	return image.type === 'image/png' ? image : failure( 'no_outline' );
}

/**
 * The largest file the server takes (`data-max-bytes` on the table), or 0.
 */
function maxBytes(): number {
	const value = Number(
		document.querySelector< HTMLElement >( '[data-max-bytes]' )?.dataset
			.maxBytes ?? 0
	);

	return Number.isFinite( value ) && value > 0 ? value : 0;
}

/**
 * Sends a file to an admin URL.
 *
 * @param url    The URL.
 * @param fields Other fields.
 * @param file   The PNG.
 * @param name   Its file name.
 */
async function send(
	url: string,
	fields: Record< string, string >,
	file: Blob,
	name: string
): Promise< Failure | null > {
	const limit = maxBytes();

	if ( limit > 0 && file.size > limit ) {
		return failure( 'file_too_large' );
	}

	const body = new FormData();

	for ( const [ key, value ] of Object.entries( fields ) ) {
		body.append( key, value );
	}

	body.append( 'file', new File( [ file ], name, { type: 'image/png' } ) );

	return ( await request( url, { method: 'POST', body } ) ).failure;
}

/**
 * Makes a product's images: reads its job, draws and sends each one.
 *
 * @param button The product's button (`data-job`, `data-upload`).
 * @param say    Shows the progress.
 * @return The failures; none when every image was made.
 */
async function makeProduct(
	button: HTMLButtonElement,
	say: ( text: string ) => void
): Promise< Failure[] > {
	const answer = await request( button.dataset.job ?? '' );
	const job =
		answer.failure === null
			? parseJob( { success: true, data: answer.data } )
			: null;

	if ( job === null ) {
		const failed = [ answer.failure ?? failure( 'view' ) ];
		say( describe( failed ) );
		return failed;
	}

	const failures: Failure[] = [];
	let done = 0;

	for ( const [ index, slot ] of job.entries() ) {
		say(
			sprintf(
				/* translators: 1: images done, 2: all images. */
				__( 'Image %1$d of %2$d…', 'merchboard' ),
				index + 1,
				job.length
			)
		);

		const png = await drawSlot( slot );
		const failed =
			png instanceof Blob
				? await send(
						button.dataset.upload ?? '',
						{ key: slot.key },
						png,
						'cutout.png'
					)
				: png;

		if ( failed === null ) {
			done++;
		} else {
			failures.push( failed );
		}
	}

	say(
		0 === failures.length
			? sprintf(
					/* translators: %d: number of images. */
					__( '%d images made.', 'merchboard' ),
					done
				)
			: sprintf(
					/* translators: 1: images made, 2: images that failed, 3: why. */
					__( '%1$d images made, %2$d failed: %3$s', 'merchboard' ),
					done,
					failures.length,
					describe( failures )
				)
	);

	return failures;
}

/**
 * Gives the sides of a product's base product without an outline a
 * silhouette made from the template's photo on white (`data-outline-job`,
 * `data-outline`).
 *
 * @param button The product's button.
 * @param say    Shows the progress.
 * @return The failures; none when there was nothing to do or every side got one.
 */
async function makeOutline(
	button: HTMLButtonElement,
	say: ( text: string ) => void
): Promise< Failure[] > {
	const jobUrl = button.dataset.outlineJob;

	if ( jobUrl === undefined ) {
		return [];
	}

	say( __( 'Removing the white background…', 'merchboard' ) );

	const answer = await request( jobUrl );
	const sides =
		typeof answer.data === 'object' && answer.data !== null
			? ( answer.data as { sides?: unknown } ).sides
			: null;

	if ( answer.failure !== null || ! Array.isArray( sides ) ) {
		const failed = [ answer.failure ?? failure( 'view' ) ];
		say( describe( failed ) );
		return failed;
	}

	for ( const side of sides as { key?: unknown; photo?: unknown }[] ) {
		const png =
			typeof side.photo === 'string'
				? await silhouetteFromUrl( side.photo )
				: null;
		const failed =
			png === null || typeof side.key !== 'string'
				? failure( 'not_plain' )
				: await send(
						button.dataset.outline ?? '',
						{ side: side.key },
						png,
						'silhouette.png'
					);

		if ( failed !== null ) {
			say( describe( [ failed ] ) );
			return [ failed ];
		}
	}

	return [];
}

/**
 * Puts a product's waiting images in the shop.
 *
 * @param button The product's button (`data-apply`).
 * @return The failure, or null.
 */
async function applyProduct(
	button: HTMLButtonElement
): Promise< Failure | null > {
	return ( await request( button.dataset.apply ?? '', { method: 'POST' } ) )
		.failure;
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
			status.style.color = text.includes( '(' ) ? '#b32d2e' : '';
		}
	};
}

/**
 * Wires the buttons. The page reloads only when everything worked, so a
 * failure stays on the screen with its reason and code.
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
			void ( async () => {
				const failed = await makeOutline( button, say );

				if ( 0 === failed.length ) {
					failed.push( ...( await makeProduct( button, say ) ) );
				}

				if ( 0 === failed.length ) {
					window.location.reload();
					return;
				}

				lock( false );
			} )();
		} )
	);

	// A silhouette uploaded by hand (e.g. a PNG exported from the PSD), then the page shows the product as ready.
	document
		.querySelectorAll< HTMLInputElement >(
			'input.merchboard-cutouts-silhouette'
		)
		.forEach( ( input ) =>
			input.addEventListener( 'change', () => {
				const file = input.files?.[ 0 ];

				if ( file === undefined ) {
					return;
				}

				const say = statusOf( input );

				say( __( 'Saving the silhouette…', 'merchboard' ) );
				void send(
					input.dataset.outline ?? '',
					{ side: input.dataset.side ?? '' },
					file,
					'silhouette.png'
				).then( ( failed ) => {
					if ( failed === null ) {
						window.location.reload();
						return;
					}

					say( describe( [ failed ] ) );
				} );
			} )
		);

	// The automatic run: make each product's images and put them in the shop at once.
	all?.addEventListener( 'click', () => {
		const say = statusOf( all );
		let applied = 0;
		let failedProducts = 0;

		lock( true );

		void ( async () => {
			for ( const [ index, button ] of buttons.entries() ) {
				const sayHere = statusOf( button );

				say(
					sprintf(
						/* translators: 1: product number, 2: all products. */
						__( 'Product %1$d of %2$d…', 'merchboard' ),
						index + 1,
						buttons.length
					)
				);

				// The old images on white: an outline from the template's photo first.
				const failed = await makeOutline( button, sayHere );

				if (
					0 === failed.length &&
					button.dataset.state !== 'pending'
				) {
					failed.push( ...( await makeProduct( button, sayHere ) ) );
				}

				const applyFailed =
					0 === failed.length ? await applyProduct( button ) : null;

				if ( 0 === failed.length && applyFailed === null ) {
					applied++;
				} else {
					failedProducts++;

					if ( applyFailed !== null ) {
						sayHere( describe( [ applyFailed ] ) );
					}
				}
			}

			say(
				sprintf(
					/* translators: 1: products changed, 2: all products, 3: products that failed. */
					__(
						'%1$d of %2$d products use images without background. %3$d failed: see the reason at each product.',
						'merchboard'
					),
					applied,
					buttons.length,
					failedProducts
				)
			);

			if ( 0 === failedProducts ) {
				window.location.reload();
				return;
			}

			lock( false );
		} )();
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
