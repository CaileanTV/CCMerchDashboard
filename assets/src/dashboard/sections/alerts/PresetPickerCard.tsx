import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { applyAlertPreset, getAlertPresets } from '../../api/alerts-presets';
import type { ApiError } from '../../api/client';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { useApi } from '../../hooks/useApi';
import type { AlertFontOption, AlertsOverlayState } from '../../types/alerts';
import { OverlayDesigner } from './OverlayDesigner';
import { useFontStylesheet } from './useFontStylesheet';

interface Props {
	readonly fonts: readonly AlertFontOption[];
	readonly onApplied: ( state: AlertsOverlayState ) => void;
	readonly notify: ( message: string ) => void;
}

/**
 * "Start from a standard overlay" (step 25.9.5): the administrator's
 * standard overlays with a preview of the chosen one; copying it replaces
 * the look and the texts of the creator's overlay. The creator's sounds and
 * URL stay, and later changes of the standard do not change the copy.
 * Nothing is shown while there is no standard overlay.
 *
 * @param props           Props.
 * @param props.fonts     Approved fonts (for the preview).
 * @param props.onApplied Called with the overlay after the copy.
 * @param props.notify    Shows a short message.
 */
export function PresetPickerCard( { fonts, onApplied, notify }: Props ) {
	const { state } = useApi( getAlertPresets );
	const [ chosen, setChosen ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );
	const presets = state.status === 'success' ? state.data : [];
	const preset =
		presets.find( ( candidate ) => candidate.id === chosen ) ??
		presets[ 0 ] ??
		null;
	const font =
		preset === null
			? undefined
			: fonts.find(
					( option ) => option.family === preset.settings.font_family
				);

	useFontStylesheet( font?.url ?? null );

	if ( preset === null ) {
		return null;
	}

	const apply = async () => {
		if (
			// eslint-disable-next-line no-alert -- Replacing the look needs an explicit confirmation.
			! window.confirm(
				__(
					'Copy this standard overlay into your overlay? Its look and texts replace yours; your sounds and your overlay URL stay.',
					'merchboard'
				)
			)
		) {
			return;
		}

		setBusy( true );
		setError( null );
		const result = await applyAlertPreset( preset.id );
		setBusy( false );

		if ( result.ok ) {
			onApplied( result.data );
			notify(
				__(
					'The standard overlay was copied into your overlay.',
					'merchboard'
				)
			);
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ __( 'Start from a standard overlay', 'merchboard' ) }>
			<p>
				{ __(
					'Copy a ready-made look and its texts into your overlay, then change it as you like. Your sounds and your overlay URL stay.',
					'merchboard'
				) }
			</p>
			{ error !== null && <ApiErrorState error={ error } /> }
			<Field label={ __( 'Standard overlay', 'merchboard' ) }>
				{ ( control ) => (
					<select
						{ ...control }
						className="merchboard-input"
						value={ preset.id }
						onChange={ ( event ) =>
							setChosen( event.target.value )
						}
					>
						{ presets.map( ( option ) => (
							<option key={ option.id } value={ option.id }>
								{ option.name }
							</option>
						) ) }
					</select>
				) }
			</Field>
			<OverlayDesigner
				key={ preset.id }
				layout={ preset.layout }
				settings={ preset.settings }
				images={ preset.images }
				fontFamily={
					font === undefined
						? 'inherit'
						: `"${ font.family }", sans-serif`
				}
				onSaved={ () => undefined }
				readOnly
			/>
			<div className="merchboard-alerts__actions">
				<Button onClick={ apply } isBusy={ busy }>
					{ __( 'Use this standard overlay', 'merchboard' ) }
				</Button>
			</div>
		</Card>
	);
}
