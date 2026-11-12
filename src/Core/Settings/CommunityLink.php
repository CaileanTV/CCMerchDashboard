<?php
/**
 * The query parameter of the public community links.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The public links of the community features (a Twitch gift's and a
 * giveaway's page, ADR-107 and ADR-110, and later links of the same kind)
 * are the home page with one query parameter: `?<name>=<token>`. An
 * administrator chooses the name under Settings → Community; it defaults to
 * the name used so far, so nothing changes until it is set.
 *
 * Links already passed on keep working: the names used before (the
 * original one and up to MAX_PREVIOUS earlier choices) are still read.
 */
final class CommunityLink {

	/**
	 * The name used before 2.1.1 (and the default).
	 */
	public const LEGACY = 'merchboard_gift_claim';

	/**
	 * Earlier names that are still read.
	 */
	public const MAX_PREVIOUS = 10;

	/**
	 * Names that are never allowed: WordPress' and WooCommerce's public query
	 * variables and the plugin's own parameters.
	 */
	private const RESERVED = array(
		'attachment',
		'attachment_id',
		'author',
		'author_name',
		'cat',
		'category_name',
		'comments_popup',
		'cpage',
		'day',
		'embed',
		'error',
		'feed',
		'hour',
		'm',
		'minute',
		'monthnum',
		'name',
		'order',
		'orderby',
		'p',
		'page',
		'page_id',
		'paged',
		'pagename',
		'post_type',
		'preview',
		'rest_route',
		's',
		'search',
		'second',
		'static',
		'subpost',
		'subpost_id',
		'tag',
		'tag_id',
		'taxonomy',
		'term',
		'tb',
		'w',
		'withcomments',
		'withoutcomments',
		'year',
		'add-to-cart',
		'wc-ajax',
		'wc-api',
		'key',
		'order-received',
		'twitch',
		'chatbot',
		'merchboard_gift',
		'merchboard_gift_redeem',
		'merchboard_giveaway_pay',
	);

	/**
	 * Whether a name may be used: 2–40 lowercase letters, digits, `_` or `-`,
	 * starting with a letter, and not a reserved query variable.
	 *
	 * @param string $name Name.
	 */
	public static function valid( string $name ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_-]{1,39}$/', $name ) && ! in_array( $name, self::RESERVED, true );
	}

	/**
	 * The name new links use.
	 */
	public static function name(): string {
		return Settings::all()[ Settings::COMMUNITY_LINK_PARAM ];
	}

	/**
	 * Every name a link may use: the current one, the earlier ones and the
	 * original one.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		$settings = Settings::all();

		return array_values( array_unique( array_merge( array( $settings[ Settings::COMMUNITY_LINK_PARAM ] ), $settings[ Settings::COMMUNITY_LINK_PREVIOUS ], array( self::LEGACY ) ) ) );
	}

	/**
	 * A community link to the home page with a token.
	 *
	 * @param string               $token Token.
	 * @param array<string,string> $extra Further query arguments.
	 */
	public static function url( string $token, array $extra = array() ): string {
		return add_query_arg( array( self::name() => $token ) + $extra, home_url( '/' ) );
	}

	/**
	 * The token of a request's query, under any name a link may use; '' for none.
	 *
	 * @param array<mixed> $query Unslashed query arguments.
	 */
	public static function token( array $query ): string {
		foreach ( self::names() as $name ) {
			if ( is_string( $query[ $name ] ?? null ) && '' !== $query[ $name ] ) {
				return sanitize_key( $query[ $name ] );
			}
		}

		return '';
	}

	/**
	 * The earlier names after a change from one name to another: the old name
	 * first, at most MAX_PREVIOUS, without the new name and the original one.
	 *
	 * @phpstan-param list<string> $previous
	 *
	 * @param string[] $previous The earlier names so far.
	 * @param string   $old      The name before the change.
	 * @param string   $next     The new name.
	 * @return list<string>
	 */
	public static function previous_after( array $previous, string $old, string $next ): array {
		$names = array_filter(
			array_unique( array_merge( array( $old ), $previous ) ),
			static fn( string $name ): bool => $name !== $next && self::LEGACY !== $name && self::valid( $name )
		);

		return array_slice( array_values( $names ), 0, self::MAX_PREVIOUS );
	}
}
