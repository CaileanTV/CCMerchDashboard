<?php
/**
 * Creator profile.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

defined( 'ABSPATH' ) || exit;

/**
 * The creator-editable profile: display name (creators table), bio and social
 * links (creator meta). Immutable.
 */
final class CreatorProfile {

	/**
	 * Constructor.
	 *
	 * @param string                $display_name Display name.
	 * @param string                $bio          Plain-text bio ('' when not set).
	 * @param array<string, string> $social_links Every platform of SocialLinks::PLATFORMS.
	 */
	public function __construct(
		public readonly string $display_name,
		public readonly string $bio,
		public readonly array $social_links
	) {
	}

	/**
	 * The REST representation (docs/API.md). Nothing else about the creator or
	 * the WordPress user is exposed.
	 *
	 * @return array{display_name: string, bio: string, social_links: array<string, string>}
	 */
	public function to_array(): array {
		return array(
			'display_name' => $this->display_name,
			'bio'          => $this->bio,
			'social_links' => $this->social_links,
		);
	}
}
