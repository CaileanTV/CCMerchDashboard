<?php
/**
 * The customer's optional username for alerts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Checkout;

use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The rules of the username a customer can give in the checkout (ADR-065
 * pkt. 9, step 25.9.1): plain text on one line, at most MAX_LENGTH
 * characters. The same cleaning applies when the field is saved and when an
 * order's username is read, so the overlay and the chat never get tags,
 * control characters or line breaks.
 */
final class CheckoutUsername {

	/**
	 * Longest username (Twitch and Kick allow 25, YouTube handles 30,
	 * display names up to about 50).
	 */
	public const MAX_LENGTH = 50;

	/**
	 * The field's id in the block checkout (WooCommerce stores the value as
	 * order meta `_wc_other/` + this id).
	 */
	public const BLOCK_FIELD_ID = 'merchboard/alert-username';

	/**
	 * The order meta of the block checkout field.
	 */
	public const BLOCK_META = '_wc_other/' . self::BLOCK_FIELD_ID;

	/**
	 * The id of the checkbox that shows the field in the block checkout.
	 */
	public const BLOCK_SHOW_FIELD_ID = 'merchboard/alert-username-show';

	/**
	 * The order meta of the block checkout's checkbox ('1' when ticked).
	 */
	public const BLOCK_SHOW_META = '_wc_other/' . self::BLOCK_SHOW_FIELD_ID;

	/**
	 * The field's name in the classic checkout.
	 */
	public const CLASSIC_FIELD = 'merchboard_alert_username';

	/**
	 * The name of the checkbox that shows the field in the classic checkout.
	 */
	public const CLASSIC_SHOW_FIELD = 'merchboard_alert_username_show';

	/**
	 * Plain text on one line: tags and control characters removed, runs of
	 * white space collapsed, trimmed; '' for anything but a scalar.
	 *
	 * @param mixed $value Value.
	 */
	public static function clean( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = wp_strip_all_tags( (string) $value );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Whether a cleaned username is longer than allowed.
	 *
	 * @param string $username Cleaned username.
	 */
	public static function too_long( string $username ): bool {
		return mb_strlen( $username ) > self::MAX_LENGTH;
	}

	/**
	 * The field's label: the administrator's, else the default.
	 */
	public static function label(): string {
		$label = Settings::checkout_username_label();

		return '' !== $label ? $label : self::default_label();
	}

	/**
	 * The checkbox text that shows the field (the setting "help text"): the
	 * administrator's, else the default.
	 */
	public static function help(): string {
		$help = Settings::checkout_username_help();

		return '' !== $help ? $help : self::default_help();
	}

	/**
	 * The default label.
	 */
	public static function default_label(): string {
		return __( 'Stream username', 'merchboard' );
	}

	/**
	 * The default checkbox text: the name is shown publicly.
	 */
	public static function default_help(): string {
		return __( 'Show my username in the creator\'s livestream (overlay and chat). It is shown publicly.', 'merchboard' );
	}

	/**
	 * The message when the username is too long.
	 */
	public static function too_long_message(): string {
		/* translators: %d: the most characters of a username. */
		return sprintf( __( 'The username can be at most %d characters.', 'merchboard' ), self::MAX_LENGTH );
	}
}
