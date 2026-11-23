/**
 * The upload of a clipart with parts (phase 56, step 56.13, ADR-121): the
 * PSD is read and baked in the browser and never sent. The form gets the
 * preview, the atlas and the definition; the administrator chooses the base
 * products, a palette or any colour and the lock, and is warned of what the
 * clipart will do on the chosen products before it is saved.
 */
import { __, sprintf } from '@wordpress/i18n';

import type { DesignerThread } from '../dashboard/api/designer';
import type {
	ClipartArt,
	ClipartPaint,
} from '../dashboard/sections/designer/clipart-model';
import {
	drawClipart,
	lockedPlacement,
} from '../dashboard/sections/designer/clipart-paint';
import { nearestThread } from '../dashboard/sections/designer/threads';
import { bakeClipart } from './clipart-bake';
import { differenceOf } from './clipart-compare';
import type { Difference } from './clipart-compare';
import { ClipartError, readClipart } from './psd-clipart';
import type { Rgba } from './psd-template';

/** Largest PSD read in the browser (it is never uploaded). */
const MAX_PSD_BYTES = 314572800;

/** What the form's fields take besides the files. */
const FORM_SLACK = 65536;

/** A mean difference per channel that is more than rounding. */
const MEAN_LIMIT = 2;

/** A share of pixels that differ at once that is more than an edge. */
const SEEN_LIMIT = 0.002;

/** A side of a base product as the form knows it. */
export interface SideInfo {
	readonly name: string;
	/** Print size in px. */
	readonly width: number;
	readonly height: number;
	readonly dpi: number;
	readonly pack: {
		readonly max: number;
		readonly threads: readonly DesignerThread[];
	} | null;
}

/** A base product as the form knows it. */
export interface BaseInfo {
	readonly id: number;
	readonly name: string;
	readonly views: readonly SideInfo[];
}

/** The administrator's choices. */
export interface ClipartChoices {
	/** Chosen base products; null for all. */
	readonly bases: readonly number[] | null;
	/** The colours to choose between; null for any. */
	readonly palette: readonly string[] | null;
	readonly locked: boolean;
}

/**
 * The colours a clipart starts with: the parts' paints and the starts of
 * their gradients. A palette always holds them.
 *
 * @param art The clipart.
 */
export function startColors( art: ClipartArt ): string[] {
	const colors = new Set< string >();
	const add = ( paint: ClipartPaint ): void => {
		if ( paint.type === 'solid' ) {
			colors.add( paint.color );
		} else {
			colors.add( paint.from );
			colors.add( paint.to );
		}
	};

	for ( const part of art.parts ) {
		add( part.paint );
		add( part.gradient );
	}

	return Array.from( colors );
}

/**
 * A palette typed by the administrator with the clipart's own colours, or
 * null when a colour does not read.
 *
 * @param text Colours as `#rrggbb`, separated by spaces, commas or lines.
 * @param art  The clipart.
 */
export function paletteOf( text: string, art: ClipartArt ): string[] | null {
	const typed = text
		.split( /[\s,;]+/ )
		.filter( ( item ) => item !== '' )
		.map( ( item ) => item.toLowerCase() );

	if ( typed.some( ( item ) => ! /^#[0-9a-f]{6}$/.test( item ) ) ) {
		return null;
	}

	return Array.from( new Set( [ ...startColors( art ), ...typed ] ) );
}

/**
 * The definition as the server reads it; the server names the atlas' file.
 *
 * @param art          The clipart.
 * @param atlas        The atlas' size.
 * @param atlas.width  Width.
 * @param atlas.height Height.
 * @param choices      The administrator's choices.
 */
export function definitionOf(
	art: ClipartArt,
	atlas: { readonly width: number; readonly height: number },
	choices: ClipartChoices
): Record< string, unknown > {
	return {
		bases: choices.bases,
		locked: choices.locked,
		parts: art.parts,
		steps: art.steps,
		atlas: { width: atlas.width, height: atlas.height },
		palette: choices.palette,
	};
}

/**
 * The colours of a clipart that never change: its strokes and its fixed
 * pixels that are solid on embroidery.
 *
 * @param art   The clipart.
 * @param atlas Its atlas.
 */
export function fixedColors( art: ClipartArt, atlas: Rgba ): string[] {
	const colors = new Set< string >();

	for ( const part of art.parts ) {
		if ( part.stroke !== null ) {
			colors.add( part.stroke );
		}
	}

	for ( const step of art.steps ) {
		if ( step.type !== 'image' ) {
			continue;
		}

		for ( let row = 0; row < step.rect.height; row++ ) {
			for ( let column = 0; column < step.rect.width; column++ ) {
				const at =
					( ( step.image.y + row ) * atlas.width +
						step.image.x +
						column ) *
					4;

				if ( ( atlas.data[ at + 3 ] ?? 0 ) >= 128 ) {
					colors.add(
						`#${ [ 0, 1, 2 ]
							.map( ( channel ) =>
								( atlas.data[ at + channel ] ?? 0 )
									.toString( 16 )
									.padStart( 2, '0' )
							)
							.join( '' ) }`
					);
				}
			}
		}
	}

	return Array.from( colors );
}

/**
 * What the administrator should know before saving: where a locked clipart
 * fits the width or prints under the side's dpi, where any clipart filling
 * the area does, and which threads embroidery locks.
 *
 * @param art     The clipart.
 * @param fixed   Its colours that never change.
 * @param bases   The base products.
 * @param choices The administrator's choices.
 */
export function clipartWarnings(
	art: ClipartArt,
	fixed: readonly string[],
	bases: readonly BaseInfo[],
	choices: ClipartChoices
): string[] {
	const warnings: string[] = [];
	const chosen = bases.filter(
		( base ) => choices.bases === null || choices.bases.includes( base.id )
	);

	for ( const base of chosen ) {
		for ( const side of base.views ) {
			const where = `${ base.name } – ${ side.name }`;
			const across = side.width / art.width;
			const down = side.height / art.height;
			const scale = choices.locked
				? lockedPlacement(
						art.width,
						art.height,
						side.width,
						side.height
					).scaleX
				: Math.min( across, down );

			if ( choices.locked && across < down ) {
				warnings.push(
					sprintf(
						/* translators: %s: base product and side. */
						__(
							'%s: the clipart is wider than the print area, so it fits the width and does not reach from top to bottom.',
							'merchboard'
						),
						where
					)
				);
			}

			if ( scale > 1 ) {
				const printed = Math.floor( side.dpi / scale );

				warnings.push(
					choices.locked
						? sprintf(
								/* translators: 1: base product and side, 2: dpi it prints at, 3: the side's dpi. */
								__(
									'%1$s: the clipart prints at about %2$d dpi, under the side’s %3$d dpi.',
									'merchboard'
								),
								where,
								printed,
								side.dpi
							)
						: sprintf(
								/* translators: 1: base product and side, 2: dpi it prints at, 3: the side's dpi. */
								__(
									'%1$s: filling the print area, the clipart prints at about %2$d dpi, under the side’s %3$d dpi.',
									'merchboard'
								),
								where,
								printed,
								side.dpi
							)
				);
			}

			if ( side.pack !== null && fixed.length > 0 ) {
				const pack = side.pack;
				const threads = Array.from(
					new Map(
						fixed.map( ( color ) => {
							const thread = nearestThread( color, pack.threads );

							return [ thread.code, thread ];
						} )
					).values()
				);

				warnings.push(
					sprintf(
						/* translators: 1: base product and side, 2: threads. */
						__(
							'%1$s is embroidered: the stroke and the fixed parts use %2$s, which cannot be swapped.',
							'merchboard'
						),
						where,
						threads
							.map(
								( thread ) =>
									`${ thread.code } ${ thread.name }`
							)
							.join( ', ' )
					)
				);

				// Each part needs a thread too, if only one of those.
				if ( threads.length > pack.max ) {
					warnings.push(
						sprintf(
							/* translators: 1: base product and side, 2: most threads. */
							__(
								'%1$s: the clipart needs more than %2$d threads and cannot be sent there.',
								'merchboard'
							),
							where,
							pack.max
						)
					);
				}
			}
		}
	}

	return warnings;
}

/**
 * Whether a baked clipart differs from the PSD's own picture by more than
 * rounding.
 *
 * @param difference The difference; null when the PSD has no picture.
 */
export function differsTooMuch( difference: Difference | null ): boolean {
	return (
		difference !== null &&
		( difference.mean > MEAN_LIMIT || difference.seen > SEEN_LIMIT )
	);
}

/**
 * The message of a refused PSD.
 *
 * @param error What went wrong.
 */
export function clipartError( error: unknown ): string {
	if ( ! ( error instanceof ClipartError ) ) {
		return __(
			'The PSD could not be read as a clipart. Please try again.',
			'merchboard'
		);
	}

	const layer = error.detail;

	switch ( error.code ) {
		case 'mode':
			return __( 'Save the PSD in RGB colour.', 'merchboard' );
		case 'depth':
			return __(
				'Save the PSD with 8 bits per channel (Image → Mode → 8 Bits/Channel).',
				'merchboard'
			);
		case 'size':
			return __(
				'The clipart may be at most 8000 × 8000 pixels and 16 megapixels.',
				'merchboard'
			);
		case 'profile':
			return __(
				'Save the PSD with the sRGB colour profile.',
				'merchboard'
			);
		case 'no_parts':
			return __(
				'The PSD has no layer whose name begins with "farvet lag".',
				'merchboard'
			);
		case 'too_many':
			return __(
				'A clipart may have at most 8 parts to colour.',
				'merchboard'
			);
		case 'too_complex':
		case 'atlas':
			return __(
				'The clipart has too many or too large layers. Merge the fixed layers and try again.',
				'merchboard'
			);
		case 'unreadable':
			return __(
				'The file is not a readable PSD. Export AI files to PSD first.',
				'merchboard'
			);
		default:
			return sprintf(
				/* translators: %s: layer name. */
				__(
					'The layer "%s" uses something that cannot be drawn the same way (an effect, blend mode, mask, clipping, adjustment or opacity). Simplify or merge it and try again.',
					'merchboard'
				),
				layer
			);
	}
}

/**
 * An image as a PNG.
 *
 * @param image RGBA pixels.
 */
function pngOf( image: Rgba ): Promise< Blob > {
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
 * Puts a file into a file field.
 *
 * @param field The field.
 * @param blob  The file's content.
 * @param name  Its name.
 */
function fill( field: HTMLInputElement, blob: Blob, name: string ): void {
	const transfer = new DataTransfer();

	transfer.items.add( new File( [ blob ], name, { type: 'image/png' } ) );
	field.files = transfer.files;
}

/**
 * The base products of the form, or none.
 *
 * @param form The form.
 */
function basesOf( form: HTMLFormElement ): BaseInfo[] {
	try {
		const read: unknown = JSON.parse(
			form.dataset.merchboardClipartForm ?? '[]'
		);

		return Array.isArray( read ) ? ( read as BaseInfo[] ) : [];
	} catch {
		return [];
	}
}

/**
 * Wires the upload form.
 *
 * @param form The form (`data-merchboard-clipart-form`).
 */
export function attachClipartUpload( form: HTMLFormElement ): void {
	const psd = form.querySelector< HTMLInputElement >(
		'input[data-merchboard-clipart]'
	);
	const result = form.querySelector< HTMLElement >(
		'[data-merchboard-clipart-result]'
	);
	const submit = form.querySelector< HTMLButtonElement >(
		'button[type="submit"]'
	);
	const field = ( name: string ): Element | RadioNodeList | null =>
		form.elements.namedItem( name );
	const preview = field( 'clipart_preview' );
	const atlasField = field( 'clipart_atlas' );
	const definition = field( 'clipart_definition' );
	const paletteField = field( 'clipart_palette' );

	if (
		psd === null ||
		result === null ||
		submit === null ||
		! ( preview instanceof HTMLInputElement ) ||
		! ( atlasField instanceof HTMLInputElement ) ||
		! ( definition instanceof HTMLInputElement ) ||
		! ( paletteField instanceof HTMLTextAreaElement )
	) {
		return;
	}

	const warned = form.querySelector< HTMLElement >(
		'[data-merchboard-clipart-warnings]'
	);
	const confirm = form.querySelector< HTMLElement >(
		'[data-merchboard-clipart-confirm]'
	);
	const confirmed = confirm?.querySelector( 'input' ) ?? null;
	const bases = basesOf( form );
	const postMax = Number( form.dataset.merchboardPostMax ?? '0' );
	let baked: {
		art: ClipartArt;
		atlas: Rgba;
		fixed: string[];
		differs: boolean;
	} | null = null;

	const say = ( text: string ): void => {
		result.textContent = text;
	};
	const choices = (): ClipartChoices | string => {
		const colors = field( 'clipart_colors' );
		const palette =
			colors instanceof RadioNodeList &&
			colors.value === 'palette' &&
			baked !== null
				? paletteOf( paletteField.value, baked.art )
				: null;

		if (
			colors instanceof RadioNodeList &&
			colors.value === 'palette' &&
			palette === null
		) {
			return __(
				'Write the palette as colours like #ff0000, separated by spaces.',
				'merchboard'
			);
		}

		const scope = field( 'clipart_scope' );
		const all = scope instanceof RadioNodeList && scope.value === 'all';
		const chosen = Array.from(
			form.querySelectorAll< HTMLInputElement >(
				'input[name="clipart_bases[]"]:checked'
			),
			( box ) => Number( box.value )
		);

		if ( ! all && chosen.length === 0 ) {
			return __(
				'Choose the base products, or all of them.',
				'merchboard'
			);
		}

		const locked = field( 'clipart_locked' );

		return {
			bases: all ? null : chosen,
			palette,
			locked: locked instanceof HTMLInputElement && locked.checked,
		};
	};
	const update = (): void => {
		if ( baked === null ) {
			return;
		}

		const chosen = choices();

		if ( warned !== null ) {
			warned.textContent =
				typeof chosen === 'string'
					? chosen
					: clipartWarnings(
							baked.art,
							baked.fixed,
							bases,
							chosen
						).join( ' ' );
		}

		if ( confirm !== null ) {
			confirm.hidden = ! baked.differs;
		}
	};

	psd.addEventListener( 'change', async () => {
		const file = psd.files?.[ 0 ];

		baked = null;
		submit.disabled = true;

		if ( file === undefined ) {
			return;
		}

		if ( file.size > MAX_PSD_BYTES ) {
			say( __( 'The PSD may be at most 300 MB.', 'merchboard' ) );

			return;
		}

		say( __( 'Reading the PSD…', 'merchboard' ) );

		try {
			const source = readClipart( await file.arrayBuffer() );
			const made = bakeClipart( source );
			const drawn = drawClipart(
				made.art,
				made.atlas,
				made.art.parts.map( ( part ) => part.paint )
			);

			if ( drawn === null ) {
				throw new ClipartError( 'atlas' );
			}

			const difference =
				source.composite === null
					? null
					: differenceOf( drawn, source.composite );

			fill( preview, await pngOf( drawn ), 'clipart.png' );
			fill( atlasField, await pngOf( made.atlas ), 'atlas.png' );
			baked = {
				art: made.art,
				atlas: made.atlas,
				fixed: fixedColors( made.art, made.atlas ),
				differs: differsTooMuch( difference ),
			};

			const name = field( 'clipart_name' );

			if (
				name instanceof HTMLInputElement &&
				name.value.trim() === ''
			) {
				name.value = file.name.replace( /\.psd$/i, '' ).slice( 0, 100 );
			}

			if ( paletteField.value.trim() === '' ) {
				paletteField.value = startColors( made.art ).join( ' ' );
			}

			say(
				[
					sprintf(
						/* translators: 1: parts, 2: width in px, 3: height in px. */
						__( 'Read %1$s (%2$d × %3$d px).', 'merchboard' ),
						made.art.parts
							.map( ( part, index ) =>
								part.label === ''
									? sprintf(
											/* translators: %d: the number of a part. */
											__( 'Part %d', 'merchboard' ),
											index + 1
										)
									: part.label
							)
							.join( ', ' ),
						made.art.width,
						made.art.height
					),
					difference === null
						? __(
								'The PSD has no picture of its own to compare with (save it with "Maximize compatibility").',
								'merchboard'
							)
						: sprintf(
								/* translators: 1: mean difference 0–255, 2: share of pixels in percent. */
								__(
									'Compared with the PSD: %1$s/255 on average, %2$s %% of the pixels clearly differ.',
									'merchboard'
								),
								difference.mean.toFixed( 2 ),
								( difference.seen * 100 ).toFixed( 3 )
							),
				].join( ' ' )
			);
			submit.disabled = false;
			update();
		} catch ( error ) {
			preview.value = '';
			atlasField.value = '';
			say( clipartError( error ) );
		} finally {
			// The PSD itself is never sent with the form.
			psd.value = '';
		}
	} );

	form.addEventListener( 'input', update );
	form.addEventListener( 'change', update );

	form.addEventListener( 'submit', ( event ) => {
		const refuse = ( text: string ): void => {
			event.preventDefault();
			say( text );
		};
		const files = [ preview.files?.[ 0 ], atlasField.files?.[ 0 ] ];

		if ( baked === null || files.includes( undefined ) ) {
			refuse( __( 'Choose the PSD first.', 'merchboard' ) );

			return;
		}

		const chosen = choices();

		if ( typeof chosen === 'string' ) {
			refuse( chosen );

			return;
		}

		if ( baked.differs && confirmed?.checked !== true ) {
			refuse(
				__(
					'The clipart differs from the PSD. Check the PSD, or tick that it may be saved anyway.',
					'merchboard'
				)
			);

			return;
		}

		const size = files.reduce(
			( total, file ) => total + ( file?.size ?? 0 ),
			0
		);

		if ( postMax > 0 && size + FORM_SLACK > postMax ) {
			refuse(
				sprintf(
					/* translators: 1: size of the upload in MB, 2: the server's limit in MB. */
					__(
						'The clipart is %1$s MB, more than the server takes (%2$s MB). Make the PSD smaller, or ask your host to raise post_max_size.',
						'merchboard'
					),
					( size / 1048576 ).toFixed( 1 ),
					( postMax / 1048576 ).toFixed( 1 )
				)
			);

			return;
		}

		definition.value = JSON.stringify(
			definitionOf( baked.art, baked.atlas, chosen )
		);
	} );
}
