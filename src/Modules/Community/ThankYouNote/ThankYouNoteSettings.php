<?php
/**
 * A creator's thank-you note on the packing slip.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\ThankYouNote;

defined( 'ABSPATH' ) || exit;

/**
 * On/off and the creator's text (phase 58): plain text with line breaks,
 * printed on the packing slip of an order with the creator's products. One
 * note per creator, without approval (the owner's choice 2026-11-25).
 */
final class ThankYouNoteSettings {

	/**
	 * The longest text.
	 */
	public const MAX_TEXT = 300;

	/**
	 * The most lines.
	 */
	public const MAX_LINES = 6;

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled Whether the note is printed.
	 * @param string $text    The creator's text.
	 */
	public function __construct( public bool $enabled = false, public string $text = '' ) {
	}

	/**
	 * From creator meta.
	 *
	 * @param mixed $stored Stored value.
	 */
	public static function from_stored( mixed $stored ): self {
		$s    = is_array( $stored ) ? $stored : array();
		$text = is_string( $s['text'] ?? null ) && mb_strlen( $s['text'] ) <= self::MAX_TEXT ? $s['text'] : '';

		return new self( true === ( $s['enabled'] ?? false ) && '' !== $text, $text );
	}

	/**
	 * For creator meta.
	 *
	 * @return array{enabled: bool, text: string}
	 */
	public function to_stored(): array {
		return array(
			'enabled' => $this->enabled,
			'text'    => $this->text,
		);
	}

	/**
	 * The text printed on the packing slip; '' when there is none.
	 */
	public function printed(): string {
		return $this->enabled ? $this->text : '';
	}
}
