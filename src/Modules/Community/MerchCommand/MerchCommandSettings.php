<?php
/**
 * A creator's !merch chat command.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchCommand;

defined( 'ABSPATH' ) || exit;

/**
 * On/off, the creator's text and the cooldown (ADR-113 pkt. 5). The text
 * may hold `$Link` (the creator's collection); without it the link is put
 * last. An empty text uses the default text.
 */
final class MerchCommandSettings {

	/**
	 * The command.
	 */
	public const COMMAND = '!merch';

	/**
	 * The link's placeholder.
	 */
	public const LINK = '$Link';

	/**
	 * The longest text.
	 */
	public const MAX_TEXT = 200;

	/**
	 * The shortest cooldown in seconds.
	 */
	public const MIN_COOLDOWN = 10;

	/**
	 * The longest cooldown in seconds.
	 */
	public const MAX_COOLDOWN = 300;

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled  Whether the bot answers !merch.
	 * @param string $text     The creator's text; '' for the default.
	 * @param int    $cooldown Seconds between two answers per channel.
	 */
	public function __construct( public bool $enabled = false, public string $text = '', public int $cooldown = 30 ) {
	}

	/**
	 * From creator meta.
	 *
	 * @param mixed $stored Stored value.
	 */
	public static function from_stored( mixed $stored ): self {
		$s        = is_array( $stored ) ? $stored : array();
		$text     = is_string( $s['text'] ?? null ) && mb_strlen( $s['text'] ) <= self::MAX_TEXT ? $s['text'] : '';
		$cooldown = $s['cooldown'] ?? null;

		return new self(
			true === ( $s['enabled'] ?? false ),
			$text,
			is_int( $cooldown ) && $cooldown >= self::MIN_COOLDOWN && $cooldown <= self::MAX_COOLDOWN ? $cooldown : 30
		);
	}

	/**
	 * For creator meta.
	 *
	 * @return array{enabled: bool, text: string, cooldown: int}
	 */
	public function to_stored(): array {
		return array(
			'enabled'  => $this->enabled,
			'text'     => $this->text,
			'cooldown' => $this->cooldown,
		);
	}

	/**
	 * The default text.
	 */
	public static function default_text(): string {
		/* translators: $Link is replaced by the link to the creator's collection; keep it as it is. */
		return __( 'Find my merch here: $Link', 'merchboard' );
	}

	/**
	 * The chat message: the text with the link in place of `$Link`, or the
	 * link last.
	 *
	 * @param string $text The creator's text ('' for the default).
	 * @param string $link The collection's URL.
	 */
	public static function message( string $text, string $link ): string {
		$text = '' === trim( $text ) ? self::default_text() : $text;

		return str_contains( $text, self::LINK ) ? str_replace( self::LINK, $link, $text ) : rtrim( $text ) . ' ' . $link;
	}
}
