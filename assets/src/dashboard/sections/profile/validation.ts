/**
 * Client-side checks for the profile form. They mirror the backend rules for
 * quick feedback only; the server validates again and is authoritative (also
 * the social platforms' domains). Error codes are the same as the API's, so
 * server and client errors share one set of messages.
 */
import { sprintf, __ } from '@wordpress/i18n';

import { PROFILE_LIMITS, SOCIAL_PLATFORMS } from '../../types/creator';
import type { SocialLinks } from '../../types/creator';

export type FieldErrors = Readonly< Record< string, string > >;

/** The text values of the profile form. */
export interface ProfileValues {
	readonly displayName: string;
	readonly description: string;
	readonly bannerLink: string;
	readonly socialLinks: SocialLinks;
}

/**
 * A valid https URL of at most PROFILE_LIMITS.url characters (or empty).
 *
 * @param value Candidate.
 */
export function isValidLink( value: string ): boolean {
	const url = value.trim();

	if ( url === '' ) {
		return true;
	}

	if ( url.length > PROFILE_LIMITS.url || /[\s<>"'\\]/.test( url ) ) {
		return false;
	}

	try {
		const parsed = new URL( url );

		return (
			parsed.protocol === 'https:' &&
			parsed.hostname !== '' &&
			parsed.username === '' &&
			parsed.password === ''
		);
	} catch {
		return false;
	}
}

/**
 * Field → error code for the whole form.
 *
 * @param values Form values.
 */
export function validateProfile( values: ProfileValues ): FieldErrors {
	const errors: Record< string, string > = {};
	const name = values.displayName.trim();

	if ( name === '' ) {
		errors.display_name = 'required';
	} else if ( name.length > PROFILE_LIMITS.displayName ) {
		errors.display_name = 'too_long';
	}

	if ( values.description.trim().length > PROFILE_LIMITS.description ) {
		errors.description = 'too_long';
	}

	if (
		values.bannerLink.trim().length > PROFILE_LIMITS.link ||
		/^http:\/\//i.test( values.bannerLink.trim() )
	) {
		errors.banner_link = 'invalid_url';
	}

	for ( const platform of SOCIAL_PLATFORMS ) {
		const value = values.socialLinks[ platform ].trim();

		if ( value.length > PROFILE_LIMITS.url || /[\s<>"'\\]/.test( value ) ) {
			errors[ `social_links.${ platform }` ] = 'invalid_url';
		}
	}

	return errors;
}

/**
 * The message for an error code; unknown codes get a generic message.
 *
 * @param code  Error code.
 * @param limit Character limit of the field, for `too_long`.
 */
export function errorMessage( code: string, limit?: number ): string {
	switch ( code ) {
		case 'required':
			return __( 'This field is required.', 'merchboard' );
		case 'too_long':
			return limit === undefined
				? __( 'This text is too long.', 'merchboard' )
				: sprintf(
						/* translators: %d: maximum number of characters. */
						__( 'Use at most %d characters.', 'merchboard' ),
						limit
					);
		case 'invalid_url':
			return __(
				'Enter a full https:// address on the platform, or just the user name.',
				'merchboard'
			);
		case 'unavailable':
			return __( 'This cannot be changed on this shop.', 'merchboard' );
		case 'too_large':
			return __( 'The image is too large (at most 5 MB).', 'merchboard' );
		case 'invalid_type':
			return __( 'Use a JPG, PNG or WebP image.', 'merchboard' );
		case 'unreadable':
		case 'no_file':
			return __( 'The image could not be read.', 'merchboard' );
		case 'too_small':
			return __( 'The image is too small.', 'merchboard' );
		case 'too_big':
			return __(
				'The image is too big (at most 4096 pixels wide and high).',
				'merchboard'
			);
		case 'not_square':
			return __( 'The logo must be square.', 'merchboard' );
		default:
			return __( 'This value is not valid.', 'merchboard' );
	}
}
