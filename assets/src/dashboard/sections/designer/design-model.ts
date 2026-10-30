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
}

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

	return { version: DESIGN_VERSION, sides };
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

/**
 * The sides that show something (at least one visible object), in the
 * document's order: the sides a submission renders (the server's
 * `SubmissionFiles::used_sides()`).
 *
 * @param design The document.
 */
export function usedSides( design: DesignDocument ): string[] {
	return Object.entries( design.sides )
		.filter( ( [ , objects ] ) =>
			objects.some( ( object ) => ! object.hidden )
		)
		.map( ( [ side ] ) => side );
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

	if ( objects.length === 0 ) {
		delete sides[ side ];
	} else {
		sides[ side ] = objects;
	}

	return { version: DESIGN_VERSION, sides };
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
 * @param extra     Price of each used side after the first (decimal string).
 * @param sides     Used sides; one is included in the base price.
 */
export function quotePrice(
	basePrice: string,
	kind: 'percent' | 'fixed',
	value: string,
	decimals: number,
	extra = '0',
	sides = 1
): number | null {
	const parsed = Number( value.trim().replace( ',', '.' ) );
	const base =
		Number( basePrice ) + Number( extra ) * Math.max( 0, sides - 1 );

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
