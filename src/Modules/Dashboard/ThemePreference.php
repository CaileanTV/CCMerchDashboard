<?php
/**
 * The user's dashboard theme preference.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

use InvalidArgumentException;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A personal UI preference of the WordPress user (ADR-044), stored in user
 * meta, not on the creator record. Only the six theme values of ADR-043 are
 * valid. No value means no explicit choice: the dashboard then follows the
 * system's `prefers-color-scheme` in CSS, and nothing about the system choice
 * is ever stored. No audit: it has no security or business meaning.
 */
final class ThemePreference {

	/**
	 * User meta key (also listed in Core\Lifecycle\Uninstaller::USER_META_KEYS).
	 */
	public const META_KEY = 'merchboard_dashboard_theme';

	/**
	 * The valid themes, in display order.
	 */
	public const THEMES = array( 'light', 'dark-black', 'dark-green', 'dark-blue', 'dark-red', 'dark-pink' );

	/**
	 * Whether a value is one of the six themes.
	 *
	 * @param mixed $value Value.
	 */
	public static function is_theme( mixed $value ): bool {
		return is_string( $value ) && in_array( $value, self::THEMES, true );
	}

	/**
	 * The user's explicit theme, or null without a valid one. An invalid stored
	 * value counts as no choice and is not repaired here.
	 *
	 * @param int $user_id User id.
	 */
	public function for_user( int $user_id ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}

		$value = get_user_meta( $user_id, self::META_KEY, true );

		return self::is_theme( $value ) ? $value : null;
	}

	/**
	 * Saves the user's theme, or removes the explicit choice with null.
	 *
	 * @param int         $user_id User id.
	 * @param string|null $theme   One of THEMES, or null.
	 * @throws InvalidArgumentException When the theme or user is invalid.
	 * @throws RuntimeException When the value could not be stored.
	 */
	public function save( int $user_id, ?string $theme ): void {
		if ( $user_id <= 0 ) {
			throw new InvalidArgumentException( 'Invalid user.' );
		}

		if ( null === $theme ) {
			delete_user_meta( $user_id, self::META_KEY );
		} elseif ( self::is_theme( $theme ) ) {
			// False also when the value is unchanged; the read-back below decides.
			update_user_meta( $user_id, self::META_KEY, $theme );
		} else {
			throw new InvalidArgumentException( 'Invalid theme.' );
		}

		if ( $this->for_user( $user_id ) !== $theme ) {
			throw new RuntimeException( 'The theme preference could not be saved.' );
		}
	}
}
