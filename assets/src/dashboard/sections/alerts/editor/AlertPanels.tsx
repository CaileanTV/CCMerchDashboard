/**
 * The side panel's contents (alert designer 2.0, ADR-088): "Merch alert"
 * (image, sound, layout, message and timing), the panels Text (tabs Text
 * and Advanced) and Animation (every animation: the box, the texts, the
 * fill of the letters and the text around the image), and "Position, size
 * and style" at the bottom.
 */
import { useId } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { Alert } from '../../../components/Alert';
import { Field } from '../../../components/Field';
import { FontPicker } from '../../../components/FontPicker';
import {
	ALERT_ANIMATIONS,
	HIGHLIGHT_EFFECTS,
	NUMBER_FORMATS,
	TTS_VOICES,
} from '../../../../overlay/look';
import type {
	AlertAnimationName,
	HighlightEffect,
	LayoutMode,
	NumberFormat,
	TextCase,
	TextDirection,
	TtsVoice,
} from '../../../../overlay/look';
import {
	CANVAS,
	FONT_WEIGHTS,
	TEMPLATE_MAX_LENGTH,
	TEXT_FITS,
} from '../../../types/alerts';
import type {
	AlertFontOption,
	AlertsImages,
	AlertsOverlayState,
	OverlayLayout,
	OverlaySettings,
	TextAlign,
	TextFit,
} from '../../../types/alerts';
import { AlertImagesCard } from '../AlertImagesCard';
import { AnimationFields } from '../AnimationFields';
import { OVERLAY_VARIABLES, TemplateVariables } from '../TemplateVariables';
import { withBox } from '../designer';
import { moveWidget } from './AlertCanvas';
import { ColorControl, NumberControl, Segmented, Switch } from './controls';
import { Panel, Tabs } from './Panel';

/** What every panel edits. */
export interface EditorFields {
	readonly values: OverlaySettings;
	readonly layout: OverlayLayout;
	readonly set: ( change: Partial< OverlaySettings > ) => void;
	readonly setBoth: (
		settings: OverlaySettings,
		layout: OverlayLayout
	) => void;
}

function layoutLabel( mode: LayoutMode ): string {
	switch ( mode ) {
		case 'above':
			return __( 'Image above text', 'merchboard' );
		case 'side':
			return __( 'Image beside text', 'merchboard' );
		case 'overlay':
			return __( 'Text on image', 'merchboard' );
		default:
			return __( 'Free', 'merchboard' );
	}
}

/**
 * The name of an animation of the alert box.
 *
 * @param name Animation.
 */
export function alertAnimationLabel( name: AlertAnimationName ): string {
	const labels: Record< AlertAnimationName, string > = {
		none: __( 'None', 'merchboard' ),
		fade: __( 'Fade', 'merchboard' ),
		'slide-up': __( 'Slide up', 'merchboard' ),
		'slide-down': __( 'Slide down', 'merchboard' ),
		'slide-left': __( 'Slide left', 'merchboard' ),
		'slide-right': __( 'Slide right', 'merchboard' ),
		zoom: __( 'Zoom', 'merchboard' ),
		bounce: __( 'Bounce', 'merchboard' ),
		flip: __( 'Flip', 'merchboard' ),
	};

	return labels[ name ];
}

function highlightLabel( effect: HighlightEffect ): string {
	const labels: Record< HighlightEffect, string > = {
		none: __( 'No effect', 'merchboard' ),
		pulse: __( 'Pulse', 'merchboard' ),
		glow: __( 'Glow', 'merchboard' ),
		bounce: __( 'Bounce', 'merchboard' ),
		shake: __( 'Shake', 'merchboard' ),
	};

	return labels[ effect ];
}

function numberFormatLabel( format: NumberFormat ): string {
	switch ( format ) {
		case 'whole':
			return __( 'Without decimals (249 kr.)', 'merchboard' );
		case 'plain':
			return __( 'Number only (249,00)', 'merchboard' );
		default:
			return __( 'As the shop shows it (249,00 kr.)', 'merchboard' );
	}
}

function fitLabel( fit: TextFit ): string {
	switch ( fit ) {
		case 'shrink':
			return __( 'Shrink the text to fit the box', 'merchboard' );
		case 'grow':
			return __( 'Let the box grow with the text', 'merchboard' );
		default:
			return __( 'Fixed text size', 'merchboard' );
	}
}

/**
 * A select of fixed choices.
 *
 * @param props          Props.
 * @param props.label    Label.
 * @param props.value    Value.
 * @param props.choices  Values.
 * @param props.name     A value's name.
 * @param props.onChange Called with the chosen value.
 */
function Choice< T extends string >( {
	label,
	value,
	choices,
	name,
	onChange,
}: {
	readonly label: string;
	readonly value: T;
	readonly choices: readonly T[];
	readonly name: ( value: T ) => string;
	readonly onChange: ( value: T ) => void;
} ) {
	return (
		<Field label={ label }>
			{ ( control ) => (
				<select
					{ ...control }
					className="merchboard-input"
					value={ value }
					onChange={ ( event ) =>
						onChange( event.target.value as T )
					}
				>
					{ choices.map( ( choice ) => (
						<option key={ choice } value={ choice }>
							{ name( choice ) }
						</option>
					) ) }
				</select>
			) }
		</Field>
	);
}

/**
 * The highlight of the inserted values: on or off, its effect and colour.
 *
 * @param props        Props.
 * @param props.fields The edited values.
 */
function HighlightFields( { fields }: { readonly fields: EditorFields } ) {
	const { values, set } = fields;
	const highlight = values.highlight;

	return (
		<Panel level="group" title={ __( 'Highlight', 'merchboard' ) }>
			<Switch
				label={ __( 'Highlight the inserted values', 'merchboard' ) }
				checked={ highlight.enabled }
				help={ __(
					'The username, product, amounts and the other variables get their own colour and effect.',
					'merchboard'
				) }
				onChange={ ( enabled ) =>
					set( { highlight: { ...highlight, enabled } } )
				}
			/>
			{ highlight.enabled && (
				<div className="merchboard-alert-grid">
					<Choice
						label={ __( 'Effect', 'merchboard' ) }
						value={ highlight.effect }
						choices={ HIGHLIGHT_EFFECTS }
						name={ highlightLabel }
						onChange={ ( effect ) =>
							set( { highlight: { ...highlight, effect } } )
						}
					/>
					<ColorControl
						label={ __( 'Colour', 'merchboard' ) }
						value={ highlight.color }
						onChange={ ( color ) =>
							set( { highlight: { ...highlight, color } } )
						}
					/>
				</div>
			) }
		</Panel>
	);
}

/**
 * "Merch alert": the product image, the sound, the layout, the message and
 * the timing. Open from the start; the panels Variations, Read-out, Text
 * and Animation follow it.
 *
 * @param props           Props.
 * @param props.fields    The edited values.
 * @param props.sounds    The sound controls (the default's only).
 * @param props.isDefault Whether the default is edited, not a variation.
 */
export function MerchAlertPanel( {
	fields,
	sounds,
	isDefault = true,
}: {
	readonly fields: EditorFields;
	readonly sounds: ReactNode;
	readonly isDefault?: boolean;
} ) {
	const id = useId();
	const { values, layout, set, setBoth } = fields;
	const image = layout.elements.product_image;
	const setImage = ( change: Partial< typeof image > ) =>
		setBoth(
			values,
			withBox( layout, 'product_image', { ...image, ...change } )
		);

	return (
		<Panel title={ __( 'Merch alert', 'merchboard' ) } defaultOpen>
			<Panel level="group" title={ __( 'Product image', 'merchboard' ) }>
				<Switch
					label={ __( 'Show the product image', 'merchboard' ) }
					checked={ image.visible }
					onChange={ ( visible ) => setImage( { visible } ) }
				/>
				<div className="merchboard-alert-grid">
					<NumberControl
						label={ __( 'Image quality', 'merchboard' ) }
						unit="px"
						value={ values.image_size }
						min={ 32 }
						max={ 512 }
						help={ __(
							'The size of the image that is loaded, not of the box.',
							'merchboard'
						) }
						onChange={ ( size ) => set( { image_size: size } ) }
					/>
					<NumberControl
						label={ __( 'Image corners', 'merchboard' ) }
						unit="px"
						value={ image.border_radius }
						min={ 0 }
						max={ 500 }
						onChange={ ( radius ) =>
							setImage( { border_radius: radius } )
						}
					/>
				</div>
			</Panel>

			<Panel level="group" title={ __( 'Sound', 'merchboard' ) }>
				{ sounds ?? (
					<p className="merchboard-field__help">
						{ __(
							"The sound files are set in the default; choose the variation's own sound above.",
							'merchboard'
						) }
					</p>
				) }
				<NumberControl
					label={ __( 'Sound volume', 'merchboard' ) }
					unit="%"
					value={ values.audio_volume }
					min={ 0 }
					max={ 100 }
					onChange={ ( volume ) => set( { audio_volume: volume } ) }
				/>
				<Switch
					label={ __(
						'Show the alert as long as its sound plays',
						'merchboard'
					) }
					checked={ values.display_from_sound }
					onChange={ ( on ) => set( { display_from_sound: on } ) }
				/>
			</Panel>

			<Panel level="group" title={ __( 'Layout', 'merchboard' ) }>
				<Segmented< LayoutMode >
					label={ __( 'Layout', 'merchboard' ) }
					value={ values.layout_mode }
					choices={ (
						[ 'above', 'side', 'overlay', 'free' ] as const
					 ).map( ( mode ) => ( {
						value: mode,
						title: layoutLabel( mode ),
						label: (
							<span
								className={ `merchboard-alert-layout-icon is-${ mode }` }
								aria-hidden="true"
							>
								<span />
								<span />
							</span>
						),
					} ) ) }
					onChange={ ( mode ) => set( { layout_mode: mode } ) }
				/>
				{ values.layout_mode === 'free' && (
					<p className="merchboard-field__help">
						{ __(
							'Free: drag the image and the texts inside the alert box on the canvas.',
							'merchboard'
						) }
					</p>
				) }
			</Panel>

			<Panel
				level="group"
				title={ __( 'Message', 'merchboard' ) }
				defaultOpen
			>
				<Field
					label={ __( 'Alert message', 'merchboard' ) }
					help={ __(
						'Variables: $UserName, $Product, $Quantity, $Variation, $Category, $Price, $LineTotal and $Total. Customer names are never shown.',
						'merchboard'
					) }
				>
					{ ( control ) => (
						<textarea
							{ ...control }
							className="merchboard-input merchboard-input--textarea"
							rows={ 3 }
							maxLength={ TEMPLATE_MAX_LENGTH }
							value={ values.template }
							onChange={ ( event ) =>
								set( { template: event.target.value } )
							}
						/>
					) }
				</Field>
				<TemplateVariables variables={ OVERLAY_VARIABLES } />
				<Switch
					label={ __( 'Show an extra line of text', 'merchboard' ) }
					checked={ values.show_secondary }
					onChange={ ( show ) =>
						setBoth(
							{ ...values, show_secondary: show },
							withBox( layout, 'secondary_text', {
								...layout.elements.secondary_text,
								visible: show,
							} )
						)
					}
				/>
				{ values.show_secondary && (
					<Field label={ __( 'Extra line', 'merchboard' ) }>
						{ ( control ) => (
							<textarea
								{ ...control }
								className="merchboard-input merchboard-input--textarea"
								rows={ 2 }
								maxLength={ TEMPLATE_MAX_LENGTH }
								value={ values.secondary_template }
								onChange={ ( event ) =>
									set( {
										secondary_template: event.target.value,
									} )
								}
							/>
						) }
					</Field>
				) }
			</Panel>
			<Panel level="group" title={ __( 'Timing', 'merchboard' ) }>
				<div className="merchboard-alert-grid">
					<NumberControl
						label={ __( 'Alert duration', 'merchboard' ) }
						unit="s"
						step={ 0.5 }
						value={ values.display_duration / 1000 }
						min={ 1 }
						max={ 90 }
						onChange={ ( seconds ) =>
							set( {
								display_duration: Math.round( seconds * 1000 ),
							} )
						}
					/>
					<NumberControl
						label={ __( 'Longest username', 'merchboard' ) }
						value={ values.max_name_length }
						min={ 0 }
						max={ 100 }
						help={ __(
							'Longer usernames are cut off. 0: no limit.',
							'merchboard'
						) }
						onChange={ ( length ) =>
							set( { max_name_length: length } )
						}
					/>
				</div>
			</Panel>
			{ isDefault && (
				<p id={ `${ id }-default` } className="merchboard-field__help">
					{ __(
						'These are the default settings, used when no variation is chosen.',
						'merchboard'
					) }
				</p>
			) }
		</Panel>
	);
}

/**
 * The text section: the tabs Text (font, colour, size, spacing, alignment,
 * style, stroke, shadow and highlight) and Advanced (margin, number format,
 * highlight).
 *
 * @param props              Props.
 * @param props.fields       The edited values.
 * @param props.fonts        The approved fonts.
 * @param props.fontApproved Whether the saved font is still approved.
 */
export function TextSection( {
	fields,
	fonts,
	fontApproved,
}: {
	readonly fields: EditorFields;
	readonly fonts: readonly AlertFontOption[];
	readonly fontApproved: boolean;
} ) {
	const { values, set } = fields;
	const margin = values.margin;

	const text = (
		<>
			{ ! fontApproved && (
				<Alert tone="warning">
					{ __(
						'Your font is no longer available. The overlay shows the default font until you choose another one.',
						'merchboard'
					) }
				</Alert>
			) }
			<Panel
				level="group"
				title={ __( 'Font and size', 'merchboard' ) }
				defaultOpen
			>
				<Field label={ __( 'Font', 'merchboard' ) }>
					{ ( control ) => (
						<FontPicker
							{ ...control }
							value={ values.font_family }
							onChange={ ( value ) =>
								set( { font_family: value } )
							}
							choices={ [
								{
									value: 'inherit',
									label: __( 'Default font', 'merchboard' ),
									url: null,
								},
								...( ! fontApproved &&
								values.font_family !== 'inherit'
									? [
											{
												value: values.font_family,
												label: values.font_family,
												url: null,
												disabled: true,
											},
										]
									: [] ),
								...fonts.map( ( font ) => ( {
									value: font.family,
									label: font.family,
									url: font.url,
								} ) ),
							] }
						/>
					) }
				</Field>
				<div className="merchboard-alert-grid">
					<ColorControl
						label={ __( 'Colour', 'merchboard' ) }
						value={ values.text_color }
						onChange={ ( color ) => set( { text_color: color } ) }
						opacity={ values.text_opacity }
						onOpacityChange={ ( opacity ) =>
							set( { text_opacity: opacity } )
						}
					/>
					<Choice
						label={ __( 'Font weight', 'merchboard' ) }
						value={ values.font_weight }
						choices={
							[
								...FONT_WEIGHTS,
							] as OverlaySettings[ 'font_weight' ][]
						}
						name={ ( weight ) => weight }
						onChange={ ( weight ) =>
							set( { font_weight: weight } )
						}
					/>
					<NumberControl
						label={ __( 'Font size', 'merchboard' ) }
						unit="px"
						value={ values.font_size }
						min={ 8 }
						max={ 200 }
						onChange={ ( size ) => set( { font_size: size } ) }
					/>
					<NumberControl
						label={ __( 'Line height', 'merchboard' ) }
						unit="%"
						value={ values.line_height }
						min={ 50 }
						max={ 300 }
						onChange={ ( height ) =>
							set( { line_height: height } )
						}
					/>
					<NumberControl
						label={ __( 'Letter spacing', 'merchboard' ) }
						unit="px"
						value={ values.letter_spacing }
						min={ -20 }
						max={ 100 }
						onChange={ ( spacing ) =>
							set( { letter_spacing: spacing } )
						}
					/>
					<NumberControl
						label={ __( 'Word spacing', 'merchboard' ) }
						unit="px"
						value={ values.word_spacing }
						min={ -20 }
						max={ 200 }
						onChange={ ( spacing ) =>
							set( { word_spacing: spacing } )
						}
					/>
				</div>
			</Panel>
			<Panel
				level="group"
				title={ __( 'Alignment and style', 'merchboard' ) }
			>
				<Segmented< TextAlign >
					label={ __( 'Alignment', 'merchboard' ) }
					value={ values.text_align }
					choices={ [
						{
							value: 'left',
							label: '⯇',
							title: __( 'Left', 'merchboard' ),
						},
						{
							value: 'center',
							label: '≡',
							title: __( 'Center', 'merchboard' ),
						},
						{
							value: 'right',
							label: '⯈',
							title: __( 'Right', 'merchboard' ),
						},
						{
							value: 'justify',
							label: '☰',
							title: __( 'Justify', 'merchboard' ),
						},
					] }
					onChange={ ( align ) => set( { text_align: align } ) }
				/>
				<Segmented< TextDirection >
					label={ __( 'Direction', 'merchboard' ) }
					value={ values.text_direction }
					choices={ [
						{
							value: 'ltr',
							label: '→',
							title: __( 'Left to right', 'merchboard' ),
						},
						{
							value: 'rtl',
							label: '←',
							title: __( 'Right to left', 'merchboard' ),
						},
					] }
					onChange={ ( direction ) =>
						set( { text_direction: direction } )
					}
				/>
				<div className="merchboard-alert-grid">
					<Switch
						label={ __( 'Italic', 'merchboard' ) }
						checked={ values.italic }
						onChange={ ( italic ) => set( { italic } ) }
					/>
					<Switch
						label={ __( 'Underline', 'merchboard' ) }
						checked={ values.underline }
						onChange={ ( underline ) => set( { underline } ) }
					/>
				</div>
				<Segmented< TextCase >
					label={ __( 'Casing', 'merchboard' ) }
					value={ values.text_case }
					choices={ [
						{
							value: 'none',
							label: '—',
							title: __( 'As written', 'merchboard' ),
						},
						{
							value: 'upper',
							label: 'AB',
							title: __( 'Capitals', 'merchboard' ),
						},
						{
							value: 'lower',
							label: 'ab',
							title: __( 'Small letters', 'merchboard' ),
						},
						{
							value: 'capitalize',
							label: 'Ab',
							title: __( 'Capital first letters', 'merchboard' ),
						},
					] }
					onChange={ ( textCase ) => set( { text_case: textCase } ) }
				/>
			</Panel>
			<Panel level="group" title={ __( 'Stroke', 'merchboard' ) }>
				<div className="merchboard-alert-grid">
					<NumberControl
						label={ __( 'Width', 'merchboard' ) }
						unit="px"
						value={ values.stroke_width }
						min={ 0 }
						max={ 20 }
						onChange={ ( width ) => set( { stroke_width: width } ) }
					/>
					<ColorControl
						label={ __( 'Colour', 'merchboard' ) }
						value={ values.stroke_color }
						onChange={ ( color ) => set( { stroke_color: color } ) }
						opacity={ values.stroke_opacity }
						onOpacityChange={ ( opacity ) =>
							set( { stroke_opacity: opacity } )
						}
					/>
				</div>
			</Panel>
			<Panel level="group" title={ __( 'Shadow and fit', 'merchboard' ) }>
				<Switch
					label={ __( 'Text shadow', 'merchboard' ) }
					checked={ values.text_shadow_enabled }
					onChange={ ( on ) =>
						set( {
							text_shadow_enabled: on,
							text_shadow:
								on && values.text_shadow === ''
									? '2px 2px 4px rgba(0,0,0,0.8)'
									: values.text_shadow,
						} )
					}
				/>
				{ values.text_shadow_enabled && (
					<Field
						label={ __( 'Shadow', 'merchboard' ) }
						help={ __(
							'For example 2px 2px 4px rgba(0,0,0,0.8).',
							'merchboard'
						) }
					>
						{ ( control ) => (
							<input
								{ ...control }
								type="text"
								className="merchboard-input"
								maxLength={ 100 }
								value={ values.text_shadow }
								onChange={ ( event ) =>
									set( { text_shadow: event.target.value } )
								}
							/>
						) }
					</Field>
				) }
				<Choice
					label={ __( 'Text size in the boxes', 'merchboard' ) }
					value={ values.text_fit }
					choices={ TEXT_FITS }
					name={ fitLabel }
					onChange={ ( fit ) => set( { text_fit: fit } ) }
				/>
			</Panel>
			<HighlightFields fields={ fields } />
		</>
	);

	const advanced = (
		<>
			<Panel level="group" title={ __( 'Margin', 'merchboard' ) }>
				<div className="merchboard-alert-grid">
					{ (
						[
							[ 'top', __( 'Top', 'merchboard' ) ],
							[ 'right', __( 'Right', 'merchboard' ) ],
							[ 'bottom', __( 'Bottom', 'merchboard' ) ],
							[ 'left', __( 'Left', 'merchboard' ) ],
						] as const
					 ).map( ( [ side, label ] ) => (
						<NumberControl
							key={ side }
							label={ label }
							unit="px"
							value={ margin[ side ] }
							min={ -500 }
							max={ 500 }
							onChange={ ( value ) =>
								set( {
									margin: { ...margin, [ side ]: value },
								} )
							}
						/>
					) ) }
				</div>
			</Panel>
			<Panel level="group" title={ __( 'Number format', 'merchboard' ) }>
				<Choice
					label={ __( 'Number format', 'merchboard' ) }
					value={ values.number_format }
					choices={ NUMBER_FORMATS }
					name={ numberFormatLabel }
					onChange={ ( format ) => set( { number_format: format } ) }
				/>
				<p className="merchboard-field__help">
					{ __(
						'Applies to the amounts in the text: $Price, $LineTotal and $Total.',
						'merchboard'
					) }
				</p>
			</Panel>
			<HighlightFields fields={ fields } />
		</>
	);

	return (
		<Panel title={ __( 'Text', 'merchboard' ) }>
			<Tabs
				label={ __( 'Text settings', 'merchboard' ) }
				tabs={ [
					{
						key: 'text',
						label: __( 'Text', 'merchboard' ),
						content: text,
					},
					{
						key: 'advanced',
						label: __( 'Advanced', 'merchboard' ),
						content: advanced,
					},
				] }
			/>
		</Panel>
	);
}

/**
 * The animation section: the alert box's in- and out-animation, each text's
 * animations with their times, when the texts come and go, what fills their
 * letters and the text around the image.
 *
 * @param props         Props.
 * @param props.fields  The edited values.
 * @param props.images  The images a text can be filled with.
 * @param props.onSaved Called with the answer after an image change.
 * @param props.notify  Shows a short confirmation.
 * @param props.onPlay  Plays the alert on the canvas.
 */
export function AnimationSection( {
	fields,
	images,
	onSaved,
	notify,
	onPlay,
}: {
	readonly fields: EditorFields;
	readonly images: AlertsImages | null;
	readonly onSaved: ( state: AlertsOverlayState ) => void;
	readonly notify: ( message: string ) => void;
	readonly onPlay: () => void;
} ) {
	const { values, set } = fields;
	const alert = values.animations.alert;
	const setAlert = ( change: Partial< typeof alert > ) =>
		set( {
			animations: {
				...values.animations,
				alert: { ...alert, ...change },
			},
		} );
	const texts = [
		[ 'main_text', __( 'Main text', 'merchboard' ) ],
		...( values.show_secondary
			? [
					[
						'secondary_text',
						__( 'Extra line', 'merchboard' ),
					] as const,
				]
			: [] ),
	] as const;
	const usesImage =
		values.text_fills.main_text.type === 'image' ||
		( values.show_secondary &&
			values.text_fills.secondary_text.type === 'image' );

	return (
		<Panel title={ __( 'Animation', 'merchboard' ) }>
			<Panel
				level="group"
				title={ __( 'Alert', 'merchboard' ) }
				defaultOpen
			>
				<div className="merchboard-alert-grid">
					<Choice
						label={ __( 'Enter animation', 'merchboard' ) }
						value={ alert.in }
						choices={ ALERT_ANIMATIONS }
						name={ alertAnimationLabel }
						onChange={ ( name ) => setAlert( { in: name } ) }
					/>
					<NumberControl
						label={ __( 'Duration', 'merchboard' ) }
						unit="s"
						step={ 0.1 }
						value={ alert.in_ms / 1000 }
						min={ 0 }
						max={ 10 }
						onChange={ ( seconds ) =>
							setAlert( { in_ms: Math.round( seconds * 1000 ) } )
						}
					/>
					<Choice
						label={ __( 'Exit animation', 'merchboard' ) }
						value={ alert.out }
						choices={ ALERT_ANIMATIONS }
						name={ alertAnimationLabel }
						onChange={ ( name ) => setAlert( { out: name } ) }
					/>
					<NumberControl
						label={ __( 'Duration', 'merchboard' ) }
						unit="s"
						step={ 0.1 }
						value={ alert.out_ms / 1000 }
						min={ 0 }
						max={ 10 }
						onChange={ ( seconds ) =>
							setAlert( { out_ms: Math.round( seconds * 1000 ) } )
						}
					/>
				</div>
			</Panel>
			<Panel level="group" title={ __( 'Text timing', 'merchboard' ) }>
				<div className="merchboard-alert-grid">
					<NumberControl
						label={ __( 'Show text after', 'merchboard' ) }
						unit="s"
						step={ 0.1 }
						value={ values.text_timing.show_after / 1000 }
						min={ 0 }
						max={ 90 }
						onChange={ ( seconds ) =>
							set( {
								text_timing: {
									...values.text_timing,
									show_after: Math.round( seconds * 1000 ),
								},
							} )
						}
					/>
					<NumberControl
						label={ __( 'Hide text before the end', 'merchboard' ) }
						unit="s"
						step={ 0.1 }
						value={ values.text_timing.hide_before / 1000 }
						min={ 0 }
						max={ 90 }
						onChange={ ( seconds ) =>
							set( {
								text_timing: {
									...values.text_timing,
									hide_before: Math.round( seconds * 1000 ),
								},
							} )
						}
					/>
				</div>
			</Panel>
			{ texts.map( ( [ key, legend ] ) => {
				const animation = values.animations[ key ];
				const setText = ( change: Partial< typeof animation > ) =>
					set( {
						animations: {
							...values.animations,
							[ key ]: { ...animation, ...change },
						},
					} );

				return (
					<Panel key={ key } level="group" title={ legend }>
						<AnimationFields
							legend={ legend }
							value={ animation }
							duration={ animation.in_ms }
							fill={ values.text_fills[ key ] }
							onFillChange={ ( fill ) =>
								set( {
									text_fills: {
										...values.text_fills,
										[ key ]: fill,
									},
								} )
							}
							images={ images }
							{ ...( key === 'main_text'
								? {
										frame: values.text_frame,
										onFrameChange: ( frame ) =>
											set( { text_frame: frame } ),
									}
								: {} ) }
							onChange={ ( next ) => setText( next ) }
						/>
						<div className="merchboard-alert-grid">
							<NumberControl
								label={ sprintf(
									/* translators: %s: main text or extra line. */
									__( '%s: in-time', 'merchboard' ),
									legend
								) }
								unit="s"
								step={ 0.1 }
								value={ animation.in_ms / 1000 }
								min={ 0 }
								max={ 10 }
								onChange={ ( seconds ) =>
									setText( {
										in_ms: Math.round( seconds * 1000 ),
									} )
								}
							/>
							<NumberControl
								label={ sprintf(
									/* translators: %s: main text or extra line. */
									__( '%s: out-time', 'merchboard' ),
									legend
								) }
								unit="s"
								step={ 0.1 }
								value={ animation.out_ms / 1000 }
								min={ 0 }
								max={ 10 }
								onChange={ ( seconds ) =>
									setText( {
										out_ms: Math.round( seconds * 1000 ),
									} )
								}
							/>
						</div>
					</Panel>
				);
			} ) }
			{ images !== null && usesImage && (
				<AlertImagesCard
					images={ images }
					onSaved={ onSaved }
					notify={ notify }
				/>
			) }
			<button
				type="button"
				className="merchboard-button merchboard-button--secondary merchboard-button--sm"
				onClick={ onPlay }
			>
				{ __( 'Play on the canvas', 'merchboard' ) }
			</button>
		</Panel>
	);
}

/**
 * "Position, size and style": the alert box's place, size, opacity and
 * rotation, a button that centres it, the text boxes' style and the grid.
 *
 * @param props        Props.
 * @param props.fields The edited values.
 */
export function PositionPanel( { fields }: { readonly fields: EditorFields } ) {
	const { values, layout, set, setBoth } = fields;
	const widget = values.widget;
	const place = ( x: number, y: number ) => {
		const next = moveWidget( values, layout, x, y );
		setBoth( next.settings, next.layout );
	};

	return (
		<Panel title={ __( 'Position, size and style', 'merchboard' ) }>
			<Panel
				level="group"
				title={ __( 'Position and size', 'merchboard' ) }
				defaultOpen
			>
				<div className="merchboard-alert-grid">
					<NumberControl
						label="X"
						unit="px"
						value={ widget.x }
						min={ -3840 }
						max={ 3840 }
						onChange={ ( x ) => place( x, widget.y ) }
					/>
					<NumberControl
						label="Y"
						unit="px"
						value={ widget.y }
						min={ -2160 }
						max={ 2160 }
						onChange={ ( y ) => place( widget.x, y ) }
					/>
					<NumberControl
						label={ __( 'Width', 'merchboard' ) }
						unit="px"
						value={ widget.w }
						min={ 10 }
						max={ 3840 }
						onChange={ ( w ) =>
							set( { widget: { ...widget, w } } )
						}
					/>
					<NumberControl
						label={ __( 'Height', 'merchboard' ) }
						unit="px"
						value={ widget.h }
						min={ 10 }
						max={ 2160 }
						onChange={ ( h ) =>
							set( { widget: { ...widget, h } } )
						}
					/>
					<NumberControl
						label={ __( 'Opacity', 'merchboard' ) }
						unit="%"
						value={ widget.opacity }
						min={ 0 }
						max={ 100 }
						onChange={ ( opacity ) =>
							set( { widget: { ...widget, opacity } } )
						}
					/>
					<NumberControl
						label={ __( 'Rotation', 'merchboard' ) }
						unit="°"
						value={ widget.rotation }
						min={ -180 }
						max={ 180 }
						onChange={ ( rotation ) =>
							set( { widget: { ...widget, rotation } } )
						}
					/>
				</div>
				<button
					type="button"
					className="merchboard-button merchboard-button--secondary merchboard-button--sm"
					onClick={ () =>
						place(
							( CANVAS.width - widget.w ) / 2,
							( CANVAS.height - widget.h ) / 2
						)
					}
				>
					{ __( 'Centre the alert', 'merchboard' ) }
				</button>
			</Panel>
			<Panel level="group" title={ __( 'Text boxes', 'merchboard' ) }>
				<div className="merchboard-alert-grid">
					<ColorControl
						label={ __( 'Background', 'merchboard' ) }
						value={ values.bg_color }
						onChange={ ( color ) => set( { bg_color: color } ) }
						opacity={ values.bg_opacity }
						onOpacityChange={ ( opacity ) =>
							set( { bg_opacity: opacity } )
						}
					/>
					<NumberControl
						label={ __( 'Corners', 'merchboard' ) }
						unit="px"
						value={ values.border_radius }
						min={ 0 }
						max={ 100 }
						onChange={ ( radius ) =>
							set( { border_radius: radius } )
						}
					/>
					<NumberControl
						label={ __( 'Padding', 'merchboard' ) }
						unit="px"
						value={ values.padding }
						min={ 0 }
						max={ 200 }
						onChange={ ( padding ) => set( { padding } ) }
					/>
				</div>
			</Panel>
			<Panel level="group" title={ __( 'Canvas', 'merchboard' ) }>
				<Switch
					label={ __( 'Snap to the grid', 'merchboard' ) }
					checked={ layout.canvas.snap_grid }
					onChange={ ( snapGrid ) =>
						setBoth( values, {
							...layout,
							canvas: { ...layout.canvas, snap_grid: snapGrid },
						} )
					}
				/>
				<NumberControl
					label={ __( 'Grid size', 'merchboard' ) }
					unit="px"
					value={ layout.canvas.grid_size }
					min={ 1 }
					max={ 100 }
					onChange={ ( size ) =>
						setBoth( values, {
							...layout,
							canvas: { ...layout.canvas, grid_size: size },
						} )
					}
				/>
			</Panel>
		</Panel>
	);
}

/**
 * A voice's name in the list.
 *
 * @param voice Google voice name.
 */
function voiceLabel( voice: TtsVoice ): string {
	const languages: Record< string, string > = {
		'da-DK': __( 'Danish', 'merchboard' ),
		'en-GB': __( 'English (UK)', 'merchboard' ),
		'en-US': __( 'English (US)', 'merchboard' ),
		'de-DE': __( 'German', 'merchboard' ),
		'sv-SE': __( 'Swedish', 'merchboard' ),
		'nb-NO': __( 'Norwegian', 'merchboard' ),
	};
	const language = languages[ voice.slice( 0, 5 ) ] ?? voice.slice( 0, 5 );

	return sprintf(
		/* translators: 1: language, 2: voice letter, e.g. "A". */
		__( '%1$s, voice %2$s', 'merchboard' ),
		language,
		voice.slice( -1 )
	);
}

/**
 * The read-out section (ADR-089): on or off, the voice, its volume, the
 * delay after the alert appears and the least amount.
 *
 * @param props           Props.
 * @param props.fields    The edited values.
 * @param props.available Whether an administrator switched the read-out on.
 */
export function TtsSection( {
	fields,
	available,
}: {
	readonly fields: EditorFields;
	readonly available: boolean;
} ) {
	const { values, set } = fields;
	const tts = values.tts;
	const setTts = ( change: Partial< typeof tts > ) =>
		set( { tts: { ...tts, ...change } } );

	return (
		<Panel title={ __( 'Read-out (TTS)', 'merchboard' ) }>
			{ ! available && (
				<p className="merchboard-field__help">
					{ __(
						'Read-out is not switched on for your shop yet. You can set it up already; it starts when it is switched on.',
						'merchboard'
					) }
				</p>
			) }
			<Switch
				label={ __( 'Read the alert out', 'merchboard' ) }
				checked={ tts.enabled }
				help={ __(
					'The alert message is read out with a computer voice, at most 200 characters.',
					'merchboard'
				) }
				onChange={ ( enabled ) => setTts( { enabled } ) }
			/>
			<Choice
				label={ __( 'Voice', 'merchboard' ) }
				value={ tts.voice }
				choices={ TTS_VOICES }
				name={ voiceLabel }
				onChange={ ( voice ) => setTts( { voice } ) }
			/>
			<div className="merchboard-alert-grid">
				<NumberControl
					label={ __( 'Volume', 'merchboard' ) }
					unit="%"
					value={ tts.volume }
					min={ 0 }
					max={ 100 }
					onChange={ ( volume ) => setTts( { volume } ) }
				/>
				<NumberControl
					label={ __( 'Speech delay', 'merchboard' ) }
					unit="s"
					step={ 0.1 }
					value={ tts.delay / 1000 }
					min={ 0 }
					max={ 10 }
					onChange={ ( seconds ) =>
						setTts( { delay: Math.round( seconds * 1000 ) } )
					}
				/>
			</div>
			<NumberControl
				label={ __( 'Least amount', 'merchboard' ) }
				value={ tts.min_amount }
				min={ 0 }
				max={ 1000000 }
				help={ __(
					"Only purchases of at least this amount are read out, in the shop's currency. 0: all.",
					'merchboard'
				) }
				onChange={ ( amount ) =>
					setTts( { min_amount: Math.round( amount ) } )
				}
			/>
		</Panel>
	);
}
