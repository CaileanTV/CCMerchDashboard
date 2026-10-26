<?php
/**
 * Stand-in for the banner plugin CCMerch Kategoribannere (phase 28,
 * ADR-077): the two functions Merch Dashboard calls, reading the same term
 * meta as the plugin. The default banner is a fixed URL per category.
 * Loaded only by the tests that need an active banner plugin.
 *
 * @package MerchBoard
 */

if ( ! function_exists( 'ccmkb_category_banner' ) ) {
	/**
	 * The category's own banner, or null.
	 *
	 * @param int $term_id Term id.
	 * @return array{id: int, mobile: int, link: string, new_tab: bool}|null
	 */
	function ccmkb_category_banner( $term_id ) {
		$id = (int) get_term_meta( $term_id, 'ccm_banner_id', true );

		return $id > 0 ? array(
			'id'      => $id,
			'mobile'  => (int) get_term_meta( $term_id, 'ccm_banner_mobile_id', true ),
			'link'    => (string) get_term_meta( $term_id, 'ccm_banner_link', true ),
			'new_tab' => '1' === get_term_meta( $term_id, 'ccm_banner_new_tab', true ),
		) : null;
	}

	/**
	 * The category's banner: its own, else the default banner.
	 *
	 * @param WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	function ccmkb_banner_for_term( $term ) {
		return ccmkb_category_banner( $term->term_id ) ?? array(
			'id'      => 0,
			'url'     => 'https://shop.example/wp-content/uploads/ccm-bannere/Banner_' . $term->slug . '.png',
			'mobile'  => 0,
			'link'    => '',
			'new_tab' => false,
		);
	}
}
