<?php
/**
 * The HTML a news message may contain.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News;

defined( 'ABSPATH' ) || exit;

/**
 * Simple formatting only (the project owner's choice, ADR-072): paragraphs,
 * line breaks, bold, italic, lists and links (http, https, mailto). Every
 * other tag is removed with its markup, its text kept; every other attribute
 * is removed. Used when a message is saved and again when it is read; the
 * dashboard rebuilds the same elements itself and never inserts HTML.
 */
final class NewsHtml {

	/**
	 * Longest body in characters (after cleaning).
	 */
	public const MAX_LENGTH = 20000;

	/**
	 * Longest title in characters.
	 */
	public const TITLE_MAX = 150;

	/**
	 * The allowed tags and attributes.
	 */
	private const ALLOWED = array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'a'      => array( 'href' => true ),
	);

	/**
	 * Cleaned HTML (at most MAX_LENGTH characters).
	 *
	 * @param string $html Submitted or stored HTML.
	 */
	public static function clean( string $html ): string {
		$clean = wp_kses( $html, self::ALLOWED, array( 'http', 'https', 'mailto' ) );

		// Only full addresses: a relative or stripped address loses its href.
		$clean = (string) preg_replace_callback(
			'/<a href="([^"]*)">/i',
			static fn( array $link ): string => 1 === preg_match( '#^(https?://|mailto:)#i', $link[1] ) ? $link[0] : '<a>',
			$clean
		);

		return mb_substr( trim( $clean ), 0, self::MAX_LENGTH );
	}

	/**
	 * Whether cleaned HTML has text.
	 *
	 * @param string $html Cleaned HTML.
	 */
	public static function has_text( string $html ): bool {
		return '' !== trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * A cleaned title: one plain line of at most TITLE_MAX characters.
	 *
	 * @param string $title Submitted title.
	 */
	public static function title( string $title ): string {
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $title ) ) ), 0, self::TITLE_MAX );
	}
}
