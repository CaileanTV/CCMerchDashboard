/**
 * Variations in the designer (ADR-088): what the side panel shows while a
 * variation is edited, and how a change is written back. A variation has
 * its own look (OverlaySettings::LOOK_KEYS on the server); the alert box,
 * the text boxes' style and the canvas grid stay the overlay's.
 */
import { parseLayout, parseOverlaySettings } from '../../../api/alerts';
import { MAX_VARIATIONS } from '../../../../overlay/look';
import type { Variation } from '../../../../overlay/look';
import type { OverlayLayout, OverlaySettings } from '../../../types/alerts';

/** The settings a variation sets (besides its layout and sound). */
export const LOOK_KEYS = [
	'template',
	'secondary_template',
	'font_family',
	'font_size',
	'font_weight',
	'text_color',
	'text_opacity',
	'text_shadow',
	'text_shadow_enabled',
	'text_align',
	'text_fit',
	'line_height',
	'letter_spacing',
	'word_spacing',
	'text_direction',
	'italic',
	'underline',
	'text_case',
	'stroke_width',
	'stroke_color',
	'stroke_opacity',
	'highlight',
	'margin',
	'number_format',
	'max_name_length',
	'image_size',
	'anim_duration',
	'display_duration',
	'display_from_sound',
	'audio_volume',
	'layout_mode',
	'animations',
	'text_fills',
	'text_frame',
	'text_timing',
	'tts',
] as const satisfies ReadonlyArray< keyof OverlaySettings >;

type LookKey = ( typeof LOOK_KEYS )[ number ];

function pick(
	source: Readonly< Record< string, unknown > >
): Partial< Record< LookKey, unknown > > {
	const look: Partial< Record< LookKey, unknown > > = {};

	for ( const key of LOOK_KEYS ) {
		if ( key in source ) {
			look[ key ] = source[ key ];
		}
	}

	return look;
}

/**
 * What the panel and the canvas show while a variation is edited: the
 * overlay's settings with the variation's look on top.
 *
 * @param values    The overlay's settings.
 * @param layout    The overlay's layout.
 * @param variation The variation.
 */
export function variationView(
	values: OverlaySettings,
	layout: OverlayLayout,
	variation: Variation
): { settings: OverlaySettings; layout: OverlayLayout } {
	const own = parseLayout( variation.look.layout );
	const view: OverlayLayout =
		own === null ? layout : { ...own, canvas: layout.canvas };
	const settings = parseOverlaySettings(
		{
			...values,
			...pick( variation.look ),
			show_secondary: view.elements.secondary_text.visible,
		},
		view
	);

	return { settings: settings ?? values, layout: view };
}

/**
 * Writes a change made while a variation is edited: its look gets the look
 * keys and the layout's elements, the overlay keeps the rest.
 *
 * @param values    The overlay's settings.
 * @param layout    The overlay's layout.
 * @param variation The variation.
 * @param settings  The changed settings as shown.
 * @param view      The changed layout as shown.
 */
export function writeVariation(
	values: OverlaySettings,
	layout: OverlayLayout,
	variation: Variation,
	settings: OverlaySettings,
	view: OverlayLayout
): { settings: OverlaySettings; layout: OverlayLayout } {
	const shared: Partial< OverlaySettings > = {};

	for ( const [ key, value ] of Object.entries( settings ) ) {
		if (
			! ( LOOK_KEYS as readonly string[] ).includes( key ) &&
			key !== 'show_secondary' &&
			key !== 'variations'
		) {
			( shared as Record< string, unknown > )[ key ] = value;
		}
	}

	const next: Variation = {
		...variation,
		look: {
			...variation.look,
			...pick( settings as unknown as Record< string, unknown > ),
			layout: { elements: view.elements, canvas: view.canvas },
		},
	};

	return {
		settings: {
			...values,
			...shared,
			variations: values.variations.map( ( item ) =>
				item.id === variation.id ? next : item
			),
		},
		layout: { ...layout, canvas: view.canvas },
	};
}

/**
 * The elements of every free layout moved with the alert box (their boxes
 * are on the canvas): the overlay's and each variation's, except the one
 * the move came from (it moved already).
 *
 * @param settings The overlay's settings after the move.
 * @param layout   The overlay's layout.
 * @param dx       Horizontal move.
 * @param dy       Vertical move.
 * @param moved    '' when the overlay's own elements moved already, else the variation's id.
 */
export function followBox(
	settings: OverlaySettings,
	layout: OverlayLayout,
	dx: number,
	dy: number,
	moved: string
): { settings: OverlaySettings; layout: OverlayLayout } {
	if ( dx === 0 && dy === 0 ) {
		return { settings, layout };
	}

	const shift = ( elements: OverlayLayout[ 'elements' ] ) =>
		Object.fromEntries(
			Object.entries( elements ).map( ( [ key, box ] ) => [
				key,
				{ ...box, x: box.x + dx, y: box.y + dy },
			] )
		) as OverlayLayout[ 'elements' ];

	return {
		settings: {
			...settings,
			variations: settings.variations.map( ( variation ) => {
				const own = parseLayout( variation.look.layout );

				return variation.id === moved ||
					own === null ||
					variation.look.layout_mode !== 'free'
					? variation
					: {
							...variation,
							look: {
								...variation.look,
								layout: {
									...own,
									elements: shift( own.elements ),
								},
							},
						};
			} ),
		},
		layout:
			moved !== '' && settings.layout_mode === 'free'
				? { ...layout, elements: shift( layout.elements ) }
				: layout,
	};
}

/**
 * A new variation: the overlay's current look, on, without conditions and
 * with the overlay's sounds.
 *
 * @param values The overlay's settings.
 * @param layout The overlay's layout.
 * @param name   Its name.
 */
export function newVariation(
	values: OverlaySettings,
	layout: OverlayLayout,
	name: string
): Variation {
	const id = Array.from(
		window.crypto.getRandomValues( new Uint8Array( 6 ) ),
		( byte ) => byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );

	return {
		id: `v${ id }`,
		name,
		enabled: true,
		conditions: { min_amount: 0, min_quantity: 0, categories: [] },
		look: {
			...pick( values as unknown as Record< string, unknown > ),
			layout: { elements: layout.elements, canvas: layout.canvas },
			audio_default_sound: 0,
		},
	};
}

/**
 * Whether another variation can be added.
 *
 * @param values The overlay's settings.
 */
export function canAddVariation( values: OverlaySettings ): boolean {
	return values.variations.length < MAX_VARIATIONS;
}
