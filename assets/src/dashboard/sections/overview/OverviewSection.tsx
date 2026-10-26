import { __, sprintf } from '@wordpress/i18n';

import { getProfile } from '../../api/creator';
import { useCreator } from '../../app/creator-context';
import { Link } from '../../app/router';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { Skeleton } from '../../components/Skeleton';
import { useApi } from '../../hooks/useApi';
import { SOCIAL_PLATFORMS } from '../../types/creator';
import type { CreatorStatus, Profile } from '../../types/creator';
import { platformLabel } from '../profile/platforms';
import { isValidLink } from '../profile/validation';
import { SalesKpis } from './SalesKpis';

function statusLabel( status: CreatorStatus ): string {
	return status === 'active'
		? __( 'Active', 'merchboard' )
		: __( 'Inactive', 'merchboard' );
}

/**
 * The description and social links of the creator's category, as the shop
 * shows them now (`GET /me/profile`, ADR-077). Links are shown only when they
 * pass the same https check as the profile form, and open in a new tab
 * without giving the target page access to the dashboard.
 */
function ProfileSummary( { profile }: { readonly profile: Profile } ) {
	const description = profile.category?.description ?? '';
	const socialLinks = profile.category?.socialLinks;
	const links =
		socialLinks === undefined
			? []
			: SOCIAL_PLATFORMS.filter(
					( platform ) =>
						socialLinks[ platform ] !== '' &&
						isValidLink( socialLinks[ platform ] )
				);

	if (
		description === '' &&
		( socialLinks === undefined || links.length === 0 )
	) {
		return (
			<EmptyState
				title={ __( 'Your profile is still empty.', 'merchboard' ) }
				description={ __(
					'Add a description and links to your channels.',
					'merchboard'
				) }
				action={
					<Link
						to="profile"
						className="merchboard-button merchboard-button--secondary merchboard-button--md"
					>
						{ __( 'Edit profile', 'merchboard' ) }
					</Link>
				}
			/>
		);
	}

	return (
		<div className="merchboard-profile-summary">
			{ description !== '' && (
				<p className="merchboard-profile-summary__bio">
					{ description }
				</p>
			) }
			{ socialLinks !== undefined && links.length > 0 && (
				<ul className="merchboard-profile-summary__links">
					{ links.map( ( platform ) => (
						<li key={ platform }>
							<a
								href={ socialLinks[ platform ] }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ platformLabel( platform ) }
								<span className="merchboard-visually-hidden">
									{ __(
										'(opens in a new tab)',
										'merchboard'
									) }
								</span>
							</a>
						</li>
					) ) }
				</ul>
			) }
			<p>
				<Link to="profile">{ __( 'Edit profile', 'merchboard' ) }</Link>
			</p>
		</div>
	);
}

function CreatorInfo() {
	const { state, retry } = useApi( getProfile );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading your profile…', 'merchboard' ) }
				lines={ 3 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return <ProfileSummary profile={ state.data } />;
}

/**
 * Overview: welcome, creator info and key figures. The key figures come from
 * `GET /me/sales` with its default period (SalesKpis, ADR-040 pkt. 12); there
 * is no `/me/overview`.
 */
export function OverviewSection() {
	const { state } = useCreator();
	const name = state.status === 'success' ? state.data.displayName : '';
	const status = state.status === 'success' ? state.data.status : 'active';

	return (
		<div className="merchboard-overview">
			<Card
				title={ sprintf(
					/* translators: %s: creator display name. */
					__( 'Welcome, %s', 'merchboard' ),
					name
				) }
			>
				<p>
					{ __(
						'This is your creator dashboard. Here you will follow your merch and keep your profile up to date.',
						'merchboard'
					) }
				</p>
				<p className="merchboard-overview__status">
					{ __( 'Account status:', 'merchboard' ) }{ ' ' }
					<span
						className={ `merchboard-badge merchboard-badge--${ status }` }
					>
						{ statusLabel( status ) }
					</span>
				</p>
			</Card>

			<Card title={ __( 'Your profile', 'merchboard' ) }>
				<CreatorInfo />
			</Card>

			<section
				className="merchboard-overview__kpis"
				aria-labelledby="merchboard-kpi-heading"
			>
				<h2
					id="merchboard-kpi-heading"
					className="merchboard-card__title"
				>
					{ __( 'Key figures', 'merchboard' ) }
				</h2>
				<SalesKpis />
			</section>
		</div>
	);
}
