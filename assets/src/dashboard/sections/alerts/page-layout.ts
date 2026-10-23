/**
 * The setup of the creator's overlay settings page (step 25.9.6): which
 * cards stand in full width at the top or in the left or right column, in
 * which order, the order of the fields in each card and whether "Designs"
 * hides its preview. Always complete: parsePageLayout() normalizes like the
 * server (CreatorPageLayout::from_array()).
 */
import { __ } from '@wordpress/i18n';

import { isRecord } from '../../api/validate';

export const ZONES = [ 'full', 'left', 'right' ] as const;

export type Zone = ( typeof ZONES )[ number ];

/** The default place of every card (the page as before step 25.9.6). */
const DEFAULT_CARDS = {
	full: [ 'designs' ],
	left: [ 'browser_source', 'sounds', 'alert_text', 'text_style' ],
	right: [ 'box_image', 'animations', 'timing' ],
} as const;

export type PageCard = ( typeof DEFAULT_CARDS )[ Zone ][ number ];

/** The fields of the cards that have fields to order, in default order. */
export const PAGE_FIELDS = {
	alert_text: [ 'template', 'secondary_template', 'show_secondary' ],
	text_style: [
		'font',
		'font_size',
		'font_weight',
		'text_color',
		'text_align',
		'text_fit',
		'text_shadow',
	],
	box_image: [
		'bg_color',
		'bg_opacity',
		'border_radius',
		'padding',
		'image_size',
	],
	animations: [ 'main_text', 'secondary_text' ],
	timing: [ 'display_from_sound', 'display_duration', 'anim_duration' ],
} as const;

export type FieldCard = keyof typeof PAGE_FIELDS;

export type PageField = ( typeof PAGE_FIELDS )[ FieldCard ][ number ];

export interface PageLayout {
	readonly cards: Readonly< Record< Zone, readonly PageCard[] > >;
	readonly fields: Readonly< Record< FieldCard, readonly PageField[] > >;
	readonly designsCompact: boolean;
}

export const DEFAULT_PAGE_LAYOUT: PageLayout = {
	cards: DEFAULT_CARDS,
	fields: PAGE_FIELDS,
	designsCompact: false,
};

const ALL_CARDS: readonly string[] = ZONES.flatMap( ( zone ) => [
	...DEFAULT_CARDS[ zone ],
] );

function insert< T >( items: readonly T[], item: T, index: number ): T[] {
	const next = [ ...items ];
	next.splice( Math.min( index, next.length ), 0, item );

	return next;
}

/**
 * A complete setup: unknown and repeated keys are dropped, and a missing card
 * or field comes at its default place.
 *
 * @param value The server's `{ cards, fields, designs_compact }`.
 */
export function parsePageLayout( value: unknown ): PageLayout {
	const given = isRecord( value ) ? value : {};
	const cardsIn = isRecord( given.cards ) ? given.cards : {};
	const seen = new Set< string >();
	const cards = { full: [], left: [], right: [] } as Record<
		Zone,
		PageCard[]
	>;

	for ( const zone of ZONES ) {
		const list = cardsIn[ zone ];

		for ( const card of Array.isArray( list ) ? list : [] ) {
			if (
				typeof card === 'string' &&
				ALL_CARDS.includes( card ) &&
				! seen.has( card )
			) {
				seen.add( card );
				cards[ zone ].push( card as PageCard );
			}
		}
	}

	for ( const zone of ZONES ) {
		DEFAULT_CARDS[ zone ].forEach( ( card, index ) => {
			if ( ! seen.has( card ) ) {
				cards[ zone ] = insert( cards[ zone ], card, index );
			}
		} );
	}

	const fieldsIn = isRecord( given.fields ) ? given.fields : {};
	const fields = {} as Record< FieldCard, PageField[] >;

	for ( const card of Object.keys( PAGE_FIELDS ) as FieldCard[] ) {
		const defaults: readonly string[] = PAGE_FIELDS[ card ];
		const list = fieldsIn[ card ];
		let order: PageField[] = [];

		for ( const field of Array.isArray( list ) ? list : [] ) {
			if (
				typeof field === 'string' &&
				defaults.includes( field ) &&
				! order.includes( field as PageField )
			) {
				order.push( field as PageField );
			}
		}

		defaults.forEach( ( field, index ) => {
			if ( ! order.includes( field as PageField ) ) {
				order = insert( order, field as PageField, index );
			}
		} );

		fields[ card ] = order;
	}

	return { cards, fields, designsCompact: given.designs_compact === true };
}

/**
 * The setup as the server stores it.
 *
 * @param layout The setup.
 */
export function pageLayoutBody(
	layout: PageLayout
): Record< string, unknown > {
	return {
		cards: layout.cards,
		fields: layout.fields,
		designs_compact: layout.designsCompact,
	};
}

/**
 * Whether a card has fields to order.
 *
 * @param card Card.
 */
export function hasFields( card: PageCard ): card is FieldCard {
	return card in PAGE_FIELDS;
}

/**
 * A card's name (the creator page's titles).
 *
 * @param card Card.
 */
export function cardLabel( card: PageCard ): string {
	switch ( card ) {
		case 'designs':
			return __( 'Designs', 'merchboard' );
		case 'browser_source':
			return __( 'Browser source', 'merchboard' );
		case 'sounds':
			return __( 'Sounds', 'merchboard' );
		case 'alert_text':
			return __( 'Alert text', 'merchboard' );
		case 'text_style':
			return __( 'Text style', 'merchboard' );
		case 'box_image':
			return __( 'Box and image', 'merchboard' );
		case 'animations':
			return __( 'Animations and text fill', 'merchboard' );
		default:
			return __( 'Timing', 'merchboard' );
	}
}

/**
 * A field's name (the creator page's labels).
 *
 * @param field Field.
 */
export function fieldLabel( field: PageField ): string {
	const labels: Record< PageField, string > = {
		template: __( 'Text', 'merchboard' ),
		secondary_template: __( 'Secondary text', 'merchboard' ),
		show_secondary: __( 'Show the secondary text', 'merchboard' ),
		font: __( 'Font', 'merchboard' ),
		font_size: __( 'Font size (px)', 'merchboard' ),
		font_weight: __( 'Font weight', 'merchboard' ),
		text_color: __( 'Text color', 'merchboard' ),
		text_align: __( 'Alignment', 'merchboard' ),
		text_fit: __( 'Text size in the boxes', 'merchboard' ),
		text_shadow: __( 'Text shadow', 'merchboard' ),
		bg_color: __( 'Background color', 'merchboard' ),
		bg_opacity: __( 'Background opacity (%)', 'merchboard' ),
		border_radius: __( 'Corner radius (px)', 'merchboard' ),
		padding: __( 'Padding (px)', 'merchboard' ),
		image_size: __( 'Image quality (px)', 'merchboard' ),
		main_text: __( 'Main text', 'merchboard' ),
		secondary_text: __( 'Secondary text', 'merchboard' ),
		display_from_sound: __(
			'Show the alert as long as its sound plays',
			'merchboard'
		),
		display_duration: __( 'Display time (ms)', 'merchboard' ),
		anim_duration: __( 'Animation time (ms)', 'merchboard' ),
	};

	return labels[ field ];
}

/**
 * A zone's name.
 *
 * @param zone Zone.
 */
export function zoneLabel( zone: Zone ): string {
	switch ( zone ) {
		case 'full':
			return __( 'Full width (top)', 'merchboard' );
		case 'left':
			return __( 'Left column', 'merchboard' );
		default:
			return __( 'Right column', 'merchboard' );
	}
}
