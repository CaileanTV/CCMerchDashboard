<?php
/**
 * Plain text for the creator dashboard's REST responses.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Names from WooCommerce (products, order numbers, status labels) as plain
 * text: tags removed (script and style with their content), entities decoded
 * and trimmed. The client renders every value as text.
 */
final class PlainText {

	/**
	 * The value as plain text.
	 *
	 * @param string $value Value.
	 */
	public static function of( string $value ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
