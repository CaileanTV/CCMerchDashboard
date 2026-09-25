import { __ } from '@wordpress/i18n';

import type { SocialPlatform } from '../../types/creator';

/**
 * Display name of a social platform (brand names are not translated).
 *
 * @param platform Platform.
 */
export function platformLabel( platform: SocialPlatform ): string {
	switch ( platform ) {
		case 'website':
			return __( 'Website', 'merchboard' );
		case 'instagram':
			return 'Instagram';
		case 'tiktok':
			return 'TikTok';
		case 'youtube':
			return 'YouTube';
		case 'x':
			return 'X';
		default:
			return 'Facebook';
	}
}
