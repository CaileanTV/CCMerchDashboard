import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ApiErrorState } from '../../components/ApiErrorState';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Toast } from '../../components/Toast';
import { useThemePreference } from '../../hooks/useThemePreference';
import { THEMES, isTheme, readTheme, themeLabel } from '../../theme/themes';

/** Option value for "follow the system" (no explicit theme). */
const SYSTEM = '';

/**
 * The user's dashboard theme (ADR-043/ADR-044): a native, labelled select
 * with the six themes by name and "System default". A choice is applied at
 * once and saved with `PATCH /me/preferences`; a failed save restores the
 * saved theme and shows the standard error with a retry. The current choice
 * comes from the server-rendered `data-theme` on <html>.
 */
export function ThemeSettings() {
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );
	const onSaved = useCallback(
		() => setToast( __( 'Theme saved.', 'merchboard' ) ),
		[]
	);
	const [ initial ] = useState( () => readTheme() );
	const { selected, error, choose, retry } = useThemePreference(
		initial,
		onSaved
	);

	return (
		<Card title={ __( 'Appearance', 'merchboard' ) }>
			{ error !== null && (
				<ApiErrorState error={ error } onRetry={ retry } />
			) }
			<Field
				label={ __( 'Theme', 'merchboard' ) }
				help={ __(
					'System default follows the light or dark setting of your device.',
					'merchboard'
				) }
			>
				{ ( control ) => (
					<select
						{ ...control }
						className="merchboard-input"
						value={ selected ?? SYSTEM }
						onChange={ ( event ) => {
							const value = event.target.value;
							choose( isTheme( value ) ? value : null );
						} }
					>
						<option value={ SYSTEM }>
							{ __( 'System default', 'merchboard' ) }
						</option>
						{ THEMES.map( ( theme ) => (
							<option key={ theme } value={ theme }>
								{ themeLabel( theme ) }
							</option>
						) ) }
					</select>
				) }
			</Field>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</Card>
	);
}
