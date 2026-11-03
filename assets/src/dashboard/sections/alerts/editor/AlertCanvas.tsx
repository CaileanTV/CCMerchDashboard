/**
 * The designer's canvas (alert designer 2.0, ADR-088): the 1920 × 1080
 * overlay with the alert drawn by the overlay's own renderer, so the preview
 * is what OBS shows. The alert box is moved and sized by dragging (or the
 * arrow keys); in the layout "free", the image and the texts inside it too.
 * The toolbar below sends a test alert, undoes and redoes, zooms, switches
 * the grid and the preview's sound, and plays the alert as the overlay
 * does. Read-only, it is the preview of saved designs.
 */
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { KeyboardEvent, PointerEvent } from 'react';

import '../../../../overlay/animations.css';
import '../../../../overlay/textfill.css';
import '../../../../overlay/frame.css';
import '../../../../overlay/render.css';

import { arrange, highlight } from '../../../../overlay/render';
import type { AlertElements, AlertLook } from '../../../../overlay/render';
import { AlertPlayer } from '../../../../overlay/player';
import { applyFill, clearFill, layoutFill } from '../../../../overlay/textfill';
import { applyFrame, clearFrame } from '../../../../overlay/frame';
import { fitText, followText } from '../../../../overlay/fit';
import { resetText } from '../../../../overlay/animations';
import { playSound } from '../../../../overlay/sound';
import type { TextPart } from '../../../../overlay/look';
import { CANVAS } from '../../../types/alerts';
import type {
	LayoutBox,
	LayoutElement,
	OverlayLayout,
	OverlaySettings,
} from '../../../types/alerts';
import { moveBox, resizeBox, snap, withBox } from '../designer';

/** The sample alert: its texts in pieces. */
export interface SampleAlert {
	readonly parts: readonly TextPart[];
	readonly secondaryParts: readonly TextPart[];
}

type Widget = OverlaySettings[ 'widget' ];

/** Zoom steps on top of "fit to the width". */
const ZOOMS = [ 0.5, 0.75, 1, 1.5, 2 ] as const;

/** Edges and corners a box is resized from. */
const HANDLES = [ 'n', 'e', 's', 'w', 'ne', 'se', 'sw', 'nw' ] as const;

type Handle = ( typeof HANDLES )[ number ];

/**
 * The alert box moved; in the layout "free" the elements move with it, as
 * their boxes are on the canvas.
 *
 * @param settings Settings.
 * @param layout   Layout.
 * @param x        New left edge.
 * @param y        New top edge.
 */
export function moveWidget(
	settings: OverlaySettings,
	layout: OverlayLayout,
	x: number,
	y: number
): { settings: OverlaySettings; layout: OverlayLayout } {
	const widget = settings.widget;
	const nx = Math.max( -3840, Math.min( 3840, Math.round( x ) ) );
	const ny = Math.max( -2160, Math.min( 2160, Math.round( y ) ) );
	const dx = nx - widget.x;
	const dy = ny - widget.y;
	let next = layout;

	if ( settings.layout_mode === 'free' && ( dx !== 0 || dy !== 0 ) ) {
		for ( const element of [
			'product_image',
			'main_text',
			'secondary_text',
		] as const ) {
			const box = layout.elements[ element ];
			next = withBox( next, element, {
				...box,
				x: Math.max( -3840, Math.min( 3840, box.x + dx ) ),
				y: Math.max( -2160, Math.min( 2160, box.y + dy ) ),
			} );
		}
	}

	return {
		settings: { ...settings, widget: { ...widget, x: nx, y: ny } },
		layout: next,
	};
}

/**
 * A box resized from a handle (canvas pixels), at least 10 pixels.
 *
 * @param from   The box when the drag started.
 * @param from.x Left.
 * @param from.y Top.
 * @param from.w Width.
 * @param from.h Height.
 * @param handle Edge or corner.
 * @param dx     Horizontal distance.
 * @param dy     Vertical distance.
 */
export function resizeFrom(
	from: { x: number; y: number; w: number; h: number },
	handle: Handle,
	dx: number,
	dy: number
): { x: number; y: number; w: number; h: number } {
	let { x, y, w, h } = from;

	if ( handle.includes( 'e' ) ) {
		w = Math.max( 10, from.w + dx );
	}

	if ( handle.includes( 's' ) ) {
		h = Math.max( 10, from.h + dy );
	}

	if ( handle.includes( 'w' ) ) {
		w = Math.max( 10, from.w - dx );
		x = from.x + from.w - w;
	}

	if ( handle.includes( 'n' ) ) {
		h = Math.max( 10, from.h - dy );
		y = from.y + from.h - h;
	}

	return {
		x: Math.round( x ),
		y: Math.round( y ),
		w: Math.round( w ),
		h: Math.round( h ),
	};
}

/**
 * Draws the alert still: placed, styled and filled, without animation.
 *
 * @param elements The alert's elements.
 * @param look     Look.
 * @param sample   Sample texts.
 */
function drawStill(
	elements: AlertElements,
	look: AlertLook,
	sample: SampleAlert
): void {
	arrange( elements, look );
	elements.alert.classList.remove(
		'merchboard-alert--hidden',
		'merchboard-alert--entering',
		'merchboard-alert--leaving'
	);

	const texts: Array< [ HTMLElement, readonly TextPart[], boolean ] > = [
		[ elements.text, sample.parts, look.elements.main_text.visible ],
		[ elements.secondary, sample.secondaryParts, look.show_secondary ],
	];

	for ( const [ element, parts, shown ] of texts ) {
		resetText( element );
		clearFill( element );
		clearFrame( element );
		element.style.removeProperty( 'font-size' );
		element.textContent = shown
			? parts.map( ( part ) => part.text ).join( '' )
			: '';
		highlight( element, parts, look );
	}

	applyFill( elements.text, look.fills.main );
	applyFill( elements.secondary, look.fills.secondary );

	if ( look.frame ) {
		applyFrame( elements.text, look.frame );
	} else if ( look.text_fit === 'shrink' ) {
		fitText( elements.text );
	}

	if ( look.text_fit === 'shrink' ) {
		fitText( elements.secondary );
	}

	if ( elements.text.dataset.growsUp === '1' ) {
		followText( elements.image, elements.text );
	} else {
		elements.image.style.removeProperty( 'margin-top' );
	}

	layoutFill( elements.text );
	layoutFill( elements.secondary );
}

interface Props {
	readonly settings: OverlaySettings;
	readonly layout: OverlayLayout;
	readonly look: AlertLook;
	readonly sample: SampleAlert;
	/** The preview's sound URL ('' for none). */
	readonly sound?: string;
	/** The canvas of saved designs: no handles and no toolbar. */
	readonly readOnly?: boolean;
	/** A drag starts: one undo step for the whole drag. */
	readonly onBegin?: () => void;
	/** Live change while dragging. */
	readonly onLive?: (
		settings: OverlaySettings,
		layout: OverlayLayout
	) => void;
	/** A drag ends. */
	readonly onEnd?: () => void;
	/** A change outside a drag (arrow keys): one undo step. */
	readonly onChange?: (
		settings: OverlaySettings,
		layout: OverlayLayout
	) => void;
	readonly canUndo?: boolean;
	readonly canRedo?: boolean;
	readonly onUndo?: () => void;
	readonly onRedo?: () => void;
	/** Sends a test alert to the overlay. */
	readonly onTest?: () => void;
	readonly testing?: boolean;
	/** Changes when the preview should play (the animation section's button). */
	readonly playRequest?: number;
}

/**
 * The canvas.
 *
 * @param props             Props.
 * @param props.settings    Settings.
 * @param props.layout      Layout.
 * @param props.look        The look the renderer draws.
 * @param props.sample      The sample texts.
 * @param props.sound       The preview's sound URL.
 * @param props.readOnly    Without handles and toolbar.
 * @param props.onBegin     A drag starts.
 * @param props.onLive      Live change while dragging.
 * @param props.onEnd       A drag ends.
 * @param props.onChange    A change outside a drag.
 * @param props.canUndo     Whether a step can be undone.
 * @param props.canRedo     Whether a step can be redone.
 * @param props.onUndo      Undoes a step.
 * @param props.onRedo      Redoes a step.
 * @param props.onTest      Sends a test alert.
 * @param props.testing     Whether a test alert is being sent.
 * @param props.playRequest Changes when the preview should play.
 */
export function AlertCanvas( {
	settings,
	layout,
	look,
	sample,
	sound = '',
	readOnly = false,
	onBegin,
	onLive,
	onEnd,
	onChange,
	canUndo = false,
	canRedo = false,
	onUndo,
	onRedo,
	onTest,
	testing = false,
	playRequest = 0,
}: Props ) {
	const wrap = useRef< HTMLDivElement | null >( null );
	const alertRef = useRef< HTMLDivElement | null >( null );
	const imageRef = useRef< HTMLDivElement | null >( null );
	const textsRef = useRef< HTMLDivElement | null >( null );
	const textRef = useRef< HTMLDivElement | null >( null );
	const secondaryRef = useRef< HTMLDivElement | null >( null );
	const [ width, setWidth ] = useState( 960 );
	const [ zoom, setZoom ] = useState< ( typeof ZOOMS )[ number ] >( 1 );
	const [ muted, setMuted ] = useState( true );
	const [ playing, setPlaying ] = useState( false );
	const scale = ( width / CANVAS.width ) * zoom;

	const elements = useCallback( (): AlertElements | null => {
		const [ alert, image, texts, text, secondary ] = [
			alertRef.current,
			imageRef.current,
			textsRef.current,
			textRef.current,
			secondaryRef.current,
		];

		return alert && image && texts && text && secondary
			? { alert, image, texts, text, secondary }
			: null;
	}, [] );

	// The canvas fits the width of its place; zoom goes on top of that.
	useEffect( () => {
		const node = wrap.current;

		if ( node === null || typeof ResizeObserver === 'undefined' ) {
			return undefined;
		}

		const observer = new ResizeObserver( ( entries ) => {
			const measured = entries[ 0 ]?.contentRect.width ?? 0;

			if ( measured > 0 ) {
				setWidth( measured );
			}
		} );

		observer.observe( node );

		return () => observer.disconnect();
	}, [] );

	useLayoutEffect( () => {
		const parts = elements();

		if ( parts !== null && ! playing ) {
			drawStill( parts, look, sample );
		}
	}, [ elements, look, sample, playing ] );

	const play = useCallback( () => {
		const parts = elements();

		if ( parts === null || playing ) {
			return;
		}

		setPlaying( true );
		const text = sample.parts.map( ( part ) => part.text ).join( '' );
		const secondary = sample.secondaryParts
			.map( ( part ) => part.text )
			.join( '' );
		const player = new AlertPlayer( parts, () => look, {
			...( muted
				? {}
				: { play: ( url, volume ) => playSound( url, volume ) } ),
			fit: fitText,
			follow: followText,
			idle: () => setPlaying( false ),
		} );

		parts.alert.classList.add( 'merchboard-alert--hidden' );
		player.enqueue( [
			{
				id: 0,
				look: '',
				image: '',
				text,
				secondary,
				parts: [ ...sample.parts ],
				secondaryParts: [ ...sample.secondaryParts ],
				sound: muted ? '' : sound,
				tts: '',
			},
		] );
	}, [ elements, look, muted, playing, sample, sound ] );

	// The animation section's "Play" button.
	const lastRequest = useRef( playRequest );

	useEffect( () => {
		if ( playRequest !== lastRequest.current ) {
			lastRequest.current = playRequest;
			play();
		}
	}, [ playRequest, play ] );

	const drag = (
		event: PointerEvent< HTMLElement >,
		target: 'alert' | LayoutElement,
		handle: Handle | 'move'
	) => {
		if ( readOnly || event.button !== 0 ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		const node = event.currentTarget;
		node.setPointerCapture?.( event.pointerId );
		const start = { x: event.clientX, y: event.clientY };
		const widget: Widget = settings.widget;
		const box: LayoutBox | null =
			target === 'alert' ? null : layout.elements[ target ];

		onBegin?.();

		const moveTo = ( clientX: number, clientY: number ) => {
			const dx = ( clientX - start.x ) / scale;
			const dy = ( clientY - start.y ) / scale;

			if ( target === 'alert' ) {
				if ( handle === 'move' ) {
					const next = moveWidget(
						settings,
						layout,
						snap( widget.x + dx, layout ),
						snap( widget.y + dy, layout )
					);
					onLive?.( next.settings, next.layout );
				} else {
					const sized = resizeFrom( widget, handle, dx, dy );
					onLive?.(
						{
							...settings,
							widget: {
								...widget,
								x: snap( sized.x, layout ),
								y: snap( sized.y, layout ),
								w: Math.max( 10, snap( sized.w, layout ) ),
								h: Math.max( 10, snap( sized.h, layout ) ),
							},
						},
						layout
					);
				}

				return;
			}

			if ( box === null ) {
				return;
			}

			const next =
				handle === 'move'
					? moveBox( box, box.x + dx, box.y + dy, layout )
					: resizeBox( box, box.w + dx, box.h + dy, layout );

			onLive?.( settings, withBox( layout, target, next ) );
		};

		const up = () => {
			node.removeEventListener( 'pointermove', move );
			node.removeEventListener( 'pointerup', up );
			node.removeEventListener( 'pointercancel', up );
			onEnd?.();
		};
		const move = ( moved: globalThis.PointerEvent ) =>
			moveTo( moved.clientX, moved.clientY );

		node.addEventListener( 'pointermove', move );
		node.addEventListener( 'pointerup', up );
		node.addEventListener( 'pointercancel', up );
	};

	const nudge = ( event: KeyboardEvent< HTMLDivElement > ) => {
		const steps: Record< string, [ number, number ] > = {
			ArrowLeft: [ -1, 0 ],
			ArrowRight: [ 1, 0 ],
			ArrowUp: [ 0, -1 ],
			ArrowDown: [ 0, 1 ],
		};
		const step = steps[ event.key ];

		if ( step === undefined || readOnly ) {
			return;
		}

		event.preventDefault();
		const by = event.shiftKey ? 10 : 1;
		const next = moveWidget(
			settings,
			layout,
			settings.widget.x + step[ 0 ] * by,
			settings.widget.y + step[ 1 ] * by
		);
		onChange?.( next.settings, next.layout );
	};

	const frame = ( box: { x: number; y: number; w: number; h: number } ) => ( {
		left: `${ box.x * scale }px`,
		top: `${ box.y * scale }px`,
		width: `${ box.w * scale }px`,
		height: `${ box.h * scale }px`,
	} );

	const elementLabels: Record< LayoutElement, string > = {
		product_image: __( 'Product image', 'merchboard' ),
		main_text: __( 'Main text', 'merchboard' ),
		secondary_text: __( 'Secondary text', 'merchboard' ),
	};

	const zoomIndex = ZOOMS.indexOf( zoom );

	return (
		<div className="merchboard-alert-canvas">
			<div className="merchboard-alert-canvas__viewport" ref={ wrap }>
				<div
					className={ `merchboard-alert-canvas__stage${
						layout.canvas.snap_grid ? ' has-grid' : ''
					}` }
					style={ {
						width: `${ CANVAS.width * scale }px`,
						height: `${ CANVAS.height * scale }px`,
						// The grid's size on screen.
						[ '--alert-grid' as string ]: `${
							layout.canvas.grid_size * scale
						}px`,
					} }
				>
					<div
						className="merchboard-alert-canvas__screen"
						style={ { transform: `scale(${ scale })` } }
						aria-hidden="true"
					>
						<div ref={ alertRef } className="merchboard-alert">
							<div
								ref={ imageRef }
								className="merchboard-alert__image merchboard-alert-canvas__sample-image"
							/>
							<div
								ref={ textsRef }
								className="merchboard-alert__texts"
							>
								<div
									ref={ textRef }
									className="merchboard-alert__text"
								/>
								<div
									ref={ secondaryRef }
									className="merchboard-alert__text"
								/>
							</div>
						</div>
					</div>
					{ ! readOnly && ! playing && (
						<>
							{ /* The alert box: drag it, or move it with the arrow keys (Shift: 10 px). */ }
							<div
								className="merchboard-alert-canvas__frame"
								style={ frame( settings.widget ) }
								role="button"
								tabIndex={ 0 }
								aria-label={ __(
									'Alert box: drag to move, or use the arrow keys',
									'merchboard'
								) }
								onPointerDown={ ( event ) =>
									drag( event, 'alert', 'move' )
								}
								onKeyDown={ nudge }
							>
								<span className="merchboard-alert-canvas__size">
									{ `${ settings.widget.w } × ${ settings.widget.h }` }
								</span>
								{ HANDLES.map( ( handle ) => (
									<span
										key={ handle }
										className={ `merchboard-alert-canvas__handle is-${ handle }` }
										onPointerDown={ ( event ) =>
											drag( event, 'alert', handle )
										}
									/>
								) ) }
							</div>
							{ settings.layout_mode === 'free' &&
								(
									Object.keys(
										elementLabels
									) as LayoutElement[]
								 )
									.filter( ( element ) =>
										element === 'secondary_text'
											? settings.show_secondary
											: layout.elements[ element ].visible
									)
									.map( ( element ) => (
										<div
											key={ element }
											className="merchboard-alert-canvas__element"
											style={ frame(
												layout.elements[ element ]
											) }
											title={ elementLabels[ element ] }
											onPointerDown={ ( event ) =>
												drag( event, element, 'move' )
											}
										>
											<span
												className="merchboard-alert-canvas__handle is-se"
												onPointerDown={ ( event ) =>
													drag( event, element, 'se' )
												}
											/>
										</div>
									) ) }
						</>
					) }
				</div>
			</div>
			{ ! readOnly && (
				<div
					className="merchboard-alert-canvas__toolbar"
					role="toolbar"
					aria-label={ __( 'Canvas', 'merchboard' ) }
				>
					<button
						type="button"
						className="merchboard-alert-canvas__tool is-primary"
						onClick={ onTest }
						disabled={ testing || onTest === undefined }
					>
						{ __( 'Test alert', 'merchboard' ) }
					</button>
					<button
						type="button"
						className="merchboard-alert-canvas__tool"
						onClick={ play }
						disabled={ playing }
					>
						{ __( 'Play', 'merchboard' ) }
					</button>
					<span className="merchboard-alert-canvas__divider" />
					<button
						type="button"
						className="merchboard-alert-canvas__tool"
						onClick={ onUndo }
						disabled={ ! canUndo }
					>
						{ __( 'Undo', 'merchboard' ) }
					</button>
					<button
						type="button"
						className="merchboard-alert-canvas__tool"
						onClick={ onRedo }
						disabled={ ! canRedo }
					>
						{ __( 'Redo', 'merchboard' ) }
					</button>
					<span className="merchboard-alert-canvas__divider" />
					<button
						type="button"
						className="merchboard-alert-canvas__tool"
						aria-label={ __( 'Zoom out', 'merchboard' ) }
						onClick={ () =>
							setZoom(
								ZOOMS[ Math.max( 0, zoomIndex - 1 ) ] ?? 1
							)
						}
						disabled={ zoomIndex <= 0 }
					>
						−
					</button>
					<button
						type="button"
						className="merchboard-alert-canvas__tool"
						onClick={ () => setZoom( 1 ) }
					>
						{ `${ Math.round( zoom * 100 ) }%` }
					</button>
					<button
						type="button"
						className="merchboard-alert-canvas__tool"
						aria-label={ __( 'Zoom in', 'merchboard' ) }
						onClick={ () =>
							setZoom(
								ZOOMS[
									Math.min( ZOOMS.length - 1, zoomIndex + 1 )
								] ?? 1
							)
						}
						disabled={ zoomIndex >= ZOOMS.length - 1 }
					>
						+
					</button>
					<span className="merchboard-alert-canvas__divider" />
					<button
						type="button"
						className="merchboard-alert-canvas__tool"
						aria-pressed={ layout.canvas.snap_grid }
						onClick={ () =>
							onChange?.( settings, {
								...layout,
								canvas: {
									...layout.canvas,
									snap_grid: ! layout.canvas.snap_grid,
								},
							} )
						}
					>
						{ __( 'Grid', 'merchboard' ) }
					</button>
					<button
						type="button"
						className="merchboard-alert-canvas__tool"
						aria-pressed={ ! muted }
						onClick={ () => setMuted( ! muted ) }
					>
						{ muted
							? __( 'Sound off', 'merchboard' )
							: __( 'Sound on', 'merchboard' ) }
					</button>
				</div>
			) }
		</div>
	);
}
