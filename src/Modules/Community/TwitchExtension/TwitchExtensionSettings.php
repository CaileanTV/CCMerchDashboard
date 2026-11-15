<?php
/**
 * A creator's Twitch Extension panel.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchExtension;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the creator's panel is on, the chosen products in order (none:
 * the panel shows the newest) and an optional short text in a pill at the
 * top, e.g. "Type !merch in the chat" (ADR-112 pkt. 4). The text is plain,
 * at most 60 characters and never a link.
 */
final class TwitchExtensionSettings {

	/**
	 * The most products in the panel.
	 */
	public const MAX_PRODUCTS = 12;

	/**
	 * The longest pill text.
	 */
	public const MAX_NOTE = 60;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param bool   $enabled     Whether the panel shows the products.
	 * @param int[]  $product_ids Chosen parent products, in order.
	 * @param string $note        The pill's text; '' for none.
	 */
	public function __construct( public bool $enabled = false, public array $product_ids = array(), public string $note = '' ) {
	}

	/**
	 * From creator meta (anything unreadable is off and empty).
	 *
	 * @param mixed $stored Stored value.
	 */
	public static function from_stored( mixed $stored ): self {
		$stored = is_array( $stored ) ? $stored : array();
		$ids    = is_array( $stored['product_ids'] ?? null ) ? $stored['product_ids'] : array();
		$ids    = array_values( array_unique( array_filter( array_map( static fn( mixed $id ): int => is_int( $id ) ? $id : 0, $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$note   = is_string( $stored['note'] ?? null ) ? $stored['note'] : '';

		return new self( true === ( $stored['enabled'] ?? false ), array_slice( $ids, 0, self::MAX_PRODUCTS ), self::note_error( $note ) === null ? $note : '' );
	}

	/**
	 * Why a pill text is not allowed, or null when it is.
	 *
	 * @param string $note The text (already trimmed).
	 * @return 'too_long'|'no_links'|null
	 */
	public static function note_error( string $note ): ?string {
		if ( mb_strlen( $note ) > self::MAX_NOTE ) {
			return 'too_long';
		}

		// Twitch does not allow links to shops in an extension (ADR-112): no URL or domain.
		if ( 1 === preg_match( '#(https?:|//|www\.|\b[a-z0-9-]+\.[a-z]{2,}\b)#i', $note ) ) {
			return 'no_links';
		}

		return null;
	}

	/**
	 * For creator meta.
	 *
	 * @return array{enabled: bool, product_ids: list<int>, note: string}
	 */
	public function to_stored(): array {
		return array(
			'enabled'     => $this->enabled,
			'product_ids' => $this->product_ids,
			'note'        => $this->note,
		);
	}
}
