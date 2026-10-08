import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { getProfile, updateProfile } from '../../api/creator';
import type { ProfileChanges } from '../../api/creator';
import type { ApiError } from '../../api/client';
import { useConfig } from '../../app/config-context';
import { useCreator } from '../../app/creator-context';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';
import { PROFILE_LIMITS, SOCIAL_PLATFORMS } from '../../types/creator';
import type { Profile, SocialLinks, SocialPlatform } from '../../types/creator';
import { platformLabel } from './platforms';
import { ThemeSettings } from './ThemeSettings';
import { errorMessage, validateProfile } from './validation';
import type { FieldErrors } from './validation';

/**
 * Only the fields that differ from the saved profile.
 *
 * @param saved  Saved profile.
 * @param values Form values.
 */
function changes( saved: Profile, values: Profile ): ProfileChanges {
	const links: Partial< Record< SocialPlatform, string > > = {};

	for ( const platform of SOCIAL_PLATFORMS ) {
		const value = values.socialLinks[ platform ].trim();

		if ( value !== saved.socialLinks[ platform ] ) {
			links[ platform ] = value;
		}
	}

	return {
		...( values.displayName.trim() === saved.displayName
			? {}
			: { displayName: values.displayName.trim() } ),
		...( values.bio.trim() === saved.bio ? {} : { bio: values.bio } ),
		...( Object.keys( links ).length === 0 ? {} : { socialLinks: links } ),
	};
}

function ProfileForm( { initial }: { readonly initial: Profile } ) {
	const config = useConfig();
	const creator = useCreator();
	const [ saved, setSaved ] = useState( initial );
	const [ values, setValues ] = useState( initial );
	const [ errors, setErrors ] = useState< FieldErrors >( {} );
	const [ saveError, setSaveError ] = useState< ApiError | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	const setLink = ( platform: SocialPlatform, url: string ) =>
		setValues( ( current ) => ( {
			...current,
			socialLinks: {
				...current.socialLinks,
				[ platform ]: url,
			} as SocialLinks,
		} ) );

	const onSubmit = async ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		setSaveError( null );

		const clientErrors = validateProfile( values );
		setErrors( clientErrors );

		if ( Object.keys( clientErrors ).length > 0 ) {
			return;
		}

		const changed = changes( saved, values );

		if ( Object.keys( changed ).length === 0 ) {
			setToast( __( 'No changes to save.', 'merchboard' ) );
			return;
		}

		setBusy( true );
		const result = await updateProfile( changed );
		setBusy( false );

		if ( result.ok ) {
			setSaved( result.data );
			setValues( result.data );
			creator.update( {
				displayName: result.data.displayName,
				status: 'active',
			} );
			setToast( __( 'Profile saved.', 'merchboard' ) );
			return;
		}

		if (
			result.error.kind === 'invalid_request' &&
			Object.keys( result.error.fields ).length > 0
		) {
			setErrors( result.error.fields );
			return;
		}

		setSaveError( result.error );
	};

	const error = ( field: string, limit?: number ) => {
		const code = errors[ field ];

		return code === undefined ? {} : { error: errorMessage( code, limit ) };
	};

	return (
		<form className="merchboard-form" onSubmit={ onSubmit } noValidate>
			{ saveError !== null && <ApiErrorState error={ saveError } /> }

			<Card title={ __( 'About you', 'merchboard' ) }>
				<Field
					label={ __( 'Display name', 'merchboard' ) }
					{ ...error( 'display_name', PROFILE_LIMITS.displayName ) }
				>
					{ ( control ) => (
						<input
							{ ...control }
							type="text"
							className="merchboard-input"
							autoComplete="nickname"
							maxLength={ PROFILE_LIMITS.displayName }
							required
							value={ values.displayName }
							onChange={ ( event ) =>
								setValues( {
									...values,
									displayName: event.target.value,
								} )
							}
						/>
					) }
				</Field>
				<Field
					label={ __( 'Bio', 'merchboard' ) }
					help={ `${ values.bio.length } / ${ PROFILE_LIMITS.bio }` }
					{ ...error( 'bio', PROFILE_LIMITS.bio ) }
				>
					{ ( control ) => (
						<textarea
							{ ...control }
							className="merchboard-input merchboard-input--textarea"
							rows={ 5 }
							value={ values.bio }
							onChange={ ( event ) =>
								setValues( {
									...values,
									bio: event.target.value,
								} )
							}
						/>
					) }
				</Field>
			</Card>

			<Card title={ __( 'Links', 'merchboard' ) }>
				{ SOCIAL_PLATFORMS.map( ( platform ) => (
					<Field
						key={ platform }
						label={ platformLabel( platform ) }
						{ ...error( `social_links.${ platform }` ) }
					>
						{ ( control ) => (
							<input
								{ ...control }
								type="url"
								className="merchboard-input"
								inputMode="url"
								placeholder="https://"
								maxLength={ PROFILE_LIMITS.url }
								value={ values.socialLinks[ platform ] }
								onChange={ ( event ) =>
									setLink( platform, event.target.value )
								}
							/>
						) }
					</Field>
				) ) }
			</Card>

			<Card title={ __( 'Password', 'merchboard' ) }>
				<p>
					<a href={ config.passwordUrl }>
						{ __( 'Change your password', 'merchboard' ) }
					</a>
				</p>
			</Card>

			<div className="merchboard-form__actions">
				<Button type="submit" isBusy={ busy }>
					{ busy
						? __( 'Saving…', 'merchboard' )
						: __( 'Save profile', 'merchboard' ) }
				</Button>
			</div>

			<Toast message={ toast } onDismiss={ dismissToast } />
		</form>
	);
}

function ProfileContent() {
	const { state, retry } = useApi( getProfile );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading your profile…', 'merchboard' ) }
				lines={ 5 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return <ProfileForm initial={ state.data } />;
}

/**
 * Profile section (phase 14): the creator's own display name, bio and links,
 * and (phase 21) the personal dashboard theme. The theme is saved on its own,
 * outside the profile form, and does not wait for the profile to load.
 */
export function ProfileSection() {
	return (
		<>
			<ProfileContent />
			<ThemeSettings />
		</>
	);
}
