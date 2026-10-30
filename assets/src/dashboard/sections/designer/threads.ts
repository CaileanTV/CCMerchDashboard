/**
 * Embroidery: a side with a pack keeps to the pack's threads (the owner's
 * choice, 2026-10-30). The design keeps its own colours. What is shown and
 * printed follows from them: every colour goes to its nearest thread, the
 * threads that fill the most are kept up to the pack's limit, the others move
 * to the nearest kept thread, and the creator can swap each kept thread for
 * another. A side without a pack never comes here.
 */
import type { DesignerEmbroidery, DesignerThread } from '../../api/designer';
import type { DesignObject } from './design-model';

/** The share of an image's visible pixels by colour (`#rrggbb`). */
export type Histogram = ReadonlyMap< string, number >;

export interface ThreadRow {
	/** The thread found for the design's colours. */
	readonly found: DesignerThread;
	/** The thread used: `found`, or the creator's swap. */
	readonly used: DesignerThread;
}

export interface ThreadPlan {
	/** The kept threads, the fullest first. */
	readonly rows: readonly ThreadRow[];
	/** The threads a side's file may hold. */
	readonly threads: readonly DesignerThread[];
	/** How many threads moved to a kept one because of the limit. */
	readonly merged: number;
	/** Changes when the colours shown change. */
	readonly signature: string;
	/** The thread colour shown for a colour. */
	readonly colorOf: ( color: string ) => string;
}

export interface ThreadedSide {
	readonly plan: ThreadPlan;
	/** The objects as they are shown and printed. */
	readonly objects: readonly DesignObject[];
	/** The images as they are shown and printed. */
	readonly images: ReadonlyMap< number, string >;
}

type Lab = readonly [ number, number, number ];

const SOLID = 128;

const histograms = new Map< number, Histogram >();

const painted = new Map< string, string >();

/**
 * A colour in OKLab, where distance is close to the difference the eye sees.
 *
 * @param color `#rrggbb`.
 */
function oklab( color: string ): Lab {
	const linear = ( start: number ): number => {
		const value =
			Number.parseInt( color.slice( start, start + 2 ), 16 ) / 255;

		return value <= 0.04045
			? value / 12.92
			: ( ( value + 0.055 ) / 1.055 ) ** 2.4;
	};
	const r = linear( 1 );
	const g = linear( 3 );
	const b = linear( 5 );
	const l = Math.cbrt(
		0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b
	);
	const m = Math.cbrt(
		0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b
	);
	const s = Math.cbrt(
		0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b
	);

	return [
		0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
		1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
		0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
	];
}

/**
 * The nearest thread of a colour.
 *
 * @param color   `#rrggbb`.
 * @param threads Threads (at least one).
 */
export function nearestThread(
	color: string,
	threads: readonly DesignerThread[]
): DesignerThread {
	const [ l, a, b ] = oklab( color );
	let best = threads[ 0 ] as DesignerThread;
	let distance = Number.POSITIVE_INFINITY;

	for ( const thread of threads ) {
		const [ tl, ta, tb ] = oklab( thread.hex );
		const next = ( l - tl ) ** 2 + ( a - ta ) ** 2 + ( b - tb ) ** 2;

		if ( next < distance ) {
			best = thread;
			distance = next;
		}
	}

	return best;
}

/**
 * The colours of an image's pixels, each channel in steps of 8 so a photo
 * does not give a colour per pixel.
 *
 * @param data RGBA pixels.
 */
export function histogramOf( data: ArrayLike< number > ): Histogram {
	const counts = new Map< string, number >();
	let total = 0;

	for ( let index = 0; index + 3 < data.length; index += 4 ) {
		if ( ( data[ index + 3 ] as number ) < SOLID ) {
			continue;
		}

		const key = [ 0, 1, 2 ]
			.map( ( channel ) => {
				const value = data[ index + channel ] as number;

				return ( value - ( value % 8 ) )
					.toString( 16 )
					.padStart( 2, '0' );
			} )
			.join( '' );

		counts.set( `#${ key }`, ( counts.get( `#${ key }` ) ?? 0 ) + 1 );
		total++;
	}

	return new Map(
		Array.from( counts, ( [ color, count ] ) => [ color, count / total ] )
	);
}

/**
 * The colours of an object with the area each fills.
 *
 * @param object The object.
 * @param images The images' colours by asset.
 */
function weights(
	object: DesignObject,
	images: ReadonlyMap< number, Histogram >
): [ string, number ][] {
	const area =
		object.width *
		object.height *
		Math.abs( object.scaleX * object.scaleY );

	if ( object.type === 'image' ) {
		return Array.from(
			images.get( object.asset ) ?? [],
			( [ color, share ] ): [ string, number ] => [ color, share * area ]
		);
	}

	const outline =
		2 *
		( object.width + object.height ) *
		object.strokeWidth *
		Math.abs( object.scaleX );

	return [
		...( object.fill === null
			? []
			: [ [ object.fill, area ] as [ string, number ] ] ),
		...( object.stroke === null
			? []
			: [ [ object.stroke, outline ] as [ string, number ] ] ),
	];
}

/**
 * The threads of a side.
 *
 * @param objects The side's objects.
 * @param images  The images' colours by asset.
 * @param swaps   The creator's choice: thread code by found thread code.
 * @param pack    The side's pack.
 */
export function threadPlan(
	objects: readonly DesignObject[],
	images: ReadonlyMap< number, Histogram >,
	swaps: Readonly< Record< string, string > >,
	pack: DesignerEmbroidery
): ThreadPlan {
	const filled = new Map< string, number >();

	for ( const object of objects ) {
		if ( ! object.hidden ) {
			for ( const [ color, area ] of weights( object, images ) ) {
				const { code } = nearestThread( color, pack.threads );

				filled.set( code, ( filled.get( code ) ?? 0 ) + area );
			}
		}
	}

	const present = pack.threads
		.filter( ( thread ) => filled.has( thread.code ) )
		.sort(
			( one, other ) =>
				( filled.get( other.code ) ?? 0 ) -
				( filled.get( one.code ) ?? 0 )
		);
	const kept = present.slice( 0, pack.max );
	const chosen = ( thread: DesignerThread ): DesignerThread =>
		pack.threads.find(
			( candidate ) => candidate.code === swaps[ thread.code ]
		) ?? thread;
	const rows = kept.map( ( found ) => ( { found, used: chosen( found ) } ) );
	const known = new Map< string, string >();

	return {
		rows,
		threads: Array.from(
			new Map(
				rows.map( ( row ) => [ row.used.code, row.used ] )
			).values()
		),
		merged: present.length - kept.length,
		signature: rows
			.map( ( row ) => `${ row.found.code }:${ row.used.code }` )
			.join( ',' ),
		colorOf: ( color ) => {
			const shown = known.get( color );

			if ( shown !== undefined ) {
				return shown;
			}

			const thread = nearestThread( color, pack.threads );
			const hex = chosen(
				kept.length === 0 || kept.includes( thread )
					? thread
					: nearestThread( thread.hex, kept )
			).hex;

			known.set( color, hex );

			return hex;
		},
	};
}

/**
 * An object as it is shown and printed: thread colours, and solid, as a
 * thread cannot be half transparent.
 *
 * @param object The object.
 * @param plan   The side's threads.
 */
export function threadObject(
	object: DesignObject,
	plan: ThreadPlan
): DesignObject {
	if ( object.type === 'image' ) {
		return { ...object, opacity: 1 };
	}

	const paint = ( color: string | null ): string | null =>
		color === null ? null : plan.colorOf( color );

	return object.type === 'text'
		? {
				...object,
				opacity: 1,
				fill: plan.colorOf( object.fill ),
				stroke: paint( object.stroke ),
			}
		: {
				...object,
				opacity: 1,
				fill: paint( object.fill ),
				stroke: paint( object.stroke ),
			};
}

/**
 * Paints pixels: a pixel is either gone or solid in the colour it is given.
 *
 * @param data  RGBA pixels, changed in place.
 * @param paint The colour shown for a colour.
 */
export function paintPixels(
	data: Uint8ClampedArray,
	paint: ( color: string ) => string
): void {
	const known = new Map< number, readonly number[] >();

	for ( let index = 0; index + 3 < data.length; index += 4 ) {
		if ( ( data[ index + 3 ] as number ) < SOLID ) {
			data.fill( 0, index, index + 4 );

			continue;
		}

		const channels = data.subarray( index, index + 3 );
		const color =
			( channels[ 0 ] as number ) * 65536 +
			( channels[ 1 ] as number ) * 256 +
			( channels[ 2 ] as number );
		let shown = known.get( color );

		if ( shown === undefined ) {
			const hex = paint(
				`#${ color.toString( 16 ).padStart( 6, '0' ) }`
			);

			shown = [ 1, 3, 5 ].map( ( start ) =>
				Number.parseInt( hex.slice( start, start + 2 ), 16 )
			);
			known.set( color, shown );
		}

		data.set( [ ...shown, 255 ], index );
	}
}

/**
 * The last pass over a print file: edges and scaling leave colours between
 * the threads, and a file holds threads only.
 *
 * @param canvas The print file.
 * @param plan   The side's threads.
 */
export function threadCanvas(
	canvas: HTMLCanvasElement,
	plan: ThreadPlan
): void {
	const context = canvas.getContext( '2d' );

	if ( context === null || plan.threads.length === 0 ) {
		return;
	}

	const pixels = context.getImageData( 0, 0, canvas.width, canvas.height );
	const known = new Map< string, string >();

	paintPixels( pixels.data, ( color ) => {
		const hex =
			known.get( color ) ?? nearestThread( color, plan.threads ).hex;

		known.set( color, hex );

		return hex;
	} );
	context.putImageData( pixels, 0, 0 );
}

/**
 * An image on a canvas of its own size.
 *
 * @param url `data:` URL.
 */
async function drawn(
	url: string
): Promise< CanvasRenderingContext2D | null > {
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

	const context = canvas.getContext( '2d', { willReadFrequently: true } );

	context?.drawImage( image, 0, 0 );

	return context;
}

/**
 * The colours of a design's image.
 *
 * @param asset The asset.
 * @param url   Its `data:` URL.
 */
async function colorsOf(
	asset: number,
	url: string
): Promise< Histogram | null > {
	const known = histograms.get( asset );

	if ( known !== undefined ) {
		return known;
	}

	const context = await drawn( url );

	if ( context === null ) {
		return null;
	}

	const { canvas } = context;
	const histogram = histogramOf(
		context.getImageData( 0, 0, canvas.width, canvas.height ).data
	);

	histograms.set( asset, histogram );

	return histogram;
}

/**
 * An image in its side's threads. The stored asset is never touched.
 *
 * @param asset The asset.
 * @param url   Its `data:` URL.
 * @param plan  The side's threads.
 */
async function paintedImage(
	asset: number,
	url: string,
	plan: ThreadPlan
): Promise< string | null > {
	const key = `${ asset }:${ plan.signature }`;
	const known = painted.get( key );

	if ( known !== undefined ) {
		return known;
	}

	const context = await drawn( url );

	if ( context === null ) {
		return null;
	}

	const { canvas } = context;
	const pixels = context.getImageData( 0, 0, canvas.width, canvas.height );

	paintPixels( pixels.data, plan.colorOf );
	context.putImageData( pixels, 0, 0 );

	const result = canvas.toDataURL( 'image/png' );

	painted.set( key, result );

	return result;
}

/**
 * A side in its threads.
 *
 * @param objects The side's objects.
 * @param images  `data:` URL by asset id.
 * @param swaps   The creator's choice of threads.
 * @param pack    The side's pack.
 */
export async function threadSide(
	objects: readonly DesignObject[],
	images: ReadonlyMap< number, string >,
	swaps: Readonly< Record< string, string > >,
	pack: DesignerEmbroidery
): Promise< ThreadedSide > {
	const assets = new Set(
		objects.flatMap( ( object ) =>
			object.type === 'image' ? [ object.asset ] : []
		)
	);
	const colors = new Map< number, Histogram >();

	for ( const asset of assets ) {
		const url = images.get( asset );
		const histogram =
			url === undefined ? null : await colorsOf( asset, url );

		if ( histogram !== null ) {
			colors.set( asset, histogram );
		}
	}

	const plan = threadPlan( objects, colors, swaps, pack );
	const shown = new Map( images );

	for ( const asset of assets ) {
		const url = images.get( asset );
		const result =
			url === undefined ? null : await paintedImage( asset, url, plan );

		if ( result !== null ) {
			shown.set( asset, result );
		}
	}

	return {
		plan,
		objects: objects.map( ( object ) => threadObject( object, plan ) ),
		images: shown,
	};
}
