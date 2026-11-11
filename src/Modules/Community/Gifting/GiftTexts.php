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
			'entered'   => '✅ Med i lodtrækningen om %1$s: %2$s',
			'closed'    => '⏳ Tilmeldingen er lukket! Vinderen af %1$s findes om %2$d sekunder …',
			'again'     => 'Allerede med: %s',
			'more'      => '%1$s og %2$d flere',
			'reminder'  => '⏰ %1$d sekunder tilbage! Skriv !enter for at være med i lodtrækningen om %2$s.',
			'giveaway'  => '🎁 %1$s giver %2$s væk! Skriv !enter inden for %3$d sekunder for at være med i lodtrækningen.',
		),
		'en' => array(
			'announce'  => '🎁 %1$s gifted %2$s! Type !enter within %3$d seconds to join the draw.',
			'anonymous' => 'An anonymous viewer',
			'winner'    => '🎉 @%1$s won %2$s! Claim the gift within 24 hours: %3$s',
			'nobody'    => 'Nobody typed !enter, so the gift waits for the next draw.',
			'paused'    => 'Gifts are paused.',
			'resumed'   => 'Gifts are back on.',
			'none'      => 'There are no gifts waiting.',
			'entered'   => '✅ In the draw for %1$s: %2$s',
			'closed'    => '⏳ Entries are closed! The winner of %1$s is revealed in %2$d seconds …',
			'again'     => 'Already in: %s',
			'more'      => '%1$s and %2$d more',
			'reminder'  => '⏰ %1$d seconds left! Type !enter to join the draw for %2$s.',
			'giveaway'  => '🎁 %1$s is giving away %2$s! Type !enter within %3$d seconds to join the draw.',
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
	 * The announcement of a creator's giveaway (ADR-110).
	 *
	 * @param string $language `da` or `en`.
	 * @param string $creator  The creator's name.
	 * @param string $product  Product name.
	 * @param int    $seconds  Window.
	 */
	public static function giveaway( string $language, string $creator, string $product, int $seconds ): string {
		return sprintf( self::text( $language, 'giveaway' ), $creator, $product, $seconds );
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
	 * A reminder of the seconds left to type `!enter`.
	 *
	 * @param string $language `da` or `en`.
	 * @param int    $seconds  Seconds left.
	 * @param string $product  Product name.
	 */
	public static function reminder( string $language, int $seconds, string $product ): string {
		return sprintf( self::text( $language, 'reminder' ), $seconds, $product );
	}

	/**
	 * The entries are closed; the winner follows.
	 *
	 * @param string $language `da` or `en`.
	 * @param string $product  Product name.
	 * @param int    $seconds  Seconds until the winner.
	 */
	public static function closed( string $language, string $product, int $seconds ): string {
		return sprintf( self::text( $language, 'closed' ), $product, $seconds );
	}

	/**
	 * The confirmation of new entrants and of those who typed `!enter`
	 * again, as one message within Twitch's 500 characters; names that do not
	 * fit are counted ("and 3 more"). '' when there is nobody to confirm.
	 *
	 * @phpstan-param list<string> $entered
	 * @phpstan-param list<string> $again
	 *
	 * @param string   $language `da` or `en`.
	 * @param string   $product  Product name.
	 * @param string[] $entered  New entrants' logins.
	 * @param string[] $again    Logins of those already in.
	 */
	public static function entered( string $language, string $product, array $entered, array $again ): string {
		$parts = array();

		if ( array() !== $entered ) {
			$parts[] = sprintf( self::text( $language, 'entered' ), $product, self::names( $language, $entered, 300 ) );
		}

		if ( array() !== $again ) {
			$parts[] = sprintf( self::text( $language, 'again' ), self::names( $language, $again, 120 ) );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * `@login` names up to about `$budget` characters, then "and N more".
	 *
	 * @phpstan-param list<string> $logins
	 *
	 * @param string   $language `da` or `en`.
	 * @param string[] $logins   Logins.
	 * @param int      $budget   Characters.
	 */
	private static function names( string $language, array $logins, int $budget ): string {
		$shown = array();
		$used  = 0;

		foreach ( $logins as $login ) {
			$name = '@' . $login;

			if ( array() !== $shown && $used + strlen( $name ) + 1 > $budget ) {
				break;
			}

			$shown[] = $name;
			$used   += strlen( $name ) + 1;
		}

		$list = implode( ' ', $shown );
		$rest = count( $logins ) - count( $shown );

		return $rest > 0 ? sprintf( self::text( $language, 'more' ), $list, $rest ) : $list;
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
