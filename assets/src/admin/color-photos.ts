/**
 * Many colour photos of a side at once on Merch Dashboard → Base products. The
 * administrator chooses a side's PNG files, and a file named after a colour
 * becomes that colour's background; a new colour joins the colours field. One
 * request may hold only a few files, so saving first sends the form and then
 * the photos in batches, one after the other. Each batch is kept whole or not
 * at all. A side without a template gets the first photo as its background, so
 * the print area can be drawn on it before anything is sent.
 *
 * A photo up to 1 % off the side's size (e.g. 1000 × 1001 among 1001 × 1001)
 * is fitted to it, centred on a transparent canvas; a photo further off is
 * left out by name, and the others go on (the owner's JN mockups,
 * 2026-11-07). A file named after its colour and side ("Aske Forside.png")
 * gives the colour without the side.
 */
import { __, sprintf } from '@wordpress/i18n';

export interface PhotoColor {
	readonly name: string;
	readonly hex: string;
}

/** The colour of a transparent or unreadable photo. */
const NO_COLOR = '#808080';

/** The side of the small image a colour is measured on. */
const MEASURE = 64;

/** Of the most a request may hold, what is used: the form's fields count too. */
const BYTES_USED = 0.8;

/** How far off the side's size a photo may be and still be fitted to it. */
const FIT_SHARE = 0.01;

/** The usual names of a side at the end of a file name. */
const SIDE_WORDS =
	/\s+(?:forside|bagside|(?:højre|venstre)\s+(?:side|ærme)|ærme|front|back|(?:left|right)(?:\s+(?:side|sleeve))?|sleeve)$/iu;

/** A photo's size. */
export interface PhotoSize {
	readonly width: number;
	readonly height: number;
}

/**
 * The colour a file is named after: the file name without the side's name
 * (or a usual side name) at its end.
 *
 * @param name File name.
 * @param side The side's name.
 */
export function colorOfFile( name: string, side = '' ): string {
	const full = name
		.replace( /\.png$/i, '' )
		.normalize( 'NFC' )
		.trim();
	const tail = side.normalize( 'NFC' ).trim().toLowerCase();

	if (
		tail !== '' &&
		full.length > tail.length + 1 &&
		full.toLowerCase().endsWith( ` ${ tail }` )
	) {
		return full.slice( 0, full.length - tail.length ).trim();
	}

	return full.replace( SIDE_WORDS, '' ).trim() || full;
}

/**
 * The size the photos of a side get: the stored template's, or else the
 * size most of them have (the first photo's on a tie).
 *
 * @param sizes  The photos' sizes.
 * @param stored The stored template's size, or null.
 */
export function targetSize(
	sizes: readonly PhotoSize[],
	stored: PhotoSize | null
): PhotoSize | null {
	if ( stored !== null ) {
		return stored;
	}

	const counts = new Map< string, number >();
	let best: PhotoSize | null = null;
	let most = 0;

	for ( const size of sizes ) {
		const key = `${ size.width }x${ size.height }`;
		const count = ( counts.get( key ) ?? 0 ) + 1;

		counts.set( key, count );

		if ( count > most ) {
			most = count;
			best = size;
		}
	}

	return best;
}

/**
 * Whether a photo is close enough to the side's size to be fitted to it.
 *
 * @param size   The photo's size.
 * @param target The side's size.
 */
export function fits( size: PhotoSize, target: PhotoSize ): boolean {
	const near = ( value: number, wanted: number ) =>
		Math.abs( value - wanted ) <=
		Math.max( 1, Math.round( wanted * FIT_SHARE ) );

	return (
		near( size.width, target.width ) && near( size.height, target.height )
	);
}

/**
 * The colours field with the colours it lacks. A colour is the same whatever
 * its case. A colour still white from a catalog without colour codes gets the
 * photo's.
 *
 * @param text   The field's text.
 * @param colors The photos' colours.
 */
export function withColors(
	text: string,
	colors: readonly PhotoColor[]
): string {
	const lines = text
		.split( /\r?\n/ )
		.filter( ( line ) => line.trim() !== '' );
	const known = lines.map( ( line ) =>
		line
			.trim()
			.replace( /[\s,;]+#?[0-9a-f]{6}$/i, '' )
			.toLowerCase()
	);

	for ( const { name, hex } of colors ) {
		const at = known.indexOf( name.toLowerCase() );

		if ( at < 0 ) {
			known.push( name.toLowerCase() );
			lines.push( `${ name } ${ hex }` );
		} else {
			lines[ at ] = ( lines[ at ] ?? '' ).replace( /#ffffff\s*$/i, hex );
		}
	}

	return lines.join( '\n' );
}

/**
 * The middle colour of a photo's solid pixels.
 *
 * @param data RGBA pixels.
 */
export function medianHex( data: Uint8ClampedArray ): string {
	const channels: number[][] = [ [], [], [] ];

	for ( let at = 0; at + 3 < data.length; at += 4 ) {
		if ( ( data[ at + 3 ] ?? 0 ) > 200 ) {
			channels.forEach( ( channel, index ) =>
				channel.push( data[ at + index ] ?? 0 )
			);
		}
	}

	if ( channels[ 0 ]?.length === 0 ) {
		return NO_COLOR;
	}

	return `#${ channels
		.map( ( channel ) => {
			const sorted = [ ...channel ].sort( ( a, b ) => a - b );

			return ( sorted[ Math.floor( sorted.length / 2 ) ] ?? 0 )
				.toString( 16 )
				.padStart( 2, '0' );
		} )
		.join( '' ) }`;
}

/**
 * Files split so that each batch fits one request. A file too large for a
 * request goes alone, and the server refuses it.
 *
 * @param files    The files.
 * @param maxFiles The most files of a request.
 * @param maxBytes The most bytes of a request.
 */
export function batches< T extends { readonly size: number } >(
	files: readonly T[],
	maxFiles: number,
	maxBytes: number
): T[][] {
	const all: T[][] = [];
	let batch: T[] = [];
	let bytes = 0;

	for ( const file of files ) {
		if (
			batch.length > 0 &&
			( batch.length >= maxFiles || bytes + file.size > maxBytes )
		) {
			all.push( batch );
			batch = [];
			bytes = 0;
		}

		batch.push( file );
		bytes += file.size;
	}

	if ( batch.length > 0 ) {
		all.push( batch );
	}

	return all;
}

/**
 * A photo's size and middle colour.
 *
 * @param file The photo.
 */
async function measure(
	file: File
): Promise< { width: number; height: number; hex: string } > {
	const image = await createImageBitmap( file );
	const canvas = document.createElement( 'canvas' );

	canvas.width = MEASURE;
	canvas.height = MEASURE;

	const context = canvas.getContext( '2d' );

	context?.drawImage( image, 0, 0, MEASURE, MEASURE );

	const measured = {
		width: image.width,
		height: image.height,
		hex:
			context === null
				? NO_COLOR
				: medianHex(
						context.getImageData( 0, 0, MEASURE, MEASURE ).data
					),
	};

	// The full-size picture is freed at once (30.15 review: hundreds of photos).
	image.close?.();

	return measured;
}

/**
 * A photo fitted to the side's size: centred on a transparent canvas of that
 * size, as a PNG with the same name.
 *
 * @param file   The photo.
 * @param target The side's size.
 */
async function fitted( file: File, target: PhotoSize ): Promise< File > {
	const image = await createImageBitmap( file );
	const canvas = document.createElement( 'canvas' );

	canvas.width = target.width;
	canvas.height = target.height;
	canvas
		.getContext( '2d' )
		?.drawImage(
			image,
			Math.round( ( target.width - image.width ) / 2 ),
			Math.round( ( target.height - image.height ) / 2 )
		);
	image.close?.();

	const blob = await new Promise< Blob | null >( ( done ) =>
		canvas.toBlob( done, 'image/png' )
	);

	if ( blob === null ) {
		throw new Error( 'The photo could not be fitted.' );
	}

	return new File( [ blob ], file.name, { type: 'image/png' } );
}

/**
 * Wires the colour photo fields of the form.
 *
 * @param form     The base product form.
 * @param navigate Goes to a page.
 */
export function attachColorPhotos(
	form: HTMLFormElement,
	navigate: ( url: string ) => void = ( url ) => window.location.assign( url )
): void {
	const queues = new Map< string, File[] >();
	const value = ( name: string ): string => {
		const field = form.elements.namedItem( name );

		return field instanceof HTMLInputElement ? field.value.trim() : '';
	};
	const say = ( index: string, text: string ): void => {
		const status = document.getElementById(
			`merchboard-photos-status-${ index }`
		);

		if ( status !== null ) {
			status.textContent = text;
		}
	};
	const sideName = ( index: string ): string =>
		value( `views[${ index }][name]` );
	const showPhotos = ( index: string, files: readonly File[] ): void => {
		const preview = document.getElementById(
			`merchboard-photos-preview-${ index }`
		);

		preview?.replaceChildren(
			...files.map( ( file ) => {
				const image = document.createElement( 'img' );

				image.src = URL.createObjectURL( file );
				image.alt = colorOfFile( file.name, sideName( index ) );
				image.title = image.alt;
				image.style.cssText =
					'width:56px;height:56px;margin:0 4px 4px 0;object-fit:contain';

				return image;
			} )
		);
	};
	const setTemplate = ( index: string, file: File ): void => {
		const holder = form.querySelector< HTMLElement >(
			`[data-merchboard-area="${ index }"]`
		);
		// A stored template stays, and the photo only shows for the drawing.
		if ( holder?.dataset.url ) {
			holder.dispatchEvent(
				new CustomEvent( 'merchboard-template', {
					detail: URL.createObjectURL( file ),
				} )
			);

			return;
		}

		const field = form.elements.namedItem( `layer_${ index }_background` );

		if ( ! ( field instanceof HTMLInputElement ) ) {
			return;
		}

		const transfer = new DataTransfer();

		transfer.items.add( file );
		field.files = transfer.files;
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	};
	const choose = async ( input: HTMLInputElement ): Promise< void > => {
		const index = input.dataset.merchboardPhotos ?? '';
		const files = Array.from( input.files ?? [] );

		queues.delete( index );
		showPhotos( index, files );

		if ( files.length === 0 ) {
			return;
		}

		say( index, __( 'Reading the photos…', 'merchboard' ) );

		try {
			// One photo at a time: hundreds of full-size pictures decoded together exhaust the memory.
			const sizes: Array< Awaited< ReturnType< typeof measure > > > = [];

			for ( const file of files ) {
				sizes.push( await measure( file ) );
			}
			const holder = form.querySelector< HTMLElement >(
				`[data-merchboard-area="${ index }"]`
			);
			const storedWidth = Number( value( `views[${ index }][width]` ) );
			const storedHeight = Number( value( `views[${ index }][height]` ) );
			const target = targetSize(
				sizes,
				holder?.dataset.url && storedWidth > 0 && storedHeight > 0
					? { width: storedWidth, height: storedHeight }
					: null
			);
			const kept: File[] = [];
			const keptSizes: typeof sizes = [];
			const adjusted: string[] = [];
			const left: string[] = [];

			for ( const [ position, file ] of files.entries() ) {
				const size = sizes[ position ];
				const name = colorOfFile( file.name, sideName( index ) );

				if ( size === undefined || target === null ) {
					continue;
				}

				if (
					size.width === target.width &&
					size.height === target.height
				) {
					kept.push( file );
					keptSizes.push( size );
				} else if ( fits( size, target ) ) {
					kept.push( await fitted( file, target ) );
					keptSizes.push( size );
					adjusted.push( name );
				} else {
					left.push(
						`${ name } (${ size.width } × ${ size.height })`
					);
				}
			}

			showPhotos( index, kept );

			if ( kept.length === 0 || target === null ) {
				say(
					index,
					__(
						'None of the photos has the size of the side.',
						'merchboard'
					)
				);

				return;
			}

			const colors = form.elements.namedItem( 'colors' );

			if ( colors instanceof HTMLTextAreaElement ) {
				colors.value = withColors(
					colors.value,
					kept.map( ( file, position ) => ( {
						name: colorOfFile( file.name, sideName( index ) ),
						hex: keptSizes[ position ]?.hex ?? NO_COLOR,
					} ) )
				);
			}

			if ( kept[ 0 ] !== undefined ) {
				setTemplate( index, kept[ 0 ] );
			}

			queues.set( index, kept );
			say(
				index,
				[
					sprintf(
						/* translators: 1: number of photos, 2: width in px, 3: height in px. */
						__(
							'%1$d photos of %2$d × %3$d px. They are uploaded when you save.',
							'merchboard'
						),
						kept.length,
						target.width,
						target.height
					),
					adjusted.length === 0
						? ''
						: sprintf(
								/* translators: %s: colour names. */
								__(
									'Fitted to that size, centred (they were a few pixels off): %s.',
									'merchboard'
								),
								adjusted.join( ', ' )
							),
					left.length === 0
						? ''
						: sprintf(
								/* translators: %s: colour names with their sizes. */
								__(
									'Left out, another size: %s.',
									'merchboard'
								),
								left.join( ', ' )
							),
				]
					.filter( ( part ) => part !== '' )
					.join( ' ' )
			);
		} catch {
			showPhotos( index, [] );
			say( index, __( 'The photos could not be read.', 'merchboard' ) );
		}
	};
	const incomplete = (): boolean => {
		for ( const index of queues.keys() ) {
			const needed = [
				'name',
				'width',
				'height',
				'width_mm',
				'height_mm',
			];

			if (
				needed.some(
					( name ) => value( `views[${ index }][${ name }]` ) === ''
				)
			) {
				say(
					index,
					__(
						'Name the side, draw its print area and enter its print size before you save.',
						'merchboard'
					)
				);

				return true;
			}
		}

		return false;
	};
	const post = ( body: FormData ): Promise< Response > =>
		// `form.action` is the form's field of that name.
		fetch( form.getAttribute( 'action' ) ?? '', {
			method: 'POST',
			body,
			credentials: 'same-origin',
		} );
	const resultOf = ( response: Response ): string | null =>
		new URL( response.url ).searchParams.get(
			form.dataset.resultArg ?? ''
		);
	const send = async (): Promise< void > => {
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		const [ index = '' ] = queues.keys();
		const failed = ( where: string ): void => {
			if ( button !== null ) {
				button.disabled = false;
			}

			say(
				where,
				__(
					'The upload stopped. Please choose the photos and save again.',
					'merchboard'
				)
			);
		};

		if ( button !== null ) {
			button.disabled = true;
		}

		say( index, __( 'Saving…', 'merchboard' ) );

		try {
			const saved = await post( new FormData( form ) );
			const base = new URL( saved.url ).searchParams.get( 'base' );
			const result = resultOf( saved );

			if ( result === null ) {
				failed( index );

				return;
			}

			if ( result !== 'saved' || base === null ) {
				navigate( saved.url );

				return;
			}

			for ( const [ side, files ] of queues ) {
				const target =
					value( `views[${ side }][key]` ) ||
					value( `views[${ side }][name]` );
				let sent = 0;

				for ( const batch of batches(
					files,
					Number( form.dataset.photoFiles ?? 1 ),
					Number( form.dataset.photoBytes ?? 0 ) * BYTES_USED
				) ) {
					const body = new FormData();

					body.set( 'action', value( 'action' ) );
					body.set( '_wpnonce', value( '_wpnonce' ) );
					body.set( 'do', 'color_photos' );
					body.set( 'base_id', base );
					body.set( 'side', target );
					batch.forEach( ( file, position ) => {
						body.set(
							`photo_colors[${ position }]`,
							colorOfFile( file.name, sideName( side ) )
						);
						body.set( `photo_${ position }`, file );
					} );
					say(
						side,
						sprintf(
							/* translators: 1: photos sent, 2: all the photos. */
							__(
								'Uploading photos: %1$d of %2$d…',
								'merchboard'
							),
							sent,
							files.length
						)
					);

					const response = await post( body );
					const answer = resultOf( response );

					if ( answer === null ) {
						failed( side );

						return;
					}

					// The page tells why; the batches before this one are kept.
					if ( answer !== 'saved' ) {
						navigate( response.url );

						return;
					}

					sent += batch.length;
				}
			}

			navigate( saved.url );
		} catch {
			failed( index );
		}
	};

	form.querySelectorAll< HTMLInputElement >(
		'input[data-merchboard-photos]'
	).forEach( ( input ) =>
		input.addEventListener( 'change', () => void choose( input ) )
	);

	form.addEventListener( 'submit', ( event ) => {
		if ( queues.size === 0 ) {
			return;
		}

		event.preventDefault();

		if ( ! incomplete() ) {
			void send();
		}
	} );
}
