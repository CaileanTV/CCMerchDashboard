<?php
/**
 * The business details that fill the placeholders of the YouTube chatbot's
 * privacy policy and terms.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Legal;

use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Where each placeholder comes from (ADR-071, step 8.5):
 *
 * - `[COMPANY]`: the company name (Settings → Company details).
 * - `[CVR]`: the CVR number of the settlement documents (Settings →
 *   Payouts, ADR-062 K09).
 * - `[ADDRESS]`: WooCommerce's store address (WooCommerce → Settings →
 *   General), as on the settlement documents.
 * - `[EMAIL]`: the contact e-mail (Settings → Company details).
 *
 * The values are read when a page is shown, so a changed detail needs no
 * new version of the texts.
 */
final class CompanyDetails {

	/**
	 * The value of each placeholder ('' when not registered).
	 *
	 * @return array<string, string>
	 */
	public static function values(): array {
		return array(
			'[COMPANY]' => Settings::company_name(),
			'[CVR]'     => Settings::company_cvr(),
			'[ADDRESS]' => self::address(),
			'[EMAIL]'   => Settings::company_email(),
		);
	}

	/**
	 * WooCommerce's store address on one line ('' when none is set).
	 */
	public static function address(): string {
		$country = strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' );
		$country = is_string( $country ) ? $country : '';
		$list    = function_exists( 'WC' ) ? WC()->countries : null;
		$names   = is_object( $list ) && method_exists( $list, 'get_countries' ) ? $list->get_countries() : array();
		$names   = is_array( $names ) ? $names : array();
		$lines   = array(
			(string) get_option( 'woocommerce_store_address', '' ),
			(string) get_option( 'woocommerce_store_address_2', '' ),
			trim( (string) get_option( 'woocommerce_store_postcode', '' ) . ' ' . (string) get_option( 'woocommerce_store_city', '' ) ),
			is_string( $names[ $country ] ?? null ) ? $names[ $country ] : $country,
		);

		return implode( ', ', array_filter( array_map( 'trim', $lines ), static fn( string $line ): bool => '' !== $line ) );
	}
}
