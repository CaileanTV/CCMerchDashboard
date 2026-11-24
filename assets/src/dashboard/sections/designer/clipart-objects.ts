/**
 * Cliparts with a definition in a design (phase 56, step 56.11, ADR-121):
 * a new one with the file's paints, the rules the server keeps them to
 * (ClipartObjects in PHP), and their pictures with the creator's paints for
 * the canvas and the print file.
 *
 * The rules are applied to what is shown and printed, so a print file is
 * always of the design the server stores, whatever edit made it.
 */
import type { DesignerAsset, DesignerThread } from '../../api/designer';
import type { ClipartArt, ClipartPaint } from './clipart-model';
import { drawClipart, lockedPlacement } from './clipart-paint';
import type { ClipartPixels } from './clipart-paint';
import type { DesignObject, ImageObject } from './design-model';
import { newImage } from './design-model';
import type { ImageResolver } from './fabric-stage';
import { nearestThread, paintPixels } from './threads';

/** An asset by id; undefined for one not known. */
export type AssetLookup = ( asset: number ) => DesignerAsset | undefined;

/** How the canvas lets a clipart be changed. */
export type ClipartHandling = 'sideways' | 'uniform';

/**
 * Most bytes of drawn pictures kept, their canvases counted (step 57.20: a
 * clipart can be 16 million pixels, so a count of pictures could hold GBs).
 */
const DRAWN_BYTES = 256 * 1024 * 1024;

/**
 * The longest side a clipart is drawn at while a colour is dragged (step
 * 57.20): about the canvas on a screen; the full picture follows at the end.
 */
export const PREVIEW_LONGEST = 1024;

/** A drawn picture: its pixels and, once shown, its canvas. */
interface Drawn {
	readonly pixels: ClipartPixels | null;
	canvas?: HTMLCanvasElement | null;
}

/**
 * The bytes a drawn picture holds: its pixels and its canvas of the same size.
 *
 * @param drawn The picture.
 */
function bytesOf( drawn: Drawn ): number {
	return ( drawn.pixels?.data.length ?? 0 ) * 2;
}

/**
 * How a clipart with parts is drawn; null for any other asset.
 *
 * @param asset The asset.
 */
export function artOf( asset: DesignerAsset | undefined ): ClipartArt | null {
	return asset?.clipart?.art?.art ?? null;
}

/**
 * The paints a clipart starts with: the file's.
 *
 * @param art The clipart.
 */
export function startPaints( art: ClipartArt ): ClipartPaint[] {
	return art.parts.map( ( part ) => part.paint );
}

/**
 * A new image of an asset in the middle; a clipart with a definition as its
 * rules have it.
 *
 * @param asset       The asset.
 * @param printWidth  Print file width.
 * @param printHeight Print file height.
 */
export function newAssetImage(
	asset: DesignerAsset,
	printWidth: number,
	printHeight: number
): ImageObject {
	const image = newImage(
		asset.id,
		asset.width,
		asset.height,
		printWidth,
		printHeight
	);
	const art = artOf( asset );

	return ruledImage(
		art === null ? image : { ...image, paints: startPaints( art ) },
		asset,
		printWidth,
		printHeight,
		false
	);
}

/**
 * An image as the server stores it (ClipartObjects::normalized): its own
 * size, a locked one in its place, one with parts evenly scaled, never faded
 * and on embroidery without gradients. Missing paints are left missing: the
 * picture then cannot be drawn, as the server refuses it.
 *
 * @param object      The image.
 * @param asset       Its asset; undefined when not known.
 * @param printWidth  Print width of the side.
 * @param printHeight Print height of the side.
 * @param embroidered Whether the side is embroidered.
 */
export function ruledImage(
	object: ImageObject,
	asset: DesignerAsset | undefined,
	printWidth: number,
	printHeight: number,
	embroidered: boolean
): ImageObject {
	const fields = asset?.clipart;

	if ( fields === undefined || asset === undefined ) {
		return object;
	}

	let ruled: ImageObject = {
		...object,
		width: asset.width,
		height: asset.height,
	};

	if ( fields.locked ) {
		ruled = {
			...ruled,
			...lockedPlacement(
				asset.width,
				asset.height,
				printWidth,
				printHeight,
				object.x
			),
		};
	}

	if ( ( fields.art?.art.parts.length ?? 0 ) === 0 ) {
		const { paints: _paints, ...plain } = ruled;

		return same( object, plain ) ? object : plain;
	}

	ruled = { ...ruled, scaleY: ruled.scaleX, opacity: 1 };

	if ( embroidered && ruled.paints !== undefined ) {
		ruled = {
			...ruled,
			paints: ruled.paints.map( ( paint ) =>
				paint.type === 'gradient'
					? { type: 'solid', color: paint.from }
					: paint
			),
		};
	}

	return same( object, ruled ) ? object : ruled;
}

/**
 * A side's objects with the rules of their cliparts; the same array when
 * nothing changes.
 *
 * @param objects     Objects, bottom first.
 * @param lookup      Assets by id.
 * @param printWidth  Print width of the side.
 * @param printHeight Print height of the side.
 * @param embroidered Whether the side is embroidered.
 */
export function ruledObjects(
	objects: readonly DesignObject[],
	lookup: AssetLookup,
	printWidth: number,
	printHeight: number,
	embroidered: boolean
): readonly DesignObject[] {
	let changed = false;
	const ruled = objects.map( ( object ) => {
		if ( object.type !== 'image' ) {
			return object;
		}

		const next = ruledImage(
			object,
			lookup( object.asset ),
			printWidth,
			printHeight,
			embroidered
		);
		changed = changed || next !== object;

		return next;
	} );

	return changed ? ruled : objects;
}

/**
 * How the canvas lets an image be changed: a locked clipart only sideways,
 * one with parts only evenly; null for any image.
 *
 * @param asset Its asset.
 */
export function handlingOf(
	asset: DesignerAsset | undefined
): ClipartHandling | null {
	const fields = asset?.clipart;

	if ( fields === undefined ) {
		return null;
	}

	if ( fields.locked ) {
		return 'sideways';
	}

	return ( fields.art?.art.parts.length ?? 0 ) > 0 ? 'uniform' : null;
}

/**
 * The assets whose atlas the objects need.
 *
 * @param objects Objects.
 * @param lookup  Assets by id.
 */
export function atlasesOf(
	objects: readonly DesignObject[],
	lookup: AssetLookup
): number[] {
	const needed = new Set< number >();

	for ( const object of objects ) {
		if (
			object.type === 'image' &&
			artOf( lookup( object.asset ) ) !== null
		) {
			needed.add( object.asset );
		}
	}

	return Array.from( needed );
}

/**
 * What a pack's threads are, for the keys of drawings.
 *
 * @param threads The threads, or null.
 */
function packKey( threads: readonly DesignerThread[] | null ): string {
	return threads === null
		? ''
		: threads
				.map( ( thread ) => `${ thread.code }=${ thread.hex }` )
				.join( ',' );
}

/**
 * Whether two images are alike.
 *
 * @param one   An image.
 * @param other Another.
 */
function same( one: ImageObject, other: ImageObject ): boolean {
	return JSON.stringify( one ) === JSON.stringify( other );
}

/**
 * A canvas with the pixels, or null.
 *
 * @param pixels Pixels.
 */
export function canvasOf( pixels: ClipartPixels ): HTMLCanvasElement | null {
	const canvas = document.createElement( 'canvas' );
	canvas.width = pixels.width;
	canvas.height = pixels.height;
	const context = canvas.getContext( '2d' );

	if ( context === null ) {
		return null;
	}

	context.putImageData(
		new ImageData(
			new Uint8ClampedArray( pixels.data ),
			pixels.width,
			pixels.height
		),
		0,
		0
	);

	return canvas;
}

/**
 * The pixels of an image at a `data:` URL, or null.
 *
 * @param url `data:` URL.
 */
export function pixelsOfUrl( url: string ): Promise< ClipartPixels | null > {
	return new Promise( ( resolve ) => {
		const image = new Image();
		image.onerror = () => resolve( null );
		image.onload = () => {
			const canvas = document.createElement( 'canvas' );
			canvas.width = image.naturalWidth;
			canvas.height = image.naturalHeight;
			const context = canvas.getContext( '2d', {
				willReadFrequently: true,
			} );

			if ( context === null || canvas.width < 1 || canvas.height < 1 ) {
				resolve( null );

				return;
			}

			context.drawImage( image, 0, 0 );
			const { data } = context.getImageData(
				0,
				0,
				canvas.width,
				canvas.height
			);
			resolve( { width: canvas.width, height: canvas.height, data } );
		};
		image.src = url;
	} );
}

/**
 * The cliparts' atlases, loaded once, and their pictures with the creator's
 * paints, drawn once per paint.
 */
export class ClipartPictures {
	private readonly atlases = new Map< number, ClipartPixels | null >();

	private readonly loading = new Map<
		number,
		Promise< ClipartPixels | null >
	>();

	private readonly drawn = new Map< string, Drawn >();

	/** The bytes the drawn pictures hold. */
	private bytes = 0;

	/** The one picture drawn small while a colour is dragged (step 57.20). */
	private live: { readonly key: string; readonly made: Drawn } | null = null;

	/**
	 * Constructor.
	 *
	 * @param load     The atlas of an asset, or null.
	 * @param toCanvas Pixels as a canvas.
	 * @param limit    Most bytes of drawn pictures kept.
	 */
	public constructor(
		private readonly load: (
			asset: number
		) => Promise< ClipartPixels | null >,
		private readonly toCanvas: (
			pixels: ClipartPixels
		) => HTMLCanvasElement | null = canvasOf,
		private readonly limit = DRAWN_BYTES
	) {}

	/**
	 * Loads the atlases the objects need.
	 *
	 * @param objects Objects.
	 * @param lookup  Assets by id.
	 * @return Whether every one is loaded.
	 */
	public async ready(
		objects: readonly DesignObject[],
		lookup: AssetLookup
	): Promise< boolean > {
		const loaded = await Promise.all(
			atlasesOf( objects, lookup ).map( ( asset ) => this.atlas( asset ) )
		);

		return loaded.every( ( pixels ) => pixels !== null );
	}

	/**
	 * The pictures as the canvas and the print file draw them.
	 *
	 * @param lookup  Assets by id.
	 * @param hard    Embroidery: every pixel wholly one colour or empty.
	 * @param threads Embroidery: the pack's threads, each pixel in its nearest.
	 * @param preview While a colour is dragged: the longest side drawn (never
	 *                for embroidery or a print file); null for the full size.
	 */
	public resolver(
		lookup: AssetLookup,
		hard = false,
		threads: readonly DesignerThread[] | null = null,
		preview: number | null = null
	): ImageResolver {
		const loaded = Array.from( this.atlases.entries() )
			.filter( ( [ , pixels ] ) => pixels !== null )
			.map( ( [ asset ] ) => asset )
			.sort( ( one, other ) => one - other )
			.join( ',' );
		const pack = packKey( threads );
		const longest = hard || preview === null ? null : preview;

		return {
			key: `${ hard ? 'hard' : 'soft' }:${ pack }:${ loaded }${
				longest === null ? '' : `:preview${ longest }`
			}`,
			image: ( object ) =>
				this.picture( object, lookup, hard, threads, longest ),
			ratio: ( object ) =>
				this.ratio( object, lookup, hard, threads, longest ),
			handling: ( object ) => handlingOf( lookup( object.asset ) ),
		};
	}

	/**
	 * An image's pixels with its paints: undefined for one that is its file,
	 * null when it cannot be drawn.
	 *
	 * @param object  The image.
	 * @param lookup  Assets by id.
	 * @param hard    Embroidery.
	 * @param threads Embroidery: the pack's threads; null for none.
	 */
	public pixels(
		object: ImageObject,
		lookup: AssetLookup,
		hard = false,
		threads: readonly DesignerThread[] | null = null
	): ClipartPixels | null | undefined {
		return this.entry( object, lookup, hard, threads )?.pixels;
	}

	/**
	 * An image's picture as a canvas.
	 *
	 * @param object  The image.
	 * @param lookup  Assets by id.
	 * @param hard    Embroidery.
	 * @param threads The pack's threads, or null.
	 */
	private picture(
		object: ImageObject,
		lookup: AssetLookup,
		hard: boolean,
		threads: readonly DesignerThread[] | null,
		longest: number | null = null
	): HTMLCanvasElement | null | undefined {
		const entry = this.entry( object, lookup, hard, threads, longest );

		if ( entry === undefined ) {
			return undefined;
		}

		if ( entry.canvas === undefined ) {
			entry.canvas =
				entry.pixels === null ? null : this.toCanvas( entry.pixels );
		}

		return entry.canvas;
	}

	/**
	 * How wide an image's picture is against its full size: below 1 while a
	 * colour is dragged, so the canvas shows it as large (step 57.20).
	 *
	 * @param object  The image.
	 * @param lookup  Assets by id.
	 * @param hard    Embroidery.
	 * @param threads The pack's threads, or null.
	 * @param longest The longest side drawn, or null for the full size.
	 */
	private ratio(
		object: ImageObject,
		lookup: AssetLookup,
		hard: boolean,
		threads: readonly DesignerThread[] | null,
		longest: number | null
	): number {
		const art = artOf( lookup( object.asset ) );
		const pixels = this.entry(
			object,
			lookup,
			hard,
			threads,
			longest
		)?.pixels;

		return art === null || pixels === null || pixels === undefined
			? 1
			: pixels.width / art.width;
	}

	/**
	 * An image's drawing, made once per paint; while a colour is dragged a
	 * small one in a single place, so passing colours fill no memory.
	 *
	 * @param object  The image.
	 * @param lookup  Assets by id.
	 * @param hard    Embroidery.
	 * @param threads The pack's threads, or null.
	 * @param longest The longest side drawn, or null for the full size.
	 */
	private entry(
		object: ImageObject,
		lookup: AssetLookup,
		hard: boolean,
		threads: readonly DesignerThread[] | null,
		longest: number | null = null
	): Drawn | undefined {
		const asset = lookup( object.asset );
		const art = artOf( asset );

		// Any other image, also a clipart without parts: its file.
		if ( object.paints === undefined && art === null ) {
			return undefined;
		}

		const atlas = this.atlases.get( object.asset ) ?? null;

		if ( art === null || object.paints === undefined || atlas === null ) {
			return { pixels: null, canvas: null };
		}

		const key = `${ object.asset }|${ hard ? 1 : 0 }|${ packKey(
			threads
		) }|${ JSON.stringify( object.paints ) }`;
		const scale =
			longest === null
				? 1
				: Math.min( 1, longest / Math.max( art.width, art.height ) );

		if ( scale < 1 ) {
			const liveKey = `${ key }|${ longest }`;

			if ( this.live?.key !== liveKey ) {
				this.live = {
					key: liveKey,
					made: {
						pixels: drawClipart(
							art,
							atlas,
							object.paints,
							hard,
							scale
						),
					},
				};
			}

			return this.live.made;
		}

		const known = this.drawn.get( key );

		if ( known !== undefined ) {
			// Most recently used last.
			this.drawn.delete( key );
			this.drawn.set( key, known );

			return known;
		}

		const pixels = drawClipart( art, atlas, object.paints, hard );

		if ( pixels !== null && threads !== null && threads.length > 0 ) {
			const nearest = new Map< string, string >();

			paintPixels( pixels.data, ( color ) => {
				const hex =
					nearest.get( color ) ?? nearestThread( color, threads ).hex;

				nearest.set( color, hex );

				return hex;
			} );
		}

		const made = { pixels };

		this.drawn.set( key, made );
		this.bytes += bytesOf( made );

		// The oldest go first; the picture just drawn always stays.
		for ( const [ oldest, old ] of this.drawn ) {
			if ( this.bytes <= this.limit || oldest === key ) {
				break;
			}

			this.drawn.delete( oldest );
			this.bytes -= bytesOf( old );
		}

		return made;
	}

	/**
	 * An asset's atlas, loaded once; a failed load is tried again next time.
	 *
	 * @param asset Asset id.
	 */
	private atlas( asset: number ): Promise< ClipartPixels | null > {
		const known = this.atlases.get( asset );

		if ( known !== undefined && known !== null ) {
			return Promise.resolve( known );
		}

		const pending = this.loading.get( asset );

		if ( pending !== undefined ) {
			return pending;
		}

		const loading = this.load( asset )
			.catch( () => null )
			.then( ( pixels ) => {
				this.loading.delete( asset );
				this.atlases.set( asset, pixels );

				return pixels;
			} );
		this.loading.set( asset, loading );

		return loading;
	}
}
