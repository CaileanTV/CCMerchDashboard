/**
 * PSD import on Merch Dashboard → Base products (phase 30, step 30.7,
 * ADR-080 pkt. 2). Each side of the form has a PSD field: the file is read
 * in the browser (never uploaded), its `mb_*` layers become PNG files in the
 * side's layer fields and `mb_print_area` fills the print area. Nothing is
 * saved until the administrator saves the form, so the server validates the
 * PNG layers as any other upload.
 */
import { __, sprintf } from '@wordpress/i18n';

import { LAYERS, readTemplate, TemplateError } from './psd-template';
import type { PrintArea, PsdTemplate, Rgba } from './psd-template';

/** Largest PSD read in the browser (it is never uploaded). */
export const MAX_PSD_BYTES = 314572800;

/**
 * The message of a refused PSD.
 *
 * @param error What went wrong.
 */
export function errorMessage( error: unknown ): string {
	if ( ! ( error instanceof TemplateError ) ) {
		return __(
			'The PSD could not be imported. Please try again.',
			'merchboard'
		);
	}

	switch ( error.code ) {
		case 'depth':
			return __(
				'Save the PSD with 8 bits per channel (Image → Mode → 8 Bits/Channel).',
				'merchboard'
			);
		case 'size':
			return __(
				'The PSD may be at most 8000 × 8000 pixels.',
				'merchboard'
			);
		case 'no_layers':
			return __(
				'The PSD needs a layer named mb_background or mb_color.',
				'merchboard'
			);
		case 'duplicate':
			return sprintf(
				/* translators: %s: layer name, e.g. "mb_color". */
				__( 'The layer %s appears more than once.', 'merchboard' ),
				error.detail
			);
		case 'empty_area':
			return __(
				'The layer mb_print_area is empty. Draw the print area as a filled rectangle.',
				'merchboard'
			);
		default:
			return __(
				'The file is not a readable PSD. Export AI files to PSD first.',
				'merchboard'
			);
	}
}

/**
 * Writes the print area into a side's fields.
 *
 * @param form  The base product form.
 * @param index The side's index in the form.
 * @param area  The print area.
 */
export function fillArea(
	form: HTMLFormElement,
	index: string,
	area: PrintArea
): void {
	for ( const key of [ 'x', 'y', 'width', 'height' ] as const ) {
		const field = form.elements.namedItem( `views[${ index }][${ key }]` );

		if ( field instanceof HTMLInputElement ) {
			field.value = String( area[ key ] );
		}
	}
}

/**
 * Sets a side's name from the PSD's file name when it is empty.
 *
 * @param form     The base product form.
 * @param index    The side's index in the form.
 * @param fileName The PSD's file name.
 */
export function fillName(
	form: HTMLFormElement,
	index: string,
	fileName: string
): void {
	const field = form.elements.namedItem( `views[${ index }][name]` );

	if ( field instanceof HTMLInputElement && '' === field.value.trim() ) {
		field.value = fileName.replace( /\.psd$/i, '' ).slice( 0, 60 );
	}
}

/**
 * An image as a PNG.
 *
 * @param image Full-size RGBA image.
 */
function toPng( image: Rgba ): Promise< Blob > {
	const canvas = document.createElement( 'canvas' );
	canvas.width = image.width;
	canvas.height = image.height;
	const context = canvas.getContext( '2d' );

	if ( null === context ) {
		return Promise.reject( new Error( 'No canvas.' ) );
	}

	context.putImageData(
		new ImageData(
			new Uint8ClampedArray( image.data ),
			image.width,
			image.height
		),
		0,
		0
	);

	return new Promise( ( resolve, reject ) => {
		canvas.toBlob(
			( blob ) =>
				null === blob
					? reject( new Error( 'No PNG.' ) )
					: resolve( blob ),
			'image/png'
		);
	} );
}

/**
 * Puts the template's layers as PNG files into a side's layer fields.
 *
 * @param form     The base product form.
 * @param index    The side's index in the form.
 * @param template The template.
 * @return The layers that were filled.
 */
async function fillLayers(
	form: HTMLFormElement,
	index: string,
	template: PsdTemplate
): Promise< string[] > {
	const filled: string[] = [];

	for ( const kind of LAYERS ) {
		const image = template.layers[ kind ];
		const field = form.elements.namedItem( `layer_${ index }_${ kind }` );

		if ( undefined === image || ! ( field instanceof HTMLInputElement ) ) {
			continue;
		}

		const transfer = new DataTransfer();
		transfer.items.add(
			new File( [ await toPng( image ) ], `${ kind }.png`, {
				type: 'image/png',
			} )
		);
		field.files = transfer.files;
		filled.push( `mb_${ kind }` );
	}

	return filled;
}

/**
 * Wires one PSD field.
 *
 * @param input The PSD field (`data-merchboard-psd` = the side's index).
 */
function attach( input: HTMLInputElement ): void {
	const index = input.dataset.merchboardPsd ?? '';
	const status = document.getElementById(
		`merchboard-psd-status-${ index }`
	);
	const say = ( text: string ): void => {
		if ( null !== status ) {
			status.textContent = text;
		}
	};

	input.addEventListener( 'change', async () => {
		const file = input.files?.[ 0 ];
		const form = input.form;

		if ( undefined === file || null === form ) {
			return;
		}

		if ( file.size > MAX_PSD_BYTES ) {
			say( __( 'The PSD may be at most 300 MB.', 'merchboard' ) );

			return;
		}

		say( __( 'Reading the PSD…', 'merchboard' ) );

		try {
			const template = readTemplate( await file.arrayBuffer() );
			const filled = await fillLayers( form, index, template );

			if ( null !== template.area ) {
				fillArea( form, index, template.area );
				filled.push( 'mb_print_area' );
			}

			fillName( form, index, file.name );
			say(
				sprintf(
					/* translators: 1: imported layers, 2: width in px, 3: height in px. */
					__(
						'Imported %1$s (%2$d × %3$d px). Check the print size in mm and dpi, then save.',
						'merchboard'
					),
					filled.join( ', ' ),
					template.width,
					template.height
				)
			);
		} catch ( error ) {
			say( errorMessage( error ) );
		} finally {
			// The PSD itself is never sent with the form.
			input.value = '';
		}
	} );
}

document
	.querySelectorAll< HTMLInputElement >( 'input[data-merchboard-psd]' )
	.forEach( attach );
