/**
 * Pure helpers of the overlay designer (ADR-065 pkt. 3): moving and
 * resizing boxes on the 1920 × 1080 canvas with snap-to-grid and the
 * server's bounds, moving several boxes together and aligning them (step
 * 25.6.6), the default layout, and the sample text of the preview.
 */
import { BOX_LIMITS, CANVAS } from '../../types/alerts';
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
	// Removed (the project owner's choice 2026-10-23): shows nothing.
	$OrderNumber: '',
	$Product: 'Hoodie',
	$Quantity: '1',
	$Category: 'Merch',
	$Variation: 'Size: L',
	$Price: '249,00 kr.',
	$LineTotal: '249,00 kr.',
	$Total: '498,00 kr.',
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
		/\$(UserName|FirstName|OrderNumber|Product|Quantity|Category|Variation|Price|LineTotal|Total)/g,
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

/**
 * Whether a text box with "grow" grows up: its middle is in the lower half
 * of the canvas, so it keeps its bottom edge (as the overlay does).
 *
 * @param box Box.
 */
export function growsUp( box: LayoutBox ): boolean {
	return 2 * box.y + box.h > CANVAS.height;
}

/**
 * Moves several boxes together: the lead box is moved (and snapped) as
 * moveBox() does, and the others move by the same distance.
 *
 * @param layout Layout (the boxes are replaced in it).
 * @param starts The boxes as they were when the drag started.
 * @param lead   The box that is dragged.
 * @param dx     Horizontal distance in canvas pixels.
 * @param dy     Vertical distance in canvas pixels.
 */
export function moveBoxes(
	layout: OverlayLayout,
	starts: Readonly< Partial< Record< LayoutElement, LayoutBox > > >,
	lead: LayoutElement,
	dx: number,
	dy: number
): OverlayLayout {
	const from = starts[ lead ];

	if ( from === undefined ) {
		return layout;
	}

	const to = moveBox( from, from.x + dx, from.y + dy, layout );
	let next = layout;

	for ( const [ element, box ] of Object.entries( starts ) as Array<
		[ LayoutElement, LayoutBox ]
	> ) {
		next = withBox( next, element, {
			...box,
			x: clamp( box.x + to.x - from.x, BOX_LIMITS.x ),
			y: clamp( box.y + to.y - from.y, BOX_LIMITS.y ),
		} );
	}

	return next;
}

/** Edges and centres boxes can be aligned to. */
export const ALIGNMENTS = [
	'left',
	'center',
	'right',
	'top',
	'middle',
	'bottom',
] as const;

export type Alignment = ( typeof ALIGNMENTS )[ number ];

/** What boxes are aligned to: the canvas or the selected boxes' outline. */
export type AlignTarget = 'canvas' | 'selection';

/**
 * Aligns boxes to the canvas or to the outline around all of them.
 *
 * @param layout    Layout.
 * @param elements  The boxes to align.
 * @param alignment Edge or centre.
 * @param target    Canvas or selection.
 */
export function alignBoxes(
	layout: OverlayLayout,
	elements: readonly LayoutElement[],
	alignment: Alignment,
	target: AlignTarget
): OverlayLayout {
	const boxes = elements.map( ( element ) => layout.elements[ element ] );

	if ( boxes.length === 0 ) {
		return layout;
	}

	const area =
		target === 'canvas'
			? { left: 0, top: 0, right: CANVAS.width, bottom: CANVAS.height }
			: {
					left: Math.min( ...boxes.map( ( box ) => box.x ) ),
					top: Math.min( ...boxes.map( ( box ) => box.y ) ),
					right: Math.max( ...boxes.map( ( box ) => box.x + box.w ) ),
					bottom: Math.max(
						...boxes.map( ( box ) => box.y + box.h )
					),
				};
	let next = layout;

	elements.forEach( ( element, index ) => {
		const box = boxes[ index ] as LayoutBox;
		let { x, y } = box;

		switch ( alignment ) {
			case 'left':
				x = area.left;
				break;
			case 'center':
				x = ( area.left + area.right - box.w ) / 2;
				break;
			case 'right':
				x = area.right - box.w;
				break;
			case 'top':
				y = area.top;
				break;
			case 'middle':
				y = ( area.top + area.bottom - box.h ) / 2;
				break;
			default:
				y = area.bottom - box.h;
		}

		next = withBox( next, element, {
			...box,
			x: clamp( x, BOX_LIMITS.x ),
			y: clamp( y, BOX_LIMITS.y ),
		} );
	} );

	return next;
}
