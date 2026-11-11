<?php
/**
 * A creator's Twitch gifting settings.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 2: gifting on or off, paused (by the creator, a moderator or
 * the dashboard), the `!enter` window in seconds (20–180, 60 by default) and
 * the language of the chat texts (Danish or English). The test mode is set
 * by an administrator only: the creator then counts as live, so gifts can be
 * bought and drawn without streaming (testing on a staging site).
 */
final readonly class GiftingSettings {

	public const MIN_WINDOW     = 20;
	public const MAX_WINDOW     = 180;
	public const DEFAULT_WINDOW = 60;
	public const LANGUAGES      = array( 'da', 'en' );

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled  Whether gifting is on.
	 * @param bool   $paused   Whether it is paused.
	 * @param int    $window   `!enter` window in seconds.
	 * @param string $language  `da` or `en`.
	 * @param bool   $test_mode Whether the creator counts as live (set by an administrator).
	 */
	public function __construct( public bool $enabled, public bool $paused, public int $window, public string $language, public bool $test_mode = false ) {
	}

	/**
	 * Nothing saved: off, not paused, 60 seconds, Danish.
	 */
	public static function defaults(): self {
		return new self( false, false, self::DEFAULT_WINDOW, 'da' );
	}

	/**
	 * The stored settings; anything unreadable falls back to the default.
	 *
	 * @param mixed $stored Stored creator meta.
	 */
	public static function from_stored( mixed $stored ): self {
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}

		$window = $stored['window'] ?? self::DEFAULT_WINDOW;

		return new self(
			true === ( $stored['enabled'] ?? false ),
			true === ( $stored['paused'] ?? false ),
			is_int( $window ) && $window >= self::MIN_WINDOW && $window <= self::MAX_WINDOW ? $window : self::DEFAULT_WINDOW,
			in_array( $stored['language'] ?? null, self::LANGUAGES, true ) ? (string) $stored['language'] : 'da',
			true === ( $stored['test_mode'] ?? false )
		);
	}

	/**
	 * The value to store.
	 *
	 * @return array{enabled: bool, paused: bool, window: int, language: string, test_mode: bool}
	 */
	public function to_stored(): array {
		return array(
			'enabled'   => $this->enabled,
			'paused'    => $this->paused,
			'window'    => $this->window,
			'language'  => $this->language,
			'test_mode' => $this->test_mode,
		);
	}

	/**
	 * Whether gifts can be bought and drawn now (on and not paused).
	 */
	public function open(): bool {
		return $this->enabled && ! $this->paused;
	}

	/**
	 * The same settings paused or resumed.
	 *
	 * @param bool $paused Paused.
	 */
	public function with_paused( bool $paused ): self {
		return new self( $this->enabled, $paused, $this->window, $this->language, $this->test_mode );
	}

	/**
	 * The same settings with the test mode on or off.
	 *
	 * @param bool $test_mode Test mode.
	 */
	public function with_test_mode( bool $test_mode ): self {
		return new self( $this->enabled, $this->paused, $this->window, $this->language, $test_mode );
	}
}
