<?php
/**
 * Whether creators may sign themselves up, and under which category.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Signup;

defined( 'ABSPATH' ) || exit;

/**
 * The option of ADR-091 and ADR-093: the switch (off by default) and the
 * `product_cat` a creator's category is created under when they go live.
 */
final class SignupSettings {

	public const OPTION = 'merchboard_creator_signup';

	/**
	 * Whether the sign-up page is open.
	 */
	public static function enabled(): bool {
		return true === ( self::stored()['enabled'] ?? false );
	}

	/**
	 * The parent category of the creators' categories (0 for none).
	 */
	public static function parent_category(): int {
		$term = self::stored()['parent_category'] ?? 0;

		return is_int( $term ) && $term > 0 ? $term : 0;
	}

	/**
	 * Saves both settings.
	 *
	 * @param bool $enabled         Whether the page is open.
	 * @param int  $parent_category Parent `product_cat` (0 for none).
	 */
	public static function save( bool $enabled, int $parent_category ): void {
		update_option(
			self::OPTION,
			array(
				'enabled'         => $enabled,
				'parent_category' => max( 0, $parent_category ),
			),
			false
		);
	}

	/**
	 * The stored option.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}
}
