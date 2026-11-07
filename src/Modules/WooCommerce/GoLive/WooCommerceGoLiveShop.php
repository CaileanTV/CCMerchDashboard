<?php
/**
 * Going live in the WooCommerce shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\GoLive;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\GoLive\GoLiveException;
use MerchBoard\Modules\Creators\GoLive\GoLiveShop;
use MerchBoard\Modules\Creators\Signup\FrontGroup;
use MerchBoard\Modules\WooCommerce\CreatorCategoryException;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorPricingSettings;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\PricingException;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-093: the plugin creates the creator's `product_cat` under the parent
 * category (WordPress makes the slug) and maps it with the existing
 * service, so the reconciliation (ADR-033) runs as for a manual mapping. A
 * term with the same name under the parent is used only when it holds no
 * products and no other creator has it, so a new creator never gets old
 * products (review 32.7). The markup is set through the pricing settings
 * (audited), when the administrator may manage creator pricing; a markup
 * that is not set does not undo the category.
 *
 * ADR-097: before the term is mapped, the banner plugin's
 * `ccm_front_group` is written on it (a new or a reused term) and read back;
 * when it is not there, the term is not mapped, so "Try again" reuses it.
 * Nothing is written on a category the creator has already.
 */
final class WooCommerceGoLiveShop implements GoLiveShop {

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryService    $categories The mappings (ADR-033).
	 * @param CreatorCategoryRepository $mappings   Mappings (read).
	 * @param CreatorPricingSettings    $pricing    The creator's markup.
	 */
	public function __construct( private CreatorCategoryService $categories, private CreatorCategoryRepository $mappings, private CreatorPricingSettings $pricing ) {
	}

	/**
	 * Whether product categories are there.
	 */
	public function available(): bool {
		return taxonomy_exists( 'product_cat' );
	}

	/**
	 * Refuses a markup that is not a percentage from 0 to 100.
	 *
	 * @param string $percent Percentage, e.g. "20" or "17,5".
	 * @throws GoLiveException When it is invalid.
	 */
	public function check_markup( string $percent ): void {
		try {
			$markup = MarkupBps::from_percent( str_replace( ',', '.', $percent ) );
		} catch ( InvalidArgumentException ) {
			throw new GoLiveException( GoLiveException::MARKUP );
		}

		if ( $markup->bps > 10000 ) {
			throw new GoLiveException( GoLiveException::MARKUP );
		}
	}

	/**
	 * Whether the creator has a product category.
	 *
	 * @param int $creator_id Creator.
	 */
	public function has_category( int $creator_id ): bool {
		try {
			return array() !== $this->mappings->for_creator( $creator_id );
		} catch ( DatabaseException ) {
			return false;
		}
	}

	/**
	 * Creates and maps the category, then sets the markup.
	 *
	 * @param int    $creator_id Creator (live already).
	 * @param string $name       Category name.
	 * @param int    $parent_id  Parent `product_cat`.
	 * @param string $percent    Markup percentage.
	 * @param string $front_group Front-page group of the new category.
	 * @return bool Whether the markup was set.
	 * @throws GoLiveException When the category cannot be made or mapped.
	 */
	public function provision( int $creator_id, string $name, int $parent_id, string $percent, string $front_group ): bool {
		if ( ! $this->has_category( $creator_id ) ) {
			$term_id = $this->term( $name, $parent_id );
			self::set_front_group( $term_id, $front_group );
			$this->map( $creator_id, $term_id );
		}

		if ( ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			return false;
		}

		try {
			$this->pricing->set_markup( $creator_id, str_replace( ',', '.', $percent ) );
		} catch ( PricingException | DatabaseException ) {
			return false;
		}

		return true;
	}

	/**
	 * The category's term: a new one under the parent, or the existing one
	 * with the same name there when it holds no products.
	 *
	 * @param string $name      Name.
	 * @param int    $parent_id Parent.
	 * @throws GoLiveException When it cannot be made.
	 */
	private function term( string $name, int $parent_id ): int {
		$term = wp_insert_term( $name, 'product_cat', array( 'parent' => max( 0, $parent_id ) ) );

		if ( is_array( $term ) ) {
			return (int) $term['term_id'];
		}

		$existing = 'term_exists' === $term->get_error_code() ? $term->get_error_data( 'term_exists' ) : null;

		if ( is_numeric( $existing ) && array() === get_objects_in_term( (int) $existing, 'product_cat' ) ) {
			return (int) $existing;
		}

		throw new GoLiveException( GoLiveException::CATEGORY_FAILED );
	}

	/**
	 * Writes the front-page group on the term and checks it.
	 *
	 * @param int    $term_id Term.
	 * @param string $group   FrontGroup value.
	 * @throws GoLiveException When it is not a value or not stored.
	 */
	private static function set_front_group( int $term_id, string $group ): void {
		if ( ! FrontGroup::is_valid( $group ) ) {
			throw new GoLiveException( GoLiveException::FRONT_GROUP );
		}

		update_term_meta( $term_id, FrontGroup::TERM_META, $group );
		wp_cache_delete( $term_id, 'term_meta' );

		if ( get_term_meta( $term_id, FrontGroup::TERM_META, true ) !== $group ) {
			throw new GoLiveException( GoLiveException::CATEGORY_FAILED );
		}
	}

	/**
	 * Maps the term to the creator.
	 *
	 * @param int $creator_id Creator.
	 * @param int $term_id    Term.
	 * @throws GoLiveException When it cannot be mapped (e.g. another creator has it).
	 */
	private function map( int $creator_id, int $term_id ): void {
		try {
			$this->categories->link( $creator_id, $term_id );
		} catch ( CreatorCategoryException | DatabaseException ) {
			throw new GoLiveException( GoLiveException::CATEGORY_FAILED );
		}
	}
}
