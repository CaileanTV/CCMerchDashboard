/**
 * Client-side checks for the profile form. They mirror the backend rules for
 * quick feedback only; the server validates again and is authoritative.
 * Error codes are the same as the API's (`required`, `invalid`, `too_long`,
 * `invalid_url`), so server and client errors share one set of messages.
 */
import { sprintf, __ } from '@wordpress/i18n';

import { PROFILE_LIMITS, SOCIAL_PLATFORMS } from '../../types/creator';
import type { Profile } from '../../types/creator';

export type FieldErrors = Readonly< Record< string, string > >;

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
export function validateProfile( values: Profile ): FieldErrors {
	const errors: Record< string, string > = {};
	const name = values.displayName.trim();

	if ( name === '' ) {
		errors.display_name = 'required';
	} else if ( name.length > PROFILE_LIMITS.displayName ) {
		errors.display_name = 'too_long';
	}

	if ( values.bio.trim().length > PROFILE_LIMITS.bio ) {
		errors.bio = 'too_long';
	}

	for ( const platform of SOCIAL_PLATFORMS ) {
		if ( ! isValidLink( values.socialLinks[ platform ] ) ) {
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
				'Enter a full address starting with https://',
				'merchboard'
			);
		default:
			return __( 'This value is not valid.', 'merchboard' );
	}
}
