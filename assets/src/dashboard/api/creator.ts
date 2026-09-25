/**
 * Creator-scope endpoints: `/me` and `/me/profile`. The creator is always the
 * one the server resolves for the logged-in user; no function here takes or
 * sends a creator id.
 */
import { SOCIAL_PLATFORMS } from '../types/creator';
import type {
	Me,
	Profile,
	SocialLinks,
	SocialPlatform,
} from '../types/creator';
import { request } from './client';
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

function parseSocialLinks( value: unknown ): SocialLinks | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const links: Partial< Record< SocialPlatform, string > > = {};

	for ( const platform of SOCIAL_PLATFORMS ) {
		const url = value[ platform ];

		if (
			typeof url !== 'string' ||
			( url !== '' && ! url.startsWith( 'https://' ) )
		) {
			return null;
		}

		links[ platform ] = url;
	}

	return links as SocialLinks;
}

/**
 * Validates `/me/profile`: `{ display_name, bio, social_links }`.
 *
 * @param body Response body.
 */
export function parseProfile( body: unknown ): Profile | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const { display_name: displayName, bio } = body;
	const socialLinks = parseSocialLinks( body.social_links );

	if (
		typeof displayName !== 'string' ||
		displayName === '' ||
		typeof bio !== 'string' ||
		socialLinks === null
	) {
		return null;
	}

	return { displayName, bio, socialLinks };
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

/** Fields a creator can change; only these are sent. */
export interface ProfileChanges {
	readonly displayName?: string;
	readonly bio?: string;
	readonly socialLinks?: Partial< SocialLinks >;
}

/**
 * `PATCH /me/profile` with the whitelisted fields only.
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

	if ( changes.bio !== undefined ) {
		data.bio = changes.bio;
	}

	if ( changes.socialLinks !== undefined ) {
		data.social_links = { ...changes.socialLinks };
	}

	return request(
		{ path: '/merchboard/v1/me/profile', method: 'PATCH', data },
		parseProfile
	);
}
