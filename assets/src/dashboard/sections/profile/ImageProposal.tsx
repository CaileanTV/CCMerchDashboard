import { useEffect, useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getProposedImage } from '../../api/creator';
import { Button } from '../../components/Button';
import type {
	ImageField,
	PendingProposal,
	Profile,
	ProposalField,
} from '../../types/creator';
import { ProposalNote } from './ProposalNote';

const IMAGE_ACCEPT = 'image/png,image/jpeg,image/webp';

/**
 * The proposed image, fetched with the creator's session and shown from a
 * local object URL (never a public URL).
 *
 * @param props          Props.
 * @param props.proposal Pending image proposal.
 * @param props.alt      Alternative text.
 */
function ProposedImage( {
	proposal,
	alt,
}: {
	readonly proposal: PendingProposal;
	readonly alt: string;
} ) {
	const [ url, setUrl ] = useState< string | null >( null );

	useEffect( () => {
		const controller = new AbortController();
		let objectUrl: string | null = null;

		void getProposedImage( proposal, controller.signal ).then(
			( result ) => {
				if ( result.ok && typeof URL.createObjectURL === 'function' ) {
					objectUrl = URL.createObjectURL( result.data );
					setUrl( objectUrl );
				}
			}
		);

		return () => {
			controller.abort();

			if ( objectUrl !== null ) {
				URL.revokeObjectURL( objectUrl );
			}
		};
	}, [ proposal ] );

	return url === null ? null : (
		<img
			className="merchboard-profile-image__img"
			src={ url }
			alt={ alt }
		/>
	);
}

export interface ImageProposalProps {
	readonly profile: Profile;
	readonly field: ImageField;
	readonly label: string;
	/** The requirements of the image. */
	readonly help: string;
	/** The current image ('' for none). */
	readonly current: string;
	/** Whether the current image is the banner plugin's default banner. */
	readonly isDefault?: boolean;
	readonly busy: string | null;
	readonly error: string | null;
	readonly onUpload: ( field: ImageField, file: File ) => void;
	readonly onRemove: ( field: ImageField ) => void;
	readonly onWithdraw: ( field: ProposalField ) => void;
}

/**
 * One image of the category (logo, banner, mobile banner): the current one,
 * the proposed one, and buttons to propose another or remove it.
 *
 * @param props Props.
 */
export function ImageProposal( props: ImageProposalProps ) {
	const {
		profile,
		field,
		label,
		help,
		current,
		isDefault = false,
		busy,
		error,
		onUpload,
		onRemove,
		onWithdraw,
	} = props;
	const id = useId();
	const pending = profile.pending[ field ];
	const working = busy !== null;

	return (
		<div className="merchboard-profile-image">
			<h3 className="merchboard-profile-image__title">{ label }</h3>
			<div className="merchboard-profile-image__now">
				{ current === '' ? (
					<p className="merchboard-field__help">
						{ __( 'None yet.', 'merchboard' ) }
					</p>
				) : (
					<figure>
						<img
							className="merchboard-profile-image__img"
							src={ current }
							alt={ label }
						/>
						<figcaption className="merchboard-field__help">
							{ isDefault
								? __(
										'Now: the shop’s standard banner with your logo.',
										'merchboard'
									)
								: __( 'Now in the shop.', 'merchboard' ) }
						</figcaption>
					</figure>
				) }
				{ pending?.file !== null && pending !== undefined && (
					<figure>
						<ProposedImage
							proposal={ pending }
							alt={ __( 'Proposed image', 'merchboard' ) }
						/>
						<figcaption className="merchboard-field__help">
							{ __( 'Proposed', 'merchboard' ) }
						</figcaption>
					</figure>
				) }
			</div>
			<ProposalNote
				profile={ profile }
				field={ field }
				label={ label }
				busy={ busy === `withdraw:${ field }` }
				onWithdraw={ onWithdraw }
			/>
			{ error !== null && (
				<p className="merchboard-field__error" role="alert">
					{ error }
				</p>
			) }
			<p className="merchboard-field__help">{ help }</p>
			<div className="merchboard-alerts__actions">
				<input
					id={ `${ id }-file` }
					type="file"
					className="merchboard-visually-hidden"
					accept={ IMAGE_ACCEPT }
					disabled={ working }
					onChange={ ( event ) => {
						const file = event.target.files?.[ 0 ];
						event.target.value = '';

						if ( file !== undefined ) {
							onUpload( field, file );
						}
					} }
				/>
				<label
					className="merchboard-button merchboard-button--secondary merchboard-button--sm"
					htmlFor={ `${ id }-file` }
					aria-busy={ busy === `upload:${ field }` }
					aria-disabled={ working }
				>
					{ busy === `upload:${ field }`
						? __( 'Uploading…', 'merchboard' )
						: __( 'Upload new', 'merchboard' ) }
				</label>
				{ current !== '' && ! isDefault && pending === undefined && (
					<Button
						variant="ghost"
						size="sm"
						disabled={ working }
						isBusy={ busy === `remove:${ field }` }
						onClick={ () => onRemove( field ) }
					>
						{ __( 'Remove', 'merchboard' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
