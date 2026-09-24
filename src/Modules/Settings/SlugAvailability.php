<?php
/**
 * Dashboard slug conflict check.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a well-formed dashboard slug is already used by the site's own
 * URLs, which the dashboard route would otherwise shadow: any post, page or
 * product (published, draft, pending, private or scheduled) with that slug,
 * and the rewrite bases of public post types, archives and taxonomies
 * (e.g. WooCommerce's `product-category`) and of categories and tags.
 *
 * Settings::valid_slug() already refuses the fixed WordPress and WooCommerce
 * paths (wp-admin, wp-json, shop, my-account, …).
 */
final class SlugAvailability {

	/**
	 * Whether the slug is free.
	 *
	 * @param string $slug Well-formed slug.
	 */
	public function is_available( string $slug ): bool {
		return ! in_array( $slug, $this->rewrite_bases(), true ) && ! $this->used_by_content( $slug );
	}

	/**
	 * Whether a post of any type uses the slug.
	 *
	 * @param string $slug Slug.
	 */
	private function used_by_content( string $slug ): bool {
		$ids = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => array_values( get_post_types() ),
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		return array() !== $ids;
	}

	/**
	 * First path segments of the site's public rewrite bases.
	 *
	 * @return array<int, string>
	 */
	private function rewrite_bases(): array {
		$bases = array( (string) get_option( 'category_base' ), (string) get_option( 'tag_base' ) );

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( is_array( $type->rewrite ) && isset( $type->rewrite['slug'] ) ) {
				$bases[] = (string) $type->rewrite['slug'];
			}

			if ( is_string( $type->has_archive ) ) {
				$bases[] = $type->has_archive;
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( is_array( $taxonomy->rewrite ) && isset( $taxonomy->rewrite['slug'] ) ) {
				$bases[] = (string) $taxonomy->rewrite['slug'];
			}
		}

		$first = array();

		foreach ( $bases as $base ) {
			$segment = strtolower( explode( '/', trim( $base, '/' ) )[0] );

			if ( '' !== $segment ) {
				$first[] = $segment;
			}
		}

		return $first;
	}
}
