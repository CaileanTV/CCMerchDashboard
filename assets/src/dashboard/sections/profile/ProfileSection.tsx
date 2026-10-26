import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import {
	getProfile,
	updateProfile,
	uploadProfileImage,
	withdrawProposal,
} from '../../api/creator';
import type { ProfileChanges } from '../../api/creator';
import type { ApiError, ApiResult } from '../../api/client';
import { useConfig } from '../../app/config-context';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';
import { PROFILE_LIMITS, SOCIAL_PLATFORMS } from '../../types/creator';
import type {
	ImageField,
	Profile,
	ProposalField,
	SocialLinks,
	SocialPlatform,
} from '../../types/creator';
import { DiscountCodeCard } from './DiscountCodeCard';
import { ImageProposal } from './ImageProposal';
import { platformLabel } from './platforms';
import { ProposalNote } from './ProposalNote';
import { ThemeSettings } from './ThemeSettings';
import { errorMessage, validateProfile } from './validation';
import type { FieldErrors, ProfileValues } from './validation';

/**
 * The form's starting values: a pending proposal, else the value now.
 *
 * @param profile Profile.
 */
function initialValues( profile: Profile ): ProfileValues {
	const text = ( field: ProposalField, now: string ): string =>
		profile.pending[ field ]?.value ?? now;
	const links: Partial< Record< SocialPlatform, string > > = {};

	for ( const platform of SOCIAL_PLATFORMS ) {
		links[ platform ] = text(
			`social_${ platform }`,
			profile.category?.socialLinks[ platform ] ?? ''
		);
	}

	return {
		displayName: text( 'display_name', profile.displayName ),
		description: text( 'description', profile.category?.description ?? '' ),
		bannerLink: text( 'banner_link', profile.category?.bannerLink ?? '' ),
		socialLinks: links as SocialLinks,
	};
}

/**
 * Only the fields that differ from the starting values.
 *
 * @param initial Starting values.
 * @param values  Form values.
 */
function changes(
	initial: ProfileValues,
	values: ProfileValues
): ProfileChanges {
	const links: Partial< Record< SocialPlatform, string > > = {};

	for ( const platform of SOCIAL_PLATFORMS ) {
		const value = values.socialLinks[ platform ].trim();

		if ( value !== initial.socialLinks[ platform ] ) {
			links[ platform ] = value;
		}
	}

	return {
		...( values.displayName.trim() === initial.displayName
			? {}
			: { displayName: values.displayName.trim() } ),
		...( values.description.trim() === initial.description
			? {}
			: { description: values.description } ),
		...( values.bannerLink.trim() === initial.bannerLink
			? {}
			: { bannerLink: values.bannerLink.trim() } ),
		...( Object.keys( links ).length === 0 ? {} : { socialLinks: links } ),
	};
}

function imageLabel( field: ImageField ): string {
	switch ( field ) {
		case 'image':
			return __( 'Logo (category image)', 'merchboard' );
		case 'banner':
			return __( 'Banner', 'merchboard' );
		default:
			return __( 'Banner on mobile', 'merchboard' );
	}
}

function imageHelp( field: ImageField ): string {
	switch ( field ) {
		case 'image':
			return __(
				'Square, JPG, PNG or WebP, at least 300 × 300 pixels and at most 5 MB. Larger than 1000 × 1000 pixels is scaled down. Without an own banner it is shown in the middle of the shop’s standard banner.',
				'merchboard'
			);
		case 'banner':
			return __(
				'JPG, PNG or WebP, at least 1280 pixels wide and at most 5 MB, e.g. 2000 × 394 pixels. Remove it to show the standard banner again.',
				'merchboard'
			);
		default:
			return __(
				'Optional, for screens below 768 pixels: JPG, PNG or WebP, at least 600 pixels wide and at most 5 MB.',
				'merchboard'
			);
	}
}

interface ProfileFormProps {
	readonly profile: Profile;
	readonly onChange: ( profile: Profile ) => void;
}

function ProfileForm( { profile, onChange }: ProfileFormProps ) {
	const config = useConfig();
	const [ initial, setInitial ] = useState( () => initialValues( profile ) );
	const [ values, setValues ] = useState( initial );
	const [ errors, setErrors ] = useState< FieldErrors >( {} );
	const [ saveError, setSaveError ] = useState< ApiError | null >( null );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );
	const can = ( field: ProposalField ) => profile.fields.includes( field );
	const category = profile.category;

	/**
	 * Takes a new profile from the server; the form starts from it again.
	 *
	 * @param next    New profile.
	 * @param message Toast.
	 */
	const accept = ( next: Profile, message: string ) => {
		const start = initialValues( next );
		onChange( next );
		setInitial( start );
		setValues( start );
		setErrors( {} );
		setToast( message );
	};

	/**
	 * Runs one request; field errors are shown, other errors above the form.
	 *
	 * @param key     Busy key.
	 * @param send    Request.
	 * @param message Toast on success.
	 */
	const run = async (
		key: string,
		send: () => Promise< ApiResult< Profile > >,
		message: string
	) => {
		setSaveError( null );
		setBusy( key );
		const result = await send();
		setBusy( null );

		if ( result.ok ) {
			accept( result.data, message );
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

	const onSubmit = async ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();

		const clientErrors = validateProfile( values );
		setErrors( clientErrors );

		if ( Object.keys( clientErrors ).length > 0 ) {
			return;
		}

		const changed = changes( initial, values );

		if ( Object.keys( changed ).length === 0 ) {
			setToast( __( 'No changes to send.', 'merchboard' ) );
			return;
		}

		await run(
			'save',
			() => updateProfile( changed ),
			__( 'Sent for approval.', 'merchboard' )
		);
	};

	const onUpload = ( field: ImageField, file: File ) =>
		void run(
			`upload:${ field }`,
			() => uploadProfileImage( field, file ),
			__( 'Image sent for approval.', 'merchboard' )
		);
	const onRemove = ( field: ImageField ) =>
		void run(
			`remove:${ field }`,
			() => updateProfile( { remove: [ field ] } ),
			__( 'Sent for approval.', 'merchboard' )
		);
	const onWithdraw = ( field: ProposalField ) =>
		void run(
			`withdraw:${ field }`,
			() => withdrawProposal( field ),
			__( 'Proposal withdrawn.', 'merchboard' )
		);

	const error = ( field: string, limit?: number ) => {
		const code = errors[ field ];

		return code === undefined ? {} : { error: errorMessage( code, limit ) };
	};
	const note = ( field: ProposalField, label: string, showValue = false ) => (
		<ProposalNote
			profile={ profile }
			field={ field }
			label={ label }
			showValue={ showValue }
			busy={ busy === `withdraw:${ field }` }
			onWithdraw={ onWithdraw }
		/>
	);
	const setLink = ( platform: SocialPlatform, url: string ) =>
		setValues( ( current ) => ( {
			...current,
			socialLinks: {
				...current.socialLinks,
				[ platform ]: url,
			} as SocialLinks,
		} ) );
	const images: ReadonlyArray< { field: ImageField; current: string } > = [
		{ field: 'image', current: category?.imageUrl ?? '' },
		{ field: 'banner', current: category?.bannerUrl ?? '' },
		{ field: 'banner_mobile', current: category?.bannerMobileUrl ?? '' },
	];
	const platforms = SOCIAL_PLATFORMS.filter( ( platform ) =>
		can( `social_${ platform }` )
	);

	return (
		<form className="merchboard-form" onSubmit={ onSubmit } noValidate>
			<Alert tone="info">
				{ __(
					'Your changes are sent to the shop for approval. They are shown in the shop when an administrator has approved them.',
					'merchboard'
				) }
			</Alert>
			{ category === null && profile.fields.length > 1 && (
				<Alert tone="warning">
					{ __(
						'You do not have a product category in the shop yet. Your proposals are kept until you get one.',
						'merchboard'
					) }
				</Alert>
			) }
			{ saveError !== null && <ApiErrorState error={ saveError } /> }

			<Card title={ __( 'About you', 'merchboard' ) }>
				<Field
					label={ __( 'Name', 'merchboard' ) }
					help={ __(
						'Also the name of your category in the shop.',
						'merchboard'
					) }
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
				{ note( 'display_name', __( 'Name', 'merchboard' ), true ) }
				{ can( 'description' ) && (
					<>
						<Field
							label={ __( 'Description', 'merchboard' ) }
							help={ `${ values.description.length } / ${ PROFILE_LIMITS.description }` }
							{ ...error(
								'description',
								PROFILE_LIMITS.description
							) }
						>
							{ ( control ) => (
								<textarea
									{ ...control }
									className="merchboard-input merchboard-input--textarea"
									rows={ 5 }
									value={ values.description }
									onChange={ ( event ) =>
										setValues( {
											...values,
											description: event.target.value,
										} )
									}
								/>
							) }
						</Field>
						{ note(
							'description',
							__( 'Description', 'merchboard' )
						) }
					</>
				) }
			</Card>

			{ can( 'image' ) && (
				<Card title={ __( 'Logo and banner', 'merchboard' ) }>
					{ images
						.filter( ( { field } ) => can( field ) )
						.map( ( { field, current } ) => (
							<ImageProposal
								key={ field }
								profile={ profile }
								field={ field }
								label={ imageLabel( field ) }
								help={ imageHelp( field ) }
								current={ current }
								isDefault={
									field === 'banner' &&
									category?.bannerDefault === true
								}
								busy={ busy }
								error={
									errors[ field ] === undefined
										? null
										: errorMessage( errors[ field ] ?? '' )
								}
								onUpload={ onUpload }
								onRemove={ onRemove }
								onWithdraw={ onWithdraw }
							/>
						) ) }
					{ can( 'banner_link' ) && (
						<>
							<Field
								label={ __(
									'Link on the banner',
									'merchboard'
								) }
								help={ __(
									'Optional, e.g. your Twitch or YouTube channel. Opens in a new tab.',
									'merchboard'
								) }
								{ ...error( 'banner_link' ) }
							>
								{ ( control ) => (
									<input
										{ ...control }
										type="url"
										className="merchboard-input"
										inputMode="url"
										placeholder="https://"
										maxLength={ PROFILE_LIMITS.link }
										value={ values.bannerLink }
										onChange={ ( event ) =>
											setValues( {
												...values,
												bannerLink: event.target.value,
											} )
										}
									/>
								) }
							</Field>
							{ note(
								'banner_link',
								__( 'Link on the banner', 'merchboard' ),
								true
							) }
						</>
					) }
				</Card>
			) }

			{ platforms.length > 0 && (
				<Card title={ __( 'Social media', 'merchboard' ) }>
					<p className="merchboard-field__help">
						{ __(
							'Shown as icons on your category page. Enter the full address or just your user name (e.g. @name).',
							'merchboard'
						) }
					</p>
					{ platforms.map( ( platform ) => (
						<div key={ platform }>
							<Field
								label={ platformLabel( platform ) }
								{ ...error( `social_links.${ platform }` ) }
							>
								{ ( control ) => (
									<input
										{ ...control }
										type="text"
										className="merchboard-input"
										inputMode="url"
										placeholder="@name"
										maxLength={ PROFILE_LIMITS.url }
										value={ values.socialLinks[ platform ] }
										onChange={ ( event ) =>
											setLink(
												platform,
												event.target.value
											)
										}
									/>
								) }
							</Field>
							{ note(
								`social_${ platform }`,
								platformLabel( platform )
							) }
						</div>
					) ) }
				</Card>
			) }

			<Card title={ __( 'Password', 'merchboard' ) }>
				<p>
					<a href={ config.passwordUrl }>
						{ __( 'Change your password', 'merchboard' ) }
					</a>
				</p>
			</Card>

			<div className="merchboard-form__actions">
				<Button type="submit" isBusy={ busy === 'save' }>
					{ busy === 'save'
						? __( 'Sending…', 'merchboard' )
						: __( 'Send for approval', 'merchboard' ) }
				</Button>
			</div>

			<Toast message={ toast } onDismiss={ dismissToast } />
		</form>
	);
}

function ProfileContent() {
	const { state, retry } = useApi( getProfile );
	const [ profile, setProfile ] = useState< Profile | null >( null );

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

	return (
		<ProfileForm
			profile={ profile ?? state.data }
			onChange={ setProfile }
		/>
	);
}

/**
 * Profile section: the creator's name and product category (phase 28,
 * ADR-077) — description, logo, banner, mobile banner, link and social
 * links — proposed for an administrator's approval; the creator's own
 * discount code, hidden until shown (ADR-068); and (phase 21) the personal
 * dashboard theme. The code and the theme are on their own, outside the
 * profile form, and do not wait for the profile to load.
 */
export function ProfileSection() {
	return (
		<>
			<ProfileContent />
			<DiscountCodeCard />
			<ThemeSettings />
		</>
	);
}
