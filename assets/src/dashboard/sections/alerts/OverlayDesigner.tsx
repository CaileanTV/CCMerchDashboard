import {
	useCallback,
	useEffect,
	useId,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { CSSProperties, PointerEvent as ReactPointerEvent } from 'react';

import '../../../overlay/animations.css';
import { leaveText, renderText, resetText } from '../../../overlay/animations';
import type { TextAnimation } from '../../../overlay/animations';
import { fitText } from '../../../overlay/fit';
import '../../../overlay/textfill.css';
import '../../../overlay/frame.css';
import { applyFrame, clearFrame, frameBox } from '../../../overlay/frame';
import type { TextFrame } from '../../../overlay/frame';
import {
	applyFill,
	clearFill,
	fillLook,
	layoutFill,
} from '../../../overlay/textfill';
import type { FillLook } from '../../../overlay/textfill';
import type { ApiError } from '../../api/client';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Toast } from '../../components/Toast';
import { BOX_LIMITS, CANVAS, LAYOUT_ELEMENTS } from '../../types/alerts';
import type {
	AlertsImages,
	AlertsOverlayState,
	LayoutBox,
	LayoutElement,
	OverlayLayout,
	OverlaySettings,
} from '../../types/alerts';
import {
	ALIGNMENTS,
	DEFAULT_LAYOUT,
	alignBoxes,
	background,
	growsUp,
	moveBoxes,
	resizeBox,
	sampleText,
	withBox,
} from './designer';
import type { Alignment, AlignTarget } from './designer';
import { useHistory } from './history';
import { useOverlayApi } from './overlay-api-context';

/**
 * Whether a key press is undo (Ctrl/Cmd+Z) or redo (Ctrl/Cmd+Shift+Z, or
 * Ctrl+Y as on Windows).
 *
 * @param event Key event.
 */
export function historyKey( event: KeyboardEvent ): 'undo' | 'redo' | null {
	if ( ! ( event.ctrlKey || event.metaKey ) || event.altKey ) {
		return null;
	}

	const key = event.key.toLowerCase();

	if ( key === 'z' ) {
		return event.shiftKey ? 'redo' : 'undo';
	}

	return key === 'y' && ! event.shiftKey ? 'redo' : null;
}

/**
 * Whether the browser's own undo belongs to an element (text fields).
 *
 * @param target Event target.
 */
function editsText( target: EventTarget | null ): boolean {
	return (
		target instanceof HTMLElement &&
		( target.isContentEditable ||
			target instanceof HTMLTextAreaElement ||
			( target instanceof HTMLInputElement &&
				! [ 'number', 'checkbox', 'range' ].includes( target.type ) ) )
	);
}

function alignmentLabel( alignment: Alignment ): string {
	switch ( alignment ) {
		case 'left':
			return __( 'Align left', 'merchboard' );
		case 'center':
			return __( 'Center horizontally', 'merchboard' );
		case 'right':
			return __( 'Align right', 'merchboard' );
		case 'top':
			return __( 'Align top', 'merchboard' );
		case 'middle':
			return __( 'Center vertically', 'merchboard' );
		default:
			return __( 'Align bottom', 'merchboard' );
	}
}

function elementLabel( element: LayoutElement ): string {
	switch ( element ) {
		case 'product_image':
			return __( 'Product image', 'merchboard' );
		case 'secondary_text':
			return __( 'Secondary text', 'merchboard' );
		default:
			return __( 'Main text', 'merchboard' );
	}
}

/**
 * A text box of the preview; with "shrink", the text gets smaller until it
 * fits, and "Play animation" plays its animation, as in the overlay.
 *
 * @param props             Props.
 * @param props.text        Text.
 * @param props.style       Style.
 * @param props.shrink      Whether to shrink the text to fit.
 * @param props.size        Box size (the fit is redone when it changes).
 * @param props.animation   The text's animation.
 * @param props.play        Increases each time the animation is played.
 * @param props.animTime    In/out time (ms).
 * @param props.displayTime Display time (ms; the preview shows at most 4 s).
 * @param props.fill        The text fill (step 25.6.10); null for none.
 * @param props.frame       The text around the image (step 25.6.11); null for none.
 */
function PreviewText( {
	text,
	style,
	shrink,
	fill,
	frame = null,
	size,
	animation,
	play,
	animTime,
	displayTime,
}: {
	readonly text: string;
	readonly style: CSSProperties;
	readonly shrink: boolean;
	readonly size: string;
	readonly animation: TextAnimation;
	/** Increases each time the animation is played. */
	readonly play: number;
	readonly animTime: number;
	readonly displayTime: number;
	readonly fill: FillLook | null;
	readonly frame?: TextFrame | null;
} ) {
	const ref = useRef< HTMLSpanElement >( null );
	const fillKey = JSON.stringify( [ fill, frame ] );
	// Around the image, the frame sizes the text; it is not fitted to a box.
	const fits = shrink && frame === null;

	// Compared by value: a re-render with the same look keeps a running preview.
	const look = JSON.stringify( style );

	// The text is set here, not by React, so the animation can split it into letters.
	useLayoutEffect( () => {
		const element = ref.current;

		if ( element === null ) {
			return;
		}

		resetText( element );
		clearFrame( element );
		element.textContent = text;
		applyFill( element, fill );
		applyFrame( element, frame );

		if ( fits ) {
			fitText( element );
		} else {
			element.style.fontSize = '';
		}

		layoutFill( element );
		// The fill and the frame are compared by value (fillKey).
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ text, look, shrink, size, fillKey ] );

	// "Play animation": in, effect, out, as in the overlay (step 25.6.9).
	useEffect( () => {
		const element = ref.current;

		if ( play === 0 || element === null ) {
			return undefined;
		}

		const times = renderText( element, text, animation, animTime, true );
		const timers: number[] = [];
		// The text as it is shown when the preview has ended.
		const still = () => {
			resetText( element );
			clearFrame( element );
			element.textContent = text;
			applyFill( element, fill );
			applyFrame( element, frame );

			if ( fits ) {
				fitText( element );
			}

			layoutFill( element );
		};

		applyFill( element, fill );
		applyFrame( element, frame );

		if ( fits ) {
			fitText( element );
		}

		layoutFill( element );

		timers.push(
			window.setTimeout(
				() => {
					leaveText( element );
					timers.push( window.setTimeout( still, times.outTotal ) );
				},
				Math.max(
					times.inTotal,
					Math.min( displayTime, PREVIEW_LIMIT )
				)
			)
		);

		return () => {
			timers.forEach( ( timer ) => window.clearTimeout( timer ) );
			clearFill( element );
			still();
		};
		// Only a new play starts the animation; the text and look follow on the next play.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ play ] );

	return (
		<span
			ref={ ref }
			className="merchboard-designer__text"
			style={ style }
		/>
	);
}

/** Longest display time of the preview (ms). */
const PREVIEW_LIMIT = 4000;

interface Drag {
	readonly element: LayoutElement;
	readonly mode: 'move' | 'resize';
	readonly startX: number;
	readonly startY: number;
	readonly box: LayoutBox;
	/** Every box that moves, as it was when the drag started. */
	readonly boxes: Readonly< Partial< Record< LayoutElement, LayoutBox > > >;
	readonly scale: number;
}

interface Props {
	readonly layout: OverlayLayout;
	readonly settings: OverlaySettings;
	/**
	 * The settings form's unsaved values: the preview shows them, so a choice
	 * (e.g. an animation) can be seen before it is saved.
	 */
	readonly preview?: OverlaySettings | null;
	/** The CSS font-family of the preview (approved font or inherit). */
	readonly fontFamily: string;
	readonly onSaved: ( state: AlertsOverlayState ) => void;
	/** Images the texts can be filled with (step 25.6.10). */
	readonly images?: AlertsImages | null;
}

/**
 * The overlay designer (ADR-065 pkt. 3): the image, the main text and the
 * secondary text on the 1920 × 1080 canvas of OBS. Boxes are dragged and
 * resized with the pointer (resize from the lower right corner) or set
 * exactly in the properties below, which also serve keyboard users.
 * Shift-, Ctrl- or Cmd-click (or Shift+Enter) selects several boxes, which
 * then move together and can be aligned to the canvas or to each other
 * (step 25.6.6). Ctrl/Cmd+Z undoes a change and Ctrl/Cmd+Shift+Z (or Ctrl+Y)
 * redoes it; a drag is one step. The preview uses sample values, never
 * customer data, and
 * shows "shrink" and "grow" as the overlay does.
 *
 * @param props            Props.
 * @param props.layout     The saved layout.
 * @param props.settings   The saved settings.
 * @param props.preview    The form's unsaved settings (look of the preview).
 * @param props.fontFamily CSS font-family of the preview.
 * @param props.onSaved    Called with the answer after saving.
 * @param props.images     Images the texts can be filled with.
 */
export function OverlayDesigner( {
	layout: saved,
	settings,
	preview = null,
	fontFamily,
	onSaved,
	images = null,
}: Props ) {
	const look = preview ?? settings;
	const fillImages = [
		...( images?.own ?? [] ),
		...( images?.library ?? [] ),
	];
	const id = useId();
	const canvas = useRef< HTMLDivElement >( null );
	const drag = useRef< Drag | null >( null );
	const history = useHistory( saved );
	// Each press of "Play animation" increases it (step 25.6.9).
	const [ play, setPlay ] = useState( 0 );
	const api = useOverlayApi();
	const layout = history.value;
	const setLayout = history.set;
	const root = useRef< HTMLDivElement >( null );
	const [ chosen, setSelection ] = useState< LayoutElement[] >( [
		'main_text',
	] );
	// The secondary text is only on the canvas when it is switched on in the
	// overlay settings, so creators are not confused by an unused box.
	// Around the image (step 25.6.11), the main text follows the image: it is shown round it, not as a box.
	const framed = look.text_frame.enabled;
	const elements = LAYOUT_ELEMENTS.filter(
		( element ) =>
			( element !== 'secondary_text' || look.show_secondary ) &&
			( element !== 'main_text' || ! framed )
	);
	const visible = chosen.filter( ( element ) =>
		elements.includes( element )
	);
	const selection: LayoutElement[] =
		visible.length > 0
			? visible
			: [ framed ? 'product_image' : 'main_text' ];
	const [ alignTarget, setAlignTarget ] = useState< AlignTarget >( 'canvas' );
	const mainBox = useRef< HTMLDivElement >( null );
	const [ imageShift, setImageShift ] = useState( 0 );
	// The last selected box is the one the properties show.
	const selected = selection[ selection.length - 1 ] ?? 'main_text';
	const [ busy, setBusy ] = useState( false );
	const [ saveError, setSaveError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	const box = layout.elements[ selected ];
	const setBox = ( element: LayoutElement, next: LayoutBox ) =>
		setLayout( ( current ) => withBox( current, element, next ) );

	const toggle = ( element: LayoutElement ) =>
		setSelection( ( current ) => {
			if ( ! current.includes( element ) ) {
				return [ ...current, element ];
			}

			// At least one box stays selected.
			return current.length > 1
				? current.filter( ( item ) => item !== element )
				: current;
		} );

	const grows = ( element: LayoutElement ) =>
		element !== 'product_image' && look.text_fit === 'grow';
	const follows =
		grows( 'main_text' ) && growsUp( layout.elements.main_text );

	// "Grow" near the bottom: the image moves up as far as the main text grew.
	useLayoutEffect( () => {
		const main = mainBox.current;
		const height = canvas.current?.clientHeight ?? 0;
		const least = ( layout.elements.main_text.h / CANVAS.height ) * height;
		const shift =
			follows && main !== null && height > 0
				? Math.max( 0, Math.round( main.offsetHeight - least ) )
				: 0;

		if ( shift !== imageShift ) {
			setImageShift( shift );
		}
		// The text, its look and the boxes change the main text's height.
	}, [ follows, imageShift, layout, look, fontFamily ] );

	const start = (
		event: ReactPointerEvent< HTMLElement >,
		element: LayoutElement,
		mode: Drag[ 'mode' ]
	) => {
		const width = canvas.current?.clientWidth ?? 0;

		if ( width === 0 ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		if (
			mode === 'move' &&
			( event.shiftKey || event.ctrlKey || event.metaKey )
		) {
			toggle( element );
			return;
		}

		// A selected box drags the whole selection; any other box only itself.
		const group =
			mode === 'move' && selection.includes( element )
				? [
						...selection.filter( ( item ) => item !== element ),
						element,
					]
				: [ element ];
		const boxes: Partial< Record< LayoutElement, LayoutBox > > = {};

		for ( const item of group ) {
			boxes[ item ] = layout.elements[ item ];
		}

		event.currentTarget.setPointerCapture?.( event.pointerId );
		setSelection( group );
		history.checkpoint();
		drag.current = {
			element,
			mode,
			startX: event.clientX,
			startY: event.clientY,
			box: layout.elements[ element ],
			boxes,
			scale: CANVAS.width / width,
		};
	};

	const move = ( event: ReactPointerEvent< HTMLElement > ) => {
		const current = drag.current;

		if ( current === null ) {
			return;
		}

		const dx = ( event.clientX - current.startX ) * current.scale;
		const dy = ( event.clientY - current.startY ) * current.scale;

		if ( current.mode === 'move' ) {
			history.live( ( latest ) =>
				moveBoxes( latest, current.boxes, current.element, dx, dy )
			);
			return;
		}

		const resized = resizeBox(
			current.box,
			current.box.w + dx,
			current.box.h + dy,
			layout
		);

		history.live( ( latest ) =>
			withBox( latest, current.element, resized )
		);
	};

	const end = () => {
		if ( drag.current !== null ) {
			history.settle();
		}

		drag.current = null;
	};

	// Ctrl/Cmd+Z undoes and Ctrl/Cmd+Shift+Z (or Ctrl+Y) redoes, unless a text
	// field outside the designer has its own undo.
	const { undo, redo } = history;

	useEffect( () => {
		const onKey = ( event: KeyboardEvent ) => {
			const action = historyKey( event );
			const inside =
				event.target instanceof Node &&
				root.current?.contains( event.target ) === true;

			if (
				action === null ||
				( editsText( event.target ) && ! inside )
			) {
				return;
			}

			event.preventDefault();

			if ( action === 'undo' ) {
				undo();
			} else {
				redo();
			}
		};

		window.addEventListener( 'keydown', onKey );

		return () => window.removeEventListener( 'keydown', onKey );
	}, [ undo, redo ] );

	const save = async () => {
		setBusy( true );
		setSaveError( null );
		// The overlay settings decide whether the secondary text is shown.
		const result = await api.update( {
			layout: withBox( layout, 'secondary_text', {
				...layout.elements.secondary_text,
				visible: settings.show_secondary,
			} ),
		} );
		setBusy( false );

		if ( ! result.ok ) {
			setSaveError( result.error );
			return;
		}

		if ( result.data.overlay !== null ) {
			history.live( result.data.overlay.layout );
		}

		onSaved( result.data );
		setToast( __( 'Layout saved.', 'merchboard' ) );
	};

	const percent = ( value: number, of: number ) =>
		`${ ( value / of ) * 100 }%`;
	// Top and height of a box; "grow" boxes near the bottom keep their bottom
	// edge and grow up (as the overlay does), and the image follows.
	const boxPlacement = (
		element: LayoutElement,
		item: LayoutBox
	): CSSProperties => {
		if ( ! grows( element ) ) {
			return {
				top: percent( item.y, CANVAS.height ),
				height: percent( item.h, CANVAS.height ),
				...( element === 'product_image' && imageShift > 0
					? { marginTop: -imageShift }
					: {} ),
			};
		}

		return growsUp( item )
			? {
					bottom: percent(
						CANVAS.height - item.y - item.h,
						CANVAS.height
					),
					height: 'auto',
					minHeight: percent( item.h, CANVAS.height ),
				}
			: {
					top: percent( item.y, CANVAS.height ),
					height: 'auto',
					minHeight: percent( item.h, CANVAS.height ),
				};
	};
	// Canvas pixels in container units, so the preview scales with the canvas.
	const scaled = ( value: number ) =>
		`${ ( value / CANVAS.width ) * 100 }cqw`;
	const textStyle = {
		fontFamily,
		fontWeight: look.font_weight,
		color: look.text_color,
		textShadow: look.text_shadow === '' ? 'none' : look.text_shadow,
		// With a text fill, the shadow is drawn as a drop shadow (textfill.css).
		'--merchboard-text-shadow':
			look.text_shadow === '' ? 'none' : look.text_shadow,
		textAlign: look.text_align,
		background: background( look.bg_color, look.bg_opacity ),
		borderRadius: scaled( look.border_radius ),
		padding: scaled( look.padding ),
		fontSize: scaled( look.font_size ),
	} as CSSProperties;

	const number = (
		field: 'x' | 'y' | 'w' | 'h' | 'border_radius',
		label: string
	) => (
		<Field label={ label }>
			{ ( control ) => (
				<input
					{ ...control }
					type="number"
					className="merchboard-input"
					step={ 1 }
					min={ BOX_LIMITS[ field ].min }
					max={ BOX_LIMITS[ field ].max }
					value={ box[ field ] }
					onChange={ ( event ) => {
						const value = event.target.valueAsNumber;

						if ( Number.isFinite( value ) ) {
							setBox(
								selected,
								field === 'w' || field === 'h'
									? resizeBox(
											box,
											field === 'w' ? value : box.w,
											field === 'h' ? value : box.h,
											{
												...layout,
												canvas: {
													...layout.canvas,
													snap_grid: false,
												},
											}
										)
									: {
											...box,
											[ field ]: Math.min(
												BOX_LIMITS[ field ].max,
												Math.max(
													BOX_LIMITS[ field ].min,
													Math.round( value )
												)
											),
										}
							);
						}
					} }
				/>
			) }
		</Field>
	);

	return (
		<Card title={ __( 'Designer', 'merchboard' ) }>
			<p>
				{ __(
					'Drag the boxes to place them, and drag the lower right corner to resize them. Shift- or Ctrl-click (or Shift+Enter) selects several boxes; drag one of them to move them together, or align them below. The canvas is your 1920 × 1080 browser source. The preview uses sample values.',
					'merchboard'
				) }
			</p>
			{ saveError !== null && <ApiErrorState error={ saveError } /> }
			<div ref={ root } className="merchboard-designer__root">
				<div
					ref={ canvas }
					className="merchboard-designer"
					onPointerMove={ move }
					onPointerUp={ end }
					onPointerCancel={ end }
					style={
						layout.canvas.snap_grid
							? ( {
									'--designer-grid': percent(
										layout.canvas.grid_size,
										CANVAS.width
									),
								} as CSSProperties )
							: undefined
					}
					data-grid={ layout.canvas.snap_grid ? 'on' : 'off' }
				>
					{ elements.map( ( element ) => {
						const item = layout.elements[ element ];
						const template =
							element === 'secondary_text'
								? look.secondary_template
								: look.template;

						return (
							<div
								key={ element }
								role="button"
								tabIndex={ 0 }
								ref={
									element === 'main_text'
										? mainBox
										: undefined
								}
								aria-pressed={ selection.includes( element ) }
								aria-label={ elementLabel( element ) }
								className={ `merchboard-designer__box${
									selection.includes( element )
										? ' is-selected'
										: ''
								}${ item.visible ? '' : ' is-hidden' }` }
								style={ {
									left: percent( item.x, CANVAS.width ),
									width: percent( item.w, CANVAS.width ),
									...boxPlacement( element, item ),
								} }
								onPointerDown={ ( event ) =>
									start( event, element, 'move' )
								}
								onKeyDown={ ( event ) => {
									if (
										event.key === 'Enter' ||
										event.key === ' '
									) {
										event.preventDefault();

										if ( event.shiftKey ) {
											toggle( element );
										} else {
											setSelection( [ element ] );
										}
									}
								} }
							>
								{ element === 'product_image' ? (
									<span
										className="merchboard-designer__image"
										style={ {
											borderRadius: scaled(
												item.border_radius
											),
										} }
									>
										{ elementLabel( element ) }
									</span>
								) : (
									<PreviewText
										text={ sampleText( template ) }
										style={
											grows( element )
												? {
														...textStyle,
														height: 'auto',
													}
												: textStyle
										}
										shrink={ look.text_fit === 'shrink' }
										size={ `${ item.w }x${ item.h }` }
										animation={
											element === 'secondary_text'
												? look.animations.secondary_text
												: look.animations.main_text
										}
										play={ play }
										fill={ fillLook(
											element === 'secondary_text'
												? look.text_fills.secondary_text
												: look.text_fills.main_text,
											fillImages
										) }
										animTime={ look.anim_duration }
										displayTime={ look.display_duration }
									/>
								) }
								<span
									className="merchboard-designer__handle"
									aria-hidden="true"
									onPointerDown={ ( event ) =>
										start( event, element, 'resize' )
									}
								/>
							</div>
						);
					} ) }
					{ framed && (
						<div
							className="merchboard-designer__frame"
							aria-hidden="true"
							style={ ( () => {
								const ring = frameBox(
									layout.elements.product_image,
									look.font_size
								);

								return {
									left: percent( ring.x, CANVAS.width ),
									top: percent( ring.y, CANVAS.height ),
									width: percent( ring.w, CANVAS.width ),
									height: percent( ring.h, CANVAS.height ),
								};
							} )() }
						>
							<PreviewText
								text={ sampleText( look.template ) }
								style={ textStyle }
								shrink={ false }
								size={ `${ layout.elements.product_image.w }x${ layout.elements.product_image.h }x${ look.font_size }` }
								animation={ look.animations.main_text }
								play={ play }
								fill={ fillLook(
									look.text_fills.main_text,
									fillImages
								) }
								frame={ look.text_frame }
								animTime={ look.anim_duration }
								displayTime={ look.display_duration }
							/>
						</div>
					) }
				</div>

				<fieldset className="merchboard-designer__align">
					<legend className="merchboard-field__label">
						{ __( 'Align', 'merchboard' ) }
					</legend>
					<div className="merchboard-alerts__actions">
						{ ALIGNMENTS.map( ( alignment ) => (
							<Button
								key={ alignment }
								variant="secondary"
								size="sm"
								onClick={ () =>
									setLayout(
										alignBoxes(
											layout,
											selection,
											alignment,
											selection.length > 1
												? alignTarget
												: 'canvas'
										)
									)
								}
							>
								{ alignmentLabel( alignment ) }
							</Button>
						) ) }
					</div>
					<Field label={ __( 'Align to', 'merchboard' ) }>
						{ ( control ) => (
							<select
								{ ...control }
								className="merchboard-input"
								value={
									selection.length > 1
										? alignTarget
										: 'canvas'
								}
								disabled={ selection.length < 2 }
								onChange={ ( event ) =>
									setAlignTarget(
										event.target.value === 'selection'
											? 'selection'
											: 'canvas'
									)
								}
							>
								<option value="canvas">
									{ __( 'The canvas', 'merchboard' ) }
								</option>
								<option value="selection">
									{ __(
										'The selected elements',
										'merchboard'
									) }
								</option>
							</select>
						) }
					</Field>
				</fieldset>

				{ selection.length > 1 && (
					<p className="merchboard-designer__selection">
						{ sprintf(
							/* translators: 1: number of elements, 2: their names. */
							__( '%1$d elements selected: %2$s.', 'merchboard' ),
							selection.length,
							selection.map( elementLabel ).join( ', ' )
						) }
					</p>
				) }

				<fieldset className="merchboard-designer__properties">
					<legend className="merchboard-field__label">
						{ elementLabel( selected ) }
					</legend>
					<div className="merchboard-alerts__grid">
						{ number( 'x', __( 'Left (px)', 'merchboard' ) ) }
						{ number( 'y', __( 'Top (px)', 'merchboard' ) ) }
						{ number( 'w', __( 'Width (px)', 'merchboard' ) ) }
						{ number( 'h', __( 'Height (px)', 'merchboard' ) ) }
						{ selected === 'product_image' &&
							number(
								'border_radius',
								__( 'Image corner radius (px)', 'merchboard' )
							) }
					</div>
					{ selected !== 'secondary_text' && (
						<label
							className="merchboard-alerts__check"
							htmlFor={ `${ id }-visible` }
						>
							<input
								id={ `${ id }-visible` }
								type="checkbox"
								checked={ box.visible }
								onChange={ ( event ) =>
									setBox( selected, {
										...box,
										visible: event.target.checked,
									} )
								}
							/>{ ' ' }
							{ __( 'Show this element', 'merchboard' ) }
						</label>
					) }
					{ selected === 'product_image' && (
						<label
							className="merchboard-alerts__check"
							htmlFor={ `${ id }-aspect` }
						>
							<input
								id={ `${ id }-aspect` }
								type="checkbox"
								checked={ box.lock_aspect }
								onChange={ ( event ) =>
									setBox( selected, {
										...box,
										lock_aspect: event.target.checked,
									} )
								}
							/>{ ' ' }
							{ __( 'Keep the aspect ratio', 'merchboard' ) }
						</label>
					) }
				</fieldset>

				<div className="merchboard-alerts__grid">
					<label
						className="merchboard-alerts__check"
						htmlFor={ `${ id }-snap` }
					>
						<input
							id={ `${ id }-snap` }
							type="checkbox"
							checked={ layout.canvas.snap_grid }
							onChange={ ( event ) =>
								setLayout( {
									...layout,
									canvas: {
										...layout.canvas,
										snap_grid: event.target.checked,
									},
								} )
							}
						/>{ ' ' }
						{ __( 'Snap to grid', 'merchboard' ) }
					</label>
					<Field label={ __( 'Grid size (px)', 'merchboard' ) }>
						{ ( control ) => (
							<input
								{ ...control }
								type="number"
								className="merchboard-input"
								min={ 1 }
								max={ 100 }
								step={ 1 }
								value={ layout.canvas.grid_size }
								onChange={ ( event ) => {
									const value = event.target.valueAsNumber;

									if (
										Number.isInteger( value ) &&
										value >= 1 &&
										value <= 100
									) {
										setLayout( {
											...layout,
											canvas: {
												...layout.canvas,
												grid_size: value,
											},
										} );
									}
								} }
							/>
						) }
					</Field>
				</div>

				<div className="merchboard-alerts__actions">
					<Button onClick={ save } isBusy={ busy }>
						{ busy
							? __( 'Saving…', 'merchboard' )
							: __( 'Save layout', 'merchboard' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => setLayout( DEFAULT_LAYOUT ) }
					>
						{ __( 'Reset to the default layout', 'merchboard' ) }
					</Button>
					<Button
						variant="ghost"
						onClick={ undo }
						disabled={ ! history.canUndo }
						aria-keyshortcuts="Control+Z Meta+Z"
					>
						{ __( 'Undo', 'merchboard' ) }
					</Button>
					<Button
						variant="ghost"
						onClick={ redo }
						disabled={ ! history.canRedo }
						aria-keyshortcuts="Control+Shift+Z Meta+Shift+Z Control+Y"
					>
						{ __( 'Redo', 'merchboard' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => setPlay( ( count ) => count + 1 ) }
					>
						{ __( 'Play animation', 'merchboard' ) }
					</Button>
				</div>
			</div>

			<Toast message={ toast } onDismiss={ dismissToast } />
		</Card>
	);
}
