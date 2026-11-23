/**
 * Cliparts with a definition in a design (phase 56, step 56.11, ADR-121):
 * a new one with the file's paints, the rules the server keeps them to
 * (ClipartObjects in PHP), and their pictures with the creator's paints for
 * the canvas and the print file.
 *
 * The rules are applied to what is shown and printed, so a print file is
 * always of the design the server stores, whatever edit made it.
 */
import type { DesignerAsset } from '../../api/designer';
import type { ClipartArt, ClipartPaint } from './clipart-model';
import { drawClipart, lockedPlacement } from './clipart-paint';
import type { ClipartPixels } from './clipart-paint';
import type { DesignObject, ImageObject } from './design-model';
import { newImage } from './design-model';
import type { ImageResolver } from './fabric-stage';

/** An asset by id; undefined for one not known. */
export type AssetLookup = ( asset: number ) => DesignerAsset | undefined;

/** How the canvas lets a clipart be changed. */
export type ClipartHandling = 'sideways' | 'uniform';

/** Most drawn pictures kept. */
const DRAWN_LIMIT = 48;

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

	private readonly drawn = new Map< string, HTMLCanvasElement | null >();

	/**
	 * Constructor.
	 *
	 * @param load     The atlas of an asset, or null.
	 * @param toCanvas Pixels as a canvas.
	 */
	public constructor(
		private readonly load: (
			asset: number
		) => Promise< ClipartPixels | null >,
		private readonly toCanvas: (
			pixels: ClipartPixels
		) => HTMLCanvasElement | null = canvasOf
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
	 * @param lookup Assets by id.
	 * @param hard   Embroidery: every pixel wholly one colour or empty.
	 */
	public resolver( lookup: AssetLookup, hard = false ): ImageResolver {
		const loaded = Array.from( this.atlases.entries() )
			.filter( ( [ , pixels ] ) => pixels !== null )
			.map( ( [ asset ] ) => asset )
			.sort( ( one, other ) => one - other )
			.join( ',' );

		return {
			key: `${ hard ? 'hard' : 'soft' }:${ loaded }`,
			image: ( object ) => this.picture( object, lookup, hard ),
			handling: ( object ) => handlingOf( lookup( object.asset ) ),
		};
	}

	/**
	 * An image's picture: undefined for one that is its file, null when it
	 * cannot be drawn.
	 *
	 * @param object The image.
	 * @param lookup Assets by id.
	 * @param hard   Embroidery.
	 */
	private picture(
		object: ImageObject,
		lookup: AssetLookup,
		hard: boolean
	): HTMLCanvasElement | null | undefined {
		const asset = lookup( object.asset );
		const art = artOf( asset );

		// Any other image, also a clipart without parts: its file.
		if ( object.paints === undefined && art === null ) {
			return undefined;
		}

		const atlas = this.atlases.get( object.asset ) ?? null;

		if ( art === null || object.paints === undefined || atlas === null ) {
			return null;
		}

		const key = `${ object.asset }|${ hard ? 1 : 0 }|${ JSON.stringify(
			object.paints
		) }`;

		if ( this.drawn.has( key ) ) {
			const known = this.drawn.get( key ) ?? null;
			// Most recently used last.
			this.drawn.delete( key );
			this.drawn.set( key, known );

			return known;
		}

		const pixels = drawClipart( art, atlas, object.paints, hard );
		const canvas = pixels === null ? null : this.toCanvas( pixels );

		this.drawn.set( key, canvas );

		if ( this.drawn.size > DRAWN_LIMIT ) {
			const oldest = this.drawn.keys().next().value;

			if ( oldest !== undefined ) {
				this.drawn.delete( oldest );
			}
		}

		return canvas;
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
