<?php
/**
 * A creator's product category as it is now.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

defined( 'ABSPATH' ) || exit;

/**
 * The current values of the creator's product category (ADR-077): name and
 * description (plain text), the category image, and the banner plugin's
 * banner (own, or the plugin's default banner), mobile banner, link and
 * social links. Images are attachment ids (0: none) with a URL to show.
 */
final class CategoryProfile {

	/**
	 * Constructor.
	 *
	 * @param int                   $term_id           `product_cat` term id.
	 * @param string                $name              Category name.
	 * @param string                $description       Description (plain text).
	 * @param int                   $image_id          Category image (0: none).
	 * @param string                $image_url         Its URL ('' without).
	 * @param bool                  $banner_plugin     Whether CCMerch Kategoribannere is active.
	 * @param int                   $banner_id         Own banner (0: none).
	 * @param string                $banner_url        Own banner's URL, else the default banner's ('' without).
	 * @param int                   $banner_mobile_id  Mobile banner (0: none).
	 * @param string                $banner_mobile_url Its URL ('' without).
	 * @param string                $banner_link       Link on the banner ('' without).
	 * @param array<string, string> $socials           Social links by platform ('' when not set).
	 * @param string                $edit_url          The category's edit screen in WooCommerce.
	 */
	public function __construct(
		public readonly int $term_id,
		public readonly string $name,
		public readonly string $description,
		public readonly int $image_id,
		public readonly string $image_url,
		public readonly bool $banner_plugin,
		public readonly int $banner_id,
		public readonly string $banner_url,
		public readonly int $banner_mobile_id,
		public readonly string $banner_mobile_url,
		public readonly string $banner_link,
		public readonly array $socials,
		public readonly string $edit_url
	) {
	}

	/**
	 * Whether the banner shown is the banner plugin's default banner.
	 */
	public function default_banner(): bool {
		return 0 === $this->banner_id && '' !== $this->banner_url;
	}

	/**
	 * The current value of a field to compare a proposal with: the text, or
	 * an image's attachment id ('' when there is none). The name is the
	 * creator's, not the category's.
	 *
	 * @param ProposalField $field Field.
	 */
	public function value( ProposalField $field ): string {
		$platform = $field->platform();

		if ( null !== $platform ) {
			return $this->socials[ $platform ] ?? '';
		}

		return match ( $field ) {
			ProposalField::DisplayName  => $this->name,
			ProposalField::Description  => $this->description,
			ProposalField::Image        => 0 < $this->image_id ? (string) $this->image_id : '',
			ProposalField::Banner       => 0 < $this->banner_id ? (string) $this->banner_id : '',
			ProposalField::BannerMobile => 0 < $this->banner_mobile_id ? (string) $this->banner_mobile_id : '',
			ProposalField::BannerLink   => $this->banner_link,
			default                     => '',
		};
	}
}
