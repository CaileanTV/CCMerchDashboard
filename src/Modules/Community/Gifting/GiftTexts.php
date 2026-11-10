<?php
/**
 * The bot's chat texts for gifts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 5: fixed texts in Danish or English (the creator's choice),
 * independent of the site's language, so the chat always gets the language
 * the creator picked. Placeholders are filled with plain text; Twitch shows
 * chat messages as text.
 */
final class GiftTexts {

	/**
	 * Texts by language and key.
	 */
	private const TEXTS = array(
		'da' => array(
			'announce'  => '🎁 %1$s har givet %2$s! Skriv !enter inden for %3$d sekunder for at være med i lodtrækningen.',
			'anonymous' => 'En anonym seer',
			'winner'    => '🎉 @%1$s vandt %2$s! Hent gaven inden for 24 timer: %3$s',
			'nobody'    => 'Ingen skrev !enter, så gaven venter til næste lodtrækning.',
			'paused'    => 'Gaver er sat på pause.',
			'resumed'   => 'Gaver er i gang igen.',
			'none'      => 'Der er ingen gaver, der venter.',
		),
		'en' => array(
			'announce'  => '🎁 %1$s gifted %2$s! Type !enter within %3$d seconds to join the draw.',
			'anonymous' => 'An anonymous viewer',
			'winner'    => '🎉 @%1$s won %2$s! Claim the gift within 24 hours: %3$s',
			'nobody'    => 'Nobody typed !enter, so the gift waits for the next draw.',
			'paused'    => 'Gifts are paused.',
			'resumed'   => 'Gifts are back on.',
			'none'      => 'There are no gifts waiting.',
		),
	);

	/**
	 * The announcement of a gift.
	 *
	 * @param string      $language `da` or `en`.
	 * @param string|null $buyer    The buyer's name; null for anonymous.
	 * @param string      $product  Product name.
	 * @param int         $seconds  Window.
	 */
	public static function announce( string $language, ?string $buyer, string $product, int $seconds ): string {
		return sprintf( self::text( $language, 'announce' ), $buyer ?? self::text( $language, 'anonymous' ), $product, $seconds );
	}

	/**
	 * The winner's announcement with the claim link.
	 *
	 * @param string $language `da` or `en`.
	 * @param string $login    Winner's Twitch login.
	 * @param string $product  Product name.
	 * @param string $url      Claim link.
	 */
	public static function winner( string $language, string $login, string $product, string $url ): string {
		return sprintf( self::text( $language, 'winner' ), $login, $product, $url );
	}

	/**
	 * A fixed text (`nobody`, `paused`, `resumed`, `none`).
	 *
	 * @param string $language `da` or `en`.
	 * @param string $key      Key.
	 */
	public static function text( string $language, string $key ): string {
		$texts = self::TEXTS[ 'en' === $language ? 'en' : 'da' ];

		return $texts[ $key ] ?? '';
	}
}
