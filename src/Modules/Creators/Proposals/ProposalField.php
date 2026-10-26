<?php
/**
 * Fields a creator can propose for their product category.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

defined( 'ABSPATH' ) || exit;

/**
 * What a creator can propose (phase 28, ADR-077). Every change waits for an
 * administrator: the name (also the category's name), the category's
 * description and image, and the banner plugin's banner, mobile banner,
 * link and social links (CCMerch Kategoribannere).
 */
enum ProposalField: string {

	case DisplayName     = 'display_name';
	case Description     = 'description';
	case Image           = 'image';
	case Banner          = 'banner';
	case BannerMobile    = 'banner_mobile';
	case BannerLink      = 'banner_link';
	case SocialInstagram = 'social_instagram';
	case SocialTiktok    = 'social_tiktok';
	case SocialYoutube   = 'social_youtube';
	case SocialX         = 'social_x';
	case SocialFacebook  = 'social_facebook';
	case SocialKick      = 'social_kick';
	case SocialTwitch    = 'social_twitch';

	/**
	 * The field of a social platform.
	 *
	 * @param string $platform One of SocialPlatforms::PLATFORMS.
	 */
	public static function social( string $platform ): ?self {
		return self::tryFrom( 'social_' . $platform );
	}

	/**
	 * Whether the value is an uploaded image (or '' to remove it).
	 */
	public function is_file(): bool {
		return in_array( $this, array( self::Image, self::Banner, self::BannerMobile ), true );
	}

	/**
	 * The social platform, or null for other fields.
	 */
	public function platform(): ?string {
		return str_starts_with( $this->value, 'social_' ) ? substr( $this->value, 7 ) : null;
	}

	/**
	 * Whether approving it writes to the creator's product category (every
	 * field but the name, which is also kept without a category).
	 */
	public function needs_category(): bool {
		return self::DisplayName !== $this;
	}

	/**
	 * Whether the banner plugin keeps it (banner, mobile banner, link and
	 * social links).
	 */
	public function needs_banner_plugin(): bool {
		return in_array( $this, array( self::Banner, self::BannerMobile, self::BannerLink ), true ) || null !== $this->platform();
	}
}
