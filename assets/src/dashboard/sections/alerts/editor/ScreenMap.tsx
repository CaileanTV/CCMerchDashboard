/**
 * The screen map of "Position, size and style" (ADR-109): the 1920 × 1080
 * screen of the OBS source, small, with the alert box on it. The canvas
 * shows only the box, so the box is placed on the screen here: drag it, or
 * click where its middle should be. The X and Y fields beside it do the
 * same with the keyboard.
 */
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { PointerEvent } from 'react';

import { CANVAS } from '../../../types/alerts';
import type { OverlaySettings } from '../../../types/alerts';

interface Props {
	readonly widget: OverlaySettings[ 'widget' ];
	/** The box's new left and top edge on the screen (one undo step). */
	readonly onMove: ( x: number, y: number ) => void;
}

/**
 * The screen map.
 *
 * @param props        Props.
 * @param props.widget The alert box.
 * @param props.onMove Moves the box.
 */
export function ScreenMap( { widget, onMove }: Props ) {
	const map = useRef< HTMLDivElement | null >( null );
	// The box's place while it is dragged; saved when the drag ends.
	const [ dragged, setDragged ] = useState< { x: number; y: number } | null >(
		null
	);
	const place = dragged ?? { x: widget.x, y: widget.y };
	const scale = () => ( map.current?.clientWidth ?? 0 ) / CANVAS.width;

	const drag = ( event: PointerEvent< HTMLDivElement > ) => {
		const ratio = scale();

		if ( event.button !== 0 || ratio <= 0 ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		const node = event.currentTarget;
		node.setPointerCapture?.( event.pointerId );
		const start = { x: event.clientX, y: event.clientY };
		let last = { x: widget.x, y: widget.y };

		const move = ( moved: globalThis.PointerEvent ) => {
			last = {
				x: Math.round( widget.x + ( moved.clientX - start.x ) / ratio ),
				y: Math.round( widget.y + ( moved.clientY - start.y ) / ratio ),
			};
			setDragged( last );
		};
		const up = () => {
			node.removeEventListener( 'pointermove', move );
			node.removeEventListener( 'pointerup', up );
			node.removeEventListener( 'pointercancel', up );
			setDragged( null );

			if ( last.x !== widget.x || last.y !== widget.y ) {
				onMove( last.x, last.y );
			}
		};

		node.addEventListener( 'pointermove', move );
		node.addEventListener( 'pointerup', up );
		node.addEventListener( 'pointercancel', up );
	};

	// A click on the screen puts the box's middle there.
	const jump = ( event: PointerEvent< HTMLDivElement > ) => {
		const ratio = scale();
		const rect = map.current?.getBoundingClientRect();

		if ( event.button !== 0 || ratio <= 0 || rect === undefined ) {
			return;
		}

		onMove(
			Math.round( ( event.clientX - rect.left ) / ratio - widget.w / 2 ),
			Math.round( ( event.clientY - rect.top ) / ratio - widget.h / 2 )
		);
	};

	return (
		<div className="merchboard-alert-screen">
			<span className="merchboard-alert-screen__label">
				{ __( 'Placement on the screen (1920 × 1080)', 'merchboard' ) }
			</span>
			{ /* The X and Y fields are the keyboard's way to place the box. */ }
			<div
				ref={ map }
				className="merchboard-alert-screen__map"
				aria-hidden="true"
				onPointerDown={ jump }
			>
				<div
					className="merchboard-alert-screen__box"
					style={ {
						left: `${ ( place.x / CANVAS.width ) * 100 }%`,
						top: `${ ( place.y / CANVAS.height ) * 100 }%`,
						width: `${ ( widget.w / CANVAS.width ) * 100 }%`,
						height: `${ ( widget.h / CANVAS.height ) * 100 }%`,
						transform: `rotate(${ widget.rotation }deg)`,
					} }
					onPointerDown={ drag }
				/>
			</div>
		</div>
	);
}
