/**
 * The designer's canvas (alert designer 2.0, ADR-088; ADR-109): the alert
 * box itself, enlarged, with the alert drawn by the overlay's own renderer,
 * so the preview is what OBS shows. The box is sized from its edges and
 * moved on the screen with the arrow keys (or the screen map of "Position,
 * size and style"). The texts are dragged and sized inside it; the image
 * where its layout lets it. A layout before ADR-109 is turned into the image
 * layout that looks the same (onConvert).
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

import { arrange, highlight, imageBox } from '../../../../overlay/render';
import type { AlertElements, AlertLook } from '../../../../overlay/render';
import { AlertPlayer } from '../../../../overlay/player';
import { applyFill, clearFill, layoutFill } from '../../../../overlay/textfill';
import { applyFrame, clearFrame } from '../../../../overlay/frame';
import { fitText, followText } from '../../../../overlay/fit';
import { resetText } from '../../../../overlay/animations';
import { playSound } from '../../../../overlay/sound';
import { isLegacyLayout } from '../../../../overlay/look';
import type { TextPart } from '../../../../overlay/look';
import type {
	LayoutBox,
	LayoutElement,
	OverlayLayout,
	OverlaySettings,
} from '../../../types/alerts';
import { convertLegacy, moveBox, resizeBox, snap, withBox } from '../designer';
import type { MeasuredBox } from '../designer';

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
 * The alert box moved; the elements move with it, as their boxes are on the
 * canvas.
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

	// The elements' boxes are on the canvas: they follow the box in every layout,
	// so a look switched to Free later still fits it (30.15 review).
	if ( dx !== 0 || dy !== 0 ) {
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
 * The tallest the stage may be: the screen's height without the editor's
 * bars, so a tall box fits without scrolling.
 */
function maxStageHeight(): number {
	return typeof window === 'undefined'
		? 600
		: Math.max( 240, window.innerHeight - 240 );
}

/**
 * An element's border box in the alert box (without the box's rotation),
 * or null when it is not drawn.
 *
 * @param element Element.
 * @param alert   The alert box.
 */
function measure(
	element: HTMLElement,
	alert: HTMLElement
): MeasuredBox | null {
	if ( element.hidden || element.offsetParent === null ) {
		return null;
	}

	let x = 0;
	let y = 0;
	let node: Element | null = element;

	while ( node instanceof HTMLElement && node !== alert ) {
		x += node.offsetLeft;
		y += node.offsetTop;
		node = node.offsetParent;
	}

	return node === alert
		? { x, y, w: element.offsetWidth, h: element.offsetHeight }
		: null;
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
	/** A layout before ADR-109 was turned into an image layout (not an undo step). */
	readonly onConvert?: ( next: {
		settings: OverlaySettings;
		layout: OverlayLayout;
	} ) => void;
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
 * @param props.onConvert   A layout before ADR-109 was converted.
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
	onConvert,
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
	// While the box is resized, the scale of the drag's start (the stage refits after it).
	const [ frozen, setFrozen ] = useState< number | null >( null );
	const widget: Widget = settings.widget;
	// The stage is the alert box (ADR-109): it fits the width and a tall box the screen's height.
	const fit = Math.min(
		// Room for the handles on the edges.
		Math.max( 40, width - 16 ) / Math.max( 10, widget.w ),
		maxStageHeight() / Math.max( 10, widget.h )
	);
	const scale = frozen ?? fit * zoom;

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

		let frame = 0;
		// Only a real change counts, once a frame: a change of a pixel or two
		// (a scroll bar coming and going) must not start a loop of resizes.
		const observer = new ResizeObserver( ( entries ) => {
			const measured = Math.floor( entries[ 0 ]?.contentRect.width ?? 0 );

			window.cancelAnimationFrame( frame );
			frame = window.requestAnimationFrame( () =>
				setWidth( ( current ) =>
					measured > 0 && Math.abs( measured - current ) > 2
						? measured
						: current
				)
			);
		} );

		observer.observe( node );

		return () => {
			window.cancelAnimationFrame( frame );
			observer.disconnect();
		};
	}, [] );

	useLayoutEffect( () => {
		const parts = elements();

		if ( parts !== null && ! playing ) {
			drawStill( parts, look, sample );
		}
	}, [ elements, look, sample, playing ] );

	// A layout before ADR-109 becomes the image layout that looks the same,
	// measured in the alert just drawn (ADR-109 pkt. 3).
	useLayoutEffect( () => {
		const parts = elements();

		if (
			parts === null ||
			readOnly ||
			playing ||
			onConvert === undefined ||
			! isLegacyLayout( settings.layout_mode ) ||
			parts.alert.offsetWidth === 0
		) {
			return;
		}

		onConvert(
			convertLegacy(
				settings,
				layout,
				settings.layout_mode,
				{
					image: measure( parts.image, parts.alert ),
					main: measure( parts.text, parts.alert ),
					secondary: measure( parts.secondary, parts.alert ),
				},
				look.margin
			)
		);
	}, [ elements, settings, layout, look, readOnly, playing, onConvert ] );

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

	// Snapping inside the box: the grid starts at the box's corner.
	const inBox = { ...layout, canvas: { ...layout.canvas, snap_grid: false } };
	const snapIn = ( value: number, origin: number ) =>
		origin + snap( value - origin, layout );

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
		const box: LayoutBox | null =
			target === 'alert' ? null : layout.elements[ target ];

		onBegin?.();

		if ( target === 'alert' ) {
			setFrozen( scale );
		}

		const moveTo = ( clientX: number, clientY: number ) => {
			const dx = ( clientX - start.x ) / scale;
			const dy = ( clientY - start.y ) / scale;

			if ( target === 'alert' ) {
				if ( handle === 'move' ) {
					return;
				}

				const sized = resizeFrom( widget, handle, dx, dy );
				onLive?.(
					{
						...settings,
						widget: {
							...widget,
							x: sized.x,
							y: sized.y,
							w: Math.max( 10, snap( sized.w, layout ) ),
							h: Math.max( 10, snap( sized.h, layout ) ),
						},
					},
					layout
				);

				return;
			}

			if ( box === null ) {
				return;
			}

			const next =
				handle === 'move'
					? moveBox(
							box,
							snapIn( box.x + dx, widget.x ),
							snapIn( box.y + dy, widget.y ),
							inBox
						)
					: resizeBox( box, box.w + dx, box.h + dy, layout );

			onLive?.( settings, withBox( layout, target, next ) );
		};

		const up = () => {
			node.removeEventListener( 'pointermove', move );
			node.removeEventListener( 'pointerup', up );
			node.removeEventListener( 'pointercancel', up );
			setFrozen( null );
			onEnd?.();
		};
		const move = ( moved: globalThis.PointerEvent ) =>
			moveTo( moved.clientX, moved.clientY );

		node.addEventListener( 'pointermove', move );
		node.addEventListener( 'pointerup', up );
		node.addEventListener( 'pointercancel', up );
	};

	// The arrow keys move the box on the screen (Shift: 10 px).
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
			widget.x + step[ 0 ] * by,
			widget.y + step[ 1 ] * by
		);
		onChange?.( next.settings, next.layout );
	};

	// A box on the canvas, drawn in the stage (the alert box's corner is 0,0).
	const frame = ( box: { x: number; y: number; w: number; h: number } ) => ( {
		left: `${ ( box.x - widget.x ) * scale }px`,
		top: `${ ( box.y - widget.y ) * scale }px`,
		width: `${ box.w * scale }px`,
		height: `${ box.h * scale }px`,
	} );

	const elementLabels: Record< LayoutElement, string > = {
		product_image: __( 'Product image', 'merchboard' ),
		main_text: __( 'Main text', 'merchboard' ),
		secondary_text: __( 'Secondary text', 'merchboard' ),
	};
	const mode = settings.layout_mode;
	const image = imageBox( look );
	// What can be done with an element: the texts move and resize freely; the
	// image only in "free", and only resizes where the layout places it.
	const handlesOf = ( element: LayoutElement ): 'all' | 'size' | null => {
		if ( isLegacyLayout( mode ) ) {
			return null;
		}

		if ( element !== 'product_image' ) {
			return 'all';
		}

		if ( mode === 'background' ) {
			return null;
		}

		return mode === 'free' ? 'all' : 'size';
	};

	const zoomIndex = ZOOMS.indexOf( zoom );

	return (
		<div className="merchboard-alert-canvas">
			<div className="merchboard-alert-canvas__viewport" ref={ wrap }>
				{ /* The stage clips the screen to the box; the handles lie on top of it, unclipped. */ }
				<div
					className="merchboard-alert-canvas__box"
					style={ {
						width: `${ widget.w * scale }px`,
						height: `${ widget.h * scale }px`,
					} }
				>
					<div
						className={ `merchboard-alert-canvas__stage${
							layout.canvas.snap_grid ? ' has-grid' : ''
						}` }
						style={ {
							// The grid's size on screen.
							[ '--alert-grid' as string ]: `${
								layout.canvas.grid_size * scale
							}px`,
						} }
					>
						<div
							className={ `merchboard-alert-canvas__screen${
								playing ? '' : ' is-still'
							}` }
							style={ {
								transform: `scale(${ scale }) translate(${ -widget.x }px, ${ -widget.y }px)`,
							} }
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
					</div>
					{ ! readOnly && ! playing && (
						<>
							{ /* The alert box is the stage: resize it from its edges; the arrow keys move it on the screen (Shift: 10 px). */ }
							<div
								className="merchboard-alert-canvas__frame"
								role="button"
								tabIndex={ 0 }
								aria-label={ __(
									'Alert box: drag the edges to resize it, or move it on the screen with the arrow keys',
									'merchboard'
								) }
								onKeyDown={ nudge }
							>
								<span className="merchboard-alert-canvas__size">
									{ `${ widget.w } × ${ widget.h }` }
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
							{ (
								Object.keys( elementLabels ) as LayoutElement[]
							 )
								.filter(
									( element ) =>
										handlesOf( element ) !== null &&
										( element === 'secondary_text'
											? settings.show_secondary
											: layout.elements[ element ]
													.visible )
								)
								.map( ( element ) => {
									const handles = handlesOf( element );

									return (
										<div
											key={ element }
											className={ `merchboard-alert-canvas__element${
												handles === 'all'
													? ''
													: ' is-fixed'
											}` }
											style={ frame(
												element === 'product_image'
													? image
													: layout.elements[ element ]
											) }
											title={ elementLabels[ element ] }
											onPointerDown={ ( event ) =>
												handles === 'all'
													? drag(
															event,
															element,
															'move'
														)
													: undefined
											}
										>
											<span
												className="merchboard-alert-canvas__handle is-se"
												onPointerDown={ ( event ) =>
													drag( event, element, 'se' )
												}
											/>
										</div>
									);
								} ) }
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
