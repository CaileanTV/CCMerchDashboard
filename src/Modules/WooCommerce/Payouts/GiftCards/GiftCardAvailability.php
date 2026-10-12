<?php
/**
 * Whether a gift card plugin can be used.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

defined( 'ABSPATH' ) || exit;

/**
 * Result of GiftCardProvider::availability() (ADR-057): available, or not
 * with a fixed code. Gift cards cannot be chosen or registered unless it is
 * available.
 */
final readonly class GiftCardAvailability {

	public const NOT_INSTALLED = 'not_installed';

	public const INACTIVE = 'inactive';

	public const UNSUPPORTED_VERSION = 'unsupported_version';

	public const SELF_TEST_FAILED = 'self_test_failed';

	/**
	 * Constructor; use available() or unavailable().
	 *
	 * @param bool        $available Whether it can be used.
	 * @param string|null $version   The plugin's version, when known.
	 * @param string|null $code      Why not, null when available.
	 */
	private function __construct( public bool $available, public ?string $version, public ?string $code ) {
	}

	/**
	 * Available.
	 *
	 * @param string $version The plugin's version.
	 */
	public static function available( string $version ): self {
		return new self( true, $version, null );
	}

	/**
	 * Not available.
	 *
	 * @param string      $code    One of the class constants.
	 * @param string|null $version The plugin's version, when known.
	 */
	public static function unavailable( string $code, ?string $version = null ): self {
		return new self( false, $version, $code );
	}
}
