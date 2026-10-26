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

/**
 * Social platforms of the creator's category, in display order (backend:
 * SocialPlatforms::PLATFORMS, the banner plugin's seven).
 */
export const SOCIAL_PLATFORMS = [
	'instagram',
	'tiktok',
	'youtube',
	'x',
	'facebook',
	'kick',
	'twitch',
] as const;

export type SocialPlatform = ( typeof SOCIAL_PLATFORMS )[ number ];

/** Every platform; '' when not set, otherwise an https URL. */
export type SocialLinks = Readonly< Record< SocialPlatform, string > >;

/** The images a creator can propose (backend: ProposalField::is_file()). */
export const IMAGE_FIELDS = [ 'image', 'banner', 'banner_mobile' ] as const;

export type ImageField = ( typeof IMAGE_FIELDS )[ number ];

/** The text fields a creator can propose. */
export type TextField = 'display_name' | 'description' | 'banner_link';

/** Every field a creator can propose (backend: ProposalField). */
export type ProposalField =
	TextField | ImageField | `social_${ SocialPlatform }`;

/** The creator's product category as it is now. */
export interface CategoryNow {
	readonly name: string;
	readonly description: string;
	readonly imageUrl: string;
	/** The own banner, else the banner plugin's default banner ('' without). */
	readonly bannerUrl: string;
	readonly bannerDefault: boolean;
	readonly bannerMobileUrl: string;
	readonly bannerLink: string;
	readonly socialLinks: SocialLinks;
}

/** A proposed image: its type and size (the image itself is fetched). */
export interface ProposedImage {
	readonly mime: string;
	readonly width: number;
	readonly height: number;
}

/** A proposal waiting for an administrator. */
export interface PendingProposal {
	readonly id: number;
	/** The proposed text ('' for an image or a removal). */
	readonly value: string;
	readonly file: ProposedImage | null;
	/** Whether it removes the current value. */
	readonly remove: boolean;
	readonly createdAt: string;
}

/** The latest rejection of a field, with the administrator's note. */
export interface RejectedProposal {
	readonly id: number;
	readonly reason: string | null;
	readonly decidedAt: string | null;
}

/** `GET` / `PATCH /merchboard/v1/me/profile` (ADR-077). */
export interface Profile {
	readonly displayName: string;
	/** The fields that can be proposed on this site. */
	readonly fields: readonly ProposalField[];
	/** The category now, or null when the creator has none. */
	readonly category: CategoryNow | null;
	readonly pending: Readonly<
		Partial< Record< ProposalField, PendingProposal > >
	>;
	readonly rejected: Readonly<
		Partial< Record< ProposalField, RejectedProposal > >
	>;
}

/** Limits shared with the backend (CreatorRepository, CategoryProposals, SocialPlatforms, ProposalFiles). */
export const PROFILE_LIMITS = {
	displayName: 191,
	description: 1000,
	url: 200,
	link: 500,
	imageBytes: 5242880,
} as const;
