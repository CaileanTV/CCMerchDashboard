import { useCallback, useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { updateAlertsOverlay } from '../../api/alerts';
import type { ApiError } from '../../api/client';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Toast } from '../../components/Toast';
import {
	FONT_WEIGHTS,
	NUMBER_FIELDS,
	TEMPLATE_MAX_LENGTH,
	TEXT_ALIGNS,
} from '../../types/alerts';
import type {
	AlertFontOption,
	AlertsOverlayState,
	NumberField,
	OverlaySettings,
	TextAlign,
} from '../../types/alerts';

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

interface Props {
	readonly settings: OverlaySettings;
	readonly fonts: readonly AlertFontOption[];
	readonly fontApproved: boolean;
	readonly onSaved: ( state: AlertsOverlayState ) => void;
}

/**
 * The overlay's text, box and timing (ADR-065 pkt. 3). Position and size of
 * the boxes, the font and the sounds have their own steps (designer, fonts,
 * sounds). Saving changes the overlay's version, so an open OBS source
 * reloads with the new look.
 *
 * @param props              Props.
 * @param props.settings     The saved settings.
 * @param props.fonts        The approved fonts.
 * @param props.fontApproved Whether the saved font is still approved.
 * @param props.onSaved      Called with the answer after saving.
 */
export function OverlaySettingsForm( {
	settings,
	fonts,
	fontApproved,
	onSaved,
}: Props ) {
	const id = useId();
	const [ values, setValues ] = useState( settings );
	const [ errors, setErrors ] = useState< Errors >( {} );
	const [ saveError, setSaveError ] = useState< ApiError | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	const set = ( change: Partial< OverlaySettings > ) =>
		setValues( ( current ) => ( { ...current, ...change } ) );

	const onSubmit = async ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		setSaveError( null );

		const clientErrors = validateOverlaySettings( values );
		setErrors( clientErrors );

		if ( Object.keys( clientErrors ).length > 0 ) {
			return;
		}

		// A font that is no longer approved is kept until the creator picks another.
		const unavailable =
			values.font_family !== 'inherit' &&
			! fonts.some( ( font ) => font.family === values.font_family );
		const { font_family: fontFamily, ...rest } = values;

		setBusy( true );
		const result = await updateAlertsOverlay(
			unavailable ? rest : { ...rest, font_family: fontFamily }
		);
		setBusy( false );

		if ( ! result.ok ) {
			setSaveError( result.error );
			return;
		}

		if ( result.data.overlay !== null ) {
			setValues( result.data.overlay.settings );
		}

		onSaved( result.data );
		setToast( __( 'Overlay settings saved.', 'merchboard' ) );
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

	return (
		<form
			className="merchboard-form merchboard-alerts__form merchboard-alerts__wide"
			onSubmit={ onSubmit }
			noValidate
		>
			{ saveError !== null && <ApiErrorState error={ saveError } /> }

			<Card title={ __( 'Alert text', 'merchboard' ) }>
				<Field
					label={ __( 'Text', 'merchboard' ) }
					help={ __(
						'Variables: $UserName, $Product, $Quantity, $Variation, $Category and $OrderNumber. $UserName is the username the customer entered at checkout, or "Anonymous"; customer names are never shown.',
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
			</Card>

			<Card title={ __( 'Text style', 'merchboard' ) }>
				{ ! fontApproved && (
					<Alert tone="warning">
						{ __(
							'Your font is no longer available. The overlay shows the default font until you choose another one.',
							'merchboard'
						) }
					</Alert>
				) }
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
									<option
										value={ values.font_family }
										disabled
									>
										{ values.font_family }
									</option>
								) }
							{ fonts.map( ( font ) => (
								<option
									key={ font.family }
									value={ font.family }
								>
									{ font.family }
								</option>
							) ) }
						</select>
					) }
				</Field>
				<div className="merchboard-alerts__grid">
					{ number(
						'font_size',
						__( 'Font size (px)', 'merchboard' )
					) }
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
					<Field label={ __( 'Alignment', 'merchboard' ) }>
						{ ( control ) => (
							<select
								{ ...control }
								className="merchboard-input"
								value={ values.text_align }
								onChange={ ( event ) =>
									set( {
										text_align: event.target
											.value as TextAlign,
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
				</div>
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
			</Card>

			<Card title={ __( 'Box and image', 'merchboard' ) }>
				<div className="merchboard-alerts__grid">
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
					{ number(
						'bg_opacity',
						__( 'Background opacity (%)', 'merchboard' )
					) }
					{ number(
						'border_radius',
						__( 'Corner radius (px)', 'merchboard' )
					) }
					{ number( 'padding', __( 'Padding (px)', 'merchboard' ) ) }
					{ number(
						'image_size',
						__( 'Image quality (px)', 'merchboard' ),
						__(
							'The size of the product image that is loaded. The box size is set in the designer.',
							'merchboard'
						)
					) }
				</div>
			</Card>

			<Card title={ __( 'Timing', 'merchboard' ) }>
				<div className="merchboard-alerts__grid">
					{ number(
						'display_duration',
						__( 'Display time (ms)', 'merchboard' )
					) }
					{ number(
						'anim_duration',
						__( 'Animation time (ms)', 'merchboard' )
					) }
				</div>
			</Card>

			<div className="merchboard-form__actions">
				<Button type="submit" isBusy={ busy }>
					{ busy
						? __( 'Saving…', 'merchboard' )
						: __( 'Save overlay settings', 'merchboard' ) }
				</Button>
			</div>

			<Toast message={ toast } onDismiss={ dismissToast } />
		</form>
	);
}
