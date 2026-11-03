/**
 * Alert designer 2.0 (ADR-088): the dashboard's settings as the overlay's
 * renderer draws them, and the sample alert of the canvas. The canvas uses
 * the same renderer as the overlay (assets/src/overlay/render.ts), so the
 * preview is what OBS shows.
 */
import { parseLook } from '../../../../overlay/render';
import type { AlertLook } from '../../../../overlay/render';
import { fillLook } from '../../../../overlay/textfill';
import type { TextPart } from '../../../../overlay/look';
import type {
	AlertsImages,
	OverlayLayout,
	OverlaySettings,
} from '../../../types/alerts';
import { SAMPLE_VALUES } from '../designer';

const VARIABLES =
	/(\$(?:UserName|FirstName|OrderNumber|Product|Quantity|Category|Variation|Price|LineTotal|Total))/;

/** Sample amounts per number format. */
const SAMPLE_AMOUNTS: Readonly<
	Record< OverlaySettings[ 'number_format' ], Record< string, string > >
> = {
	shop: {},
	whole: { $Price: '249 kr.', $LineTotal: '249 kr.', $Total: '498 kr.' },
	plain: { $Price: '249,00', $LineTotal: '249,00', $Total: '498,00' },
};

/**
 * A template with the sample values in pieces, the values marked, as the
 * server sends an alert's text (AlertTemplate::segments()).
 *
 * @param template      Template.
 * @param numberFormat  How amounts are written.
 * @param maxNameLength Longest username; 0 for no limit.
 */
export function sampleParts(
	template: string,
	numberFormat: OverlaySettings[ 'number_format' ] = 'shop',
	maxNameLength = 0
): TextPart[] {
	const parts: TextPart[] = [];

	for ( const piece of template.split( VARIABLES ) ) {
		if ( piece === '' ) {
			continue;
		}

		if ( ! VARIABLES.test( piece ) || ! ( piece in SAMPLE_VALUES ) ) {
			parts.push( { text: piece, value: false } );
			continue;
		}

		let value =
			SAMPLE_AMOUNTS[ numberFormat ][ piece ] ??
			SAMPLE_VALUES[ piece ] ??
			'';

		if (
			( piece === '$UserName' || piece === '$FirstName' ) &&
			maxNameLength > 0 &&
			Array.from( value ).length > maxNameLength
		) {
			value = `${ Array.from( value )
				.slice( 0, maxNameLength )
				.join( '' ) }…`;
		}

		if ( value !== '' ) {
			parts.push( { text: value, value: true } );
		}
	}

	return parts;
}

/**
 * The look the renderer draws: the settings, the layout's elements and the
 * fills with the images the owner may use.
 *
 * @param settings Settings.
 * @param layout   Layout.
 * @param images   The owner's and the library's images; null for none.
 */
export function lookOf(
	settings: OverlaySettings,
	layout: OverlayLayout,
	images: AlertsImages | null
): AlertLook {
	const available =
		images === null ? [] : [ ...images.own, ...images.library ];

	return parseLook(
		{
			...settings,
			elements: layout.elements,
			show_secondary:
				settings.show_secondary && settings.secondary_template !== '',
			text_shadow: settings.text_shadow_enabled
				? settings.text_shadow
				: '',
			frame: settings.text_frame.enabled ? settings.text_frame : null,
			fills: {
				main: fillLook( settings.text_fills.main_text, available ),
				secondary: fillLook(
					settings.text_fills.secondary_text,
					available
				),
			},
		},
		window.location.origin
	);
}
