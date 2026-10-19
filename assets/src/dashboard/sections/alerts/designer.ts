/**
 * Pure helpers of the overlay designer (ADR-065 pkt. 3): moving and
 * resizing boxes on the 1920 × 1080 canvas with snap-to-grid and the
 * server's bounds, the default layout, and the sample text of the preview.
 */
import { BOX_LIMITS } from '../../types/alerts';
import type {
	LayoutBox,
	LayoutElement,
	OverlayLayout,
} from '../../types/alerts';

/** MerchAlert's default layout (OverlaySettings::default_layout()). */
export const DEFAULT_LAYOUT: OverlayLayout = {
	elements: {
		product_image: {
			x: 40,
			y: 880,
			w: 120,
			h: 120,
			visible: true,
			lock_aspect: true,
			border_radius: 4,
		},
		main_text: {
			x: 176,
			y: 895,
			w: 700,
			h: 80,
			visible: true,
			lock_aspect: false,
			border_radius: 0,
		},
		secondary_text: {
			x: 176,
			y: 985,
			w: 700,
			h: 50,
			visible: false,
			lock_aspect: false,
			border_radius: 0,
		},
	},
	canvas: { snap_grid: false, grid_size: 10 },
};

/** Sample values of the preview; never real customer data. */
export const SAMPLE_VALUES: Readonly< Record< string, string > > = {
	$UserName: 'TestFan',
	$FirstName: 'TestFan',
	$OrderNumber: '0001',
	$Product: 'Hoodie',
	$Quantity: '1',
	$Category: 'Merch',
	$Variation: 'Size: L',
};

function clamp( value: number, range: { min: number; max: number } ): number {
	return Math.min( range.max, Math.max( range.min, Math.round( value ) ) );
}

/**
 * A value on the grid when snapping is on.
 *
 * @param value  Pixels.
 * @param layout Layout (canvas settings).
 */
export function snap( value: number, layout: OverlayLayout ): number {
	const grid = layout.canvas.grid_size;

	return layout.canvas.snap_grid && grid > 1
		? Math.round( value / grid ) * grid
		: Math.round( value );
}

/**
 * A box moved to a new position (canvas pixels).
 *
 * @param box    Box.
 * @param x      New left edge.
 * @param y      New top edge.
 * @param layout Layout (canvas settings).
 */
export function moveBox(
	box: LayoutBox,
	x: number,
	y: number,
	layout: OverlayLayout
): LayoutBox {
	return {
		...box,
		x: clamp( snap( x, layout ), BOX_LIMITS.x ),
		y: clamp( snap( y, layout ), BOX_LIMITS.y ),
	};
}

/**
 * A box with a new size (canvas pixels). With `lock_aspect`, the height
 * follows the width.
 *
 * @param box    Box.
 * @param w      New width.
 * @param h      New height.
 * @param layout Layout (canvas settings).
 */
export function resizeBox(
	box: LayoutBox,
	w: number,
	h: number,
	layout: OverlayLayout
): LayoutBox {
	const width = clamp( snap( w, layout ), BOX_LIMITS.w );
	const height = box.lock_aspect
		? clamp( ( width * box.h ) / box.w, BOX_LIMITS.h )
		: clamp( snap( h, layout ), BOX_LIMITS.h );

	return { ...box, w: width, h: height };
}

/**
 * The layout with one box replaced.
 *
 * @param layout  Layout.
 * @param element Element.
 * @param box     New box.
 */
export function withBox(
	layout: OverlayLayout,
	element: LayoutElement,
	box: LayoutBox
): OverlayLayout {
	return { ...layout, elements: { ...layout.elements, [ element ]: box } };
}

/**
 * A template with the sample values, in one pass like the server.
 *
 * @param template Template.
 */
export function sampleText( template: string ): string {
	return template.replace(
		/\$(UserName|FirstName|OrderNumber|Product|Quantity|Category|Variation)/g,
		( variable ) => SAMPLE_VALUES[ variable ] ?? variable
	);
}

/**
 * The text box background as rgba().
 *
 * @param hex     Six-digit hex color.
 * @param opacity Opacity in percent.
 */
export function background( hex: string, opacity: number ): string {
	const channel = ( start: number ) =>
		Number.parseInt( hex.slice( start, start + 2 ), 16 ) || 0;

	return `rgba(${ channel( 1 ) },${ channel( 3 ) },${ channel( 5 ) },${
		opacity / 100
	})`;
}
