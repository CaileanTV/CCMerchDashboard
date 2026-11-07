<?php
/**
 * Where a creator is shown on the shop's front page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Signup;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-097: "Vises på forsiden", the term meta `ccm_front_group` of the
 * banner plugin CCMerch Kategoribannere. The creator chooses it at sign-up
 * (Content creator by default); only an administrator changes it later.
 */
final class FrontGroup {

	public const CREATOR = 'creator';

	public const COMMUNITY = 'community';

	public const DEFAULT = self::CREATOR;

	/**
	 * The banner plugin's term meta on the creator's category.
	 */
	public const TERM_META = 'ccm_front_group';

	/**
	 * The values in the order they are offered.
	 *
	 * @var list<string>
	 */
	public const VALUES = array( self::CREATOR, self::COMMUNITY );

	/**
	 * Whether a value is one of the two.
	 *
	 * @param mixed $value Value.
	 */
	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && in_array( $value, self::VALUES, true );
	}

	/**
	 * A stored value, or the default for anything else (none stored).
	 *
	 * @param mixed $value Stored value.
	 */
	public static function or_default( mixed $value ): string {
		return self::is_valid( $value ) ? (string) $value : self::DEFAULT;
	}

	/**
	 * The translated label of a value.
	 *
	 * @param string $value Value.
	 */
	public static function label( string $value ): string {
		return self::COMMUNITY === $value ? __( 'Association & community', 'merchboard' ) : __( 'Content creator', 'merchboard' );
	}
}
