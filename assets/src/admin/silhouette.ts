/**
 * "Make it from the background" on a base product's side (ADR-114): the
 * silhouette layer is made in the browser from the side's background photo
 * when its background is plain. The photo's background becomes transparent
 * (the merch ad's background removal), and the silhouette is that outline
 * in black. The PNG is put into the silhouette's file field; the
 * administrator saves the form as usual.
 */
import { __ } from '@wordpress/i18n';

import { removeBackground } from '../dashboard/sections/community/cutout';

/**
 * Loads an image from a file or a URL.
 *
 * @param source A chosen file or the stored layer's URL.
 */
async function load( source: File | string ): Promise< HTMLImageElement > {
	const image = new Image();
	const url =
		typeof source === 'string' ? source : URL.createObjectURL( source );
	image.src = url;

	try {
		await image.decode();
	} finally {
		if ( typeof source !== 'string' ) {
			URL.revokeObjectURL( url );
		}
	}

	return image;
}

/**
 * Turns the pixels of a background-free photo into a silhouette, in place:
 * every visible pixel black with its alpha, the rest transparent.
 *
 * @param data RGBA pixels.
 */
export function toSilhouette( data: Uint8ClampedArray ): void {
	for ( let index = 0; index < data.length; index += 4 ) {
		data[ index ] = 0;
		data[ index + 1 ] = 0;
		data[ index + 2 ] = 0;
	}
}

/**
 * The silhouette PNG of a photo, or null when its background is not plain.
 *
 * @param image The background photo.
 */
async function silhouetteOf( image: HTMLImageElement ): Promise< Blob | null > {
	const canvas = document.createElement( 'canvas' );
	canvas.width = image.naturalWidth;
	canvas.height = image.naturalHeight;
	const context = canvas.getContext( '2d' );

	if ( context === null ) {
		return null;
	}

	context.drawImage( image, 0, 0 );
	const pixels = context.getImageData( 0, 0, canvas.width, canvas.height );

	if ( ! removeBackground( pixels ) ) {
		return null;
	}

	toSilhouette( pixels.data );
	context.putImageData( pixels, 0, 0 );

	return new Promise( ( resolve ) => canvas.toBlob( resolve, 'image/png' ) );
}

/**
 * The silhouette PNG of a photo at a URL, or null when it cannot be read or
 * its background is not plain.
 *
 * @param url The photo's URL.
 */
export async function silhouetteFromUrl( url: string ): Promise< Blob | null > {
	try {
		return await silhouetteOf( await load( url ) );
	} catch {
		return null;
	}
}

/**
 * Wires a "Make it from the background" button.
 *
 * @param button The button (`data-index`: the side's index in the form).
 */
export function attachSilhouette( button: HTMLButtonElement ): void {
	const index = button.dataset.index ?? '';
	const status = button.parentElement?.querySelector(
		'.merchboard-silhouette-auto__status'
	);
	const say = ( text: string ) => {
		if ( status instanceof HTMLElement ) {
			status.textContent = text;
		}
	};

	button.addEventListener( 'click', () => {
		const form = button.form;
		const background = form?.querySelector< HTMLInputElement >(
			`input[type="file"][name="layer_${ index }_background"]`
		);
		const target = form?.querySelector< HTMLInputElement >(
			`input[type="file"][name="layer_${ index }_silhouette"]`
		);
		const stored = background
			?.closest( 'td' )
			?.querySelector< HTMLImageElement >( 'img' );
		const source = background?.files?.[ 0 ] ?? stored?.src ?? null;

		if ( target === undefined || target === null || source === null ) {
			say(
				__(
					'Choose a background photo first, or save the side with one.',
					'merchboard'
				)
			);
			return;
		}

		button.disabled = true;
		say( __( 'Making the silhouette…', 'merchboard' ) );

		void load( source )
			.then( silhouetteOf )
			.then( ( blob ) => {
				if ( blob === null ) {
					say(
						__(
							'The background is not plain. Upload a silhouette instead.',
							'merchboard'
						)
					);
					return;
				}

				const files = new DataTransfer();
				files.items.add(
					new File( [ blob ], `silhouette-${ index }.png`, {
						type: 'image/png',
					} )
				);
				target.files = files.files;
				say(
					__(
						'The silhouette is ready. Save the base product to keep it.',
						'merchboard'
					)
				);
			} )
			.catch( () =>
				say( __( 'The photo could not be read.', 'merchboard' ) )
			)
			.finally( () => {
				button.disabled = false;
			} );
	} );
}
