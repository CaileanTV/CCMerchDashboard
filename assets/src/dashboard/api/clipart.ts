/**
 * A clipart with a definition as the designer API sends it (phase 56,
 * ADR-121): the base products it is offered on, the lock and, with parts,
 * how it is drawn. The atlas' pixels come from the asset route with
 * `atlas=1`. Anything that does not read whole is refused, so a clipart is
 * never drawn with a piece left out.
 */
import { isRecord } from './validate';
import type {
	AtlasPlace,
	ClipartArt,
	ClipartPaint,
	ClipartPart,
	ClipartRect,
	ClipartStep,
	GradientPaint,
} from '../sections/designer/clipart-model';

/** How a clipart with parts is drawn. */
export interface DesignerClipartArt {
	readonly art: ClipartArt;
	/** The atlas' size. */
	readonly atlas: { readonly width: number; readonly height: number };
	/** The colours to choose between; null for any colour. */
	readonly palette: readonly string[] | null;
}

/** What an asset with a definition adds. */
export interface DesignerClipartFields {
	/** The base products it is offered on; null for all. */
	readonly bases: readonly number[] | null;
	readonly locked: boolean;
	/** Null for a clipart without parts. */
	readonly art: DesignerClipartArt | null;
}

const HEX = /^#[0-9a-f]{6}$/;

const isInt = (
	value: unknown,
	min = Number.MIN_SAFE_INTEGER
): value is number =>
	typeof value === 'number' && Number.isInteger( value ) && value >= min;

/**
 * Every item, or null when one does not read.
 *
 * @param value Value.
 * @param item  Item parser.
 */
function all< T >(
	value: unknown,
	item: ( entry: unknown ) => T | null
): T[] | null {
	if ( ! Array.isArray( value ) ) {
		return null;
	}

	const read: T[] = [];

	for ( const entry of value ) {
		const parsed = item( entry );

		if ( parsed === null ) {
			return null;
		}

		read.push( parsed );
	}

	return read;
}

/**
 * A colour, or null.
 *
 * @param value Value.
 */
function color( value: unknown ): string | null {
	return typeof value === 'string' && HEX.test( value ) ? value : null;
}

/**
 * A rectangle, or null.
 *
 * @param value Value.
 */
function rect( value: unknown ): ClipartRect | null {
	return isRecord( value ) &&
		isInt( value.x ) &&
		isInt( value.y ) &&
		isInt( value.width, 1 ) &&
		isInt( value.height, 1 )
		? {
				x: value.x,
				y: value.y,
				width: value.width,
				height: value.height,
			}
		: null;
}

/**
 * A place in the atlas, or null.
 *
 * @param value Value.
 */
function place( value: unknown ): AtlasPlace | null {
	return isRecord( value ) && isInt( value.x, 0 ) && isInt( value.y, 0 )
		? { x: value.x, y: value.y }
		: null;
}

/**
 * A gradient, or null.
 *
 * @param value Value.
 */
export function parseGradient( value: unknown ): GradientPaint | null {
	if ( ! isRecord( value ) || value.type !== 'gradient' ) {
		return null;
	}

	const from = color( value.from );
	const to = color( value.to );

	return from !== null &&
		to !== null &&
		isInt( value.angle, 0 ) &&
		value.angle <= 359
		? { type: 'gradient', from, to, angle: value.angle }
		: null;
}

/**
 * A paint, or null.
 *
 * @param value Value.
 */
export function parsePaint( value: unknown ): ClipartPaint | null {
	if ( isRecord( value ) && value.type === 'solid' ) {
		const solid = color( value.color );

		return solid === null ? null : { type: 'solid', color: solid };
	}

	return parseGradient( value );
}

/**
 * A part, or null.
 *
 * @param value Value.
 */
function part( value: unknown ): ClipartPart | null {
	if ( ! isRecord( value ) || typeof value.label !== 'string' ) {
		return null;
	}

	const box = rect( value.box );
	const stroke = value.stroke === null ? null : color( value.stroke );
	const paint = parsePaint( value.paint );
	const gradient = parseGradient( value.gradient );

	return box !== null &&
		( value.stroke === null || stroke !== null ) &&
		typeof value.scale === 'number' &&
		value.scale >= 0.1 &&
		value.scale <= 1.5 &&
		paint !== null &&
		gradient !== null
		? {
				label: value.label,
				box,
				stroke,
				scale: value.scale,
				paint,
				gradient,
			}
		: null;
}

/**
 * A step of the drawing, or null.
 *
 * @param value Value.
 */
function step( value: unknown ): ClipartStep | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const area = rect( value.rect );

	if ( area === null ) {
		return null;
	}

	if ( value.type === 'image' ) {
		const image = place( value.image );

		return image === null ? null : { type: 'image', rect: area, image };
	}

	const fill = place( value.fill );
	const grown = value.grown === null ? null : place( value.grown );

	return value.type === 'part' &&
		isInt( value.part, 0 ) &&
		fill !== null &&
		( value.grown === null || grown !== null )
		? { type: 'part', part: value.part, rect: area, fill, grown }
		: null;
}

/**
 * How a clipart of a size is drawn, or null.
 *
 * @param value  Value.
 * @param width  The clipart's width.
 * @param height The clipart's height.
 */
function art(
	value: unknown,
	width: number,
	height: number
): DesignerClipartArt | null {
	if ( ! isRecord( value ) || ! isRecord( value.atlas ) ) {
		return null;
	}

	const parts = all( value.parts, part );
	const steps = all( value.steps, step );
	const palette = value.palette === null ? null : all( value.palette, color );
	const atlasWidth = value.atlas.width;
	const atlasHeight = value.atlas.height;

	if (
		parts === null ||
		steps === null ||
		( value.palette !== null && palette === null ) ||
		parts.length === 0 ||
		! isInt( atlasWidth, 1 ) ||
		! isInt( atlasHeight, 1 ) ||
		steps.some(
			( one ) => one.type === 'part' && parts[ one.part ] === undefined
		)
	) {
		return null;
	}

	return {
		art: { width, height, parts, steps },
		atlas: { width: atlasWidth, height: atlasHeight },
		palette,
	};
}

/**
 * What an asset with a definition adds; undefined for one without (a
 * clipart from before, an upload); null when it does not read.
 *
 * @param value  The asset as sent.
 * @param width  Its width.
 * @param height Its height.
 */
export function parseClipartFields(
	value: Record< string, unknown >,
	width: number,
	height: number
): DesignerClipartFields | null | undefined {
	if ( ! ( 'locked' in value ) ) {
		return undefined;
	}

	const bases =
		value.bases === null
			? null
			: all( value.bases, ( id ) => ( isInt( id, 1 ) ? id : null ) );
	const drawn =
		value.art === undefined ? null : art( value.art, width, height );

	if (
		typeof value.locked !== 'boolean' ||
		( value.bases !== null && bases === null ) ||
		( value.art !== undefined && drawn === null )
	) {
		return null;
	}

	return { bases, locked: value.locked, art: drawn };
}
