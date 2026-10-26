/**
 * Creator-scope endpoints: `/me` and the profile with its category proposals
 * (`/me/profile`, ADR-077). The creator is always the one the server resolves
 * for the logged-in user; no function here takes or sends a creator id.
 */
import { IMAGE_FIELDS, SOCIAL_PLATFORMS } from '../types/creator';
import type {
	CategoryNow,
	ImageField,
	Me,
	PendingProposal,
	Profile,
	ProposalField,
	ProposedImage,
	RejectedProposal,
	SocialLinks,
	SocialPlatform,
} from '../types/creator';
import { request, requestFile } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

/**
 * Validates `GET /me`: `{ display_name, status }`.
 *
 * @param body Response body.
 */
export function parseMe( body: unknown ): Me | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const { display_name: displayName, status } = body;

	if (
		typeof displayName !== 'string' ||
		displayName === '' ||
		( status !== 'active' && status !== 'inactive' )
	) {
		return null;
	}

	return { displayName, status };
}

const FIELDS: readonly ProposalField[] = [
	'display_name',
	'description',
	...IMAGE_FIELDS,
	'banner_link',
	...SOCIAL_PLATFORMS.map(
		( platform ): ProposalField => `social_${ platform }`
	),
];

function isField( value: unknown ): value is ProposalField {
	return (
		typeof value === 'string' &&
		( FIELDS as readonly string[] ).includes( value )
	);
}

/**
 * A link of the category: the value when it is an https URL, '' for any
 * other string, and null for a non-string. Links entered outside Merch
 * Dashboard (e.g. `http://` in the banner plugin's own fields) are then not
 * shown, instead of making the whole profile invalid.
 *
 * @param value Value from the server.
 */
function categoryLink( value: unknown ): string | null {
	if ( typeof value !== 'string' ) {
		return null;
	}

	return value.startsWith( 'https://' ) ? value : '';
}

function parseSocialLinks( value: unknown ): SocialLinks | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const links: Partial< Record< SocialPlatform, string > > = {};

	for ( const platform of SOCIAL_PLATFORMS ) {
		const url = categoryLink( value[ platform ] );

		if ( url === null ) {
			return null;
		}

		links[ platform ] = url;
	}

	return links as SocialLinks;
}

function parseCategory( value: unknown ): CategoryNow | null | undefined {
	if ( value === null ) {
		return null;
	}

	if ( ! isRecord( value ) ) {
		return undefined;
	}

	const socialLinks = parseSocialLinks( value.social_links );
	const bannerLink = categoryLink( value.banner_link );

	if (
		typeof value.name !== 'string' ||
		typeof value.description !== 'string' ||
		bannerLink === null ||
		typeof value.banner_default !== 'boolean' ||
		socialLinks === null
	) {
		return undefined;
	}

	const url = ( key: string ): string | null => {
		const candidate = value[ key ];

		return typeof candidate === 'string' &&
			( candidate === '' || /^https?:\/\//.test( candidate ) )
			? candidate
			: null;
	};
	const imageUrl = url( 'image_url' );
	const bannerUrl = url( 'banner_url' );
	const bannerMobileUrl = url( 'banner_mobile_url' );

	if ( imageUrl === null || bannerUrl === null || bannerMobileUrl === null ) {
		return undefined;
	}

	return {
		name: value.name,
		description: value.description,
		imageUrl,
		bannerUrl,
		bannerDefault: value.banner_default,
		bannerMobileUrl,
		bannerLink,
		socialLinks,
	};
}

function parseImage( value: unknown ): ProposedImage | null | undefined {
	if ( value === null ) {
		return null;
	}

	if (
		! isRecord( value ) ||
		typeof value.mime !== 'string' ||
		! /^image\/(png|jpeg|webp)$/.test( value.mime ) ||
		typeof value.width !== 'number' ||
		typeof value.height !== 'number'
	) {
		return undefined;
	}

	return { mime: value.mime, width: value.width, height: value.height };
}

function parsePending( value: unknown ): PendingProposal | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const file = parseImage( value.file );

	if (
		typeof value.id !== 'number' ||
		typeof value.value !== 'string' ||
		file === undefined ||
		typeof value.remove !== 'boolean' ||
		typeof value.created_at !== 'string'
	) {
		return null;
	}

	return {
		id: value.id,
		value: value.value,
		file,
		remove: value.remove,
		createdAt: value.created_at,
	};
}

function parseRejected( value: unknown ): RejectedProposal | null {
	if (
		! isRecord( value ) ||
		typeof value.id !== 'number' ||
		( value.reason !== null && typeof value.reason !== 'string' ) ||
		( value.decided_at !== null && typeof value.decided_at !== 'string' )
	) {
		return null;
	}

	return {
		id: value.id,
		reason: value.reason,
		decidedAt: value.decided_at,
	};
}

function parseByField< T >(
	value: unknown,
	parse: ( item: unknown ) => T | null
): Partial< Record< ProposalField, T > > | null {
	// An empty PHP array arrives as [].
	if ( Array.isArray( value ) && value.length === 0 ) {
		return {};
	}

	if ( ! isRecord( value ) ) {
		return null;
	}

	const items: Partial< Record< ProposalField, T > > = {};

	for ( const [ key, item ] of Object.entries( value ) ) {
		const parsed = parse( item );

		if ( ! isField( key ) || parsed === null ) {
			return null;
		}

		items[ key ] = parsed;
	}

	return items;
}

/**
 * Validates `/me/profile`: `{ display_name, fields, category, pending, rejected }`.
 *
 * @param body Response body.
 */
export function parseProfile( body: unknown ): Profile | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const { display_name: displayName, fields } = body;
	const category = parseCategory( body.category );
	const pending = parseByField( body.pending, parsePending );
	const rejected = parseByField( body.rejected, parseRejected );

	if (
		typeof displayName !== 'string' ||
		displayName === '' ||
		! Array.isArray( fields ) ||
		! fields.every( isField ) ||
		category === undefined ||
		pending === null ||
		rejected === null
	) {
		return null;
	}

	return { displayName, fields, category, pending, rejected };
}

export function getMe( signal?: AbortSignal ): Promise< ApiResult< Me > > {
	return request(
		{
			path: '/merchboard/v1/me',
			...( signal === undefined ? {} : { signal } ),
		},
		parseMe
	);
}

export function getProfile(
	signal?: AbortSignal
): Promise< ApiResult< Profile > > {
	return request(
		{
			path: '/merchboard/v1/me/profile',
			...( signal === undefined ? {} : { signal } ),
		},
		parseProfile
	);
}

/** Changes a creator proposes; only these are sent. */
export interface ProfileChanges {
	readonly displayName?: string;
	readonly description?: string;
	readonly bannerLink?: string;
	readonly socialLinks?: Partial< SocialLinks >;
	/** Images to remove from the category. */
	readonly remove?: readonly ImageField[];
}

/**
 * `PATCH /me/profile`: proposes the changed fields.
 *
 * @param changes Changed fields.
 */
export function updateProfile(
	changes: ProfileChanges
): Promise< ApiResult< Profile > > {
	const data: Record< string, unknown > = {};

	if ( changes.displayName !== undefined ) {
		data.display_name = changes.displayName;
	}

	if ( changes.description !== undefined ) {
		data.description = changes.description;
	}

	if ( changes.bannerLink !== undefined ) {
		data.banner_link = changes.bannerLink;
	}

	if ( changes.socialLinks !== undefined ) {
		data.social_links = { ...changes.socialLinks };
	}

	for ( const field of changes.remove ?? [] ) {
		data[ field ] = '';
	}

	return request(
		{ path: '/merchboard/v1/me/profile', method: 'PATCH', data },
		parseProfile
	);
}

/**
 * `POST /me/profile/files/{field}`: proposes an image.
 *
 * @param field Image field.
 * @param file  The image.
 */
export function uploadProfileImage(
	field: ImageField,
	file: File
): Promise< ApiResult< Profile > > {
	const body = new FormData();
	body.append( 'file', file );

	return request(
		{
			path: `/merchboard/v1/me/profile/files/${ field }`,
			method: 'POST',
			body,
		},
		parseProfile
	);
}

/**
 * `DELETE /me/profile/proposals/{field}`: withdraws a pending proposal.
 *
 * @param field Field.
 */
export function withdrawProposal(
	field: ProposalField
): Promise< ApiResult< Profile > > {
	return request(
		{
			path: `/merchboard/v1/me/profile/proposals/${ field }`,
			method: 'DELETE',
		},
		parseProfile
	);
}

/**
 * `GET /me/profile/files/{id}`: a proposed image waiting for approval.
 *
 * @param proposal Pending image proposal.
 * @param signal   Abort signal.
 */
export function getProposedImage(
	proposal: PendingProposal,
	signal?: AbortSignal
): Promise< ApiResult< Blob > > {
	return requestFile(
		{
			path: `/merchboard/v1/me/profile/files/${ String( proposal.id ) }`,
			...( signal === undefined ? {} : { signal } ),
		},
		proposal.file?.mime ?? ''
	);
}
