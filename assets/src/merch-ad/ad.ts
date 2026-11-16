/**
 * The merch ad overlay's data and pure helpers (ADR-113): parsing the
 * configuration the page embeds, the pill with chat commands in bold and
 * the timing. Rendering is in index.ts.
 */

export type Design =
	'spotlight' | 'shelf' | 'carousel' | 'corner' | 'showcase' | 'stack';

export type Animation = 'fade' | 'slide' | 'pop' | 'zoom' | 'blur';

export type PillPosition = 'below' | 'over';

export type PillSize = 'small' | 'medium' | 'large';

export interface AdProduct {
	readonly name: string;
	readonly image: string;
	/** Whether the image has no background. */
	readonly cutout: boolean;
}

export interface Ad {
	readonly enabled: boolean;
	readonly design: Design;
	readonly note: string;
	/** Minutes between two showings. */
	readonly every: number;
	/** Seconds a showing lasts. */
	readonly seconds: number;
	readonly enter: Animation;
	readonly leave: Animation;
	readonly accent: string;
	readonly theme: 'dark' | 'light';
	/** The pill below the products, or over the product image (its lower third). */
	readonly pillPosition: PillPosition;
	readonly pillSize: PillSize;
	/** Whether the products' names are shown. */
	readonly showNames: boolean;
	/** The products and the pill glow in the accent colour. */
	readonly glow: boolean;
	/** The pill has a gradient (else one colour). */
	readonly pillGradient: boolean;
	/** Stack: how far the two products behind stick out (0 close – 100 spread). */
	readonly stackSpread: number;
	readonly products: readonly AdProduct[];
}

export interface Config {
	/** The status URL the overlay checks every 10 seconds. */
	readonly status: string;
	readonly version: string;
	/** When "show now" was last pressed (Unix seconds). */
	readonly showAt: number;
	/** The dashboard's preview: show at once and again and again. */
	readonly preview: boolean;
	readonly ad: Ad;
}

const DESIGNS: readonly Design[] = [
	'spotlight',
	'shelf',
	'carousel',
	'corner',
	'showcase',
	'stack',
];
const ANIMATIONS: readonly Animation[] = [
	'fade',
	'slide',
	'pop',
	'zoom',
	'blur',
];

/**
 * A whole number within a range, or null.
 *
 * @param value Value.
 * @param min   Minimum.
 * @param max   Maximum.
 */
function within( value: unknown, min: number, max: number ): number | null {
	return typeof value === 'number' &&
		Number.isInteger( value ) &&
		value >= min &&
		value <= max
		? value
		: null;
}

/**
 * A record or null.
 *
 * @param value Value.
 */
function record( value: unknown ): Record< string, unknown > | null {
	return typeof value === 'object' &&
		value !== null &&
		! Array.isArray( value )
		? ( value as Record< string, unknown > )
		: null;
}

/**
 * Validates the ad; null when anything is off.
 *
 * @param value The ad from the page.
 */
export function parseAd( value: unknown ): Ad | null {
	const ad = record( value );

	if ( ad === null || ! Array.isArray( ad.products ) ) {
		return null;
	}

	const products: AdProduct[] = [];

	for ( const item of ad.products.slice( 0, 12 ) ) {
		const product = record( item );

		if (
			product === null ||
			typeof product.name !== 'string' ||
			typeof product.image !== 'string' ||
			! /^https?:\/\//.test( product.image ) ||
			typeof product.cutout !== 'boolean'
		) {
			return null;
		}

		products.push( {
			name: product.name,
			image: product.image,
			cutout: product.cutout,
		} );
	}

	const every = within( ad.every, 1, 60 );
	const seconds = within( ad.seconds, 5, 60 );

	if (
		typeof ad.enabled !== 'boolean' ||
		! DESIGNS.includes( ad.design as Design ) ||
		typeof ad.note !== 'string' ||
		every === null ||
		seconds === null ||
		! ANIMATIONS.includes( ad.enter as Animation ) ||
		! ANIMATIONS.includes( ad.leave as Animation ) ||
		typeof ad.accent !== 'string' ||
		! /^#[0-9a-f]{6}$/i.test( ad.accent ) ||
		( ad.theme !== 'dark' && ad.theme !== 'light' ) ||
		( ad.pill_position !== 'below' && ad.pill_position !== 'over' ) ||
		! [ 'small', 'medium', 'large' ].includes( ad.pill_size as string ) ||
		typeof ad.show_names !== 'boolean' ||
		typeof ad.glow !== 'boolean' ||
		typeof ad.pill_gradient !== 'boolean' ||
		within( ad.stack_spread, 0, 100 ) === null
	) {
		return null;
	}

	return {
		enabled: ad.enabled,
		design: ad.design as Design,
		note: ad.note.slice( 0, 60 ),
		every,
		seconds,
		enter: ad.enter as Animation,
		leave: ad.leave as Animation,
		accent: ad.accent,
		theme: ad.theme,
		pillPosition: ad.pill_position,
		pillSize: ad.pill_size as PillSize,
		showNames: ad.show_names,
		glow: ad.glow,
		pillGradient: ad.pill_gradient,
		stackSpread: within( ad.stack_spread, 0, 100 ) ?? 50,
		products,
	};
}

/**
 * Validates the page's configuration; null when anything is off.
 *
 * @param value The parsed JSON.
 */
export function parseConfig( value: unknown ): Config | null {
	const config = record( value );
	const ad = config === null ? null : parseAd( config.ad );

	if (
		config === null ||
		ad === null ||
		typeof config.status !== 'string' ||
		typeof config.version !== 'string' ||
		typeof config.showAt !== 'number' ||
		typeof config.preview !== 'boolean'
	) {
		return null;
	}

	return {
		status: config.status,
		version: config.version,
		showAt: config.showAt,
		preview: config.preview,
		ad,
	};
}

/**
 * How far the stack's two products behind stick out to the sides, as a CSS
 * length of the product's width: 15 % (close) to 70 % (spread).
 *
 * @param spread The creator's 0–100.
 */
export function stackOffset( spread: number ): string {
	return `${ Math.round( 15 + Math.min( 100, Math.max( 0, spread ) ) * 0.55 ) }%`;
}

/**
 * The pill's parts: plain text and chat commands (`!merch`) to show in bold.
 *
 * @param note The text.
 */
export function pillParts(
	note: string
): Array< { readonly text: string; readonly command: boolean } > {
	return note
		.split( /(![A-Za-z0-9_]+)/ )
		.filter( ( part ) => part !== '' )
		.map( ( part ) => ( {
			text: part,
			command: /^![A-Za-z0-9_]+$/.test( part ),
		} ) );
}

/**
 * How many products a design shows at once.
 *
 * @param design Design.
 */
export function perView( design: Design ): number {
	switch ( design ) {
		case 'shelf':
			return 4;
		case 'showcase':
		case 'stack':
			return 3;
		case 'carousel':
			return Number.POSITIVE_INFINITY;
		default:
			return 1;
	}
}

/**
 * How long each view of a showing lasts, in milliseconds: the showing is
 * shared between the views, at least 3 seconds each.
 *
 * @param ad The ad.
 */
export function stepMs( ad: Ad ): number {
	const views = ad.design === 'carousel' ? 1 : viewsOf( ad ).length;

	return Math.max(
		3000,
		Math.floor( ( ad.seconds * 1000 ) / Math.max( 1, views ) )
	);
}

/**
 * The views of a showing: groups of products shown together. Spotlight and
 * corner show one product at a time, the shelf four, the showcase three
 * (turning one step at a time), the carousel all.
 *
 * @param ad The ad.
 */
export function viewsOf( ad: Ad ): AdProduct[][] {
	const products = [ ...ad.products ];
	const count = products.length;

	if ( count === 0 ) {
		return [];
	}

	if ( ad.design === 'carousel' ) {
		return [ products ];
	}

	if ( ad.design === 'showcase' || ad.design === 'stack' ) {
		// Every product gets its turn in the middle (in front): left, middle, right.
		return products.map( ( _, index ) => [
			products[ ( index - 1 + count ) % count ] as AdProduct,
			products[ index ] as AdProduct,
			products[ ( index + 1 ) % count ] as AdProduct,
		] );
	}

	const size = perView( ad.design );
	const views: AdProduct[][] = [];

	for ( let start = 0; start < count; start += size ) {
		views.push( products.slice( start, start + size ) );
	}

	return views;
}

/**
 * Milliseconds until the first showing: the preview at once, else after
 * 15 seconds (so the creator sees that it works) and then every `every`
 * minutes.
 *
 * @param config The configuration.
 */
export function firstDelayMs( config: Config ): number {
	return config.preview ? 300 : 15000;
}

/**
 * Milliseconds between the start of two showings.
 *
 * @param config The configuration.
 */
export function intervalMs( config: Config ): number {
	return config.preview
		? config.ad.seconds * 1000 + 3000
		: config.ad.every * 60000;
}
