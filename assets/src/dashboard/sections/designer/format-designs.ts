/**
 * One design shared by a poster's formats (ADR-118): per value of the first
 * axis (an orientation) a master design in its own print pixels, and on each
 * linked format side a copy fitted to that side's print file. The copy is
 * what is printed, so the server's rules for sides stay as they are. Pure
 * functions only.
 */

import {
	DESIGN_VERSION,
	SHARED,
	designsOf,
	partDoc,
	partOf,
	withBackground,
	withPart,
	type AdaptMode,
	type DesignDocument,
	type DesignObject,
	type FormatDesigns,
	type SharedFormat,
} from './design-model';

/** What the shared formats need of a side. */
export interface FormatView {
	readonly key: string;
	readonly colors: readonly string[];
	readonly sizes: readonly string[];
	readonly printWidth: number;
	readonly printHeight: number;
	/** A format group's side (ADR-115). */
	readonly format: boolean;
}

/** A print file's size. */
interface Box {
	readonly width: number;
	readonly height: number;
}

/** A move of an object: x' = ax·x + bx, y' = ay·y + by, its scale times s. */
interface Move {
	readonly ax: number;
	readonly bx: number;
	readonly ay: number;
	readonly by: number;
	readonly s: number;
}

/** The share of an edge an object may leave bare and still cover the area. */
const BARE = 0.02;

/**
 * The size of an object's upright bounding box.
 *
 * @param object The object.
 */
function bounds( object: DesignObject ): Box {
	const width = object.width * Math.abs( object.scaleX );
	const height = object.height * Math.abs( object.scaleY );
	const angle = ( object.angle * Math.PI ) / 180;
	const cos = Math.abs( Math.cos( angle ) );
	const sin = Math.abs( Math.sin( angle ) );

	return {
		width: width * cos + height * sin,
		height: width * sin + height * cos,
	};
}

/**
 * Whether an object covers a print file (within BARE of each edge), e.g. a
 * background photo: in `smart` it keeps covering every format.
 *
 * @param object The object.
 * @param box    The print file.
 */
export function covers( object: DesignObject, box: Box ): boolean {
	const size = bounds( object );

	return (
		object.x - size.width / 2 <= box.width * BARE &&
		object.x + size.width / 2 >= box.width * ( 1 - BARE ) &&
		object.y - size.height / 2 <= box.height * BARE &&
		object.y + size.height / 2 >= box.height * ( 1 - BARE )
	);
}

/**
 * Scaled evenly by s around the middle of each print file.
 *
 * @param from The master's print file.
 * @param to   The format's print file.
 * @param s    Scale.
 */
function centred( from: Box, to: Box, s: number ): Move {
	return {
		ax: s,
		bx: to.width / 2 - ( from.width / 2 ) * s,
		ay: s,
		by: to.height / 2 - ( from.height / 2 ) * s,
		s,
	};
}

/**
 * How an object of the master moves to a format:
 * - `fill`: the whole design covers the format (what sticks out is cut);
 * - `fit`: the whole design is inside the format;
 * - `smart`: an object that covers the master keeps covering; any other keeps
 *   its place relative to the print file, scaled by the shorter side.
 *
 * @param mode  The adaptation.
 * @param cover Whether the object covers (only `smart` asks).
 * @param from  The master's print file.
 * @param to    The format's print file.
 */
function moveOf( mode: AdaptMode, cover: boolean, from: Box, to: Box ): Move {
	const across = to.width / from.width;
	const down = to.height / from.height;

	if ( mode === 'fill' || ( mode === 'smart' && cover ) ) {
		return centred( from, to, Math.max( across, down ) );
	}

	if ( mode === 'fit' ) {
		return centred( from, to, Math.min( across, down ) );
	}

	return { ax: across, bx: 0, ay: down, by: 0, s: Math.min( across, down ) };
}

/**
 * A number with at most two decimals, as stored.
 *
 * @param value Number.
 */
const round = ( value: number ): number => Math.round( value * 100 ) / 100;

/**
 * A scale within the server's 0.01–100 (DesignValidator).
 *
 * @param value Scale.
 */
const scaled = ( value: number ): number =>
	Math.sign( value || 1 ) *
	Math.min(
		100,
		Math.max( 0.01, Math.round( Math.abs( value ) * 10000 ) / 10000 )
	);

/**
 * An object moved.
 *
 * @param object The object.
 * @param move   The move.
 */
function moved( object: DesignObject, move: Move ): DesignObject {
	return {
		...object,
		x: round( move.ax * object.x + move.bx ),
		y: round( move.ay * object.y + move.by ),
		scaleX: scaled( object.scaleX * move.s ),
		scaleY: scaled( object.scaleY * move.s ),
	};
}

/**
 * An object moved back: the inverse of moved().
 *
 * @param object The object.
 * @param move   The move it was given.
 */
function unmoved( object: DesignObject, move: Move ): DesignObject {
	return {
		...object,
		x: round( ( object.x - move.bx ) / move.ax ),
		y: round( ( object.y - move.by ) / move.ay ),
		scaleX: scaled( object.scaleX / move.s ),
		scaleY: scaled( object.scaleY / move.s ),
	};
}

/**
 * The master's objects fitted to a format.
 *
 * @param objects The master's objects.
 * @param from    The master's print file.
 * @param to      The format's print file.
 * @param mode    The adaptation.
 */
export function adapted(
	objects: readonly DesignObject[],
	from: Box,
	to: Box,
	mode: AdaptMode
): DesignObject[] {
	return objects.map( ( object ) =>
		moved( object, moveOf( mode, covers( object, from ), from, to ) )
	);
}

/**
 * The master's objects after a format's fitted copy was edited: each object
 * moved back. In `smart` an object that covers the format covers the master
 * too (scaled up to it when the way back leaves an edge bare).
 *
 * @param edited The format's objects as edited.
 * @param from   The master's print file.
 * @param to     The format's print file.
 * @param mode   The adaptation.
 */
export function toMaster(
	edited: readonly DesignObject[],
	from: Box,
	to: Box,
	mode: AdaptMode
): DesignObject[] {
	return edited.map( ( object ) => {
		const cover = mode === 'smart' && covers( object, to );
		const back = unmoved( object, moveOf( mode, cover, from, to ) );

		if ( ! cover || covers( back, from ) ) {
			return back;
		}

		// Grown around its place until it covers the master again.
		const size = bounds( back );
		const grow = Math.max(
			( 2 * Math.max( back.x, from.width - back.x ) ) / size.width,
			( 2 * Math.max( back.y, from.height - back.y ) ) / size.height
		);

		return {
			...back,
			scaleX: scaled( back.scaleX * grow ),
			scaleY: scaled( back.scaleY * grow ),
		};
	} );
}

/**
 * The value (orientation) a format side shares its design with, or null.
 *
 * @param part The design.
 * @param side Side key.
 */
export function linkOf( part: DesignDocument, side: string ): string | null {
	return part.formats?.links[ side ] ?? null;
}

/**
 * How a linked format side is fitted.
 *
 * @param part The design.
 * @param side Side key.
 */
export function adaptOf( part: DesignDocument, side: string ): AdaptMode {
	return part.formats?.fit[ side ] ?? 'smart';
}

/**
 * The other format sides that share a side's design.
 *
 * @param part The design.
 * @param side Side key.
 */
export function sharedWith( part: DesignDocument, side: string ): string[] {
	const link = linkOf( part, side );

	return link === null
		? []
		: Object.entries( part.formats?.links ?? {} )
				.filter( ( [ key, value ] ) => value === link && key !== side )
				.map( ( [ key ] ) => key );
}

/**
 * Whether a format side is sold: its value and one of its sizes are chosen.
 *
 * @param view   The side.
 * @param link   Its value.
 * @param sizes  The chosen sizes.
 * @param colors The chosen colour keys (values).
 */
function sold(
	view: FormatView,
	link: string,
	sizes: readonly string[],
	colors: readonly string[]
): boolean {
	return (
		view.format &&
		view.colors.includes( link ) &&
		colors.includes( link ) &&
		( view.sizes.length === 0 ||
			view.sizes.some( ( size ) => sizes.includes( size ) ) )
	);
}

/**
 * The design with a fitted copy of its master on every linked format side
 * that is sold, and none on one that is not (it would be a design on a size
 * not chosen). The master stays.
 *
 * @param part   The design (of one colour design or the shared one).
 * @param views  The sides of the base product.
 * @param sizes  The chosen sizes.
 * @param colors The chosen colour keys.
 */
export function synced(
	part: DesignDocument,
	views: readonly FormatView[],
	sizes: readonly string[],
	colors: readonly string[]
): DesignDocument {
	const formats = part.formats;

	if ( formats === undefined ) {
		return part;
	}

	const sides: Record< string, readonly DesignObject[] > = { ...part.sides };
	const background: Record< string, string > = { ...part.background };

	for ( const [ side, link ] of Object.entries( formats.links ) ) {
		const view = views.find( ( candidate ) => candidate.key === side );
		const master = formats.shared[ link ];

		delete sides[ side ];
		delete background[ side ];

		if (
			view === undefined ||
			master === undefined ||
			! sold( view, link, sizes, colors )
		) {
			continue;
		}

		const objects = adapted(
			master.objects,
			master,
			{ width: view.printWidth, height: view.printHeight },
			adaptOf( part, side )
		);

		if ( master.background !== undefined ) {
			background[ side ] = master.background;
		}

		if ( objects.length > 0 || master.background !== undefined ) {
			sides[ side ] = objects;
		}
	}

	const { background: _old, ...rest } = part;

	return {
		...rest,
		version: DESIGN_VERSION,
		sides,
		...( Object.keys( background ).length === 0 ? {} : { background } ),
	};
}

/**
 * The design with new shared formats, synced.
 *
 * @param part    The design.
 * @param formats The shared formats; null for none.
 * @param views   The sides of the base product.
 * @param sizes   The chosen sizes.
 * @param colors  The chosen colour keys.
 */
function withFormats(
	part: DesignDocument,
	formats: FormatDesigns | null,
	views: readonly FormatView[],
	sizes: readonly string[],
	colors: readonly string[]
): DesignDocument {
	const { formats: _old, ...rest } = part;

	return formats === null || Object.keys( formats.links ).length === 0
		? { ...rest, version: DESIGN_VERSION }
		: synced( { ...rest, formats }, views, sizes, colors );
}

/**
 * The design with a side's objects edited: on a linked format side the
 * edit goes back to the master and out to every format that shares it.
 *
 * @param part    The design.
 * @param views   The sides of the base product.
 * @param side    Side key.
 * @param objects The side's objects as edited.
 * @param sizes   The chosen sizes.
 * @param colors  The chosen colour keys.
 */
export function withSideObjects(
	part: DesignDocument,
	views: readonly FormatView[],
	side: string,
	objects: readonly DesignObject[],
	sizes: readonly string[],
	colors: readonly string[]
): DesignDocument {
	const link = linkOf( part, side );
	const view = views.find( ( candidate ) => candidate.key === side );
	const formats = part.formats;
	const master = link === null ? undefined : formats?.shared[ link ];

	if (
		link === null ||
		view === undefined ||
		formats === undefined ||
		master === undefined
	) {
		const parts = { ...part.sides };

		// A side with a background colour stays in the document without objects (ADR-095).
		if ( objects.length === 0 && part.background?.[ side ] === undefined ) {
			delete parts[ side ];
		} else {
			parts[ side ] = objects;
		}

		return { ...part, version: DESIGN_VERSION, sides: parts };
	}

	const next: SharedFormat = {
		...master,
		objects: toMaster(
			objects,
			master,
			{ width: view.printWidth, height: view.printHeight },
			adaptOf( part, side )
		),
	};

	return withFormats(
		part,
		{ ...formats, shared: { ...formats.shared, [ link ]: next } },
		views,
		sizes,
		colors
	);
}

/**
 * The design with a background colour on some sides; on a linked format
 * side it is the shared design's, so every format sharing it gets it.
 *
 * @param part   The design.
 * @param views  The sides of the base product.
 * @param keys   Side keys.
 * @param hex    `#rrggbb`, or null to remove it.
 * @param sizes  The chosen sizes.
 * @param colors The chosen colour keys.
 */
export function withSideBackground(
	part: DesignDocument,
	views: readonly FormatView[],
	keys: readonly string[],
	hex: string | null,
	sizes: readonly string[],
	colors: readonly string[]
): DesignDocument {
	const own = keys.filter( ( key ) => linkOf( part, key ) === null );
	const links = new Set(
		keys
			.map( ( key ) => linkOf( part, key ) )
			.filter( ( link ) => link !== null )
	);
	const next = own.length === 0 ? part : withBackground( part, own, hex );
	const formats = next.formats;

	if ( links.size === 0 || formats === undefined ) {
		return next;
	}

	const shared: Record< string, SharedFormat > = { ...formats.shared };

	for ( const link of links ) {
		const master = shared[ link ];

		if ( master !== undefined ) {
			const { background: _old, ...rest } = master;

			shared[ link ] =
				hex === null
					? rest
					: { ...rest, background: hex.toLowerCase() };
		}
	}

	return withFormats( next, { ...formats, shared }, views, sizes, colors );
}

/**
 * The design with a format side on its own design: it keeps its fitted copy
 * to go on from. A master no side uses any more is dropped.
 *
 * @param part The design.
 * @param side Side key.
 */
export function unlinked( part: DesignDocument, side: string ): DesignDocument {
	const formats = part.formats;

	if ( formats === undefined || formats.links[ side ] === undefined ) {
		return part;
	}

	const links = { ...formats.links };
	const fit = { ...formats.fit };

	delete links[ side ];
	delete fit[ side ];

	const used = new Set( Object.values( links ) );
	const shared = Object.fromEntries(
		Object.entries( formats.shared ).filter( ( [ key ] ) =>
			used.has( key )
		)
	);
	const { formats: _old, ...rest } = part;

	return Object.keys( links ).length === 0
		? { ...rest, version: DESIGN_VERSION }
		: { ...rest, version: DESIGN_VERSION, formats: { shared, links, fit } };
}

/**
 * The design with a format side on its value's shared design; its own
 * design is dropped. Without a shared design yet, the side's design
 * becomes it.
 *
 * @param part   The design.
 * @param views  The sides of the base product.
 * @param side   Side key.
 * @param sizes  The chosen sizes.
 * @param colors The chosen colour keys.
 */
export function linked(
	part: DesignDocument,
	views: readonly FormatView[],
	side: string,
	sizes: readonly string[],
	colors: readonly string[]
): DesignDocument {
	const view = views.find( ( candidate ) => candidate.key === side );
	const link = view?.format === true ? view.colors[ 0 ] : undefined;

	if ( view === undefined || link === undefined ) {
		return part;
	}

	const formats: FormatDesigns = part.formats ?? {
		shared: {},
		links: {},
		fit: {},
	};
	const background = part.background?.[ side ];
	const master: SharedFormat = formats.shared[ link ] ?? {
		width: view.printWidth,
		height: view.printHeight,
		objects: part.sides[ side ] ?? [],
		...( background === undefined ? {} : { background } ),
	};

	return withFormats(
		part,
		{
			shared: { ...formats.shared, [ link ]: master },
			links: { ...formats.links, [ side ]: link },
			fit: formats.fit,
		},
		views,
		sizes,
		colors
	);
}

/**
 * The design with how a linked format side is fitted.
 *
 * @param part   The design.
 * @param views  The sides of the base product.
 * @param side   Side key.
 * @param mode   The adaptation.
 * @param sizes  The chosen sizes.
 * @param colors The chosen colour keys.
 */
export function withAdapt(
	part: DesignDocument,
	views: readonly FormatView[],
	side: string,
	mode: AdaptMode,
	sizes: readonly string[],
	colors: readonly string[]
): DesignDocument {
	const formats = part.formats;

	if ( formats === undefined || formats.links[ side ] === undefined ) {
		return part;
	}

	const fit = { ...formats.fit };

	if ( mode === 'smart' ) {
		delete fit[ side ];
	} else {
		fit[ side ] = mode;
	}

	return withFormats( part, { ...formats, fit }, views, sizes, colors );
}

/**
 * A new poster design with every format on its value's shared design
 * (ADR-118 pkt. 2); a design that has any format design is left as it is.
 *
 * @param part  The design.
 * @param views The sides of the base product.
 */
export function started(
	part: DesignDocument,
	views: readonly FormatView[]
): DesignDocument {
	const formats = views.filter(
		( view ) => view.format && view.colors.length > 0
	);

	if (
		part.formats !== undefined ||
		formats.length === 0 ||
		formats.some( ( view ) => ( part.sides[ view.key ] ?? [] ).length > 0 )
	) {
		return part;
	}

	const shared: Record< string, SharedFormat > = {};
	const links: Record< string, string > = {};

	for ( const view of formats ) {
		const link = view.colors[ 0 ] ?? '';

		shared[ link ] ??= {
			width: view.printWidth,
			height: view.printHeight,
			objects: [],
		};
		links[ view.key ] = link;
	}

	return {
		...part,
		version: DESIGN_VERSION,
		formats: { shared, links, fit: {} },
	};
}

/**
 * Every design of a document synced with the colours that use it (ADR-096),
 * and with `start` a new poster design started shared (started()).
 *
 * @param design The document.
 * @param views  The sides of the base product.
 * @param sizes  The chosen sizes.
 * @param colors The chosen colour keys.
 * @param start  Whether a design without format designs starts shared.
 */
export function syncedAll(
	design: DesignDocument,
	views: readonly FormatView[],
	sizes: readonly string[],
	colors: readonly string[],
	start: boolean
): DesignDocument {
	return [ SHARED, ...designsOf( design ).map( ( item ) => item.id ) ].reduce(
		( next, id ) => {
			const part = partDoc( next, id );
			const own = colors.filter( ( key ) => partOf( next, key ) === id );

			return withPart(
				next,
				id,
				synced(
					start ? started( part, views ) : part,
					views,
					sizes,
					own
				)
			);
		},
		design
	);
}

/**
 * Every design of a document without shared formats: each linked format
 * keeps its fitted copy as its own design (the administrator turned sharing
 * off, ADR-118).
 *
 * @param design The document.
 */
export function unshared( design: DesignDocument ): DesignDocument {
	return [ SHARED, ...designsOf( design ).map( ( item ) => item.id ) ].reduce(
		( next, id ) => {
			const { formats: _old, ...rest } = partDoc( next, id );

			return _old === undefined ? next : withPart( next, id, rest );
		},
		design
	);
}
