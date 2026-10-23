import {
	createPortal,
	Fragment,
	useCallback,
	useEffect,
	useId,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent, ReactNode } from 'react';

import type { ApiError } from '../../api/client';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Alert } from '../../components/Alert';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Toast } from '../../components/Toast';
import { AlertImagesCard } from './AlertImagesCard';
import { AnimationFields } from './AnimationFields';
import { OVERLAY_VARIABLES, TemplateVariables } from './TemplateVariables';
import {
	FONT_WEIGHTS,
	NUMBER_FIELDS,
	TEMPLATE_MAX_LENGTH,
	TEXT_ALIGNS,
	TEXT_FITS,
} from '../../types/alerts';
import type {
	AlertFontOption,
	AlertsImages,
	AlertsOverlayState,
	NumberField,
	OverlaySettings,
	TextAlign,
	TextFit,
} from '../../types/alerts';
import { useOverlayApi } from './overlay-api-context';
import { DEFAULT_PAGE_LAYOUT } from './page-layout';
import type {
	FieldCard,
	PageCard,
	PageField,
	PageLayout,
	Zone,
} from './page-layout';

type Errors = Partial< Record< keyof OverlaySettings, string > >;

/**
 * Client-side checks with the server's limits; the server normalizes again.
 *
 * @param values Form values.
 */
export function validateOverlaySettings( values: OverlaySettings ): Errors {
	const errors: Errors = {};

	for ( const [ field, range ] of Object.entries( NUMBER_FIELDS ) ) {
		const value = values[ field as NumberField ];

		if (
			! Number.isInteger( value ) ||
			value < range.min ||
			value > range.max
		) {
			errors[ field as NumberField ] = sprintf(
				/* translators: 1: minimum, 2: maximum. */
				__( 'Enter a whole number from %1$d to %2$d.', 'merchboard' ),
				range.min,
				range.max
			);
		}
	}

	if ( values.template.trim() === '' ) {
		errors.template = __( 'Enter a text for the alert.', 'merchboard' );
	}

	for ( const field of [ 'template', 'secondary_template' ] as const ) {
		if ( values[ field ].length > TEMPLATE_MAX_LENGTH ) {
			errors[ field ] = sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Use at most %d characters.', 'merchboard' ),
				TEMPLATE_MAX_LENGTH
			);
		}
	}

	return errors;
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

function alignLabel( align: TextAlign ): string {
	switch ( align ) {
		case 'left':
			return __( 'Left', 'merchboard' );
		case 'right':
			return __( 'Right', 'merchboard' );
		default:
			return __( 'Center', 'merchboard' );
	}
}

/** Small fields: next to each other, they share a grid row. */
const COMPACT_FIELDS: ReadonlySet< PageField > = new Set< PageField >( [
	'font_size',
	'font_weight',
	'text_color',
	'text_align',
	'bg_color',
	'bg_opacity',
	'border_radius',
	'padding',
	'image_size',
	'display_duration',
	'anim_duration',
] );

/** Waiting time after the last change before it is saved (ms). */
export const AUTOSAVE_DELAY = 800;

type SaveStatus = 'idle' | 'pending' | 'saving' | 'saved' | 'invalid' | 'error';

/**
 * The text of the save status.
 *
 * @param status Save status.
 */
function statusText( status: SaveStatus ): string {
	switch ( status ) {
		case 'pending':
		case 'saving':
			return __( 'Saving…', 'merchboard' );
		case 'saved':
			return __( 'All changes are saved.', 'merchboard' );
		case 'invalid':
			return __( 'Not saved: correct the marked fields.', 'merchboard' );
		case 'error':
			return __(
				'Not saved. Change a field to try again.',
				'merchboard'
			);
		default:
			return __( 'Changes are saved automatically.', 'merchboard' );
	}
}

interface Props {
	readonly settings: OverlaySettings;
	readonly fonts: readonly AlertFontOption[];
	readonly fontApproved: boolean;
	readonly onSaved: ( state: AlertsOverlayState ) => void;
	/**
	 * Where the save status is shown (right above the designer, step 25.6.1);
	 * null keeps it at the end of the form.
	 */
	readonly saveSlot?: HTMLElement | null;
	/** Called with the unsaved values on every change (the designer previews them). */
	readonly onDraft?: ( values: OverlaySettings ) => void;
	/** Images the texts can be filled with (step 25.6.10); null when not available. */
	readonly images?: AlertsImages | null;
	/** Shows a message in the section's toast stack; without it, the form has its own toast. */
	readonly onNotify?: ( message: string ) => void;
	/** Where the cards stand and the order of their fields (step 25.9.6). */
	readonly layout?: PageLayout;
	/** The section's other cards, placed by the setup like the form's own. */
	readonly extraCards?: Partial< Record< PageCard, ReactNode > >;
	/** Changes when the saved settings were replaced (a design taken into use): the form starts again from them. */
	readonly revision?: number;
}

/**
 * The overlay's text, box and timing (ADR-065 pkt. 3). Position and size of
 * the boxes, the font and the sounds have their own steps (designer, fonts,
 * sounds). Saving changes the overlay's version, so an open OBS source
 * reloads with the new look. Every change is saved automatically, a moment
 * after the last one (AUTOSAVE_DELAY); invalid fields are marked and not saved.
 * The cards (with the section's other cards, extraCards) stand in full width
 * or the left or right column and their fields in the order of the page's
 * setup (step 25.9.6); the form spans them all, so Enter in a field saves.
 *
 * @param props              Props.
 * @param props.settings     The saved settings.
 * @param props.fonts        The approved fonts.
 * @param props.fontApproved Whether the saved font is still approved.
 * @param props.onSaved      Called with the answer after saving.
 * @param props.saveSlot     Where the save status is shown.
 * @param props.onDraft      Called with the unsaved values.
 * @param props.images       Images the texts can be filled with.
 * @param props.onNotify     Shows a message in the section's toasts.
 * @param props.layout       The page's setup.
 * @param props.extraCards   The section's other cards.
 * @param props.revision     Changes when the saved settings were replaced.
 */
export function OverlaySettingsForm( {
	settings,
	fonts,
	fontApproved,
	onSaved,
	saveSlot = null,
	onDraft,
	images = null,
	onNotify,
	layout = DEFAULT_PAGE_LAYOUT,
	extraCards = {},
	revision = 0,
}: Props ) {
	const id = useId();
	const [ values, setValues ] = useState( settings );
	const api = useOverlayApi();

	useEffect( () => onDraft?.( values ), [ values, onDraft ] );
	const [ errors, setErrors ] = useState< Errors >( {} );
	const [ saveError, setSaveError ] = useState< ApiError | null >( null );
	const [ status, setStatus ] = useState< SaveStatus >( 'idle' );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );
	const notify = onNotify ?? setToast;
	// What the server has, the values now, whether a save runs and the waiting save.
	const saved = useRef( JSON.stringify( settings ) );
	const latest = useRef( values );
	const saving = useRef( false );
	const timer = useRef< number | null >( null );
	const saveRef = useRef< () => Promise< void > >( async () => undefined );

	latest.current = values;

	// Replaced settings (a design taken into use): start again from them.
	const shownRevision = useRef( revision );

	useEffect( () => {
		if ( revision === shownRevision.current ) {
			return;
		}

		shownRevision.current = revision;
		saved.current = JSON.stringify( settings );
		setValues( settings );
		setErrors( {} );
		setStatus( 'idle' );
	}, [ revision, settings ] );

	const set = ( change: Partial< OverlaySettings > ) =>
		setValues( ( current ) => ( { ...current, ...change } ) );

	const schedule = useCallback( ( delay: number ) => {
		if ( timer.current !== null ) {
			window.clearTimeout( timer.current );
		}

		timer.current = window.setTimeout( () => {
			timer.current = null;
			void saveRef.current();
		}, delay );
	}, [] );

	// Saves the current values (step 25.9.5 follow-up: every change is saved
	// on its own, a moment after the last one, so typing is not saved per key).
	saveRef.current = async () => {
		const current = latest.current;
		const key = JSON.stringify( current );

		if ( key === saved.current ) {
			return;
		}

		const clientErrors = validateOverlaySettings( current );
		setErrors( clientErrors );

		if ( Object.keys( clientErrors ).length > 0 ) {
			setStatus( 'invalid' );
			return;
		}

		// One save at a time; the next one starts when this one has ended.
		if ( saving.current ) {
			schedule( AUTOSAVE_DELAY );
			return;
		}

		// A font that is no longer approved is kept until the creator picks another.
		const unavailable =
			current.font_family !== 'inherit' &&
			! fonts.some( ( font ) => font.family === current.font_family );
		const { font_family: fontFamily, ...rest } = current;

		saving.current = true;
		setStatus( 'saving' );
		setSaveError( null );
		const result = await api.update(
			unavailable ? rest : { ...rest, font_family: fontFamily }
		);
		saving.current = false;

		if ( ! result.ok ) {
			setSaveError( result.error );
			setStatus( 'error' );
			return;
		}

		saved.current = key;

		// The server's values (normalized) only when nothing changed meanwhile.
		if (
			result.data.overlay !== null &&
			JSON.stringify( latest.current ) === key
		) {
			saved.current = JSON.stringify( result.data.overlay.settings );
			setValues( result.data.overlay.settings );
		}

		onSaved( result.data );
		setStatus( 'saved' );
		notify( __( 'Settings saved.', 'merchboard' ) );
	};

	useEffect( () => {
		if ( JSON.stringify( values ) !== saved.current ) {
			setStatus( 'pending' );
			schedule( AUTOSAVE_DELAY );
		}
	}, [ values, schedule ] );

	// Leaving the page section saves a change that still waits.
	useEffect(
		() => () => {
			if ( timer.current !== null ) {
				window.clearTimeout( timer.current );
				void saveRef.current();
			}
		},
		[]
	);

	// Enter in a field saves at once.
	const onSubmit = ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		schedule( 0 );
	};

	const error = ( field: keyof OverlaySettings ) => {
		const message = errors[ field ];

		return message === undefined ? {} : { error: message };
	};

	const number = ( field: NumberField, label: string, help?: string ) => (
		<Field
			label={ label }
			{ ...( help === undefined ? {} : { help } ) }
			{ ...error( field ) }
		>
			{ ( control ) => (
				<input
					{ ...control }
					type="number"
					className="merchboard-input"
					inputMode="numeric"
					step={ 1 }
					min={ NUMBER_FIELDS[ field ].min }
					max={ NUMBER_FIELDS[ field ].max }
					value={
						Number.isNaN( values[ field ] ) ? '' : values[ field ]
					}
					onChange={ ( event ) =>
						set( { [ field ]: event.target.valueAsNumber } )
					}
				/>
			) }
		</Field>
	);

	// The button belongs to the form (the form attribute) wherever it is shown.
	const actions = (
		<div className="merchboard-form__actions">
			<p
				className="merchboard-field__help merchboard-alerts__autosave"
				role="status"
				aria-live="polite"
			>
				{ statusText( status ) }
			</p>
		</div>
	);

	// Every field the setup can order (step 25.9.6), by its key.
	const fieldNodes: Record< PageField, ReactNode > = {
		template: (
			<>
				<Field
					label={ __( 'Text', 'merchboard' ) }
					help={ __(
						'Variables: $UserName, $Product, $Quantity, $Variation, $Category, $Price, $LineTotal and $Total; see what each shows below. Customer names are never shown.',
						'merchboard'
					) }
					{ ...error( 'template' ) }
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
				{ /* Right below the text, wherever the page's setup places it. */ }
				<TemplateVariables variables={ OVERLAY_VARIABLES } />
			</>
		),
		secondary_template: (
			<Field
				label={ __( 'Secondary text', 'merchboard' ) }
				{ ...error( 'secondary_template' ) }
			>
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
		),
		show_secondary: (
			<label
				className="merchboard-alerts__check"
				htmlFor={ `${ id }-secondary` }
			>
				<input
					id={ `${ id }-secondary` }
					type="checkbox"
					checked={ values.show_secondary }
					onChange={ ( event ) =>
						set( { show_secondary: event.target.checked } )
					}
				/>{ ' ' }
				{ __( 'Show the secondary text', 'merchboard' ) }
			</label>
		),
		font: (
			<Field
				label={ __( 'Font', 'merchboard' ) }
				help={ __(
					'Fonts approved by Merch Dashboard. They are loaded from Google Fonts.',
					'merchboard'
				) }
			>
				{ ( control ) => (
					<select
						{ ...control }
						className="merchboard-input"
						value={ values.font_family }
						onChange={ ( event ) =>
							set( { font_family: event.target.value } )
						}
					>
						<option value="inherit">
							{ __( 'Default font', 'merchboard' ) }
						</option>
						{ ! fontApproved &&
							values.font_family !== 'inherit' && (
								<option value={ values.font_family } disabled>
									{ values.font_family }
								</option>
							) }
						{ fonts.map( ( font ) => (
							<option key={ font.family } value={ font.family }>
								{ font.family }
							</option>
						) ) }
					</select>
				) }
			</Field>
		),
		font_size: number( 'font_size', __( 'Font size (px)', 'merchboard' ) ),
		font_weight: (
			<Field label={ __( 'Font weight', 'merchboard' ) }>
				{ ( control ) => (
					<select
						{ ...control }
						className="merchboard-input"
						value={ values.font_weight }
						onChange={ ( event ) =>
							set( {
								font_weight: event.target
									.value as OverlaySettings[ 'font_weight' ],
							} )
						}
					>
						{ FONT_WEIGHTS.map( ( weight ) => (
							<option key={ weight } value={ weight }>
								{ weight }
							</option>
						) ) }
					</select>
				) }
			</Field>
		),
		text_color: (
			<Field label={ __( 'Text color', 'merchboard' ) }>
				{ ( control ) => (
					<input
						{ ...control }
						type="color"
						className="merchboard-input merchboard-alerts__color"
						value={ values.text_color }
						onChange={ ( event ) =>
							set( { text_color: event.target.value } )
						}
					/>
				) }
			</Field>
		),
		text_align: (
			<Field label={ __( 'Alignment', 'merchboard' ) }>
				{ ( control ) => (
					<select
						{ ...control }
						className="merchboard-input"
						value={ values.text_align }
						onChange={ ( event ) =>
							set( {
								text_align: event.target.value as TextAlign,
							} )
						}
					>
						{ TEXT_ALIGNS.map( ( align ) => (
							<option key={ align } value={ align }>
								{ alignLabel( align ) }
							</option>
						) ) }
					</select>
				) }
			</Field>
		),
		text_fit: (
			<Field
				label={ __( 'Text size in the boxes', 'merchboard' ) }
				help={ __(
					'Fixed: the font size above; longer text is cut off. Shrink: the font size above is the largest, and long text gets smaller until it fits. Grow: the box keeps its width and grows with the text: down from its top, or up from its bottom edge when it is in the lower half of the screen (the image then moves up with the main text). Its height in the designer is the least.',
					'merchboard'
				) }
			>
				{ ( control ) => (
					<select
						{ ...control }
						className="merchboard-input"
						value={ values.text_fit }
						onChange={ ( event ) =>
							set( {
								text_fit: event.target.value as TextFit,
							} )
						}
					>
						{ TEXT_FITS.map( ( fit ) => (
							<option key={ fit } value={ fit }>
								{ fitLabel( fit ) }
							</option>
						) ) }
					</select>
				) }
			</Field>
		),
		text_shadow: (
			<Field
				label={ __( 'Text shadow', 'merchboard' ) }
				help={ __(
					'For example 2px 2px 4px rgba(0,0,0,0.8). Leave empty for no shadow.',
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
		),
		bg_color: (
			<Field label={ __( 'Background color', 'merchboard' ) }>
				{ ( control ) => (
					<input
						{ ...control }
						type="color"
						className="merchboard-input merchboard-alerts__color"
						value={ values.bg_color }
						onChange={ ( event ) =>
							set( { bg_color: event.target.value } )
						}
					/>
				) }
			</Field>
		),
		bg_opacity: number(
			'bg_opacity',
			__( 'Background opacity (%)', 'merchboard' )
		),
		border_radius: number(
			'border_radius',
			__( 'Corner radius (px)', 'merchboard' )
		),
		padding: number( 'padding', __( 'Padding (px)', 'merchboard' ) ),
		image_size: number(
			'image_size',
			__( 'Image quality (px)', 'merchboard' ),
			__(
				'The size of the product image that is loaded. The box size is set in the designer.',
				'merchboard'
			)
		),
		main_text: (
			<AnimationFields
				legend={ __( 'Main text', 'merchboard' ) }
				value={ values.animations.main_text }
				duration={ values.anim_duration }
				fill={ values.text_fills.main_text }
				onFillChange={ ( fill ) =>
					set( {
						text_fills: {
							...values.text_fills,
							main_text: fill,
						},
					} )
				}
				images={ images }
				frame={ values.text_frame }
				onFrameChange={ ( frame ) => set( { text_frame: frame } ) }
				onChange={ ( animation ) =>
					set( {
						animations: {
							...values.animations,
							main_text: animation,
						},
					} )
				}
			/>
		),
		secondary_text: values.show_secondary ? (
			<AnimationFields
				legend={ __( 'Secondary text', 'merchboard' ) }
				value={ values.animations.secondary_text }
				duration={ values.anim_duration }
				fill={ values.text_fills.secondary_text }
				onFillChange={ ( fill ) =>
					set( {
						text_fills: {
							...values.text_fills,
							secondary_text: fill,
						},
					} )
				}
				images={ images }
				onChange={ ( animation ) =>
					set( {
						animations: {
							...values.animations,
							secondary_text: animation,
						},
					} )
				}
			/>
		) : null,
		display_from_sound: (
			<>
				<label
					className="merchboard-alerts__check"
					htmlFor={ `${ id }-from-sound` }
				>
					<input
						id={ `${ id }-from-sound` }
						type="checkbox"
						checked={ values.display_from_sound }
						aria-describedby={ `${ id }-from-sound-help` }
						onChange={ ( event ) =>
							set( { display_from_sound: event.target.checked } )
						}
					/>{ ' ' }
					{ __(
						'Show the alert as long as its sound plays',
						'merchboard'
					) }
				</label>
				<p
					id={ `${ id }-from-sound-help` }
					className="merchboard-field__help"
				>
					{ __(
						'Just add a sound: an alert with a sound is shown for exactly as long as the sound lasts, then fades out. Alerts without a sound use the display time below.',
						'merchboard'
					) }
				</p>
			</>
		),
		display_duration: number(
			'display_duration',
			__( 'Display time (ms)', 'merchboard' )
		),
		anim_duration: number(
			'anim_duration',
			__( 'Animation time (ms)', 'merchboard' )
		),
	};

	// A card's fields in the setup's order; small fields next to each other share a grid.
	const fieldsOf = ( card: FieldCard ): ReactNode[] => {
		const nodes: ReactNode[] = [];
		let run: PageField[] = [];
		const flush = () => {
			if ( run.length > 0 ) {
				nodes.push(
					<div
						key={ `grid-${ run.join( '-' ) }` }
						className="merchboard-alerts__grid"
					>
						{ run.map( ( field ) => (
							<Fragment key={ field }>
								{ fieldNodes[ field ] }
							</Fragment>
						) ) }
					</div>
				);
				run = [];
			}
		};

		for ( const field of layout.fields[ card ] ) {
			if ( COMPACT_FIELDS.has( field ) ) {
				run.push( field );
				continue;
			}

			flush();
			nodes.push(
				<Fragment key={ field }>{ fieldNodes[ field ] }</Fragment>
			);
		}

		flush();

		return nodes;
	};

	const cards: Partial< Record< PageCard, ReactNode > > = {
		...extraCards,
		alert_text: (
			<Card title={ __( 'Alert text', 'merchboard' ) }>
				{ fieldsOf( 'alert_text' ) }
			</Card>
		),
		text_style: (
			<Card title={ __( 'Text style', 'merchboard' ) }>
				{ ! fontApproved && (
					<Alert tone="warning">
						{ __(
							'Your font is no longer available. The overlay shows the default font until you choose another one.',
							'merchboard'
						) }
					</Alert>
				) }
				{ fieldsOf( 'text_style' ) }
			</Card>
		),
		box_image: (
			<Card title={ __( 'Box and image', 'merchboard' ) }>
				{ fieldsOf( 'box_image' ) }
			</Card>
		),
		animations: (
			<>
				<Card title={ __( 'Animations and text fill', 'merchboard' ) }>
					<p className="merchboard-field__help">
						{ __(
							'How each text comes in, moves while it is shown and goes out, and what fills its letters: the text colour, a gradient or pattern, or an image (an animated GIF keeps moving). The animation time below sets how long the in- and out-animations take. The example shows your choice; press "Play animation" in the designer to see it on the overlay.',
							'merchboard'
						) }
					</p>
					{ fieldsOf( 'animations' ) }
				</Card>
				{ /* The own images, right below where an image is chosen, only when a text uses one. */ }
				{ images !== null &&
					( values.text_fills.main_text.type === 'image' ||
						( values.show_secondary &&
							values.text_fills.secondary_text.type ===
								'image' ) ) && (
						<AlertImagesCard
							images={ images }
							onSaved={ onSaved }
							notify={ notify }
						/>
					) }
			</>
		),
		timing: (
			<Card title={ __( 'Timing', 'merchboard' ) }>
				{ fieldsOf( 'timing' ) }
			</Card>
		),
	};

	const zone = ( name: Zone ) =>
		layout.cards[ name ].map( ( card ) =>
			cards[ card ] === undefined ? null : (
				<Fragment key={ card }>{ cards[ card ] }</Fragment>
			)
		);

	return (
		<form
			id={ `${ id }-form` }
			className="merchboard-form merchboard-alerts__form"
			onSubmit={ onSubmit }
			noValidate
		>
			{ saveError !== null && <ApiErrorState error={ saveError } /> }

			{ layout.cards.full.some(
				( card ) => cards[ card ] !== undefined
			) && (
				<div className="merchboard-alerts__full">
					{ zone( 'full' ) }
				</div>
			) }
			<div className="merchboard-alerts__columns">
				<div className="merchboard-alerts__column">
					{ zone( 'left' ) }
				</div>
				<div className="merchboard-alerts__column">
					{ zone( 'right' ) }
				</div>
			</div>

			{ saveSlot === null ? actions : createPortal( actions, saveSlot ) }

			{ onNotify === undefined && (
				<Toast message={ toast } onDismiss={ dismissToast } />
			) }
		</form>
	);
}
