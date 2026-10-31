/**
 * Drawing a side's print area on its template in the base products form. A
 * drag outside the area draws a new one, a drag inside moves it. The number
 * fields stay the truth: the form sends them, and they can be typed in.
 */
import { __ } from '@wordpress/i18n';

import type { PrintArea } from './psd-template';

export interface Point {
	readonly x: number;
	readonly y: number;
}

/**
 * The area between two points of the template, inside it.
 *
 * @param start  Where the drag began.
 * @param end    Where it is now.
 * @param width  Template width.
 * @param height Template height.
 */
export function drawnArea(
	start: Point,
	end: Point,
	width: number,
	height: number
): PrintArea {
	const inside = ( value: number, most: number ): number =>
		Math.max( 0, Math.min( most, Math.round( value ) ) );
	const left = inside( Math.min( start.x, end.x ), width );
	const top = inside( Math.min( start.y, end.y ), height );

	return {
		x: left,
		y: top,
		width: inside( Math.max( start.x, end.x ), width ) - left,
		height: inside( Math.max( start.y, end.y ), height ) - top,
	};
}

/**
 * An area moved, kept inside the template.
 *
 * @param area   The area when the drag began.
 * @param dx     Moved to the right.
 * @param dy     Moved down.
 * @param width  Template width.
 * @param height Template height.
 */
export function movedArea(
	area: PrintArea,
	dx: number,
	dy: number,
	width: number,
	height: number
): PrintArea {
	return {
		...area,
		x: Math.max(
			0,
			Math.min( width - area.width, Math.round( area.x + dx ) )
		),
		y: Math.max(
			0,
			Math.min( height - area.height, Math.round( area.y + dy ) )
		),
	};
}

/**
 * Whether a point lies in an area.
 *
 * @param area  The area.
 * @param point The point.
 */
export function isInside( area: PrintArea, point: Point ): boolean {
	return (
		point.x >= area.x &&
		point.y >= area.y &&
		point.x <= area.x + area.width &&
		point.y <= area.y + area.height
	);
}

/**
 * Wires one picker.
 *
 * @param holder The element (`data-merchboard-area` = the side's index,
 *               `data-url` = the stored template image).
 * @param form   The base product form.
 */
export function attachAreaPicker(
	holder: HTMLElement,
	form: HTMLFormElement
): void {
	const index = holder.dataset.merchboardArea ?? '';
	const field = ( key: keyof PrintArea ): HTMLInputElement | null => {
		const found = form.elements.namedItem( `views[${ index }][${ key }]` );

		return found instanceof HTMLInputElement ? found : null;
	};
	const read = (): PrintArea => ( {
		x: Number( field( 'x' )?.value ?? 0 ),
		y: Number( field( 'y' )?.value ?? 0 ),
		width: Number( field( 'width' )?.value ?? 0 ),
		height: Number( field( 'height' )?.value ?? 0 ),
	} );
	const hint = document.createElement( 'p' );
	const stage = document.createElement( 'div' );
	const image = document.createElement( 'img' );
	const box = document.createElement( 'div' );

	hint.className = 'description';
	stage.style.cssText =
		'position:relative;display:none;width:100%;max-width:40rem;line-height:0;cursor:crosshair;touch-action:none;user-select:none;background:repeating-conic-gradient(#ddd 0 25%,#fff 0 50%) 0 0/16px 16px';
	image.alt = '';
	image.draggable = false;
	image.style.cssText = 'display:block;width:100%;height:auto';
	box.style.cssText =
		'position:absolute;box-sizing:border-box;border:2px solid #d63638;background:rgba(214,54,56,.15);cursor:move;pointer-events:none';
	stage.append( image, box );
	holder.append( hint, stage );

	const paint = (): void => {
		const area = read();
		const { naturalWidth: width, naturalHeight: height } = image;

		box.hidden = width < 1 || area.width < 1 || area.height < 1;
		box.style.left = `${ ( area.x / width ) * 100 }%`;
		box.style.top = `${ ( area.y / height ) * 100 }%`;
		box.style.width = `${ ( area.width / width ) * 100 }%`;
		box.style.height = `${ ( area.height / height ) * 100 }%`;
	};
	const write = ( area: PrintArea ): void => {
		for ( const key of [ 'x', 'y', 'width', 'height' ] as const ) {
			const input = field( key );

			if ( input !== null ) {
				input.value = String( area[ key ] );
			}
		}

		paint();
	};
	const show = ( url: string ): void => {
		image.src = url;
		stage.style.display = 'block';
		hint.textContent = __(
			'Draw the print area on the image with the mouse. Drag inside it to move it.',
			'merchboard'
		);
	};
	const place = ( event: PointerEvent ): Point => {
		const frame = image.getBoundingClientRect();

		return {
			x:
				( ( event.clientX - frame.left ) / frame.width ) *
				image.naturalWidth,
			y:
				( ( event.clientY - frame.top ) / frame.height ) *
				image.naturalHeight,
		};
	};

	let drag: { start: Point; moving: PrintArea | null } | null = null;

	stage.addEventListener( 'pointerdown', ( event ) => {
		const start = place( event );
		const area = read();

		event.preventDefault();
		stage.setPointerCapture?.( event.pointerId );
		drag = {
			start,
			moving: area.width > 0 && isInside( area, start ) ? area : null,
		};
	} );
	stage.addEventListener( 'pointermove', ( event ) => {
		if ( drag === null ) {
			return;
		}

		const now = place( event );
		const { naturalWidth: width, naturalHeight: height } = image;

		write(
			drag.moving === null
				? drawnArea( drag.start, now, width, height )
				: movedArea(
						drag.moving,
						now.x - drag.start.x,
						now.y - drag.start.y,
						width,
						height
					)
		);
	} );

	const stop = (): void => {
		drag = null;
	};

	stage.addEventListener( 'pointerup', stop );
	stage.addEventListener( 'pointercancel', stop );
	image.addEventListener( 'load', paint );

	for ( const key of [ 'x', 'y', 'width', 'height' ] as const ) {
		field( key )?.addEventListener( 'input', paint );
	}

	// A new template image shows at once.
	for ( const layer of [ 'background', 'color' ] ) {
		const input = form.elements.namedItem( `layer_${ index }_${ layer }` );

		if ( input instanceof HTMLInputElement ) {
			input.addEventListener( 'change', () => {
				const file = input.files?.[ 0 ];

				if ( file !== undefined ) {
					show( URL.createObjectURL( file ) );
				}
			} );
		}
	}

	if ( ( holder.dataset.url ?? '' ) === '' ) {
		hint.textContent = __(
			'Choose a background to draw the print area on it.',
			'merchboard'
		);
	} else {
		show( holder.dataset.url ?? '' );
	}
}
