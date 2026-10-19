import { useCallback, useId, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { CSSProperties, PointerEvent as ReactPointerEvent } from 'react';

import { updateAlertsOverlay } from '../../api/alerts';
import type { ApiError } from '../../api/client';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Toast } from '../../components/Toast';
import { BOX_LIMITS, CANVAS, LAYOUT_ELEMENTS } from '../../types/alerts';
import type {
	AlertsOverlayState,
	LayoutBox,
	LayoutElement,
	OverlayLayout,
	OverlaySettings,
} from '../../types/alerts';
import {
	DEFAULT_LAYOUT,
	background,
	moveBox,
	resizeBox,
	sampleText,
	withBox,
} from './designer';

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

interface Drag {
	readonly element: LayoutElement;
	readonly mode: 'move' | 'resize';
	readonly startX: number;
	readonly startY: number;
	readonly box: LayoutBox;
	readonly scale: number;
}

interface Props {
	readonly layout: OverlayLayout;
	readonly settings: OverlaySettings;
	/** The CSS font-family of the preview (approved font or inherit). */
	readonly fontFamily: string;
	readonly onSaved: ( state: AlertsOverlayState ) => void;
}

/**
 * The overlay designer (ADR-065 pkt. 3): the image, the main text and the
 * secondary text on the 1920 × 1080 canvas of OBS. Boxes are dragged and
 * resized with the pointer (resize from the lower right corner) or set
 * exactly in the properties below, which also serve keyboard users. The
 * preview uses sample values, never customer data.
 *
 * @param props            Props.
 * @param props.layout     The saved layout.
 * @param props.settings   The saved settings (look of the preview).
 * @param props.fontFamily CSS font-family of the preview.
 * @param props.onSaved    Called with the answer after saving.
 */
export function OverlayDesigner( {
	layout: saved,
	settings,
	fontFamily,
	onSaved,
}: Props ) {
	const id = useId();
	const canvas = useRef< HTMLDivElement >( null );
	const drag = useRef< Drag | null >( null );
	const [ layout, setLayout ] = useState( saved );
	const [ selected, setSelected ] = useState< LayoutElement >( 'main_text' );
	const [ busy, setBusy ] = useState( false );
	const [ saveError, setSaveError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	const box = layout.elements[ selected ];
	const setBox = ( element: LayoutElement, next: LayoutBox ) =>
		setLayout( ( current ) => withBox( current, element, next ) );

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
		event.currentTarget.setPointerCapture?.( event.pointerId );
		setSelected( element );
		drag.current = {
			element,
			mode,
			startX: event.clientX,
			startY: event.clientY,
			box: layout.elements[ element ],
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

		setBox(
			current.element,
			current.mode === 'move'
				? moveBox(
						current.box,
						current.box.x + dx,
						current.box.y + dy,
						layout
					)
				: resizeBox(
						current.box,
						current.box.w + dx,
						current.box.h + dy,
						layout
					)
		);
	};

	const end = () => {
		drag.current = null;
	};

	const save = async () => {
		setBusy( true );
		setSaveError( null );
		const result = await updateAlertsOverlay( { layout } );
		setBusy( false );

		if ( ! result.ok ) {
			setSaveError( result.error );
			return;
		}

		if ( result.data.overlay !== null ) {
			setLayout( result.data.overlay.layout );
		}

		onSaved( result.data );
		setToast( __( 'Layout saved.', 'merchboard' ) );
	};

	const percent = ( value: number, of: number ) =>
		`${ ( value / of ) * 100 }%`;
	// Canvas pixels in container units, so the preview scales with the canvas.
	const scaled = ( value: number ) =>
		`${ ( value / CANVAS.width ) * 100 }cqw`;
	const textStyle: CSSProperties = {
		fontFamily,
		fontWeight: settings.font_weight,
		color: settings.text_color,
		textShadow: settings.text_shadow === '' ? 'none' : settings.text_shadow,
		textAlign: settings.text_align,
		background: background( settings.bg_color, settings.bg_opacity ),
		borderRadius: scaled( settings.border_radius ),
		padding: scaled( settings.padding ),
		fontSize: scaled( settings.font_size ),
	};

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
					'Drag the boxes to place them, and drag the lower right corner to resize them. The canvas is your 1920 × 1080 browser source. The preview uses sample values.',
					'merchboard'
				) }
			</p>
			{ saveError !== null && <ApiErrorState error={ saveError } /> }

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
				{ LAYOUT_ELEMENTS.map( ( element ) => {
					const item = layout.elements[ element ];
					const template =
						element === 'secondary_text'
							? settings.secondary_template
							: settings.template;

					return (
						<div
							key={ element }
							role="button"
							tabIndex={ 0 }
							aria-pressed={ selected === element }
							aria-label={ elementLabel( element ) }
							className={ `merchboard-designer__box${
								selected === element ? ' is-selected' : ''
							}${ item.visible ? '' : ' is-hidden' }` }
							style={ {
								left: percent( item.x, CANVAS.width ),
								top: percent( item.y, CANVAS.height ),
								width: percent( item.w, CANVAS.width ),
								height: percent( item.h, CANVAS.height ),
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
									setSelected( element );
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
								<span
									className="merchboard-designer__text"
									style={ textStyle }
								>
									{ sampleText( template ) }
								</span>
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
			</div>

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
			</div>

			<Toast message={ toast } onDismiss={ dismissToast } />
		</Card>
	);
}
