<?php
/**
 * Readable names of category proposal fields.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

defined( 'ABSPATH' ) || exit;

/**
 * Translated field names for the audit log and the administrator's page.
 */
final class ProposalLabels {

	/**
	 * The field's name.
	 *
	 * @param ProposalField $field Field.
	 */
	public static function field( ProposalField $field ): string {
		return match ( $field ) {
			ProposalField::DisplayName     => __( 'name', 'merchboard' ),
			ProposalField::Description     => __( 'description', 'merchboard' ),
			ProposalField::Image           => __( 'category image', 'merchboard' ),
			ProposalField::Banner          => __( 'banner', 'merchboard' ),
			ProposalField::BannerMobile    => __( 'mobile banner', 'merchboard' ),
			ProposalField::BannerLink      => __( 'link on the banner', 'merchboard' ),
			ProposalField::SocialInstagram => 'Instagram',
			ProposalField::SocialTiktok    => 'TikTok',
			ProposalField::SocialYoutube   => 'YouTube',
			ProposalField::SocialX         => 'X',
			ProposalField::SocialFacebook  => 'Facebook',
			ProposalField::SocialKick      => 'Kick',
			ProposalField::SocialTwitch    => 'Twitch',
		};
	}
}
