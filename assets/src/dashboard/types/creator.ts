/**
 * Creator data from the REST API (docs/API.md). The types mirror the backend
 * contract exactly; the API decides which creator this is.
 */

export type CreatorStatus = 'active' | 'inactive';

/** `GET /merchboard/v1/me`. */
export interface Me {
	readonly displayName: string;
	readonly status: CreatorStatus;
}

/** Social platforms, in display order (backend: SocialLinks::PLATFORMS). */
export const SOCIAL_PLATFORMS = [
	'website',
	'instagram',
	'tiktok',
	'youtube',
	'x',
	'facebook',
] as const;

export type SocialPlatform = ( typeof SOCIAL_PLATFORMS )[ number ];

/** Every platform; '' when not set, otherwise an https URL. */
export type SocialLinks = Readonly< Record< SocialPlatform, string > >;

/** `GET` / `PATCH /merchboard/v1/me/profile`. */
export interface Profile {
	readonly displayName: string;
	readonly bio: string;
	readonly socialLinks: SocialLinks;
}

/** Limits shared with the backend (CreatorRepository, CreatorProfileService, SocialLinks). */
export const PROFILE_LIMITS = {
	displayName: 191,
	bio: 1000,
	url: 200,
} as const;
