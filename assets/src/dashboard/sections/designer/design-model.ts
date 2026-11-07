/**
 * The design document of the product designer (phase 30, step 30.8,
 * ADR-080 pkt. 4): the same whitelist the server checks
 * (`MerchBoard\Modules\ProductDesigner\Designs\DesignValidator`). Objects
 * are positioned by their centre in the side's print file pixels; the
 * editor shows them on the template. Pure functions only, so the editor's
 * canvas (Fabric) is never part of the stored format.
 */

export const DESIGN_VERSION = 1;

export type ShapeType = 'rect' | 'ellipse' | 'triangle';

export type ObjectType = 'text' | 'image' | ShapeType;

interface BaseObject {
	readonly type: ObjectType;
	readonly id: string;
	readonly x: number;
	readonly y: number;
	readonly width: number;
	readonly height: number;
	readonly angle: number;
	readonly scaleX: number;
	readonly scaleY: number;
	readonly flipX: boolean;
	readonly flipY: boolean;
	readonly opacity: number;
	readonly locked: boolean;
	readonly hidden: boolean;
}

export interface TextObject extends BaseObject {
	readonly type: 'text';
	readonly text: string;
	readonly font: string;
	readonly weight: number;
	readonly italic: boolean;
	readonly size: number;
	readonly fill: string;
	readonly align: 'left' | 'center' | 'right';
	readonly lineHeight: number;
	readonly charSpacing: number;
	readonly stroke: string | null;
	readonly strokeWidth: number;
}

export interface ImageObject extends BaseObject {
	readonly type: 'image';
	readonly asset: number;
}

export interface ShapeObject extends BaseObject {
	readonly type: ShapeType;
	readonly fill: string | null;
	readonly stroke: string | null;
	readonly strokeWidth: number;
	readonly radius: number;
}

export type DesignObject = TextObject | ImageObject | ShapeObject;

export interface DesignDocument {
	readonly version: number;
	readonly sides: Readonly< Record< string, readonly DesignObject[] > >;
	/**
	 * The creator's choice of threads on embroidered sides: by side, the
	 * thread to use instead of the one found.
	 */
	readonly threads?: Readonly<
		Record< string, Readonly< Record< string, string > > >
	>;
	/** The stitch colour of a product with a choice of stitching. */
	readonly stitch?: string;
	/**
	 * The background colour (`#rrggbb`) by side, under the side's objects, on
	 * a base product that offers it (ADR-095).
	 */
	readonly background?: Readonly< Record< string, string > >;
	/**
	 * Colours with a design of their own (ADR-096); a colour not in one uses
	 * the top level, the shared design. Left out when there are none.
	 */
	readonly designs?: readonly ColorDesign[];
}

/** A design of some colours of the product (ADR-096). */
export interface ColorDesign {
	/** `d1`…`d8`, unique in the document. */
	readonly id: string;
	readonly colors: readonly string[];
	readonly sides: DesignDocument[ 'sides' ];
	readonly threads?: NonNullable< DesignDocument[ 'threads' ] >;
	readonly background?: NonNullable< DesignDocument[ 'background' ] >;
}

/** The id of the shared design. */
export const SHARED = '';

/** Most colour designs besides the shared one (the server's MAX_DESIGNS). */
export const MAX_DESIGNS = 8;

const DESIGN_ID = /^d[1-9][0-9]?$/;

export const EMPTY_DESIGN: DesignDocument = {
	version: DESIGN_VERSION,
	sides: {},
};

const COLOR = /^#[0-9a-f]{6}$/;

const isNumber = ( value: unknown ): value is number =>
	typeof value === 'number' && Number.isFinite( value );

const isRecord = ( value: unknown ): value is Record< string, unknown > =>
	typeof value === 'object' && value !== null && ! Array.isArray( value );

/**
 * A colour or null.
 *
 * @param value Value.
 */
function color( value: unknown ): string | null | undefined {
	if ( value === null ) {
		return null;
	}

	return typeof value === 'string' && COLOR.test( value ) ? value : undefined;
}

/**
 * One object from the server, or null.
 *
 * @param value Value.
 */
function parseObject( value: unknown ): DesignObject | null {
	if (
		! isRecord( value ) ||
		typeof value.id !== 'string' ||
		! isNumber( value.x ) ||
		! isNumber( value.y ) ||
		! isNumber( value.width ) ||
		! isNumber( value.height ) ||
		! isNumber( value.angle ) ||
		! isNumber( value.scaleX ) ||
		! isNumber( value.scaleY ) ||
		! isNumber( value.opacity ) ||
		typeof value.flipX !== 'boolean' ||
		typeof value.flipY !== 'boolean' ||
		typeof value.locked !== 'boolean' ||
		typeof value.hidden !== 'boolean'
	) {
		return null;
	}

	const base = {
		id: value.id,
		x: value.x,
		y: value.y,
		width: value.width,
		height: value.height,
		angle: value.angle,
		scaleX: value.scaleX,
		scaleY: value.scaleY,
		flipX: value.flipX,
		flipY: value.flipY,
		opacity: value.opacity,
		locked: value.locked,
		hidden: value.hidden,
	};

	if ( value.type === 'text' ) {
		const fill = color( value.fill );
		const stroke = color( value.stroke );

		return typeof value.text === 'string' &&
			typeof value.font === 'string' &&
			isNumber( value.weight ) &&
			typeof value.italic === 'boolean' &&
			isNumber( value.size ) &&
			typeof fill === 'string' &&
			( value.align === 'left' ||
				value.align === 'center' ||
				value.align === 'right' ) &&
			isNumber( value.lineHeight ) &&
			isNumber( value.charSpacing ) &&
			stroke !== undefined &&
			isNumber( value.strokeWidth )
			? {
					...base,
					type: 'text',
					text: value.text,
					font: value.font,
					weight: value.weight,
					italic: value.italic,
					size: value.size,
					fill,
					align: value.align,
					lineHeight: value.lineHeight,
					charSpacing: value.charSpacing,
					stroke,
					strokeWidth: value.strokeWidth,
				}
			: null;
	}

	if ( value.type === 'image' ) {
		return isNumber( value.asset )
			? { ...base, type: 'image', asset: value.asset }
			: null;
	}

	if (
		value.type === 'rect' ||
		value.type === 'ellipse' ||
		value.type === 'triangle'
	) {
		const fill = color( value.fill );
		const stroke = color( value.stroke );

		return fill !== undefined &&
			stroke !== undefined &&
			isNumber( value.strokeWidth ) &&
			isNumber( value.radius )
			? {
					...base,
					type: value.type,
					fill,
					stroke,
					strokeWidth: value.strokeWidth,
					radius: value.radius,
				}
			: null;
	}

	return null;
}

/**
 * A design from the server, or null.
 *
 * @param value Value.
 */
export function parseDesign( value: unknown ): DesignDocument | null {
	if ( Array.isArray( value ) && value.length === 0 ) {
		// A design never saved is stored as an empty list.
		return EMPTY_DESIGN;
	}

	if ( ! isRecord( value ) || value.version !== DESIGN_VERSION ) {
		return null;
	}

	const shared = parsePart( value );

	if ( shared === null ) {
		return null;
	}

	const designs: ColorDesign[] = [];

	if ( Array.isArray( value.designs ) ) {
		for ( const item of value.designs ) {
			const part = isRecord( item ) ? parsePart( item ) : null;

			if (
				part === null ||
				! isRecord( item ) ||
				typeof item.id !== 'string' ||
				! DESIGN_ID.test( item.id ) ||
				! Array.isArray( item.colors ) ||
				! item.colors.every( ( key ) => typeof key === 'string' )
			) {
				return null;
			}

			designs.push( {
				id: item.id,
				colors: item.colors as string[],
				...part,
			} );
		}
	}

	return {
		version: DESIGN_VERSION,
		...shared,
		...( typeof value.stitch === 'string' ? { stitch: value.stitch } : {} ),
		...( designs.length === 0 ? {} : { designs } ),
	};
}

/**
 * The sides, background colours and threads of one design from the server:
 * the shared one or a colour design (ADR-096); null when invalid.
 *
 * @param value The design's fields.
 */
function parsePart(
	value: Record< string, unknown >
): Pick< DesignDocument, 'sides' | 'background' | 'threads' > | null {
	const sides: Record< string, DesignObject[] > = {};
	const raw =
		Array.isArray( value.sides ) && value.sides.length === 0
			? {}
			: value.sides;

	if ( ! isRecord( raw ) ) {
		return null;
	}

	for ( const [ key, objects ] of Object.entries( raw ) ) {
		if ( ! Array.isArray( objects ) ) {
			return null;
		}

		const parsed: DesignObject[] = [];

		for ( const object of objects ) {
			const item = parseObject( object );

			if ( item === null ) {
				return null;
			}

			parsed.push( item );
		}

		sides[ key ] = parsed;
	}

	const threads = parseThreads( value.threads );
	const background = parseBackground( value.background );

	for ( const side of Object.keys( background ?? {} ) ) {
		sides[ side ] ??= [];
	}

	return {
		sides,
		...( background === undefined ? {} : { background } ),
		...( threads === undefined ? {} : { threads } ),
	};
}

/**
 * A list with one item moved.
 *
 * @param list  The list.
 * @param index The item's place.
 * @param step  -1 for earlier, 1 for later.
 */
export function shifted< T >(
	list: readonly T[],
	index: number,
	step: -1 | 1
): readonly T[] {
	const item = list[ index ];

	if (
		item === undefined ||
		index + step < 0 ||
		index + step >= list.length
	) {
		return list;
	}

	const next = list.filter( ( _, place ) => place !== index );

	next.splice( index + step, 0, item );

	return next;
}

/**
 * The creator's choice of threads from the server.
 *
 * @param value Value.
 */
function parseThreads( value: unknown ): DesignDocument[ 'threads' ] {
	if ( ! isRecord( value ) ) {
		return undefined;
	}

	const threads: Record< string, Record< string, string > > = {};

	for ( const [ side, swaps ] of Object.entries( value ) ) {
		if ( isRecord( swaps ) ) {
			threads[ side ] = Object.fromEntries(
				Object.entries( swaps ).filter(
					( entry ): entry is [ string, string ] =>
						typeof entry[ 1 ] === 'string'
				)
			);
		}
	}

	return threads;
}

let counter = 0;

/**
 * A new object id.
 */
export function newId(): string {
	counter = ( counter + 1 ) % 1000000;

	return `o${ Date.now().toString( 36 ) }${ counter.toString( 36 ) }`;
}

/**
 * The common fields of a new object in the middle of a print file.
 *
 * @param printWidth  Print file width.
 * @param printHeight Print file height.
 * @param width       Object width.
 * @param height      Object height.
 */
function placed(
	printWidth: number,
	printHeight: number,
	width: number,
	height: number
): Omit< BaseObject, 'type' > {
	return {
		id: newId(),
		x: Math.round( printWidth / 2 ),
		y: Math.round( printHeight / 2 ),
		width: Math.max( 1, Math.round( width ) ),
		height: Math.max( 1, Math.round( height ) ),
		angle: 0,
		scaleX: 1,
		scaleY: 1,
		flipX: false,
		flipY: false,
		opacity: 1,
		locked: false,
		hidden: false,
	};
}

/**
 * A new text in the middle.
 *
 * @param printWidth  Print file width.
 * @param printHeight Print file height.
 * @param font        Font family.
 * @param text        Text.
 */
export function newText(
	printWidth: number,
	printHeight: number,
	font: string,
	text: string
): TextObject {
	const size = Math.max( 4, Math.round( printWidth / 10 ) );

	return {
		...placed( printWidth, printHeight, printWidth * 0.6, size * 1.3 ),
		type: 'text',
		text,
		font,
		weight: 400,
		italic: false,
		size,
		fill: '#000000',
		align: 'center',
		lineHeight: 1.2,
		charSpacing: 0,
		stroke: null,
		strokeWidth: 0,
	};
}

/**
 * A new shape in the middle.
 *
 * @param type        Shape.
 * @param printWidth  Print file width.
 * @param printHeight Print file height.
 */
export function newShape(
	type: ShapeType,
	printWidth: number,
	printHeight: number
): ShapeObject {
	const side = Math.round( Math.min( printWidth, printHeight ) / 3 );

	return {
		...placed( printWidth, printHeight, side, side ),
		type,
		fill: '#000000',
		stroke: null,
		strokeWidth: 0,
		radius: 0,
	};
}

/**
 * A new image in the middle, fitted into half the print file.
 *
 * @param asset       Asset id.
 * @param imageWidth  Image width.
 * @param imageHeight Image height.
 * @param printWidth  Print file width.
 * @param printHeight Print file height.
 */
export function newImage(
	asset: number,
	imageWidth: number,
	imageHeight: number,
	printWidth: number,
	printHeight: number
): ImageObject {
	const scale = Math.min(
		1,
		( printWidth * 0.5 ) / imageWidth,
		( printHeight * 0.5 ) / imageHeight
	);

	return {
		...placed( printWidth, printHeight, imageWidth, imageHeight ),
		type: 'image',
		asset,
		scaleX: Math.max( 0.01, Number( scale.toFixed( 4 ) ) ),
		scaleY: Math.max( 0.01, Number( scale.toFixed( 4 ) ) ),
	};
}

/** What the sides per size need of a side. */
export interface SizedSide {
	readonly key: string;
	readonly sizes: readonly string[];
}

/**
 * The background colours of a stored design; undefined without any.
 *
 * @param value Value.
 */
function parseBackground( value: unknown ): DesignDocument[ 'background' ] {
	if ( ! isRecord( value ) ) {
		return undefined;
	}

	const background: Record< string, string > = {};

	for ( const [ side, hex ] of Object.entries( value ) ) {
		if ( typeof hex === 'string' && COLOR.test( hex ) ) {
			background[ side ] = hex;
		}
	}

	return Object.keys( background ).length === 0 ? undefined : background;
}

/**
 * The sides that show something (a visible object or a background colour),
 * in the document's order: the sides a submission renders (the server's
 * `SubmissionFiles::used_sides()`).
 *
 * @param design The document.
 */
export function usedSides( design: DesignDocument ): string[] {
	return Object.entries( design.sides )
		.filter(
			( [ side, objects ] ) =>
				design.background?.[ side ] !== undefined ||
				objects.some( ( object ) => ! object.hidden )
		)
		.map( ( [ side ] ) => side );
}

/**
 * The sides with a visible object, in the document's order: the sides that
 * count for the price of extra design areas; a side with only a background
 * colour is free (the server's `SubmissionFiles::designed_sides()`).
 *
 * @param design The document.
 */
export function designedSides( design: DesignDocument ): string[] {
	return Object.entries( design.sides )
		.filter( ( [ , objects ] ) =>
			objects.some( ( object ) => ! object.hidden )
		)
		.map( ( [ side ] ) => side );
}

/**
 * The background colour of a side, or null.
 *
 * @param design Design.
 * @param side   Side key.
 */
export function backgroundOf(
	design: DesignDocument,
	side: string
): string | null {
	return design.background?.[ side ] ?? null;
}

/**
 * The design with a background colour on some sides; null removes it.
 *
 * @param design Design.
 * @param sides  Side keys.
 * @param hex    `#rrggbb`, or null.
 */
export function withBackground(
	design: DesignDocument,
	sides: readonly string[],
	hex: string | null
): DesignDocument {
	const background: Record< string, string > = { ...design.background };
	const parts = { ...design.sides };
	const wanted = hex === null ? null : hex.toLowerCase();

	for ( const side of sides ) {
		if ( wanted === null || ! COLOR.test( wanted ) ) {
			delete background[ side ];

			if ( ( parts[ side ] ?? [] ).length === 0 ) {
				delete parts[ side ];
			}
		} else {
			background[ side ] = wanted;
			parts[ side ] ??= [];
		}
	}

	const { background: _old, ...rest } = design;

	return Object.keys( background ).length === 0
		? { ...rest, version: DESIGN_VERSION, sides: parts }
		: { ...rest, version: DESIGN_VERSION, sides: parts, background };
}

/**
 * The used sides of a size. A side without sizes is on every size.
 *
 * @param views  The sides of the base product.
 * @param design The document.
 * @param size   The size.
 */
function usedOnSize(
	views: readonly SizedSide[],
	design: DesignDocument,
	size: string
): string[] {
	const used = usedSides( design );

	return views
		.filter(
			( view ) =>
				used.includes( view.key ) &&
				( view.sizes.length === 0 || view.sizes.includes( size ) )
		)
		.map( ( view ) => view.key );
}

/**
 * How many used design areas one product has: with sides per size, those of
 * the size with the most (the server's `BaseProduct::areas()`).
 *
 * @param views  The sides of the base product.
 * @param design The document.
 * @param sizes  The chosen sizes.
 */
export function usedAreas(
	views: readonly SizedSide[],
	design: DesignDocument,
	sizes: readonly string[]
): number {
	if ( views.every( ( view ) => view.sizes.length === 0 ) ) {
		return usedSides( design ).length;
	}

	return Math.max(
		0,
		...sizes.map( ( size ) => usedOnSize( views, design, size ).length )
	);
}

/** A side with what it costs as an extra design area. */
export interface PricedSide extends SizedSide {
	readonly name: string;
	readonly small: boolean;
}

/** A design area that costs extra. */
export interface ExtraArea {
	readonly key: string;
	readonly name: string;
	readonly price: number;
}

/**
 * The design areas that cost extra: the most expensive used area is included
 * in the base price, and every other one costs a large or a small area's
 * price. With sides per size, those of the size that costs most (the server's
 * `BaseProduct::extra_amount()`).
 *
 * @param views  The sides of the base product.
 * @param design The document.
 * @param sizes  The chosen sizes.
 * @param large  Price of an extra large area (decimal string).
 * @param small  Price of an extra small area (decimal string).
 */
export function extraAreas(
	views: readonly PricedSide[],
	design: DesignDocument,
	sizes: readonly string[],
	large: string,
	small: string
): ExtraArea[] {
	// A side with only a background colour is free (ADR-095).
	const used = designedSides( design );
	// Without sides per size, a side the base product no longer has is a large area, as on the server.
	const groups = views.every( ( view ) => view.sizes.length === 0 )
		? [
				used.map(
					( key ) =>
						views.find( ( view ) => view.key === key ) ?? {
							key,
							name: key,
							small: false,
							sizes: [],
						}
				),
			]
		: sizes.map( ( size ) =>
				views.filter(
					( view ) =>
						used.includes( view.key ) &&
						( view.sizes.length === 0 ||
							view.sizes.includes( size ) )
				)
			);
	let best: ExtraArea[] = [];
	let most = -1;

	for ( const group of groups ) {
		const areas = group.map( ( view ) => ( {
			key: view.key,
			name: view.name,
			price: Number( view.small ? small : large ),
		} ) );
		const included = areas.reduce(
			( top, area, index ) =>
				area.price > ( areas[ top ]?.price ?? 0 ) ? index : top,
			0
		);
		const paid = areas.filter( ( _, index ) => index !== included );
		const sum = paid.reduce( ( total, area ) => total + area.price, 0 );

		if ( sum > most ) {
			best = paid;
			most = sum;
		}
	}

	return best;
}

/**
 * What the extra design areas cost together.
 *
 * @param areas The design areas that cost extra.
 */
export function extraTotal( areas: readonly ExtraArea[] ): number {
	return areas.reduce( ( total, area ) => total + area.price, 0 );
}

/**
 * Whether a design fits the chosen sizes: with sides per size each chosen size
 * has a design, and no design lies on a size that is not chosen (the server's
 * `BaseProduct::covers()`).
 *
 * @param views  The sides of the base product.
 * @param design The document.
 * @param sizes  The chosen sizes.
 */
export function coversSizes(
	views: readonly SizedSide[],
	design: DesignDocument,
	sizes: readonly string[]
): boolean {
	if ( views.every( ( view ) => view.sizes.length === 0 ) ) {
		return true;
	}

	const sold = sizes.map( ( size ) => usedOnSize( views, design, size ) );

	return (
		sold.every( ( sides ) => sides.length > 0 ) &&
		usedSides( design ).every( ( side ) =>
			sold.some( ( sides ) => sides.includes( side ) )
		)
	);
}

/**
 * The objects of a side.
 *
 * @param design Design.
 * @param side   Side key.
 */
export function objectsOf(
	design: DesignDocument,
	side: string
): readonly DesignObject[] {
	return design.sides[ side ] ?? [];
}

/**
 * The design with a side's objects replaced (an empty side is left out).
 *
 * @param design  Design.
 * @param side    Side key.
 * @param objects Objects, bottom first.
 */
export function withObjects(
	design: DesignDocument,
	side: string,
	objects: readonly DesignObject[]
): DesignDocument {
	const sides = { ...design.sides };

	// A side with a background colour stays in the document without objects (ADR-095).
	if ( objects.length === 0 && design.background?.[ side ] === undefined ) {
		delete sides[ side ];
	} else {
		sides[ side ] = objects;
	}

	return { ...design, version: DESIGN_VERSION, sides };
}

/**
 * The design without the background colours it cannot have: all of them
 * when the base product offers none (the tick was removed), and those of an
 * embroidered or unknown side (review 33.5; the server drops them too).
 *
 * @param design  Design.
 * @param views   The sides of the base product.
 * @param allowed Whether the base product offers a background colour.
 */
export function withKnownBackground(
	design: DesignDocument,
	views: readonly { readonly key: string; readonly embroidery: unknown }[],
	allowed: boolean
): DesignDocument {
	const sides = Object.keys( design.background ?? {} ).filter(
		( side ) =>
			! allowed ||
			! views.some(
				( view ) => view.key === side && view.embroidery === null
			)
	);

	return sides.length === 0 ? design : withBackground( design, sides, null );
}

/**
 * The objects with a copy of one right above it, moved a little down and to
 * the right, and the copy's id; null when there is no such object. The copy
 * is unlocked and shown, so it can be moved at once; an image keeps its file
 * (the owner's wish 2026-11-07).
 *
 * @param objects     Objects, bottom first.
 * @param id          The object to copy.
 * @param printWidth  Print file width (px).
 * @param printHeight Print file height (px).
 */
export function duplicateObject(
	objects: readonly DesignObject[],
	id: string,
	printWidth: number,
	printHeight: number
): { readonly objects: DesignObject[]; readonly id: string } | null {
	const index = objects.findIndex( ( object ) => object.id === id );
	const original = objects[ index ];

	if ( original === undefined ) {
		return null;
	}

	const step = Math.max(
		1,
		Math.round( Math.min( printWidth, printHeight ) * 0.03 )
	);
	const copy = {
		...original,
		id: newId(),
		x: Math.min( 20000, original.x + step ),
		y: Math.min( 20000, original.y + step ),
		locked: false,
		hidden: false,
	} as DesignObject;

	return {
		objects: [
			...objects.slice( 0, index + 1 ),
			copy,
			...objects.slice( index + 1 ),
		],
		id: copy.id,
	};
}

/**
 * The design with a thread swapped; null goes back to the thread found.
 *
 * @param design Design.
 * @param side   Side key.
 * @param found  The thread found.
 * @param used   The thread to use instead, or null.
 */
export function withThread(
	design: DesignDocument,
	side: string,
	found: string,
	used: string | null
): DesignDocument {
	const swaps: Record< string, string > = {
		...( design.threads?.[ side ] ?? {} ),
	};

	if ( used === null || used === found ) {
		delete swaps[ found ];
	} else {
		swaps[ found ] = used;
	}

	const threads: Record< string, Readonly< Record< string, string > > > = {
		...( design.threads ?? {} ),
		[ side ]: swaps,
	};

	if ( Object.keys( swaps ).length === 0 ) {
		delete threads[ side ];
	}

	const { threads: _old, ...rest } = design;

	return Object.keys( threads ).length === 0 ? rest : { ...rest, threads };
}

/**
 * The design as it is saved: a choice of threads only counts on a side that
 * is embroidered and for threads its pack still has.
 *
 * @param design Design.
 * @param views  The base product's sides.
 */
export function withKnownThreads(
	design: DesignDocument,
	views: readonly {
		readonly key: string;
		readonly embroidery: {
			readonly threads: readonly { readonly code: string }[];
		} | null;
	}[]
): DesignDocument {
	const { threads: choice, ...rest } = design;
	const threads: Record< string, Record< string, string > > = {};

	for ( const [ side, swaps ] of Object.entries( choice ?? {} ) ) {
		const codes =
			views
				.find( ( view ) => view.key === side )
				?.embroidery?.threads.map( ( thread ) => thread.code ) ?? [];
		const known = Object.entries( swaps ).filter(
			( [ found, used ] ) =>
				codes.includes( found ) && codes.includes( used )
		);

		if ( known.length > 0 ) {
			threads[ side ] = Object.fromEntries( known );
		}
	}

	return Object.keys( threads ).length === 0 ? rest : { ...rest, threads };
}

/**
 * Replaces one object.
 *
 * @param objects Objects.
 * @param id      Object id.
 * @param change  New fields.
 */
export function updateObject(
	objects: readonly DesignObject[],
	id: string,
	change: Partial< DesignObject >
): readonly DesignObject[] {
	return objects.map( ( object ) =>
		object.id === id ? ( { ...object, ...change } as DesignObject ) : object
	);
}

/**
 * Moves an object one step up (towards the front) or down.
 *
 * @param objects   Objects, bottom first.
 * @param id        Object id.
 * @param direction 1 = forward, -1 = backward.
 */
export function moveObject(
	objects: readonly DesignObject[],
	id: string,
	direction: 1 | -1
): readonly DesignObject[] {
	const index = objects.findIndex( ( object ) => object.id === id );
	const target = index + direction;

	if ( index < 0 || target < 0 || target >= objects.length ) {
		return objects;
	}

	const moved = [ ...objects ];
	const [ item ] = moved.splice( index, 1 );
	moved.splice( target, 0, item as DesignObject );

	return moved;
}

/**
 * The customer's price for a base price and a profit, as the server
 * computes it (DesignerPrices::compute): the base price rounded up, the
 * profit half away from zero, both to the store precision; null when the
 * value is not a number of at least 0.
 *
 * @param basePrice Base price as a decimal string.
 * @param kind      `percent` of the base price or `fixed` amount.
 * @param value     Percent or amount as entered (comma or point).
 * @param decimals  Store price decimals.
 * @param extra     What the extra design areas cost together (extraTotal()).
 */
export function quotePrice(
	basePrice: string,
	kind: 'percent' | 'fixed',
	value: string,
	decimals: number,
	extra = 0
): number | null {
	const parsed = Number( value.trim().replace( ',', '.' ) );
	const base = Number( basePrice ) + extra;

	if (
		value.trim() === '' ||
		! Number.isFinite( parsed ) ||
		parsed < 0 ||
		! Number.isFinite( base )
	) {
		return null;
	}

	const step = 10 ** decimals;
	const roundedBase = Math.ceil( base * step - 1e-9 ) / step;
	const profit = kind === 'percent' ? ( roundedBase * parsed ) / 100 : parsed;

	return (
		( Math.round( roundedBase * step ) +
			Math.round( profit * step + 1e-9 ) ) /
		step
	);
}

/**
 * A size in print file pixels as centimetres on the print, to one decimal:
 * the print file is the side's print size at its dpi (ADR-096 pkt. 8).
 *
 * @param width  Width (px).
 * @param height Height (px).
 * @param dpi    The side's resolution.
 */
export function sizeInCm(
	width: number,
	height: number,
	dpi: number
): { readonly width: number; readonly height: number } {
	const cm = ( px: number ): number =>
		dpi > 0 ? Math.round( ( Math.abs( px ) * 25.4 ) / dpi ) / 10 : 0;

	return { width: cm( width ), height: cm( height ) };
}

/**
 * An object's size on the print in centimetres.
 *
 * @param object The object.
 * @param dpi    The side's resolution.
 */
export function objectSizeInCm(
	object: DesignObject,
	dpi: number
): { readonly width: number; readonly height: number } {
	return sizeInCm(
		object.width * object.scaleX,
		object.height * object.scaleY,
		dpi
	);
}

/**
 * The colour designs of a document (ADR-096).
 *
 * @param design The document.
 */
export function designsOf( design: DesignDocument ): readonly ColorDesign[] {
	return design.designs ?? [];
}

/**
 * The design a colour uses: a colour design's id, or SHARED.
 *
 * @param design The document.
 * @param key    Colour key.
 */
export function partOf( design: DesignDocument, key: string ): string {
	return (
		designsOf( design ).find( ( item ) => item.colors.includes( key ) )
			?.id ?? SHARED
	);
}

/**
 * One design as a document of its own, with the product's stitch colour:
 * what the editor shows and a submission renders for its colours. An
 * unknown id has no sides.
 *
 * @param design The document.
 * @param id     A colour design's id, or SHARED.
 */
export function partDoc( design: DesignDocument, id: string ): DesignDocument {
	const found =
		id === SHARED
			? design
			: designsOf( design ).find( ( item ) => item.id === id );
	const source: Pick< DesignDocument, 'sides' | 'background' | 'threads' > =
		found ?? { sides: {} };

	return {
		version: design.version,
		sides: source.sides,
		...( source.background === undefined
			? {}
			: { background: source.background } ),
		...( source.threads === undefined ? {} : { threads: source.threads } ),
		...( design.stitch === undefined ? {} : { stitch: design.stitch } ),
	};
}

/**
 * The document with one design replaced by an edited one (its sides,
 * background colours and threads).
 *
 * @param design The document.
 * @param id     A colour design's id, or SHARED.
 * @param part   The edited design (partDoc() changed).
 */
export function withPart(
	design: DesignDocument,
	id: string,
	part: DesignDocument
): DesignDocument {
	const fields = {
		sides: part.sides,
		...( part.background === undefined
			? {}
			: { background: part.background } ),
		...( part.threads === undefined ? {} : { threads: part.threads } ),
	};

	if ( id === SHARED ) {
		const rest: DesignDocument = {
			version: design.version,
			sides: design.sides,
			...( design.designs === undefined
				? {}
				: { designs: design.designs } ),
		};

		return {
			...rest,
			...fields,
			...( part.stitch === undefined ? {} : { stitch: part.stitch } ),
		};
	}

	return {
		...design,
		designs: designsOf( design ).map( ( item ) =>
			item.id === id ? { id, colors: item.colors, ...fields } : item
		),
	};
}

/**
 * The designs the chosen colours use, with their colours in the chosen
 * order: the shared design first, when a colour uses it.
 *
 * @param design The document.
 * @param colors The chosen colours.
 */
export function effectiveParts(
	design: DesignDocument,
	colors: readonly string[]
): Array< { readonly id: string; readonly colors: readonly string[] } > {
	const ids = [ SHARED, ...designsOf( design ).map( ( item ) => item.id ) ];

	return ids
		.map( ( id ) => ( {
			id,
			colors: colors.filter( ( key ) => partOf( design, key ) === id ),
		} ) )
		.filter( ( part ) => part.colors.length > 0 );
}

/**
 * The document without colours that are not chosen and without colour
 * designs left without colours; without any, `designs` is left out (the
 * server's rule, so what is saved is what the browser renders).
 *
 * @param design The document.
 * @param colors The chosen colours.
 */
export function prunedDesigns(
	design: DesignDocument,
	colors: readonly string[]
): DesignDocument {
	return keptDesigns(
		design,
		( design.designs ?? [] ).map( ( item ) => ( {
			...item,
			colors: item.colors.filter( ( key ) => colors.includes( key ) ),
		} ) )
	);
}

/**
 * The name of a design in the editor: "Design 2" for the first colour
 * design (the shared design is design 1), counted in the list's order, so
 * the names follow when one is removed.
 *
 * @param design The document.
 * @param id     A colour design's id, or SHARED.
 */
export function designNumber( design: DesignDocument, id: string ): number {
	return id === SHARED
		? 1
		: designsOf( design ).findIndex( ( item ) => item.id === id ) + 2;
}

/**
 * A colour with a new design of its own: a copy of the design it used. Null
 * when there are MAX_DESIGNS already.
 *
 * @param design The document.
 * @param key    Colour key.
 */
export function newColorDesign(
	design: DesignDocument,
	key: string
): { readonly design: DesignDocument; readonly id: string } | null {
	const used = new Set( designsOf( design ).map( ( item ) => item.id ) );
	const free = Array.from(
		{ length: MAX_DESIGNS },
		( _, index ) => `d${ index + 1 }`
	).find( ( id ) => ! used.has( id ) );

	if ( free === undefined ) {
		return null;
	}

	const copy = partDoc( design, partOf( design, key ) );
	const without = withoutColor( design, key );

	return {
		id: free,
		design: {
			...without,
			designs: [
				...designsOf( without ),
				{
					id: free,
					colors: [ key ],
					sides: copy.sides,
					...( copy.background === undefined
						? {}
						: { background: copy.background } ),
					...( copy.threads === undefined
						? {}
						: { threads: copy.threads } ),
				},
			],
		},
	};
}

/** How two designs are joined when a colour moves into another design. */
export type MergeMode = 'target' | 'mine' | 'both';

/**
 * A colour moved into another design (ADR-096 pkt. 2):
 * - `target`: the colour takes the design it moves to;
 * - `mine`: the colour's design replaces it, for every colour in it;
 * - `both`: the colour's layers are put on top of it.
 * A colour design left without colours is removed.
 *
 * @param design The document.
 * @param key    Colour key.
 * @param target The design it moves to: a colour design's id, or SHARED.
 * @param mode   How the designs are joined.
 */
export function moveColor(
	design: DesignDocument,
	key: string,
	target: string,
	mode: MergeMode
): DesignDocument {
	const source = partOf( design, key );

	if ( source === target ) {
		return design;
	}

	const mine = partDoc( design, source );
	const theirs = partDoc( design, target );
	let next = design;

	if ( mode === 'mine' ) {
		next = withPart( next, target, mine );
	} else if ( mode === 'both' ) {
		next = withPart( next, target, mergedParts( theirs, mine ) );
	}

	next = withoutColor( next, key );

	return target === SHARED
		? next
		: {
				...next,
				designs: designsOf( next ).map( ( item ) =>
					item.id === target
						? { ...item, colors: [ ...item.colors, key ] }
						: item
				),
			};
}

/**
 * The document with these colour designs: those left without colours are
 * removed, and without any `designs` is left out.
 *
 * @param design  The document.
 * @param designs The colour designs.
 */
function keptDesigns(
	design: DesignDocument,
	designs: readonly ColorDesign[]
): DesignDocument {
	const kept = designs.filter( ( item ) => item.colors.length > 0 );
	const rest: DesignDocument = {
		version: design.version,
		sides: design.sides,
		...( design.background === undefined
			? {}
			: { background: design.background } ),
		...( design.threads === undefined ? {} : { threads: design.threads } ),
		...( design.stitch === undefined ? {} : { stitch: design.stitch } ),
	};

	return kept.length === 0 ? rest : { ...rest, designs: kept };
}

/**
 * The document with a colour out of its colour design (it uses the shared
 * design then).
 *
 * @param design The document.
 * @param key    Colour key.
 */
function withoutColor( design: DesignDocument, key: string ): DesignDocument {
	return keptDesigns(
		design,
		designsOf( design ).map( ( item ) => ( {
			...item,
			colors: item.colors.filter( ( other ) => other !== key ),
		} ) )
	);
}

/**
 * Two designs in one: per side the first's objects, then the second's on
 * top (an id already used gets a new one); a side's background colour and
 * threads come from the first when it has them.
 *
 * @param first  The design kept underneath.
 * @param second The design put on top.
 */
function mergedParts(
	first: DesignDocument,
	second: DesignDocument
): DesignDocument {
	const sides: Record< string, readonly DesignObject[] > = {
		...first.sides,
	};

	for ( const [ side, objects ] of Object.entries( second.sides ) ) {
		const under = sides[ side ] ?? [];
		const ids = new Set( under.map( ( object ) => object.id ) );

		sides[ side ] = [
			...under,
			...objects.map( ( object ) =>
				ids.has( object.id ) ? { ...object, id: newId() } : object
			),
		];
	}

	const background = { ...second.background, ...first.background };
	const threads = { ...second.threads, ...first.threads };

	return {
		version: first.version,
		sides,
		...( Object.keys( background ).length === 0 ? {} : { background } ),
		...( Object.keys( threads ).length === 0 ? {} : { threads } ),
	};
}
