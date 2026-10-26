<?php
/**
 * The creator's product category for category proposals.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Creators\Proposals\CategoryProfile;
use MerchBoard\Modules\Creators\Proposals\CategoryProfileTarget;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalException;
use MerchBoard\Modules\Creators\Proposals\ProposalField;
use MerchBoard\Modules\Creators\Proposals\SocialPlatforms;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the creator's product category (phase 28, ADR-077): the
 * category mapped to the creator (ADR-033; the first one), its name,
 * description and image (`thumbnail_id`), and the term meta of the banner
 * plugin CCMerch Kategoribannere: `ccm_banner_id`, `ccm_banner_mobile_id`,
 * `ccm_banner_link` (with `ccm_banner_new_tab`) and `ccm_social_{platform}`.
 *
 * Writes happen only for an approved proposal (CategoryProposals). Term meta
 * is written first, then the term with wp_update_term() (its slug is never
 * changed), so the banner plugin's own hook on `edited_product_cat` makes or
 * removes its default banner from the new values.
 */
final class WooCommerceCategoryProfile implements CategoryProfileTarget {

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryRepository $categories Creator ↔ category mappings.
	 */
	public function __construct( private CreatorCategoryRepository $categories ) {
	}

	/**
	 * Whether CCMerch Kategoribannere is active.
	 */
	public function has_banner_plugin(): bool {
		return function_exists( 'ccmkb_category_banner' );
	}

	/**
	 * The creator's category now, or null when it has none.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the mapping cannot be read.
	 */
	public function current( int $creator_id ): ?CategoryProfile {
		$term = $this->term( $creator_id );

		if ( null === $term ) {
			return null;
		}

		$plugin  = $this->has_banner_plugin();
		$image   = self::image_id( $term->term_id, 'thumbnail_id' );
		$banner  = $plugin ? self::image_id( $term->term_id, 'ccm_banner_id' ) : 0;
		$mobile  = $plugin ? self::image_id( $term->term_id, 'ccm_banner_mobile_id' ) : 0;
		$socials = array();

		foreach ( SocialPlatforms::PLATFORMS as $platform ) {
			$socials[ $platform ] = $plugin ? (string) get_term_meta( $term->term_id, 'ccm_social_' . $platform, true ) : '';
		}

		$edit = get_edit_term_link( $term->term_id, CreatorCategoryService::TAXONOMY, 'product' );

		return new CategoryProfile(
			$term->term_id,
			html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			trim( html_entity_decode( wp_strip_all_tags( $term->description ), ENT_QUOTES, 'UTF-8' ) ),
			$image,
			self::url( $image, 'medium' ),
			$plugin,
			$banner,
			0 < $banner ? self::url( $banner, 'large' ) : ( $plugin ? self::default_banner( $term ) : '' ),
			$mobile,
			self::url( $mobile, 'large' ),
			$plugin ? (string) get_term_meta( $term->term_id, 'ccm_banner_link', true ) : '',
			$socials,
			is_string( $edit ) ? $edit : ''
		);
	}

	/**
	 * Writes an approved value to the creator's category.
	 *
	 * @param int           $creator_id Creator.
	 * @param ProposalField $field      Field.
	 * @param string        $value      Text, or an image's attachment id ('' removes it).
	 * @return int The image it replaced, 0 for none or a text field.
	 * @throws CategoryProposalException When the creator has no category or the banner plugin is missing.
	 * @throws DatabaseException When it cannot be written.
	 */
	public function apply( int $creator_id, ProposalField $field, string $value ): int {
		$term = $this->term( $creator_id ) ?? throw new CategoryProposalException( CategoryProposalException::NO_CATEGORY );

		if ( $field->needs_banner_plugin() && ! $this->has_banner_plugin() ) {
			throw new CategoryProposalException( CategoryProposalException::UNAVAILABLE );
		}

		$replaced = 0;
		$args     = array( 'slug' => $term->slug );
		$platform = $field->platform();

		// wp_update_term() unslashes its input, so a "\" in the text would be lost.
		if ( ProposalField::DisplayName === $field ) {
			$args['name'] = wp_slash( $value );
		} elseif ( ProposalField::Description === $field ) {
			$args['description'] = wp_slash( $value );
		} elseif ( null !== $platform ) {
			self::write_meta( $term->term_id, 'ccm_social_' . $platform, $value );
		} elseif ( ProposalField::BannerLink === $field ) {
			self::write_meta( $term->term_id, 'ccm_banner_link', $value );
			// The creator's link leads off the shop: always a new tab.
			self::write_meta( $term->term_id, 'ccm_banner_new_tab', '' === $value ? '' : '1' );
		} else {
			$key = match ( $field ) {
				ProposalField::Image        => 'thumbnail_id',
				ProposalField::BannerMobile => 'ccm_banner_mobile_id',
				default                     => 'ccm_banner_id',
			};
			$replaced = self::image_id( $term->term_id, $key );
			self::write_meta( $term->term_id, $key, $value );
		}

		// The term itself, also when only meta changed: the banner plugin reacts to `edited_product_cat`.
		$updated = wp_update_term( $term->term_id, CreatorCategoryService::TAXONOMY, $args );

		if ( is_wp_error( $updated ) ) {
			throw new DatabaseException( 'Could not update the product category.' );
		}

		return $replaced;
	}

	/**
	 * The creator's category term, or null.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the mapping cannot be read.
	 */
	private function term( int $creator_id ): ?WP_Term {
		foreach ( $this->categories->for_creator( $creator_id ) as $mapping ) {
			$term = get_term( $mapping->term_id, CreatorCategoryService::TAXONOMY );

			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Writes one term meta value ('' deletes it) and checks it.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key.
	 * @param string $value   Value.
	 * @throws DatabaseException When it was not stored.
	 */
	private static function write_meta( int $term_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, $value );
		}

		wp_cache_delete( $term_id, 'term_meta' );

		if ( (string) get_term_meta( $term_id, $key, true ) !== $value ) {
			throw new DatabaseException( 'Could not update the product category.' );
		}
	}

	/**
	 * An image attachment id stored in term meta, or 0.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key.
	 */
	private static function image_id( int $term_id, string $key ): int {
		$id = (int) get_term_meta( $term_id, $key, true );

		return $id > 0 && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * An image's URL in a size, or ''.
	 *
	 * @param int    $attachment_id Attachment id (0: none).
	 * @param string $size          Image size.
	 */
	private static function url( int $attachment_id, string $size ): string {
		$url = $attachment_id > 0 ? wp_get_attachment_image_url( $attachment_id, $size ) : false;

		return is_string( $url ) ? $url : '';
	}

	/**
	 * The banner plugin's default banner of a category, or ''.
	 *
	 * @param WP_Term $term Term.
	 */
	private static function default_banner( WP_Term $term ): string {
		if ( ! function_exists( 'ccmkb_banner_for_term' ) ) {
			return '';
		}

		$banner = ccmkb_banner_for_term( $term );

		return is_array( $banner ) && is_string( $banner['url'] ?? null ) ? $banner['url'] : '';
	}
}
