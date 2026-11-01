/**
 * PSD import on Merch Dashboard → Base products (phase 30, step 30.7,
 * ADR-080 pkt. 2). Each side of the form has a PSD field: the file is read
 * in the browser (never uploaded), its `mb_*` layers become PNG files in the
 * side's layer fields and `mb_print_area` fills the print area. Nothing is
 * saved until the administrator saves the form, so the server validates the
 * PNG layers as any other upload.
 */
import { __, sprintf } from '@wordpress/i18n';

import { attachAreaPicker } from './area-picker';
import { readScene } from './psd-scene';
import { LAYERS, readTemplate, TemplateError } from './psd-template';
import type { PrintArea, PsdTemplate, Rgba } from './psd-template';

/** Largest PSD read in the browser (it is never uploaded). */
export const MAX_PSD_BYTES = 314572800;

/** Largest mockup PSD: these hold whole scenes. */
export const MAX_SCENE_BYTES = 1073741824;

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
		case 'no_design':
			return __(
				'The PSD needs a visible smart object with "design" in its name.',
				'merchboard'
			);
		case 'warp':
			return sprintf(
				/* translators: %s: layer name. */
				__(
					'The layer %s uses a warp that cannot be read. Use Edit → Transform → Warp with the custom grid.',
					'merchboard'
				),
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

/**
 * The sizes of the sizes field.
 *
 * @param text The field's text.
 */
export function sizesOf( text: string ): string[] {
	return [
		...new Set(
			text
				.split( /[,\n]/ )
				.map( ( size ) => size.trim() )
				.filter( ( size ) => size !== '' )
		),
	];
}

/**
 * The sizes with one moved a place, as the sizes field holds them.
 *
 * @param sizes The sizes.
 * @param index The size to move.
 * @param step  -1 up, 1 down.
 */
export function movedSize(
	sizes: readonly string[],
	index: number,
	step: -1 | 1
): string[] {
	const moved = [ ...sizes ];
	const [ size ] = moved.splice( index, 1 );

	if ( size !== undefined ) {
		moved.splice( index + step, 0, size );
	}

	return moved;
}

/**
 * Arrows that change the order of the sizes. They write the sizes field, as
 * typing does, so the prices and the sides follow. Nothing is saved before the
 * form is.
 *
 * @param holder The place.
 * @param sizes  The sizes field.
 */
export function attachSizeOrder(
	holder: HTMLElement,
	sizes: HTMLInputElement
): void {
	const draw = (): void => {
		const offered = sizesOf( sizes.value );

		holder.replaceChildren();
		holder.hidden = offered.length < 2;

		offered.forEach( ( size, index ) => {
			const row = document.createElement( 'p' );
			const arrow = (
				text: string,
				label: string,
				step: -1 | 1,
				disabled: boolean
			): HTMLButtonElement => {
				const button = document.createElement( 'button' );

				button.type = 'button';
				button.className = 'button button-small';
				button.textContent = text;
				button.disabled = disabled;
				button.setAttribute( 'aria-label', label );
				button.dataset.move = `${ size }:${ step }`;
				button.addEventListener( 'click', () => {
					sizes.value = movedSize( offered, index, step ).join(
						', '
					);
					sizes.dispatchEvent(
						new Event( 'input', { bubbles: true } )
					);

					// The same arrow, or the first one left at the end of the list.
					const arrows = Array.from(
						holder.querySelectorAll< HTMLButtonElement >(
							'button:not(:disabled)'
						)
					);

					(
						arrows.find(
							( candidate ) =>
								candidate.dataset.move === `${ size }:${ step }`
						) ?? arrows[ 0 ]
					)?.focus();
				} );

				return button;
			};

			row.style.margin = '4px 0';
			row.append(
				arrow(
					'↑',
					/* translators: %s: a size. */
					sprintf( __( 'Move %s up', 'merchboard' ), size ),
					-1,
					index === 0
				),
				' ',
				arrow(
					'↓',
					/* translators: %s: a size. */
					sprintf( __( 'Move %s down', 'merchboard' ), size ),
					1,
					index === offered.length - 1
				),
				` ${ size }`
			);
			holder.append( row );
		} );
	};

	sizes.addEventListener( 'input', draw );
	draw();
}

/**
 * Arrows on each stored side. They move the side in the form, and the form
 * sends the sides in that order, so nothing is saved before the form is.
 *
 * @param root What holds the sides.
 */
export function attachSideOrder( root: ParentNode ): void {
	const sides = () =>
		Array.from(
			root.querySelectorAll< HTMLElement >(
				'fieldset[data-merchboard-side]'
			)
		);

	if ( sides().length < 2 ) {
		return;
	}

	const arrows: {
		side: HTMLElement;
		step: -1 | 1;
		button: HTMLButtonElement;
	}[] = [];
	const refresh = (): void => {
		const all = sides();

		for ( const { side, step, button } of arrows ) {
			button.disabled = all[ all.indexOf( side ) + step ] === undefined;
		}
	};

	for ( const side of sides() ) {
		const name = side.dataset.merchboardSide ?? '';
		const row = document.createElement( 'p' );
		const add = ( text: string, label: string, step: -1 | 1 ) => {
			const button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'button button-small';
			button.textContent = text;
			button.setAttribute( 'aria-label', label );
			button.addEventListener( 'click', () => {
				const all = sides();
				const next = all[ all.indexOf( side ) + step ];

				if ( step < 0 ) {
					next?.before( side );
				} else {
					next?.after( side );
				}

				refresh();

				// A side at the end of the list has lost the arrow it moved with.
				( button.disabled
					? side.querySelector< HTMLButtonElement >(
							'p button:not(:disabled)'
						)
					: button
				)?.focus();
			} );
			arrows.push( { side, step, button } );
			row.append( button, ' ' );
		};

		/* translators: %s: name of a side. */
		add( '↑', sprintf( __( 'Move %s up', 'merchboard' ), name ), -1 );
		/* translators: %s: name of a side. */
		add( '↓', sprintf( __( 'Move %s down', 'merchboard' ), name ), 1 );
		side.querySelector( 'legend' )?.after( row );
	}

	refresh();
}

/**
 * Shows a price field per size while the tick is on. A size keeps what was
 * typed for it when the list of sizes changes.
 *
 * @param holder The fields' place (`data-prices`: the stored prices).
 * @param tick   The tick.
 * @param sizes  The sizes field.
 */
function attachSizePrices(
	holder: HTMLElement,
	tick: HTMLInputElement,
	sizes: HTMLInputElement
): void {
	const prices = new Map< string, string >(
		Object.entries(
			JSON.parse( holder.dataset.prices ?? '{}' ) as Record<
				string,
				string
			>
		)
	);
	const draw = (): void => {
		holder.replaceChildren();
		holder.hidden = ! tick.checked;

		if ( ! tick.checked ) {
			return;
		}

		for ( const size of sizesOf( sizes.value ) ) {
			const row = document.createElement( 'p' );
			const label = document.createElement( 'label' );
			const input = document.createElement( 'input' );

			input.type = 'text';
			input.inputMode = 'decimal';
			input.name = `size_prices[${ size }]`;
			input.value = prices.get( size ) ?? '';
			input.addEventListener( 'input', () =>
				prices.set( size, input.value )
			);
			label.append( `${ size } `, input );
			row.append( label );
			holder.append( row );
		}
	};

	tick.addEventListener( 'change', draw );
	sizes.addEventListener( 'input', draw );
	draw();
}

/**
 * The choice of the sizes a side belongs to, from the sizes field, so it works
 * while a base product is made. A size keeps its tick when the list changes.
 *
 * @param holder The place (`data-merchboard-side-sizes`: the side's field
 *               name; `data-chosen`: its stored sizes).
 * @param sizes  The sizes field.
 */
function attachSideSizes( holder: HTMLElement, sizes: HTMLInputElement ): void {
	const name = holder.dataset.merchboardSideSizes ?? '';
	const chosen = new Set< string >(
		JSON.parse( holder.dataset.chosen ?? '[]' ) as string[]
	);
	const draw = (): void => {
		const offered = sizesOf( sizes.value );

		holder.replaceChildren();
		holder.hidden = offered.length < 2;

		const sent = document.createElement( 'input' );

		sent.type = 'hidden';
		sent.name = `${ name }[sized]`;
		sent.value = '1';

		const title = document.createElement( 'p' );

		title.textContent = __(
			'Only for these sizes (none ticked: every size):',
			'merchboard'
		);
		holder.append( sent, title );

		for ( const size of offered ) {
			const label = document.createElement( 'label' );
			const box = document.createElement( 'input' );

			box.type = 'checkbox';
			box.name = `${ name }[sizes][]`;
			box.value = size;
			box.checked = chosen.has( size );
			box.addEventListener( 'change', () => {
				if ( box.checked ) {
					chosen.add( size );
				} else {
					chosen.delete( size );
				}
			} );
			label.append( box, ` ${ size } ` );
			holder.append( label );
		}
	};

	sizes.addEventListener( 'input', draw );
	draw();
}

/**
 * Wires one mockup PSD field.
 *
 * @param input The field (`data-merchboard-scene` = the side's index).
 */
function attachScene( input: HTMLInputElement ): void {
	const index = input.dataset.merchboardScene ?? '';
	const status = document.getElementById(
		`merchboard-scene-status-${ index }`
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

		if ( file.size > MAX_SCENE_BYTES ) {
			say( __( 'The mockup PSD may be at most 1 GB.', 'merchboard' ) );

			return;
		}

		say( __( 'Reading the PSD…', 'merchboard' ) );

		try {
			const { scene, atlas, designs, warnings } = readScene(
				await file.arrayBuffer()
			);
			const image = form.elements.namedItem( `layer_${ index }_scene` );
			const data = form.elements.namedItem( `views[${ index }][scene]` );

			if (
				! ( image instanceof HTMLInputElement ) ||
				! ( data instanceof HTMLInputElement )
			) {
				throw new Error( 'No fields.' );
			}

			const transfer = new DataTransfer();

			transfer.items.add(
				new File( [ await toPng( atlas ) ], 'scene.png', {
					type: 'image/png',
				} )
			);
			image.files = transfer.files;
			data.value = JSON.stringify( scene );
			say(
				[
					sprintf(
						/* translators: 1: smart objects, 2: width in px, 3: height in px. */
						__(
							'Read %1$s (mockup %2$d × %3$d px). Save to keep it.',
							'merchboard'
						),
						designs.join( ', ' ),
						scene.width,
						scene.height
					),
					warnings.length === 0
						? ''
						: sprintf(
								/* translators: %s: layer names. */
								__(
									'Effects, masks or adjustments on these layers are left out: %s.',
									'merchboard'
								),
								warnings.join( ', ' )
							),
				].join( ' ' )
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

document
	.querySelectorAll< HTMLInputElement >( 'input[data-merchboard-scene]' )
	.forEach( attachScene );

const sizePrices = document.getElementById( 'merchboard-base-size-prices' );
const sizeTick = document.getElementById( 'merchboard-base-size-priced' );
const sizeField = document.getElementById( 'merchboard-base-sizes' );

if (
	sizePrices !== null &&
	sizeTick instanceof HTMLInputElement &&
	sizeField instanceof HTMLInputElement
) {
	attachSizePrices( sizePrices, sizeTick, sizeField );
}

attachSideOrder( document );

const sizeOrder = document.getElementById( 'merchboard-base-size-order' );

if ( sizeOrder !== null && sizeField instanceof HTMLInputElement ) {
	attachSizeOrder( sizeOrder, sizeField );
}

if ( sizeField instanceof HTMLInputElement ) {
	document
		.querySelectorAll< HTMLElement >( '[data-merchboard-side-sizes]' )
		.forEach( ( holder ) => attachSideSizes( holder, sizeField ) );
}

document
	.querySelectorAll< HTMLElement >( '[data-merchboard-area]' )
	.forEach( ( holder ) => {
		const form = holder.closest( 'form' );

		if ( null !== form ) {
			attachAreaPicker( holder, form );
		}
	} );
